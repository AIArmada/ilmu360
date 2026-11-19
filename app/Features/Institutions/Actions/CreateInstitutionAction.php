<?php

declare(strict_types=1);

namespace App\Features\Institutions\Actions;

use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Api\Enums\InstitutionType;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Api\MembershipCommands;
use App\Features\Membership\Api\MemberSubjectType;
use App\Shared\Auth\AccountActor;
use Illuminate\Database\Eloquent\Model;

/**
 * Create an institution from a contribution or quick-add payload.
 */
final readonly class CreateInstitutionAction
{
    public function __construct(
        private MembershipCommands $membershipCommands,
        private GenerateInstitutionSlugAction $generateInstitutionSlugAction,
        private SyncInstitutionNamesAction $syncInstitutionNames,
        private SyncInstitutionRelationsAction $syncInstitutionRelations,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        array $payload,
        (Model&AccountActor)|null $actor = null,
        bool $verified = false,
        bool $assignOwner = false,
    ): Model {
        $name = (string) ($payload['name'] ?? 'Institution');
        $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];

        $institution = Institution::query()->create([
            'name' => $name,
            'slug' => $this->generateInstitutionSlugAction->handle($name, $address),
            'type' => $this->normalizeType($payload['type'] ?? null),
            'description' => $payload['description'] ?? null,
            'status' => $verified ? InstitutionStatus::Verified : InstitutionStatus::Pending,
            'verified_at' => $verified ? now() : null,
            'last_state_change_at' => $verified ? now() : null,
            'allow_public_event_submission' => true,
        ]);

        if ($actor instanceof Model && $assignOwner) {
            $this->membershipCommands->assignOwner(
                MemberSubjectType::Institution,
                (string) $institution->getKey(),
                (string) $actor->getKey(),
            );
        }

        if (is_array($payload['names'] ?? null)) {
            $this->syncInstitutionNames->handle($institution, $payload['names']);
        }

        $this->syncInstitutionRelations->handle($institution, $payload);
        $this->generateInstitutionSlugAction->syncInstitutionSlug($institution);

        return $institution;
    }

    private function normalizeType(mixed $value): string
    {
        if ($value instanceof InstitutionType) {
            return $value->value;
        }

        if (is_string($value) && InstitutionType::tryFrom($value) instanceof InstitutionType) {
            return $value;
        }

        return InstitutionType::Masjid->value;
    }
}
