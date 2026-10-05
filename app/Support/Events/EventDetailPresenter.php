<?php

declare(strict_types=1);

namespace App\Support\Events;

use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Models\EventAccessPolicy;
use AIArmada\Events\Models\EventLink;
use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventMaterial;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventReference;
use AIArmada\Events\Models\EventSession;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\Seating\Models\SeatMap;
use AIArmada\Ticketing\Models\TicketType;
use App\Models\Event;
use App\Models\Reference as CatalogReference;
use App\Support\Spaces\SpaceLocationPresenter;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Resolves the event graph into presentation-friendly scopes.
 *
 * The events package deliberately keeps admission, seating, locations, links,
 * and materials attachable at event, occurrence, and session level. Keeping
 * that traversal here prevents the public view from accidentally showing only
 * the event container's data.
 *
 * @phpstan-type EventScope Event|EventOccurrence|EventSession
 */
final class EventDetailPresenter
{
    public function __construct(
        private readonly Event $event,
        private readonly ?EventOccurrence $selectedOccurrence = null,
        private readonly ?EventSession $selectedSession = null,
    ) {}

    /**
     * @return Collection<int, EventOccurrence>
     */
    public function occurrences(): Collection
    {
        if ($this->selectedOccurrence instanceof EventOccurrence) {
            return collect([$this->selectedOccurrence]);
        }

        return $this->event->relationLoaded('occurrences')
            ? $this->event->occurrences
            : collect();
    }

    /**
     * Event-level sessions are unusual, but supported by the package. The
     * event relation can also contain occurrence sessions, so filter those out
     * explicitly before presenting them as standalone programme segments.
     *
     * @return Collection<int, EventSession>
     */
    public function directSessions(): Collection
    {
        if ($this->selectedOccurrence instanceof EventOccurrence) {
            return collect();
        }

        if (! $this->event->relationLoaded('sessions')) {
            return collect();
        }

        return $this->event->sessions
            ->filter(fn (EventSession $session): bool => $session->event_occurrence_id === null)
            ->values();
    }

    /**
     * @return Collection<int, EventSession>
     */
    public function sessionsFor(EventOccurrence $occurrence): Collection
    {
        if ($this->selectedSession instanceof EventSession) {
            return $this->selectedSession->event_occurrence_id === $occurrence->id
                ? collect([$this->selectedSession])
                : collect();
        }

        return $occurrence->relationLoaded('sessions')
            ? $occurrence->sessions
            : collect();
    }

    public function singleOccurrence(): ?EventOccurrence
    {
        return $this->occurrences()->count() === 1
            ? $this->occurrences()->first()
            : null;
    }

    public function scheduleMode(): string
    {
        if ($this->occurrences()->count() > 1) {
            return 'multiple_occurrences';
        }

        if ($this->singleOccurrence() instanceof EventOccurrence) {
            $sessionCount = $this->sessionsFor($this->singleOccurrence())->count();

            return match (true) {
                $sessionCount > 1 => 'one_occurrence_many_sessions',
                $sessionCount === 1 => 'one_occurrence_one_session',
                $this->singleOccurrenceNeedsDetailSection() => 'one_occurrence_details',
                default => 'merged',
            };
        }

        return $this->directSessions()->isNotEmpty() ? 'direct_sessions' : 'none';
    }

    public function shouldRenderSchedule(): bool
    {
        return $this->scheduleMode() !== 'merged' && $this->scheduleMode() !== 'none';
    }

    public function singleOccurrenceNeedsDetailSection(): bool
    {
        $occurrence = $this->singleOccurrence();

        if (! $occurrence instanceof EventOccurrence) {
            return false;
        }

        return ($occurrence->title !== null && trim((string) $occurrence->title) !== trim((string) $this->event->title))
            || $this->primaryLocationFor($occurrence) !== null && $this->primaryLocationFor($this->event) === null
            || $this->timeExpressionsFor($occurrence)->isNotEmpty()
            || $this->linksFor($occurrence)->isNotEmpty()
            || $this->materialsFor($occurrence)->isNotEmpty();
    }

    /**
     * @return Collection<int, array{scope: Event|EventOccurrence|EventSession, scope_type: string, scope_label: string}>
     */
    public function scopes(): Collection
    {
        $scopes = collect([
            $this->scopeEntry($this->event, 'event', __('Keseluruhan majlis')),
        ]);

        foreach ($this->occurrences() as $occurrence) {
            $scopes->push($this->scopeEntry($occurrence, 'occurrence', $this->scopeLabel($occurrence, 'occurrence')));

            foreach ($this->sessionsFor($occurrence) as $session) {
                $scopes->push($this->scopeEntry($session, 'session', $this->scopeLabel($session, 'session')));
            }
        }

        foreach ($this->directSessions() as $session) {
            $scopes->push($this->scopeEntry($session, 'session', $this->scopeLabel($session, 'session')));
        }

        return $scopes;
    }

    /**
     * Public reference rows for a schedule scope, in display order.
     *
     * Occurrence/session rows are EventReference pivots: bound rows point at a
     * catalog reference, unbound rows carry their own title/url/citation. Bound
     * rows whose linked record is unavailable (private/unpublished) are
     * dropped — the pivot never overrides the linked record's visibility.
     * Event-level catalog references render from $event->references directly,
     * so the event scope resolves to an empty collection here.
     *
     * @return Collection<int, EventReference>
     */
    public function referencesFor(Event|EventOccurrence|EventSession $scope): Collection
    {
        if ($scope instanceof Event || ! $scope->relationLoaded('references')) {
            return collect();
        }

        return $scope->references
            ->filter(fn (EventReference $row): bool => (string) $row->visibility === 'public'
                && $this->isRenderableReferenceRow($row))
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * An unbound row never pointed at a catalog record, so its own stored
     * title/url/citation is the public content. A bound row with an
     * unavailable target is a privacy hold, never public content.
     */
    public function isUnboundReference(EventReference $row): bool
    {
        return $row->referenceable_type === null || $row->referenceable_id === null;
    }

    private function isRenderableReferenceRow(EventReference $row): bool
    {
        if ($this->isUnboundReference($row)) {
            return filled($row->title) || filled($row->url) || filled($row->citation);
        }

        return $row->relationLoaded('referenceable') && $row->referenceable instanceof CatalogReference;
    }

    /**
     * @return Collection<int, array{ticket: TicketType, scope: Event|EventOccurrence|EventSession, scope_type: string, scope_label: string, inventory_configured: bool, inventory_available: ?int}>
     */
    public function ticketEntries(): Collection
    {
        $entries = $this->scopes()->flatMap(function (array $scope): Collection {
            $ticketable = $scope['scope'];

            if (! $ticketable->relationLoaded('ticketTypes')) {
                return collect();
            }

            return $ticketable->ticketTypes->map(function (TicketType $ticket) use ($scope): array {
                $inventoryConfigured = $ticket->relationLoaded('inventoryLevels') && $ticket->inventoryLevels->isNotEmpty();

                return [
                    'ticket' => $ticket,
                    'scope' => $scope['scope'],
                    'scope_type' => $scope['scope_type'],
                    'scope_label' => $scope['scope_label'],
                    'inventory_configured' => $inventoryConfigured,
                    'inventory_available' => $inventoryConfigured
                        ? (int) $ticket->inventoryLevels->sum(static fn (Model $level): int => (int) $level->getAttribute('available'))
                        : null,
                ];
            });
        });

        return $entries
            ->unique(fn (array $entry): string => (string) $entry['ticket']->getKey())
            ->values();
    }

    /**
     * Ticket entries whose ticketable scope is the given scope.
     *
     * @return Collection<int, array{ticket: TicketType, scope: Event|EventOccurrence|EventSession, scope_type: string, scope_label: string, inventory_configured: bool, inventory_available: ?int}>
     */
    public function ticketEntriesForScope(Event|EventOccurrence|EventSession $scope): Collection
    {
        return $this->ticketEntries()
            ->filter(fn (array $entry): bool => $entry['scope']->getMorphClass() === $scope->getMorphClass()
                && (string) $entry['scope']->getKey() === (string) $scope->getKey())
            ->values();
    }

    /**
     * Whether the scope offers online registration at all: a required
     * registration policy, an explicit registration mode, or any ticket.
     */
    public function scopeHasRegistrationOffering(Event|EventOccurrence|EventSession $scope): bool
    {
        if ($this->policiesFor($scope)->contains(fn (EventAccessPolicy $policy): bool => $policy->registration_required)
            || $this->scopeHasRegistrationMode($scope)) {
            return true;
        }

        return $this->ticketEntriesForScope($scope)->isNotEmpty();
    }

    /**
     * Whether online registration is currently open for the scope, derived
     * from lifecycle status plus the scope's own policy/ticket windows. A
     * scope with no registration offering is never "open".
     */
    public function scopeRegistrationsOpen(Event|EventOccurrence|EventSession $scope): bool
    {
        if (! $this->scopeHasRegistrationOffering($scope) || $this->scopeActionsDisabled($scope)) {
            return false;
        }

        $now = now();

        $policyOpen = $this->policiesFor($scope)->contains(fn (EventAccessPolicy $policy): bool => $policy->registration_required
            && ($policy->opens_at === null || $policy->opens_at->lessThanOrEqualTo($now))
            && ($policy->closes_at === null || $policy->closes_at->greaterThan($now)));

        if ($policyOpen) {
            return true;
        }

        return $this->ticketEntriesForScope($scope)
            ->contains(fn (array $entry): bool => $this->ticketSalesOpen($entry['ticket']));
    }

    public function ticketSalesOpen(TicketType $ticket): bool
    {
        if ((string) $ticket->status !== 'active') {
            return false;
        }

        $now = now();

        return ($ticket->sales_starts_at === null || $ticket->sales_starts_at->lessThanOrEqualTo($now))
            && ($ticket->sales_ends_at === null || $ticket->sales_ends_at->greaterThan($now));
    }

    /**
     * Own-scope remaining seats, or null when the scope declares no capacity.
     * Uses the loaded participant sums; never queries.
     */
    public function scopeCapacityRemaining(Event|EventOccurrence|EventSession $scope): ?int
    {
        $capacity = $this->capacityFor($scope);

        return $capacity === null ? null : $capacity - $this->participantCount($scope);
    }

    /**
     * Minimum remaining seats across the scope and its schedule parents, or
     * null when nothing on the chain declares a capacity. A session with room
     * still reads full when its parent date is exhausted.
     */
    public function effectiveCapacityRemaining(Event|EventOccurrence|EventSession $scope): ?int
    {
        $candidates = [$this->scopeCapacityRemaining($scope)];

        if ($scope instanceof EventSession) {
            $occurrence = $this->parentOccurrenceFor($scope);

            if ($occurrence instanceof EventOccurrence) {
                $candidates[] = $this->scopeCapacityRemaining($occurrence);
            }
        }

        $candidates = array_values(array_filter($candidates, static fn (?int $value): bool => $value !== null));

        return $candidates === [] ? null : min($candidates);
    }

    private function parentOccurrenceFor(EventSession $session): ?EventOccurrence
    {
        $selected = $this->selectedOccurrence;

        if ($selected instanceof EventOccurrence && (string) $selected->getKey() === (string) $session->event_occurrence_id) {
            return $selected;
        }

        return $this->occurrences()->firstWhere('id', $session->event_occurrence_id);
    }

    /**
     * @return Collection<int, array{policy: EventAccessPolicy, scope: Event|EventOccurrence|EventSession, scope_type: string, scope_label: string}>
     */
    public function policyEntries(): Collection
    {
        return $this->scopes()->flatMap(function (array $scope): Collection {
            return $this->policiesFor($scope['scope'])->map(fn (EventAccessPolicy $policy): array => [
                'policy' => $policy,
                'scope' => $scope['scope'],
                'scope_type' => $scope['scope_type'],
                'scope_label' => $scope['scope_label'],
            ]);
        })->values();
    }

    public function admissionKind(TicketType $ticket): string
    {
        return ($ticket->metadata['admission_kind'] ?? null) === 'registration' ? 'registration' : 'ticket';
    }

    public function scopeActionsDisabled(Event|EventOccurrence|EventSession $scope): bool
    {
        if (in_array((string) $this->event->status, ['cancelled', 'archived'], true)
            || (! $scope instanceof Event && in_array((string) $scope->status, ['cancelled', 'postponed', 'rescheduled', 'completed', 'archived'], true))) {
            return true;
        }

        $occurrence = $scope instanceof EventSession
            ? $this->parentOccurrenceFor($scope)
            : null;

        return $occurrence instanceof EventOccurrence
            && $this->scopeActionsDisabled($occurrence);
    }

    public function registrationModeFor(Event|EventOccurrence|EventSession $scope): ?RegistrationMode
    {
        return $this->rawRegistrationMode($scope);
    }

    /**
     * Resolves a seat section name from the loaded seat maps without
     * querying, for pages that load seating options without their section.
     */
    public function sectionNameFor(?string $sectionId): ?string
    {
        if ($sectionId === null || $sectionId === '') {
            return null;
        }

        foreach ($this->seatMapEntries() as $entry) {
            $name = $entry['seat_map']->relationLoaded('sections')
                ? $entry['seat_map']->sections->firstWhere('id', $sectionId)?->name
                : null;

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, array{seat_map: SeatMap, scope: Event|EventOccurrence|EventSession, scope_type: string, scope_label: string, section_count: int, section_capacity: int}>
     */
    public function seatMapEntries(): Collection
    {
        $entries = $this->scopes()->flatMap(function (array $scope): Collection {
            $seatable = $scope['scope'];

            if (! $seatable->relationLoaded('seatMaps')) {
                return collect();
            }

            return $seatable->seatMaps->map(function (SeatMap $seatMap) use ($scope): array {
                $sections = $seatMap->relationLoaded('sections') ? $seatMap->sections : collect();

                return [
                    'seat_map' => $seatMap,
                    'scope' => $scope['scope'],
                    'scope_type' => $scope['scope_type'],
                    'scope_label' => $scope['scope_label'],
                    'section_count' => $sections->count(),
                    'section_capacity' => (int) $sections->sum(static fn (Model $section): int => (int) $section->getAttribute('capacity')),
                ];
            });
        });

        return $entries
            ->unique(fn (array $entry): string => (string) $entry['seat_map']->getKey())
            ->values();
    }

    /**
     * @return Collection<int, array{link: EventLink, scope: Event|EventOccurrence|EventSession, scope_type: string, scope_label: string}>
     */
    public function linkEntries(): Collection
    {
        $entries = $this->scopes()->flatMap(function (array $scope): Collection {
            return $this->linksFor($scope['scope'])->map(fn (EventLink $link): array => [
                'link' => $link,
                'scope' => $scope['scope'],
                'scope_type' => $scope['scope_type'],
                'scope_label' => $scope['scope_label'],
            ]);
        });

        return $entries
            ->unique(fn (array $entry): string => (string) $entry['link']->getKey())
            ->values();
    }

    /**
     * @return Collection<int, EventMaterial>
     */
    public function materialEntries(): Collection
    {
        return $this->scopes()->flatMap(function (array $scope): Collection {
            return $this->materialsFor($scope['scope']);
        })->unique(fn (EventMaterial $material): string => (string) $material->getKey())->values();
    }

    /**
     * @return array{policy: EventAccessPolicy, scope: Event|EventOccurrence|EventSession, scope_type: string, scope_label: string}|null
     */
    public function primaryRegistrationEntry(): ?array
    {
        return $this->policyEntries()
            ->first(fn (array $entry): bool => (bool) $entry['policy']->registration_required)
            ?? $this->policyEntries()->first(fn (array $entry): bool => $this->scopeHasRegistrationMode($entry['scope']));
    }

    public function hasRegistration(): bool
    {
        if ($this->policyEntries()->contains(fn (array $entry): bool => (bool) $entry['policy']->registration_required)) {
            return true;
        }

        return $this->scopes()->contains(fn (array $scope): bool => $this->scopeHasRegistrationMode($scope['scope']));
    }

    public function hasAdmissionDetails(): bool
    {
        if ($this->ticketEntries()->isNotEmpty() || $this->capacityEntries()->isNotEmpty()) {
            return true;
        }

        if ($this->policyEntries()->contains(fn (array $entry): bool => $this->policyHasDisplayData($entry['policy']))) {
            return true;
        }

        return $this->scopes()->contains(fn (array $scope): bool => $this->hasExplicitRegistrationMode($scope['scope']));
    }

    public function hasPaidTickets(): bool
    {
        return $this->ticketEntries()->contains(fn (array $entry): bool => (int) ($entry['ticket']->price ?? 0) > 0);
    }

    public function requiresSeating(): bool
    {
        if ($this->seatMapEntries()->isNotEmpty()) {
            return true;
        }

        if ($this->policyEntries()->contains(fn (array $entry): bool => (bool) $entry['policy']->seating_required)) {
            return true;
        }

        return $this->ticketEntries()->contains(fn (array $entry): bool => $entry['ticket']->seating_mode?->requiresAllocation() ?? false);
    }

    public function allowsWalkIn(): bool
    {
        $policies = $this->policyEntries();

        if ($policies->isNotEmpty()) {
            return $policies->contains(fn (array $entry): bool => (bool) $entry['policy']->walk_in_allowed);
        }

        return $this->scopes()->contains(fn (array $scope): bool => $this->hasExplicitRegistrationMode($scope['scope'])
            && $this->rawRegistrationMode($scope['scope']) === RegistrationMode::None);
    }

    /**
     * @return Collection<int, array{scope: Event|EventOccurrence|EventSession, scope_type: string, scope_label: string, capacity: int, reserved: int}>
     */
    public function capacityEntries(): Collection
    {
        return $this->scopes()->map(function (array $scope): ?array {
            $capacity = $this->capacityFor($scope['scope']);

            if ($capacity === null) {
                return null;
            }

            return [
                'scope' => $scope['scope'],
                'scope_type' => $scope['scope_type'],
                'scope_label' => $scope['scope_label'],
                'capacity' => $capacity,
                'reserved' => $this->participantCount($scope['scope']),
            ];
        })->filter()->values();
    }

    public function capacityFor(Event|EventOccurrence|EventSession $scope): ?int
    {
        $policy = $this->policiesFor($scope)->first();

        if ($policy?->capacity !== null) {
            return (int) $policy->capacity;
        }

        if ($scope instanceof Event) {
            return null;
        }

        $capacity = $scope->getAttribute('capacity');

        return is_numeric($capacity) ? (int) $capacity : null;
    }

    public function participantCount(Event|EventOccurrence|EventSession $scope): int
    {
        $attributes = $scope->getAttributes();
        $sum = $attributes['registrations_sum_total_participants'] ?? null;

        if (is_numeric($sum)) {
            return (int) $sum;
        }

        $count = $attributes['registrations_count'] ?? null;

        return is_numeric($count) ? (int) $count : 0;
    }

    public function primaryLocationFor(Event|EventOccurrence|EventSession $scope): ?EventLocation
    {
        if ($scope instanceof Event) {
            // A loaded primaryLocation (including a loaded null) is already
            // the canonical selection; never replace its absence with a
            // different row from the unsorted locations collection.
            if ($scope->relationLoaded('primaryLocation')) {
                $location = $scope->primaryLocation;

                return $location instanceof EventLocation ? $location : null;
            }

            if (! $scope->relationLoaded('locations')) {
                return null;
            }

            // Mirror the canonical event-level scope and ordering
            // (sort_order, created_at, id); occurrence/session rows never
            // stand in for the event-level primary.
            return $scope->locations
                ->filter(static fn (EventLocation $location): bool => $location->location_role === 'primary'
                    && $location->event_occurrence_id === null
                    && $location->event_session_id === null)
                ->sort(static fn (EventLocation $a, EventLocation $b): int => [
                    $a->sort_order,
                    $a->created_at?->toDateTimeString() ?? '',
                    (string) $a->getKey(),
                ] <=> [
                    $b->sort_order,
                    $b->created_at?->toDateTimeString() ?? '',
                    (string) $b->getKey(),
                ])
                ->first();
        }

        if (! $scope->relationLoaded('locations')) {
            return null;
        }

        return $scope->locations->firstWhere('location_role', 'primary')
            ?? $scope->locations->first();
    }

    public function locationLabel(?EventLocation $location): ?string
    {
        if (! $location instanceof EventLocation) {
            return null;
        }

        $label = trim((string) ($location->label ?? ''));

        if ($label !== '') {
            return $label;
        }

        return SpaceLocationPresenter::name($location)
            ?? ($location->relationLoaded('venue') ? $location->venue?->name : null);
    }

    /**
     * @return Collection<int, EventLink>
     */
    public function linksFor(Event|EventOccurrence|EventSession $scope): Collection
    {
        if (! $scope->relationLoaded('links')) {
            return collect();
        }

        return $scope->links
            ->filter(function (EventLink $link): bool {
                if ((string) $link->visibility !== 'public') {
                    return false;
                }

                return ($link->opens_at === null || ! $link->opens_at->isFuture())
                    && ($link->expires_at === null || ! $link->expires_at->isPast());
            })
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * @return Collection<int, EventMaterial>
     */
    public function materialsFor(Event|EventOccurrence|EventSession $scope): Collection
    {
        if (! $scope->relationLoaded('materials')) {
            return collect();
        }

        return $scope->materials
            ->filter(fn (EventMaterial $material): bool => (string) $material->getAttribute('visibility') === 'public' && filled($material->getAttribute('url')))
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * @return Collection<int, EventTimeExpression>
     */
    public function timeExpressionsFor(Event|EventOccurrence|EventSession $scope): Collection
    {
        if (! $scope->relationLoaded('timeExpressions')) {
            return collect();
        }

        return $scope->timeExpressions;
    }

    public function linkTypeLabel(EventLink $link): string
    {
        if (filled($link->label)) {
            return (string) $link->label;
        }

        return match ((string) $link->link_type) {
            'streaming' => __('Siaran langsung'),
            'recording' => __('Rakaman'),
            'external' => __('Laman rasmi'),
            default => __('Pautan majlis'),
        };
    }

    private function scopeLabel(Event|EventOccurrence|EventSession $scope, string $scopeType): string
    {
        if ($scopeType === 'event') {
            return __('Keseluruhan majlis');
        }

        if ($scopeType === 'session') {
            return trim((string) ($scope->getAttribute('title') ?: __('Segmen program')));
        }

        $title = trim((string) ($scope->getAttribute('title') ?? ''));

        if ($title !== '' && $title !== trim((string) $this->event->title)) {
            return $title;
        }

        $startsAt = $scope->getAttribute('starts_at');

        return $startsAt instanceof CarbonInterface
            ? UserDateTimeFormatter::translatedFormat($startsAt, 'j M Y')
            : __('Tarikh program');
    }

    /**
     * @return Collection<int, EventAccessPolicy>
     */
    public function policiesForScope(Event|EventOccurrence|EventSession $scope): Collection
    {
        return $this->policiesFor($scope);
    }

    /**
     * @return Collection<int, EventAccessPolicy>
     */
    private function policiesFor(Event|EventOccurrence|EventSession $scope): Collection
    {
        if ($scope instanceof Event) {
            return $scope->relationLoaded('accessPolicy') && $scope->accessPolicy instanceof EventAccessPolicy
                ? collect([$scope->accessPolicy])
                : collect();
        }

        return $scope->relationLoaded('accessPolicies')
            ? $scope->accessPolicies
            : collect();
    }

    private function scopeHasRegistrationMode(Event|EventOccurrence|EventSession $scope): bool
    {
        $mode = $this->rawRegistrationMode($scope);

        return $mode instanceof RegistrationMode && $mode !== RegistrationMode::None;
    }

    private function rawRegistrationMode(Event|EventOccurrence|EventSession $scope): ?RegistrationMode
    {
        $rawMode = $scope->getRawOriginal('registration_mode');

        if (is_string($rawMode) && $rawMode !== '') {
            return RegistrationMode::tryFrom($rawMode);
        }

        if ($scope instanceof Event) {
            return $scope->resolvedRegistrationMode();
        }

        $policy = $this->policiesFor($scope)->first();

        if ($policy instanceof EventAccessPolicy && $policy->registration_required) {
            return RegistrationMode::Required;
        }

        $eventMode = $this->event->resolvedRegistrationMode();

        return $eventMode === RegistrationMode::None ? null : $eventMode;
    }

    private function hasExplicitRegistrationMode(Event|EventOccurrence|EventSession $scope): bool
    {
        $rawMode = $scope->getRawOriginal('registration_mode');

        return is_string($rawMode) && $rawMode !== '' && RegistrationMode::tryFrom($rawMode) instanceof RegistrationMode;
    }

    /**
     * @return array{scope: Event|EventOccurrence|EventSession, scope_type: string, scope_label: string}
     */
    private function scopeEntry(Event|EventOccurrence|EventSession $scope, string $scopeType, string $scopeLabel): array
    {
        return [
            'scope' => $scope,
            'scope_type' => $scopeType,
            'scope_label' => $scopeLabel,
        ];
    }

    private function policyHasDisplayData(EventAccessPolicy $policy): bool
    {
        return $policy->registration_required
            || $policy->approval_required
            || $policy->payment_required
            || $policy->ticket_required
            || $policy->seating_required
            || $policy->walk_in_allowed
            || $policy->waitlist_enabled
            || $policy->capacity !== null
            || filled($policy->opens_at)
            || filled($policy->closes_at)
            || filled($policy->notes);
    }
}
