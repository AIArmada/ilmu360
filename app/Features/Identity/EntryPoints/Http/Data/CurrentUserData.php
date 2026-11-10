<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Http\Data;

use App\Features\Identity\Api\AccountPreferencesSnapshot;
use App\Shared\Auth\AccountRecord;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

class CurrentUserData extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {}

    public static function fromRecord(AccountRecord $record, AccountPreferencesSnapshot $preferences): self
    {
        $payload = $record->attributes;
        // Keep nullable profile fields stable in the authenticated-user contract.
        // Eloquent omits null attributes from array serialization when they have
        // never been written, but clients should not have to infer their shape.
        $payload += [
            'gender' => null,
            'date_of_birth' => null,
        ];
        $payload['daily_prayer_institution_ids'] = $preferences->dailyPrayerInstitutionIds;
        $payload['friday_prayer_institution_ids'] = $preferences->fridayPrayerInstitutionIds;
        $payload['roles'] = $record->roles;

        return new self(payload: $payload);
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array {
        return $this->payload;
    }
}
