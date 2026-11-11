<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use AIArmada\Persons\Enums\Gender;
use App\Features\Directory\Support\DirectoryModel;
use App\Features\Membership\Api\MembershipCommands;
use App\Shared\Auth\AccountActor;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Model;

/**
 * Create a person from a contribution, quick-add or author payload.
 */
final readonly class CreatePersonAction
{
    public function __construct(
        private MembershipCommands $membershipCommands,
        private GeneratePersonSlugAction $generatePersonSlugAction,
        private SyncPersonRelationsAction $syncPersonRelations,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        array $payload,
        (Model&AccountActor)|null $actor = null,
        bool $verified = false,
        bool $assignOwner = false,
        bool $syncRelations = true,
        bool $defaultGender = true,
    ): Model {
        $name = (string) ($payload['name'] ?? 'Person');
        $now = now();
        $attributes = [
            'name' => $name,
            'middle_name' => $payload['middle_name'] ?? null,
            'family_name' => $payload['family_name'] ?? null,
            'gender' => $this->normalizeGender($payload['gender'] ?? null, $defaultGender),
            'bio' => $payload['bio'] ?? null,
            'slug' => $this->generatePersonSlugAction->handle($name, $payload),
            'status' => $verified ? 'verified' : 'pending',
            'verified_at' => $verified ? $now : null,
            'last_state_change_at' => $verified ? $now : null,
            'allow_public_event_submission' => array_key_exists('allow_public_event_submission', $payload)
                ? (bool) $payload['allow_public_event_submission']
                : true,
        ];

        if (array_key_exists('speaker_status', $payload)) {
            $attributes['speaker_status'] = $payload['speaker_status'];
        }

        $person = DirectoryModel::query('person')->create($attributes);

        if ($actor instanceof Model && $assignOwner) {
            $this->membershipCommands->assignOwner(
                MembershipSubjectType::Person,
                (string) $person->getKey(),
                (string) $actor->getKey(),
            );
        }

        if ($syncRelations) {
            $this->syncPersonRelations->handle($person, $payload);
        } elseif (array_key_exists('address', $payload)) {
            $this->syncPersonRelations->handle($person, ['address' => $payload['address']]);
        }

        $this->generatePersonSlugAction->syncPersonSlug($person);

        return $person;
    }

    private function normalizeGender(mixed $value, bool $default = true): ?string
    {
        if ($value instanceof Gender) {
            return $value->value;
        }

        if (is_string($value) && Gender::tryFrom($value) instanceof Gender) {
            return $value;
        }

        return $default ? Gender::Male->value : null;
    }
}
