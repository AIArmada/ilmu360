<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\CommerceSupport\Support\UserDateTimeFormatter;
use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Events\Features\Taxonomy\Models\EventTaxonomy;
use AIArmada\Events\Features\Taxonomy\Models\EventTerm;
use App\Features\Contributions\Api\SubmissionEntityAccess;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Api\EventCategoryPolicyResolver;
use App\Features\Events\Api\EventSubmissionOptionsCache;
use App\Features\Events\Api\EventTaxonomyCode;
use App\Features\Events\Models\Event;
use App\Features\Events\Support\SubmissionTimingPolicy;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Institutions\Api\InstitutionSubmissionOptionsCache;
use App\Features\Persons\Api\PersonSubmissionOptionsCache;
use App\Platform\Language\MalaysiaLanguageCatalog;
use App\Platform\Language\Models\Language;
use App\Shared\Auth\AccountActor;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Stateless option queries for the public event submission form.
 *
 * Every method takes explicit actor/country arguments; guest-safe results
 * keep the shared 60-second cache keys while member scopes stay on the
 * per-request instance memo so authorizations never leak across users or
 * requests.
 */
final class SubmitEventOptionsProvider
{
    public function __construct(
        private readonly InstitutionSubmissionOptionsCache $institutionSubmissionOptionsCache,
        private readonly EventSubmissionOptionsCache $submissionOptionsCache,
        private readonly PersonSubmissionOptionsCache $personSubmissionOptionsCache,
    ) {}

    /** @var array<string, array<string, string>> Request-only option memo, never shared across requests. */
    private array $optionMemo = [];

    /**
     * @return array<string, string>
     */
    public function institutionOptions(?AccountActor $submitter, ?string $countryId, ?string $scopedInstitutionId): array
    {
        if ($scopedInstitutionId !== null) {
            $label = app(InstitutionLookup::class)->displayName($scopedInstitutionId);

            return $label !== null ? [$scopedInstitutionId => $label] : [];
        }

        $memoKey = 'institutions:'.($countryId ?? 'all').':'.($submitter?->actorId() ?? 'guest');

        if (array_key_exists($memoKey, $this->optionMemo)) {
            return $this->optionMemo[$memoKey];
        }

        $access = app(SubmissionEntityAccess::class);

        $load = fn (): array => $access->institutionOptions($submitter, $countryId);

        // Guest scope is identical for every guest, so it is safe to share
        // across requests; member scopes stay request-local to avoid any
        // cross-user membership leakage.
        return $this->optionMemo[$memoKey] = $submitter !== null
            ? $load()
            : $this->institutionSubmissionOptionsCache->rememberGuestOptions($countryId, $load);
    }

    /**
     * @return array<string, string>
     */
    public function personOptions(?AccountActor $submitter): array
    {
        $memoKey = 'persons:'.($submitter?->actorId() ?? 'guest');

        if (array_key_exists($memoKey, $this->optionMemo)) {
            return $this->optionMemo[$memoKey];
        }

        $access = app(SubmissionEntityAccess::class);

        $load = fn (): array => $access->personOptions($submitter);

        return $this->optionMemo[$memoKey] = $submitter !== null
            ? $load()
            : $this->personSubmissionOptionsCache->rememberGuestPersonOptions($load);
    }

    /**
     * @return array<string, string>
     */
    public function venueOptions(?string $countryId = null): array
    {
        return $this->submissionOptionsCache->rememberVenueOptions(
            $countryId,
            fn (): array => app(SubmissionEntityAccess::class)->venueOptions($countryId),
        );
    }

    /**
     * @return array<int|string, string>
     */
    public function languageOptions(): array
    {
        if (array_key_exists('languages', $this->optionMemo)) {
            return $this->optionMemo['languages'];
        }

        $idsByCode = Language::query()
            ->whereIn('code', MalaysiaLanguageCatalog::codes())
            ->pluck('id', 'code');
        $options = [];

        foreach (MalaysiaLanguageCatalog::labels() as $code => $label) {
            $id = $idsByCode->get($code);

            if (is_string($id) && $id !== '') {
                $options[$id] = $label;
            }
        }

        return $this->optionMemo['languages'] = $options;
    }

    /**
     * @return array<string, string>
     */
    public function tagOptions(EventTaxonomyCode $type, ?string $cachePrefix = null): array
    {
        $load = static function () use ($type): array {
            $taxonomyId = EventTaxonomy::query()->where('code', $type->value)->where('is_active', true)->value('id');

            if ($taxonomyId === null) {
                return [];
            }

            return EventTerm::query()
                ->where('event_taxonomy_id', $taxonomyId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name', 'id')
                ->mapWithKeys(fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])
                ->all();
        };

        return $cachePrefix === null
            ? $load()
            : $this->submissionOptionsCache->rememberTaxonomyTagOptions($cachePrefix, app()->getLocale(), $load);
    }

    /**
     * @param  list<string>  $domainIds
     * @return array<string, string>
     */
    public function taxonomyTermOptionsForDomains(EventTaxonomyCode $type, array $domainIds, ?string $search = null): array
    {
        $taxonomyId = EventTaxonomy::query()->where('code', $type->value)->value('id');

        if ($taxonomyId === null || ($domainIds === [] && $search === null)) {
            return [];
        }

        return EventTerm::query()
            ->where('event_taxonomy_id', $taxonomyId)
            ->where('is_active', true)
            ->when($domainIds !== [], static fn (Builder $query): Builder => $query->where(static function (Builder $query) use ($domainIds): void {
                $query->whereNull('metadata->domain_ids');

                foreach ($domainIds as $domainId) {
                    $query->orWhereJsonContains('metadata->domain_ids', $domainId);
                }
            }))
            ->when($search !== null, static fn (Builder $query): Builder => $query->whereLike('name', "%{$search}%")->limit(20))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function occurrenceOptions(?Event $container): array
    {
        if (! $container instanceof Event) {
            return [];
        }

        // The container is already authorized via the update policy and scoped
        // to one event, so every eligible occurrence stays selectable — no cap
        // that would silently strand later dates. Human labels use the viewer
        // timezone formatter; clock inputs elsewhere stay on the selected
        // submission-country business timezone by design.
        $memoKey = 'occurrences:'.$container->getKey();

        if (array_key_exists($memoKey, $this->optionMemo)) {
            return $this->optionMemo[$memoKey];
        }

        return $this->optionMemo[$memoKey] = EventOccurrence::query()
            ->where('event_id', $container->getKey())
            ->whereNotIn('status', [EventOccurrence::CANCELLED, EventOccurrence::COMPLETED, EventOccurrence::ARCHIVED])
            ->orderBy('starts_at')
            ->get(['id', 'title', 'starts_at', 'timezone'])
            ->mapWithKeys(function (EventOccurrence $occurrence): array {
                $startsAt = $occurrence->starts_at;

                $label = $startsAt instanceof CarbonInterface
                    ? UserDateTimeFormatter::translatedFormat($startsAt, 'j M Y, h:i A')
                    : (string) $occurrence->title;

                if ($label === '') {
                    $label = (string) $occurrence->title;
                }

                if (is_string($occurrence->title) && $occurrence->title !== '' && $label !== $occurrence->title) {
                    $label .= ' — '.$occurrence->title;
                }

                return [(string) $occurrence->getKey() => $label];
            })
            ->all();
    }

    public function defaultOccurrenceId(?Event $container = null): ?string
    {
        if (! $container instanceof Event) {
            return null;
        }

        $options = $this->occurrenceOptions($container);

        if (count($options) !== 1) {
            return null;
        }

        return array_key_first($options);
    }

    /**
     * @return list<string>
     */
    public function speakerRequiredCategoryIds(): array
    {
        $configuration = $this->submissionOptionsCache->rememberClientProgressConfiguration(function (): array {
            $categoryCatalog = app(EventCategoryCatalog::class);
            $speakerRequiredCategoryIds = collect($categoryCatalog->options())
                ->keys()
                ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
                ->map(strval(...))
                ->filter(fn (string $id): bool => app(EventCategoryPolicyResolver::class)->requiresSpeaker(
                    $categoryCatalog->validateTermIds([$id]),
                ))
                ->values()
                ->all();

            return [
                'speaker_required_category_ids' => $speakerRequiredCategoryIds,
            ];
        });

        return $configuration['speaker_required_category_ids'];
    }

    public function defaultSubmissionCountryId(): ?string
    {
        return app(AddressCountryResolver::class)->resolveId('MY');
    }

    /**
     * Countries whose effective timezones require an explicit submission choice.
     *
     * @return list<string>
     */
    public function multiTimezoneCountryIds(): array
    {
        return $this->submissionOptionsCache->rememberMultiTimezoneCountryIds(function (): array {
            $catalog = app(SubmissionTimingPolicy::class)->countryTimezoneCatalog();
            $multi = [];

            foreach ($catalog as $countryId => $timezones) {
                if (count($timezones) > 1) {
                    $multi[] = $countryId;
                }
            }

            return $multi;
        });
    }

    public function defaultEventTermId(string $taxonomyCode, string $termCode): ?string
    {
        $taxonomyId = EventTaxonomy::query()->where('code', $taxonomyCode)->value('id');

        if (! is_string($taxonomyId)) {
            return null;
        }

        $termId = EventTerm::query()
            ->where('event_taxonomy_id', $taxonomyId)
            ->where('code', $termCode)
            ->where('is_active', true)
            ->value('id');

        return is_string($termId) ? $termId : null;
    }
}
