<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardProgram;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Staff-driven program creation (server/domain authorized; no member endpoint
 * in this slice). Programs are born as disabled drafts; activation is a
 * separate explicit transition.
 */
final readonly class CreateRewardProgramAction
{
    use AsAction;

    /**
     * @var list<string>
     */
    public const array OWNER_TYPES = ['platform', 'institution', 'organization'];

    public function handle(
        string $familyKey,
        string $title,
        RewardProgramMode|string $mode,
        string $ownerType,
        ?string $ownerId,
        string $purpose,
    ): RewardProgram {
        $familyKey = trim($familyKey);
        $title = trim($title);
        $purpose = trim($purpose);

        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $familyKey) !== 1) {
            throw new InvalidArgumentException('Family key must be a 1..64 slug.');
        }

        if ($title === '' || mb_strlen($title) > 191) {
            throw new InvalidArgumentException('Title must be a non-empty string up to 191 characters.');
        }

        if ($purpose === '' || mb_strlen($purpose) > 191) {
            throw new InvalidArgumentException('Purpose must be an explicit non-empty scope up to 191 characters.');
        }

        $modeEnum = $mode instanceof RewardProgramMode ? $mode : RewardProgramMode::tryFrom($mode);

        if (! $modeEnum instanceof RewardProgramMode) {
            throw new InvalidArgumentException('Unknown program mode.');
        }

        if (! in_array($ownerType, self::OWNER_TYPES, true)) {
            throw new InvalidArgumentException('Owner type must be one of ['.implode(',', self::OWNER_TYPES).'].');
        }

        if ($ownerType === 'platform' && $ownerId !== null) {
            throw new InvalidArgumentException('Platform-owned programs must not carry an owner id.');
        }

        if ($ownerType !== 'platform' && ($ownerId === null || ! Str::isUuid($ownerId))) {
            throw new InvalidArgumentException('Non-platform programs require an explicit owner UUID.');
        }

        try {
            return RewardProgram::query()->create([
                'family_key' => $familyKey,
                'title' => $title,
                'mode' => $modeEnum,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'purpose' => $purpose,
                'status' => RewardProgramStatus::Draft,
            ]);
        } catch (QueryException $exception) {
            if (self::isUniqueViolation($exception)) {
                throw new InvalidArgumentException('Program family already exists.', 0, $exception);
            }

            throw $exception;
        }
    }

    public static function isUniqueViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();

        if (in_array($code, ['23000', '23505'], true)) {
            return true;
        }

        return stripos($exception->getMessage(), 'unique') !== false
            || stripos($exception->getMessage(), 'duplicate') !== false;
    }
}
