<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Models\RewardProgram;
use App\Models\RewardProgramPublication;
use App\Models\RewardProgramVersion;
use App\Support\Rewards\RewardMaterialTerms;
use App\Support\Rewards\RewardRuleValidator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Staff-driven immutable version publication.
 *
 * Lock order (global convention, later scopes extend it): programs, then
 * versions, then publications. Single-program scope here; a bulk publisher
 * must lock programs in sorted family_key order first. Concurrent same-family
 * publishers serialize on the program row; the unique (program_id, version)
 * and (program_id, effective_from) constraints are the final arbiters.
 */
final readonly class PublishRewardProgramVersionAction
{
    use AsAction;

    public function __construct(
        private RewardRuleValidator $validator,
    ) {}

    /**
     * Publication is prospective: effective_from must not predate its own
     * publication moment. Earlier evidence is covered only by an explicit
     * published preclaim lookback, never by backdating a new version into
     * already-published windows. Controlled past clocks must pass both
     * effective_from and publishedAt explicitly; omitting publishedAt means
     * "published now" and a past effective_from is then a backdate.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $terms
     */
    public function handle(
        RewardProgram|string $program,
        CarbonInterface $effectiveFrom,
        array $rules,
        array $terms,
        ?string $reason = null,
        ?string $publishedByType = null,
        ?string $publishedById = null,
        ?CarbonInterface $publishedAt = null,
    ): RewardProgramVersion {
        $effectiveFrom = CarbonImmutable::parse($effectiveFrom)->setTimezone('UTC');
        $publishedAt = $publishedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($publishedAt)->setTimezone('UTC');

        if ($effectiveFrom->lessThan($publishedAt)) {
            throw new InvalidArgumentException('Effective_from must not be before its own publication; use a published preclaim lookback for earlier evidence, not a backdated publication.');
        }

        if ($reason !== null && ($reason === '' || mb_strlen($reason) > 191)) {
            throw new InvalidArgumentException('Publication reason must be null or 1..191 characters.');
        }

        if ($publishedByType !== null && ($publishedByType === '' || mb_strlen($publishedByType) > 64)) {
            throw new InvalidArgumentException('Published-by type must be null or 1..64 characters.');
        }

        $programId = $program instanceof RewardProgram ? (string) $program->getKey() : $program;

        return DB::transaction(function () use ($programId, $effectiveFrom, $publishedAt, $rules, $terms, $reason, $publishedByType, $publishedById): RewardProgramVersion {
            $locked = RewardProgram::query()->whereKey($programId)->lockForUpdate()->first();

            if (! $locked instanceof RewardProgram) {
                throw new InvalidArgumentException('Unknown reward program.');
            }

            if ($locked->status->isTerminal()) {
                throw new InvalidArgumentException('Closed programs cannot publish new versions.');
            }

            $this->validator->assertValid($rules, $locked->mode, $locked->family_key);
            $this->validator->assertValidTerms($terms, $rules);

            $hash = RewardMaterialTerms::hashForVersion($rules, $terms);

            $latest = RewardProgramVersion::query()
                ->where('program_id', $locked->getKey())
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            $nextVersion = $latest instanceof RewardProgramVersion ? ((int) $latest->version) + 1 : 1;

            if ($latest instanceof RewardProgramVersion) {
                $latestEffective = CarbonImmutable::parse($latest->effective_from)->setTimezone('UTC');
                $latestPublished = CarbonImmutable::parse($latest->published_at)->setTimezone('UTC');

                if ($effectiveFrom->lessThanOrEqualTo($latestEffective)) {
                    throw new InvalidArgumentException('Effective_from must be strictly after the latest published version; no backdating into published windows.');
                }

                if ($publishedAt->lessThan($latestPublished)) {
                    throw new InvalidArgumentException('Publication time cannot move backwards; new versions publish at or after the latest publication.');
                }
            }

            $stacking = $rules['stacking'] ?? null;
            $compatible = is_array($stacking) && is_array($stacking['compatible_with'] ?? null)
                ? array_values($stacking['compatible_with'])
                : [];

            try {
                $version = RewardProgramVersion::query()->create([
                    'program_id' => $locked->getKey(),
                    'version' => $nextVersion,
                    'effective_from' => $effectiveFrom,
                    'material_terms_hash' => $hash,
                    'rules' => $rules,
                    'terms' => $terms,
                    'compatible_with' => $compatible,
                    'published_by_type' => $publishedByType,
                    'published_by_id' => $publishedById,
                    'published_at' => $publishedAt,
                ]);

                RewardProgramPublication::query()->create([
                    'program_id' => $locked->getKey(),
                    'version_id' => $version->getKey(),
                    'previous_version_id' => $latest?->getKey(),
                    'version' => $nextVersion,
                    'effective_from' => $effectiveFrom,
                    'reason' => $reason,
                    'published_by_type' => $publishedByType,
                    'published_by_id' => $publishedById,
                    'published_at' => $publishedAt,
                ]);

                $locked->forceFill(['current_version_id' => $version->getKey()])->save();

                return $version->refresh();
            } catch (QueryException $exception) {
                if (CreateRewardProgramAction::isUniqueViolation($exception)) {
                    throw new InvalidArgumentException('Concurrent publication conflict; reload the timeline and retry.', 0, $exception);
                }

                throw $exception;
            }
        });
    }
}
