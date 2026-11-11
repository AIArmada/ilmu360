<?php

declare(strict_types=1);

namespace App\Features\Membership\Actions;

use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Snapshot, detach and restore membership-owned account state.
 *
 * Member pivot rows keep their role (plus joined and audit timestamps) across
 * deletion/restoration. Membership-application attribution is detached and
 * restored only while the column is still null, so a claim reviewed or
 * reassigned by someone else in the meantime is never overwritten.
 */
final readonly class MembershipAccountStateAction
{
    /**
     * @var array<string, string>
     */
    private const array MEMBER_TABLES = [
        'institution_members' => 'institution_id',
        'person_members' => 'person_id',
        'reference_members' => 'reference_id',
        'event_members' => 'event_id',
    ];

    /**
     * @return array<string, mixed>
     */
    public function snapshot(string $accountId): array
    {
        $snapshot = [];

        foreach (self::MEMBER_TABLES as $table => $subjectKey) {
            $snapshot[$table] = DB::table($table)
                ->where('user_id', $accountId)
                ->get([$subjectKey, 'user_id', 'role', 'joined_at', 'created_at', 'updated_at'])
                ->map(static fn (object $row): array => (array) $row)
                ->values()
                ->all();
        }

        $applicationClass = $this->applicationClass();

        $snapshot['membership_claim_ids'] = $applicationClass::query()
            ->where('applicant_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
        $snapshot['membership_application_reviewer_ids'] = $applicationClass::query()
            ->where('reviewer_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return $snapshot;
    }

    public function detach(string $accountId): void
    {
        foreach (array_keys(self::MEMBER_TABLES) as $table) {
            DB::table($table)->where('user_id', $accountId)->delete();
        }

        $applicationClass = $this->applicationClass();

        $applicationClass::query()
            ->where('applicant_id', $accountId)
            ->update(['applicant_id' => null]);
        $applicationClass::query()
            ->where('reviewer_id', $accountId)
            ->update(['reviewer_id' => null]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function restore(string $accountId, array $snapshot): void
    {
        foreach (array_keys(self::MEMBER_TABLES) as $table) {
            $rows = $this->rows($snapshot[$table] ?? []);

            if ($rows !== []) {
                DB::table($table)->insertOrIgnore($rows);
            }
        }

        $applicationClass = $this->applicationClass();
        $applicantIds = $this->stringIds($snapshot['membership_claim_ids'] ?? []);

        if ($applicantIds !== []) {
            $applicationClass::query()
                ->whereKey($applicantIds)
                ->whereNull('applicant_id')
                ->update(['applicant_id' => $accountId]);
        }

        $reviewerIds = $this->stringIds($snapshot['membership_application_reviewer_ids'] ?? []);

        if ($reviewerIds !== []) {
            $applicationClass::query()
                ->whereKey($reviewerIds)
                ->whereNull('reviewer_id')
                ->update(['reviewer_id' => $accountId]);
        }
    }

    /**
     * @return class-string<Model>
     */
    private function applicationClass(): string
    {
        return MorphModelResolver::resolve('membership_application');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function stringIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(static fn (mixed $id): bool => is_string($id) || is_int($id))
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
