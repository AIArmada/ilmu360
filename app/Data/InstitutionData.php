<?php

declare(strict_types=1);

namespace App\Data;

use AIArmada\Contacting\Enums\ContactPurpose;
use App\Data\Institutions\InstitutionAddressData;
use App\Data\Institutions\InstitutionContactMethodData;
use App\Data\Institutions\InstitutionDonationChannelData;
use App\Data\Institutions\InstitutionNameData;
use App\Data\Institutions\InstitutionSocialProfileData;
use App\Data\Institutions\InstitutionSpaceData;
use App\Enums\InstitutionStatus;
use App\Enums\InstitutionType;
use App\Models\Language;
use App\Support\Institutions\InstitutionFacilities;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Optional;

/**
 * Validated full-graph write boundary for institutions.
 *
 * Covers scalars (name/slug/type/status/source/ref/description/facilities),
 * names, the primary address with canonical role assignments, contact
 * methods, social profiles, donation channels, languages, and spaces.
 *
 * Construction contract:
 * - Name and slug are opaque curated values: presence and length are
 *   validated, but bytes are never trimmed, case-rewritten, or defaulted.
 * - Omitted graph parts preserve existing state; explicit [] clears that
 *   part; explicit values replace it. Facilities follow the same shape:
 *   null preserves, [] clears to null, an array replaces.
 * - Description and the primary address use Spatie Optional: omitted
 *   preserves, explicit null clears (description) or detaches the primary
 *   address link (the shared Address row itself is never deleted), an
 *   explicit value writes. New rows treat an omitted description as null.
 * - At most one primary is allowed per names list, and per the package
 *   grouping for contacts (type + purpose) and socials (platform +
 *   purpose); spaces and language ids must be unique so conflicting pivot
 *   data is never silently dropped.
 * - Source/external_ref are jointly present non-blank or jointly null;
 *   half-provenance is rejected, never silently dropped.
 * - Always build through validateAndCreate(), never from(), so enum,
 *   existence, coherence, and shape violations fail with a validation error
 *   before any write. Nested collections validate through their own Data
 *   rules plus the cross-field checks below.
 */
class InstitutionData extends Data
{
    /**
     * @param  DataCollection<int, InstitutionNameData>|null  $names
     * @param  DataCollection<int, InstitutionContactMethodData>|null  $contact_methods
     * @param  DataCollection<int, InstitutionSocialProfileData>|null  $social_profiles
     * @param  DataCollection<int, InstitutionDonationChannelData>|null  $donation_channels
     * @param  DataCollection<int, InstitutionSpaceData>|null  $spaces
     */
    public function __construct(
        public string $name,
        public string $slug,
        public InstitutionType $type,
        public InstitutionStatus $status,
        public ?string $source = null,
        public ?string $external_ref = null,
        public string|Optional|null $description = new Optional,
        /** @var array<string, bool>|null own-facilities flag map */
        public ?array $facilities = null,
        #[DataCollectionOf(InstitutionNameData::class)]
        /** @var DataCollection<int, InstitutionNameData>|null */
        public ?DataCollection $names = null,
        public InstitutionAddressData|Optional|null $address = new Optional,
        #[DataCollectionOf(InstitutionContactMethodData::class)]
        /** @var DataCollection<int, InstitutionContactMethodData>|null */
        public ?DataCollection $contact_methods = null,
        #[DataCollectionOf(InstitutionSocialProfileData::class)]
        /** @var DataCollection<int, InstitutionSocialProfileData>|null */
        public ?DataCollection $social_profiles = null,
        #[DataCollectionOf(InstitutionDonationChannelData::class)]
        /** @var DataCollection<int, InstitutionDonationChannelData>|null */
        public ?DataCollection $donation_channels = null,
        /** @var list<string>|null language UUIDs */
        public ?array $language_ids = null,
        #[DataCollectionOf(InstitutionSpaceData::class)]
        /** @var DataCollection<int, InstitutionSpaceData>|null */
        public ?DataCollection $spaces = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', self::nonBlankRule()],
            'slug' => ['required', 'string', 'max:255', self::nonBlankRule()],
            'type' => ['required', Rule::enum(InstitutionType::class)],
            'status' => ['required', Rule::enum(InstitutionStatus::class)],
            'source' => ['nullable', 'string', 'max:255'],
            'external_ref' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'facilities' => ['nullable', 'array', self::knownFacilitiesRule()],
            'names' => ['nullable', 'array'],
            'address' => ['nullable', 'array'],
            'contact_methods' => ['nullable', 'array'],
            'social_profiles' => ['nullable', 'array'],
            'donation_channels' => ['nullable', 'array'],
            'language_ids' => ['nullable', 'array'],
            'language_ids.*' => ['uuid', Rule::exists((new Language)->getTable(), 'id')],
            'spaces' => ['nullable', 'array'],
        ];
    }

    /**
     * Cross-field boundary checks that declarative rules cannot express.
     *
     * Only the root Data class receives this hook, so joint provenance, the
     * single-primary and uniqueness checks, plus the nested
     * address-coherence and donation-method checks all run here, against the
     * raw payload paths. Omitted defaulted properties (contact purpose,
     * social purpose, name language/type, donation flags) are left to their
     * constructor defaults and never rejected here.
     */
    #[\Override]
    public static function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();

            if (! is_array($data)) {
                return;
            }

            self::assertJointProvenance($data, $validator);
            self::assertSinglePrimaryNames($data, $validator);
            self::assertSinglePrimaryContacts($data, $validator);
            self::assertSinglePrimarySocials($data, $validator);
            self::assertUniqueSpaces($data, $validator);
            self::assertUniqueLanguages($data, $validator);

            if (isset($data['address']) && is_array($data['address'])) {
                InstitutionAddressData::assertCoherentPayload($data['address'], $validator, 'address.');
            }

            if (isset($data['donation_channels']) && is_array($data['donation_channels'])) {
                foreach ($data['donation_channels'] as $index => $channel) {
                    if (is_array($channel)) {
                        InstitutionDonationChannelData::assertMethodPayload($channel, $validator, "donation_channels.{$index}.");
                    }
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function assertJointProvenance(array $data, Validator $validator): void
    {
        $source = $data['source'] ?? null;
        $externalRef = $data['external_ref'] ?? null;

        if ($source === null && $externalRef === null) {
            return;
        }

        if (is_string($source) && trim($source) !== '' && is_string($externalRef) && trim($externalRef) !== '') {
            return;
        }

        $message = 'The source and external ref must either both be set or both be empty.';
        $validator->errors()->add('source', $message);
        $validator->errors()->add('external_ref', $message);
    }

    /**
     * Enforce the canonical flag map strictly through the value object.
     *
     * Laravel's boolean rule accepts numeric/string 0/1, which the model
     * VO rejects, so this boundary delegates to
     * InstitutionFacilities::validate() instead of a loose per-item rule.
     * Only the code => bool map shape is accepted here; the form
     * CheckboxList code arrays stay a stage-1 form concern.
     */
    public static function knownFacilitiesRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            if ($value !== [] && array_is_list($value)) {
                $fail('The facilities must be a code to boolean flag map.');

                return;
            }

            foreach (InstitutionFacilities::validate($value) as $message) {
                $fail($message);
            }
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function assertSinglePrimaryNames(array $data, Validator $validator): void
    {
        $names = $data['names'] ?? null;

        if (! is_array($names)) {
            return;
        }

        $primaries = 0;

        foreach ($names as $index => $item) {
            if (! is_array($item) || ! self::isPrimaryFlag($item['is_primary'] ?? false)) {
                continue;
            }

            $primaries++;

            if ($primaries > 1) {
                $validator->errors()->add("names.{$index}.is_primary", 'Only one institution name may be marked primary.');

                return;
            }
        }
    }

    /**
     * Reject multiple primaries within one package contact group
     * (contactable + type + purpose) instead of letting model arbitration
     * silently demote all but the last write. Omitted purposes default to
     * general, matching the nested Data defaults.
     *
     * @param  array<string, mixed>  $data
     */
    private static function assertSinglePrimaryContacts(array $data, Validator $validator): void
    {
        $contacts = $data['contact_methods'] ?? null;

        if (! is_array($contacts)) {
            return;
        }

        $seen = [];

        foreach ($contacts as $index => $item) {
            if (! is_array($item) || ! self::isPrimaryFlag($item['is_primary'] ?? false)) {
                continue;
            }

            $type = self::enumValue($item['type'] ?? null);

            if (! is_string($type) || $type === '') {
                continue;
            }

            $purpose = self::enumValue($item['purpose'] ?? null) ?? ContactPurpose::General->value;
            $key = $type."\0".$purpose;

            if (isset($seen[$key])) {
                $validator->errors()->add(
                    "contact_methods.{$index}.is_primary",
                    "Only one primary contact method is allowed per type and purpose (duplicate primary for type [{$type}] and purpose [{$purpose}])."
                );

                return;
            }

            $seen[$key] = true;
        }
    }

    /**
     * Reject multiple primaries within one package social group
     * (socialable + platform + purpose) instead of letting model
     * arbitration silently demote all but the last write.
     *
     * @param  array<string, mixed>  $data
     */
    private static function assertSinglePrimarySocials(array $data, Validator $validator): void
    {
        $socials = $data['social_profiles'] ?? null;

        if (! is_array($socials)) {
            return;
        }

        $seen = [];

        foreach ($socials as $index => $item) {
            if (! is_array($item) || ! self::isPrimaryFlag($item['is_primary'] ?? false)) {
                continue;
            }

            $platform = self::enumValue($item['platform'] ?? null);

            if (! is_string($platform) || $platform === '') {
                continue;
            }

            $purpose = self::enumValue($item['purpose'] ?? null) ?? ContactPurpose::General->value;
            $key = $platform."\0".$purpose;

            if (isset($seen[$key])) {
                $validator->errors()->add(
                    "social_profiles.{$index}.is_primary",
                    "Only one primary social profile is allowed per platform and purpose (duplicate primary for platform [{$platform}] and purpose [{$purpose}])."
                );

                return;
            }

            $seen[$key] = true;
        }
    }

    /**
     * Reject duplicate space ids so conflicting capacities can never be
     * silently collapsed by the keyed pivot sync.
     *
     * @param  array<string, mixed>  $data
     */
    private static function assertUniqueSpaces(array $data, Validator $validator): void
    {
        $spaces = $data['spaces'] ?? null;

        if (! is_array($spaces)) {
            return;
        }

        $seen = [];

        foreach ($spaces as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? null;

            if (! is_string($id) || $id === '') {
                continue;
            }

            if (isset($seen[$id])) {
                $validator->errors()->add("spaces.{$index}.id", "Duplicate space [{$id}]: each space may appear only once so conflicting capacities are never silently dropped.");

                return;
            }

            $seen[$id] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function assertUniqueLanguages(array $data, Validator $validator): void
    {
        $ids = $data['language_ids'] ?? null;

        if (! is_array($ids)) {
            return;
        }

        $seen = [];

        foreach ($ids as $index => $id) {
            if (! is_string($id) || $id === '') {
                continue;
            }

            if (isset($seen[$id])) {
                $validator->errors()->add("language_ids.{$index}", 'Duplicate language id: each language may appear only once.');

                return;
            }

            $seen[$id] = true;
        }
    }

    /**
     * Mirror the truthy side of Laravel's boolean rule: only true/1/'1'
     * count as primary, matching what the nested bool casts will hold.
     */
    private static function isPrimaryFlag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private static function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Reject blank-but-present strings without mutating the stored bytes.
     */
    public static function nonBlankRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                $fail("The {$attribute} field must not be blank.");
            }
        };
    }
}
