<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Auth\AccountActor;
use App\Shared\Directory\Enums\InstitutionStatus;
use App\Shared\Directory\Enums\InstitutionType;
use Illuminate\Database\Eloquent\Model;

/**
 * Create an institution from a contribution or quick-add payload.
 */
final readonly class CreateInstitutionAction
{
    public function __construct(
        private AddMemberAction $addMemberAction,
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

        $institution = DirectoryModel::query('institution')->create([
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
            $this->addMemberAction->handle($institution, $actor, MemberRole::Owner);
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
