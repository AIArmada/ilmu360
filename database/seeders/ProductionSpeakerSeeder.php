<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Addressing\Actions\SyncAddressAreaAssignmentsAction;
use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Addressing\Support\AddressingTableResolver;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Contacting\Models\SocialProfile;
use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Enums\PersonNameType;
use AIArmada\Persons\Models\PersonName;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleAssignment;
use App\Enums\EventKeyPersonRole;
use App\Enums\SpeakerStatus;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Imports production speakers from the restored dump database into the local
 * Person model, along with every relationship that can be safely resolved to
 * local records.
 *
 * The source database is the restored production dump (`ilmu360-prod`); the
 * target is the currently configured connection. Production user accounts are
 * never imported: member links are matched by email against local users only,
 * and global admins are never attached. Avatar media rows are imported as
 * metadata only; the S3 files themselves are not copied.
 *
 * Usage: php artisan db:seed --class=ProductionSpeakerSeeder
 */
class ProductionSpeakerSeeder extends Seeder
{
    private const string SOURCE_DATABASE = 'ilmu360-prod';

    private const array TITLE_ALIASES = [
        'ar' => 'Ar.',
        'dai' => 'Dai',
        'dato' => "Dato'",
        'dato_setia' => "Dato' Setia",
        'datuk' => 'Datuk',
        'dr' => 'Dr.',
        'habib' => 'Habib',
        'hj' => 'Haji',
        'hajah' => 'Hajah',
        'imam_muda' => 'Imam Muda',
        'ir' => 'Ir.',
        'lc.' => 'Lc',
        'ma' => 'MA',
        'maulana' => 'Maulana',
        'prof' => 'Profesor',
        'prof_madya' => 'Profesor Madya',
        'pu' => 'PU',
        'syeikh' => 'Syeikh',
        'syeikhul_maqari' => 'Syeikhul Maqari',
        'tuan_guru' => 'Tuan Guru',
        'ustaz' => 'Ustaz',
        'ustazah' => 'Ustazah',
    ];

    private string $sourceConnectionName = 'production_speaker_import_source';

    private int $importedSpeakerCount = 0;

    private int $importedAddressCount = 0;

    private int $importedSocialProfileCount = 0;

    private int $importedSearchTermCount = 0;

    private int $importedRedirectCount = 0;

    private int $importedMemberLinkCount = 0;

    private int $importedInstitutionLinkCount = 0;

    private int $importedEventLinkCount = 0;

    private int $importedMediaRecordCount = 0;

    private int $skippedAddressAssignmentCount = 0;

    private int $skippedEventLinkCount = 0;

    private int $skippedInstitutionLinkCount = 0;

    private int $skippedSocialPlatformCount = 0;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('ProductionSpeakerSeeder is for local use and cannot run in production.');
        }

        $sourceConnection = $this->registerSourceConnection();
        $source = DB::connection($sourceConnection);
        $target = DB::connection();

        if ($source->getDatabaseName() === $target->getDatabaseName()) {
            throw new RuntimeException('The production speaker source database must differ from the local target database.');
        }

        foreach (['speakers', 'speaker_user', 'speaker_search_terms', 'addresses', 'countries', 'states', 'cities', 'social_media', 'slug_redirects', 'media', 'events', 'event_key_people', 'users', 'institutions', 'institution_speaker'] as $table) {
            if (! Schema::connection($sourceConnection)->hasTable($table)) {
                throw new RuntimeException("The production speaker source database is missing the [{$table}] table.");
            }
        }

        foreach ($this->requiredTargetTables() as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Run migrations before importing production speaker relationships; target table [{$table}] is missing.");
            }
        }

        $sourceSpeakers = $source->table('speakers')->orderBy('id')->get();
        $this->command?->info(sprintf(
            'Importing %d speakers from [%s] into [%s].',
            $sourceSpeakers->count(),
            $source->getDatabaseName(),
            $target->getDatabaseName(),
        ));

        OwnerContext::withOwner(null, function () use ($source, $sourceSpeakers): void {
            DB::transaction(function () use ($source, $sourceSpeakers): void {
                foreach ($sourceSpeakers as $sourceSpeaker) {
                    $person = $this->importSpeaker($sourceSpeaker);
                    $this->importNames($sourceSpeaker, $person);
                    $this->importTitles($sourceSpeaker, $person);
                    $this->importAddress($source, $sourceSpeaker, $person);
                    $this->importSocialProfiles($source, $sourceSpeaker, $person);
                    $this->importSearchTerms($source, $sourceSpeaker, $person);
                    $this->importSlugRedirects($source, $sourceSpeaker, $person);
                    $this->importMemberLinks($source, $sourceSpeaker, $person);
                    $this->importInstitutionLinks($source, $sourceSpeaker, $person);
                    $this->importEventLinks($source, $sourceSpeaker, $person);
                    $this->importMediaMetadata($source, $sourceSpeaker, $person);

                    if ($person->wasRecentlyCreated) {
                        $this->importedSpeakerCount++;
                    }
                }
            });
        });

        $this->command?->table(
            ['Imported data', 'Count'],
            [
                ['Speakers as Person records', $this->importedSpeakerCount],
                ['Package-native addresses', $this->importedAddressCount],
                ['Social profiles', $this->importedSocialProfileCount],
                ['Search terms', $this->importedSearchTermCount],
                ['Slug redirects', $this->importedRedirectCount],
                ['Matching local non-admin user links', $this->importedMemberLinkCount],
                ['Matching local institution affiliations', $this->importedInstitutionLinkCount],
                ['Matching local event links', $this->importedEventLinkCount],
                ['Media metadata records (files not copied)', $this->importedMediaRecordCount],
                ['Skipped address area assignments', $this->skippedAddressAssignmentCount],
                ['Skipped event links without local event/role', $this->skippedEventLinkCount],
                ['Skipped institution links without local institution', $this->skippedInstitutionLinkCount],
                ['Skipped social profiles with unsupported platform', $this->skippedSocialPlatformCount],
            ],
        );
    }

    /** @return list<string> */
    private function requiredTargetTables(): array
    {
        return [
            config('persons.database.tables.persons', 'persons'),
            config('persons.database.tables.person_names', 'person_names'),
            config('persons.database.tables.titles', 'titles'),
            config('persons.database.tables.title_assignments', 'title_assignments'),
            config('persons.database.tables.affiliations', 'affiliations'),
            config('contacting.database.tables.social_profiles', 'social_profiles'),
            'person_search_terms',
            'person_members',
            'slug_redirects',
            'event_involvements',
            'media',
            'institutions',
            AddressingTableResolver::resolve('countries'),
            AddressingTableResolver::resolve('states'),
            AddressingTableResolver::resolve('cities'),
            AddressingTableResolver::resolve('addresses'),
            AddressingTableResolver::resolve('addressables'),
            AddressingTableResolver::resolve('areas'),
            AddressingTableResolver::resolve('address_area_assignments'),
        ];
    }

    private function registerSourceConnection(): string
    {
        $configuration = config('database.connections.pgsql');

        if (! is_array($configuration)) {
            throw new RuntimeException('A PostgreSQL connection is required to import the speaker archive.');
        }

        $configuration['url'] = null;
        $configuration['database'] = self::SOURCE_DATABASE;
        config(["database.connections.{$this->sourceConnectionName}" => $configuration]);
        DB::purge($this->sourceConnectionName);

        return $this->sourceConnectionName;
    }

    private function importSpeaker(object $sourceSpeaker): Person
    {
        $name = trim((string) $sourceSpeaker->name);

        // Source id first: the address observer rewrites imported slugs to the
        // canonical local form after commit, so the source slug no longer
        // matches on a rerun while the preserved source id always does.
        $person = Person::query()->whereKey((string) $sourceSpeaker->id)->first()
            ?? Person::query()->where('slug', $sourceSpeaker->slug)->first();

        if ($person instanceof Person) {
            $person->forceFill([
                'name' => $name !== '' ? $name : $person->name,
                'gender' => $sourceSpeaker->gender,
                'bio' => $this->normalizedBio($sourceSpeaker->bio),
                'status' => $this->personStatus((string) $sourceSpeaker->status),
                'speaker_status' => $sourceSpeaker->is_active
                    ? SpeakerStatus::Active->value
                    : SpeakerStatus::Inactive->value,
                'allow_public_event_submission' => (bool) $sourceSpeaker->allow_public_event_submission,
                'public_submission_locked_at' => $sourceSpeaker->public_submission_locked_at,
                'public_submission_locked_by' => null,
                'verified_at' => $sourceSpeaker->status === 'verified'
                    ? ($person->verified_at ?? $sourceSpeaker->created_at)
                    : null,
                'rejected_at' => $sourceSpeaker->status === 'rejected'
                    ? ($person->rejected_at ?? $sourceSpeaker->updated_at)
                    : null,
                'last_state_change_at' => $person->last_state_change_at ?? $sourceSpeaker->updated_at,
            ]);
            $person->saveQuietly();

            return $person;
        }

        $personId = (string) $sourceSpeaker->id;

        if (Person::query()->whereKey($personId)->exists()) {
            $personId = (string) Str::orderedUuid();
        }

        $person = new Person;
        $person->forceFill([
            'id' => $personId,
            'name' => $name !== '' ? $name : 'Imported speaker',
            'slug' => $this->uniqueSlug((string) $sourceSpeaker->slug),
            'gender' => $sourceSpeaker->gender,
            'bio' => $this->normalizedBio($sourceSpeaker->bio),
            'searchable_name' => $sourceSpeaker->searchable_name,
            'status' => $this->personStatus((string) $sourceSpeaker->status),
            'verified_at' => $sourceSpeaker->status === 'verified' ? $sourceSpeaker->created_at : null,
            'rejected_at' => $sourceSpeaker->status === 'rejected' ? $sourceSpeaker->updated_at : null,
            'last_state_change_at' => $sourceSpeaker->updated_at,
            'speaker_status' => $sourceSpeaker->is_active
                ? SpeakerStatus::Active->value
                : SpeakerStatus::Inactive->value,
            'allow_public_event_submission' => (bool) $sourceSpeaker->allow_public_event_submission,
            'public_submission_locked_at' => $sourceSpeaker->public_submission_locked_at,
            'public_submission_locked_by' => null,
            'created_at' => $sourceSpeaker->created_at,
            'updated_at' => $sourceSpeaker->updated_at,
        ]);
        $person->saveQuietly();

        return $person;
    }

    private function uniqueSlug(string $slug): string
    {
        $baseSlug = Str::slug($slug) ?: 'speaker';
        $candidate = $baseSlug;
        $suffix = (string) Str::afterLast($baseSlug, '-');
        $attempt = 0;

        while (Person::query()->where('slug', $candidate)->exists()) {
            $attempt++;
            $candidate = $baseSlug.'-'.$suffix.($attempt > 1 ? '-'.$attempt : '');
        }

        return $candidate;
    }

    private function personStatus(string $sourceStatus): string
    {
        return match ($sourceStatus) {
            'verified', 'pending', 'rejected', 'inactive' => $sourceStatus,
            default => 'pending',
        };
    }

    /** @return array<string, string>|null */
    private function normalizedBio(mixed $bio): ?array
    {
        $document = $this->jsonArray($bio);

        if (! is_array($document)) {
            return null;
        }

        $text = $this->richText($document);

        return $text !== '' ? ['ms' => $text] : null;
    }

    /** @param array<string, mixed> $node */
    private function richText(array $node): string
    {
        $text = is_string($node['text'] ?? null) ? $node['text'] : '';

        foreach ($node['content'] ?? [] as $child) {
            if (! is_array($child)) {
                continue;
            }

            $childText = $this->richText($child);

            if ($childText === '') {
                continue;
            }

            if (in_array($child['type'] ?? null, ['paragraph', 'heading', 'listItem'], true) && $text !== '') {
                $text .= "\n\n";
            }

            $text .= $childText;
        }

        return trim($text);
    }

    /** @return array<string, mixed>|null */
    private function jsonArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function importNames(object $sourceSpeaker, Person $person): void
    {
        $name = trim((string) $sourceSpeaker->name);

        if ($name !== '') {
            $this->firstOrCreateName($person, PersonNameType::Display, $name, true);
        }

        $honorific = $this->jsonArray($sourceSpeaker->honorific) ?? [];
        $preNominal = $this->jsonArray($sourceSpeaker->pre_nominal) ?? [];
        $postNominal = $this->jsonArray($sourceSpeaker->post_nominal) ?? [];
        $qualifiedName = trim(implode(' ', array_filter([
            ...array_map($this->formatTitle(...), $honorific),
            ...array_map($this->formatTitle(...), $preNominal),
            $name,
            ...array_map($this->formatTitle(...), $postNominal),
        ])));

        if ($qualifiedName !== '' && $qualifiedName !== $name) {
            $this->firstOrCreateName($person, PersonNameType::Professional, $qualifiedName);
        }
    }

    private function firstOrCreateName(Person $person, PersonNameType $type, string $fullName, bool $isPrimary = false): void
    {
        $fullName = trim($fullName);

        if ($fullName === '') {
            return;
        }

        $personName = PersonName::query()->firstOrCreate(
            [
                'person_id' => $person->getKey(),
                'name_type' => $type->value,
                'full_name' => $fullName,
                'language_code' => 'ms',
            ],
            ['is_primary' => $isPrimary],
        );

        if ($isPrimary && ! $personName->is_primary) {
            $personName->is_primary = true;
            $personName->save();
        }
    }

    private function formatTitle(mixed $title): string
    {
        if (! is_string($title)) {
            return '';
        }

        $title = trim($title);
        $key = Str::lower(str_replace(' ', '_', $title));

        return self::TITLE_ALIASES[$key] ?? $title;
    }

    private function importTitles(object $sourceSpeaker, Person $person): void
    {
        $titles = [];

        foreach (['honorific', 'pre_nominal', 'post_nominal'] as $field) {
            foreach ($this->jsonArray($sourceSpeaker->{$field}) ?? [] as $sourceTitle) {
                $titleName = $this->formatTitle($sourceTitle);

                if ($titleName !== '') {
                    $titles[$titleName] = true;
                }
            }
        }

        foreach (array_keys($titles) as $titleName) {
            $title = Title::query()
                ->where(function ($query) use ($titleName): void {
                    $query->where('name', $titleName)
                        ->orWhere('short_form', $titleName);
                })
                ->orderBy('name')
                ->first();

            if (! $title instanceof Title) {
                continue;
            }

            TitleAssignment::query()->firstOrCreate(
                [
                    'titleable_type' => $person->getMorphClass(),
                    'titleable_id' => $person->getKey(),
                    'title_id' => $title->getKey(),
                ],
                ['status' => AssignmentStatus::Active->value],
            );
        }
    }

    private function importAddress(object $source, object $sourceSpeaker, Person $person): void
    {
        $sourceAddress = $source->table('addresses')
            ->where('addressable_type', 'speaker')
            ->where('addressable_id', $sourceSpeaker->id)
            ->where('type', 'main')
            ->first();

        if ($sourceAddress === null) {
            return;
        }

        $sourceCountryCode = $source->table('countries')
            ->where('id', $sourceAddress->country_id)
            ->value('iso2');

        if (! is_string($sourceCountryCode) || $sourceCountryCode === '') {
            return;
        }

        $country = AddressCountry::query()->where('iso2', $sourceCountryCode)->first();

        if (! $country instanceof AddressCountry) {
            return;
        }

        $address = $person->primaryAddress('primary');

        if (! $address instanceof Address) {
            $addressId = (string) $sourceAddress->id;

            if (DB::table(AddressingTableResolver::resolve('addresses'))->where('id', $addressId)->exists()) {
                $addressId = (string) Str::orderedUuid();
            }

            $address = new Address;
            $address->setAttribute('id', $addressId);
        }

        $sourceState = $sourceAddress->state_id !== null
            ? $source->table('states')->where('id', $sourceAddress->state_id)->first()
            : null;
        $sourceCity = $sourceAddress->city_id !== null
            ? $source->table('cities')->where('id', $sourceAddress->city_id)->first()
            : null;
        $stateId = $this->localStateId($sourceState, $country);

        $address->forceFill([
            'country_id' => $country->getKey(),
            'state_id' => $stateId,
            'city_id' => $this->localCityId($sourceCity, $country, $stateId),
            'label' => null,
            'line1' => $sourceAddress->line1,
            'line2' => $sourceAddress->line2,
            'city' => $sourceCity?->name,
            'state' => $sourceState?->name,
            'postcode' => $sourceAddress->postcode,
            'country' => $country->name,
            'country_code' => $country->iso2,
            'latitude' => $sourceAddress->lat,
            'longitude' => $sourceAddress->lng,
            'google_maps_url' => $sourceAddress->google_maps_url,
            'google_place_id' => $sourceAddress->google_place_id,
            'waze_url' => $sourceAddress->waze_url,
            'created_at' => $sourceAddress->created_at,
            'updated_at' => $sourceAddress->updated_at,
        ]);
        $address->save();
        $person->attachAddress($address, type: 'primary', isPrimary: true);

        $this->importAreaAssignments($source, (string) $sourceAddress->id, $address);

        if ($address->wasRecentlyCreated) {
            $this->importedAddressCount++;
        }
    }

    private function localStateId(?object $sourceState, AddressCountry $country): ?string
    {
        if ($sourceState === null) {
            return null;
        }

        return State::query()
            ->where('country_id', $country->getKey())
            ->whereRaw('lower(name) = ?', [mb_strtolower((string) $sourceState->name)])
            ->value('id');
    }

    private function localCityId(?object $sourceCity, AddressCountry $country, ?string $stateId): ?string
    {
        if ($sourceCity === null) {
            return null;
        }

        return City::query()
            ->where('country_id', $country->getKey())
            ->when($stateId !== null, fn ($query) => $query->where('state_id', $stateId))
            ->whereRaw('lower(name) = ?', [mb_strtolower((string) $sourceCity->name)])
            ->value('id');
    }

    private function importAreaAssignments(object $source, string $sourceAddressId, Address $address): void
    {
        $areaTable = AddressingTableResolver::resolve('areas');

        if (! Schema::connection($this->sourceConnectionName)->hasTable('address_area_assignments')
            || ! Schema::connection($this->sourceConnectionName)->hasTable('address_areas')) {
            return;
        }

        $sourceAssignments = $source->table('address_area_assignments')
            ->where('address_id', $sourceAddressId)
            ->get(['address_area_id', 'role']);

        if ($sourceAssignments->isEmpty()) {
            return;
        }

        $mapping = [];

        foreach ($sourceAssignments as $assignment) {
            $areaName = $source->table('address_areas')
                ->where('id', $assignment->address_area_id)
                ->value('name');

            if (! is_string($areaName)) {
                continue;
            }

            $localAreaId = DB::table($areaTable)
                ->where('country_code', $address->country_code)
                ->whereRaw('lower(name) = ?', [mb_strtolower($areaName)])
                ->value('id');

            if (is_string($localAreaId)) {
                $mapping[$assignment->role] = $localAreaId;
            }
        }

        $this->skippedAddressAssignmentCount += $sourceAssignments->count() - count($mapping);

        if ($mapping === []) {
            return;
        }

        try {
            app(SyncAddressAreaAssignmentsAction::class)->execute(
                $address,
                $mapping,
                $address->state_id,
                ['source' => 'production-speaker-import'],
            );
        } catch (ValidationException $exception) {
            $this->skippedAddressAssignmentCount += count($mapping);
            Log::warning('Skipped speaker address-area assignments that do not match local geography.', [
                'address_id' => $address->getKey(),
                'roles' => array_keys($mapping),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function importSocialProfiles(object $source, object $sourceSpeaker, Person $person): void
    {
        $profiles = $source->table('social_media')
            ->where('socialable_type', 'speaker')
            ->where('socialable_id', $sourceSpeaker->id)
            ->orderBy('order_column')
            ->get();

        foreach ($profiles as $profile) {
            $platform = $this->socialPlatform((string) $profile->platform);

            if ($platform === null) {
                $this->skippedSocialPlatformCount++;

                continue;
            }

            $socialProfile = SocialProfile::query()
                ->where('socialable_type', $person->getMorphClass())
                ->where('socialable_id', $person->getKey())
                ->whereRaw("metadata->>'production_speaker_source_id' = ?", [(string) $profile->id])
                ->first();

            $socialProfile ??= SocialProfile::query()
                ->where('socialable_type', $person->getMorphClass())
                ->where('socialable_id', $person->getKey())
                ->where('platform', $platform)
                ->where('url', $profile->url)
                ->first();

            $isNewProfile = ! $socialProfile instanceof SocialProfile;

            if (! $socialProfile instanceof SocialProfile) {
                $socialProfile = new SocialProfile;
                $socialProfile->setAttribute(
                    'id',
                    DB::table('social_profiles')->where('id', $profile->id)->exists()
                        ? (string) Str::orderedUuid()
                        : (string) $profile->id,
                );
            }

            $socialProfile->forceFill([
                'socialable_type' => $person->getMorphClass(),
                'socialable_id' => $person->getKey(),
                'platform' => $platform,
                'purpose' => 'general',
                'label' => $platform !== Str::lower(trim((string) $profile->platform))
                    ? trim((string) $profile->platform)
                    : null,
                'handle' => $profile->username,
                'url' => $profile->url,
                'is_primary' => false,
                'is_public' => true,
                'is_verified' => false,
                'sort_order' => (int) ($profile->order_column ?? 0),
                'metadata' => array_merge(
                    is_array($socialProfile->metadata) ? $socialProfile->metadata : [],
                    ['production_speaker_source_id' => (string) $profile->id],
                ),
                'created_at' => $profile->created_at,
                'updated_at' => $profile->updated_at,
            ]);
            $socialProfile->save();

            if ($isNewProfile) {
                $this->importedSocialProfileCount++;
            }
        }
    }

    private function socialPlatform(string $sourcePlatform): ?string
    {
        $platform = match (Str::lower(trim($sourcePlatform))) {
            'twitter' => SocialPlatform::X->value,
            'wikipedia' => SocialPlatform::Website->value,
            default => Str::lower(trim($sourcePlatform)),
        };

        return SocialPlatform::tryFrom($platform)?->value;
    }

    private function importSearchTerms(object $source, object $sourceSpeaker, Person $person): void
    {
        $terms = $source->table('speaker_search_terms')
            ->where('speaker_id', $sourceSpeaker->id)
            ->pluck('term');

        foreach ($terms as $term) {
            $term = Str::limit(trim((string) $term), 120, '');

            if ($term === '') {
                continue;
            }

            $exists = DB::table('person_search_terms')
                ->where('person_id', $person->getKey())
                ->where('term', $term)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('person_search_terms')->insert([
                'id' => (string) Str::orderedUuid(),
                'person_id' => $person->getKey(),
                'term' => $term,
            ]);

            $this->importedSearchTermCount++;
        }
    }

    private function importSlugRedirects(object $source, object $sourceSpeaker, Person $person): void
    {
        $redirects = $source->table('slug_redirects')
            ->where('redirectable_type', 'speaker')
            ->where('redirectable_id', $sourceSpeaker->id)
            ->get();

        foreach ($redirects as $redirect) {
            $sourceSlug = (string) $redirect->source_slug;
            $destinationSlug = (string) $person->slug;
            $sourcePath = $this->personPath($sourceSlug);
            $destinationPath = $this->personPath($destinationSlug);

            if ($sourcePath === $destinationPath) {
                continue;
            }

            $existing = DB::table('slug_redirects')->where('source_path', $sourcePath)->first();

            if ($existing !== null
                && ($existing->redirectable_type !== $person->getMorphClass()
                    || (string) $existing->redirectable_id !== (string) $person->getKey())) {
                continue;
            }

            $redirectId = $existing === null ? $redirect->id : $existing->id;

            if ($existing === null && DB::table('slug_redirects')->where('id', $redirectId)->exists()) {
                $redirectId = (string) Str::orderedUuid();
            }

            DB::table('slug_redirects')->updateOrInsert(
                ['source_path' => $sourcePath],
                [
                    'id' => $redirectId,
                    'redirectable_type' => $person->getMorphClass(),
                    'redirectable_id' => $person->getKey(),
                    'source_slug' => $sourceSlug,
                    'source_path' => $sourcePath,
                    'destination_slug' => $destinationSlug,
                    'destination_path' => $destinationPath,
                    'first_visited_at' => $redirect->first_visited_at,
                    'last_redirected_at' => $redirect->last_redirected_at,
                    'redirect_count' => $redirect->redirect_count,
                    'created_at' => $redirect->created_at,
                    'updated_at' => $redirect->updated_at,
                ],
            );

            if ($existing === null) {
                $this->importedRedirectCount++;
            }
        }
    }

    private function personPath(string $slug): string
    {
        return (string) route('persons.show', ['person' => ltrim($slug, '/')], false);
    }

    private function importMemberLinks(object $source, object $sourceSpeaker, Person $person): void
    {
        $sourceMembers = $source->table('speaker_user')
            ->join('users', 'speaker_user.user_id', '=', 'users.id')
            ->where('speaker_user.speaker_id', $sourceSpeaker->id)
            ->get(['users.email', 'speaker_user.joined_at', 'speaker_user.created_at', 'speaker_user.updated_at']);

        foreach ($sourceMembers as $sourceMember) {
            if (! is_string($sourceMember->email) || trim($sourceMember->email) === '') {
                continue;
            }

            $user = User::query()
                ->whereRaw('lower(email) = ?', [mb_strtolower(trim($sourceMember->email))])
                ->first();

            if (! $user instanceof User || $user->hasGlobalAdminAccess()) {
                continue;
            }

            $this->importedMemberLinkCount += DB::table('person_members')->insertOrIgnore([
                'person_id' => $person->getKey(),
                'user_id' => $user->getKey(),
                'role' => 'viewer',
                'joined_at' => $sourceMember->joined_at,
                'created_at' => $sourceMember->created_at,
                'updated_at' => $sourceMember->updated_at,
            ]);
        }
    }

    private function importInstitutionLinks(object $source, object $sourceSpeaker, Person $person): void
    {
        $sourceLinks = $source->table('institution_speaker')
            ->join('institutions', 'institution_speaker.institution_id', '=', 'institutions.id')
            ->where('institution_speaker.speaker_id', $sourceSpeaker->id)
            ->get([
                'institutions.slug',
                'institution_speaker.position',
                'institution_speaker.is_primary',
                'institution_speaker.joined_at',
                'institution_speaker.created_at',
                'institution_speaker.updated_at',
            ]);

        foreach ($sourceLinks as $sourceLink) {
            $institution = DB::table('institutions')->where('slug', $sourceLink->slug)->first();

            if ($institution === null) {
                $this->skippedInstitutionLinkCount++;

                continue;
            }

            $exists = DB::table('affiliations')
                ->where('affiliatable_type', $person->getMorphClass())
                ->where('affiliatable_id', $person->getKey())
                ->where('institution_id', $institution->id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('affiliations')->insert([
                'id' => (string) Str::orderedUuid(),
                'affiliatable_type' => $person->getMorphClass(),
                'affiliatable_id' => $person->getKey(),
                'institution_id' => $institution->id,
                'affiliation_type' => 'member',
                'joined_at' => $sourceLink->joined_at,
                'is_primary' => (bool) $sourceLink->is_primary,
                'position' => $sourceLink->position,
                'created_at' => $sourceLink->created_at,
                'updated_at' => $sourceLink->updated_at,
            ]);
            $this->importedInstitutionLinkCount++;
        }
    }

    private function importEventLinks(object $source, object $sourceSpeaker, Person $person): void
    {
        $sourceEventLinks = $source->table('event_key_people')
            ->join('events', 'event_key_people.event_id', '=', 'events.id')
            ->where('event_key_people.speaker_id', $sourceSpeaker->id)
            ->get([
                'events.slug',
                'event_key_people.role',
                'event_key_people.name',
                'event_key_people.order_column',
                'event_key_people.is_public',
                'event_key_people.notes',
                'event_key_people.created_at',
                'event_key_people.updated_at',
            ]);

        foreach ($sourceEventLinks as $sourceEventLink) {
            $event = Event::query()->where('slug', $sourceEventLink->slug)->first();
            $role = EventKeyPersonRole::tryFrom((string) $sourceEventLink->role);

            if (! $event instanceof Event || $role === null) {
                $this->skippedEventLinkCount++;

                continue;
            }

            $existing = EventKeyPerson::query()
                ->where('event_id', $event->getKey())
                ->where('involveable_type', $person->getMorphClass())
                ->where('involveable_id', $person->getKey())
                ->where('role_code', $role->value)
                ->exists();

            if ($existing) {
                continue;
            }

            EventKeyPerson::query()->create([
                'event_id' => $event->getKey(),
                'involveable_type' => $person->getMorphClass(),
                'involveable_id' => $person->getKey(),
                'role_code' => $role->value,
                'status' => 'active',
                'visibility' => $sourceEventLink->is_public ? 'public' : 'private',
                'prominence' => '0',
                'is_featured' => false,
                'is_primary' => false,
                'sort_order' => (int) $sourceEventLink->order_column,
                'display_name' => $sourceEventLink->name,
                'notes' => $sourceEventLink->notes,
                'created_at' => $sourceEventLink->created_at,
                'updated_at' => $sourceEventLink->updated_at,
            ]);

            $this->importedEventLinkCount++;
        }
    }

    private function importMediaMetadata(object $source, object $sourceSpeaker, Person $person): void
    {
        $mediaRecords = $source->table('media')
            ->where('model_type', 'speaker')
            ->where('model_id', $sourceSpeaker->id)
            ->get();

        foreach ($mediaRecords as $mediaRecord) {
            $existing = DB::table('media')
                ->where('model_type', $person->getMorphClass())
                ->where('model_id', $person->getKey())
                ->whereRaw("custom_properties->>'production_speaker_source_media_id' = ?", [(string) $mediaRecord->id])
                ->exists();

            if ($existing) {
                continue;
            }

            $mediaData = (array) $mediaRecord;
            $customProperties = $this->jsonArray($mediaRecord->custom_properties) ?? [];
            $mediaData['model_type'] = $person->getMorphClass();
            $mediaData['model_id'] = $person->getKey();
            $mediaData['custom_properties'] = json_encode(
                array_merge($customProperties, ['production_speaker_source_media_id' => (string) $mediaRecord->id]),
                JSON_THROW_ON_ERROR,
            );
            unset($mediaData['id']);

            if (DB::table('media')->where('uuid', $mediaRecord->uuid)->exists()) {
                $mediaData['uuid'] = (string) Str::orderedUuid();
            }

            DB::table('media')->insert($mediaData);

            $this->importedMediaRecordCount++;
        }
    }
}
