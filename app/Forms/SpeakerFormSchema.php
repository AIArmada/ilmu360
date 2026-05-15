<?php

namespace App\Forms;

use App\Actions\Membership\AddMemberToSubject;
use App\Actions\Speakers\GenerateSpeakerSlugAction;
use App\Enums\Gender;
use App\Models\Speaker;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class SpeakerFormSchema
{
    /**
     * Shared createOptionForm for Speaker selects.
     *
     * @return array<int, Component>
     */
    public static function createOptionForm(): array
    {
        return SpeakerContributionFormSchema::components(
            includeMedia: true,
            regionOnlyAddress: true,
        );
    }

    /**
     * Shared createOptionUsing callback for Speaker selects.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createOptionUsing(array $data, ?Schema $schema = null): string
    {
        $speaker = Speaker::create([
            'name' => $data['name'],
            'gender' => $data['gender'] ?? Gender::Male->value,
            'honorific' => empty($data['honorific']) ? null : $data['honorific'],
            'pre_nominal' => empty($data['pre_nominal']) ? null : $data['pre_nominal'],
            'post_nominal' => empty($data['post_nominal']) ? null : $data['post_nominal'],
            'job_title' => $data['job_title'] ?? null,
            'bio' => $data['bio'] ?? null,
            'qualifications' => self::normalizeQualificationEntries($data['qualifications'] ?? []),
            'is_freelance' => (bool) ($data['is_freelance'] ?? false),
            'slug' => app(GenerateSpeakerSlugAction::class)->handle((string) ($data['name'] ?? 'Speaker'), $data),
            'status' => 'pending',
        ]);

        $creator = auth()->user();

        if ($creator instanceof User) {
            app(AddMemberToSubject::class)->handle($speaker, $creator);
        }

        // Save media uploads (avatar/cover) via Filament's relationship-saving mechanism
        $schema?->model($speaker)->saveRelationships();

        app(ContributionEntityMutationService::class)->syncSpeakerRelations($speaker, $data);

        return (string) $speaker->getKey();
    }

    /**
     * @param  iterable<int, mixed>  $qualifications
     * @return list<array{institution: string, degree: string, field: string|null, year: string|null}>
     */
    private static function normalizeQualificationEntries(iterable $qualifications): array
    {
        return collect($qualifications)
            ->map(function (mixed $qualification): ?array {
                if (! is_array($qualification)) {
                    return null;
                }

                $institution = is_string($qualification['institution'] ?? null) ? trim($qualification['institution']) : null;
                $degree = is_string($qualification['degree'] ?? null) ? trim($qualification['degree']) : null;
                $field = is_string($qualification['field'] ?? null) ? trim($qualification['field']) : null;
                $year = is_string($qualification['year'] ?? null) ? trim($qualification['year']) : null;

                if (blank($institution) || blank($degree)) {
                    return null;
                }

                return [
                    'institution' => $institution,
                    'degree' => $degree,
                    'field' => filled($field) ? $field : null,
                    'year' => filled($year) ? $year : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
