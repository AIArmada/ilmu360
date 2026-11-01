@props([
    'scheduleLeaf',
    'savedEventIds' => [],
    'signalComponent' => 'event_card',
    'testidPrefix' => null,
    'wireKey' => null,
    'roleLabel' => null,
    'hideLocation' => false,
    'hideReference' => false,
    'sessions' => null,
])

@php
    $event = $scheduleLeaf->event;
    $location = $scheduleLeaf->occurrence->locations->first()
        ?? $scheduleLeaf->sessions->first()?->locations->first();
    $sessions = $sessions ?? $scheduleLeaf->sessions;
    $locationPrimary = $location?->venue?->name ?? $location?->label ?? $event->resolvedLocationName();
    $address = $location?->venue instanceof \App\Models\Venue
        ? $location->venue->primaryAddress()
        : null;
    $address ??= $event->resolvedLocationAddress();
    $locationSecondary = \App\Support\Location\AddressHierarchyFormatter::formatShort($address) ?: null;
    $statusLabel = $event->public_change_badge_label;
    $statusClass = match (true) {
        (string) $event->status === 'pending' => 'border-amber-100 bg-amber-50 text-amber-700',
        (string) $event->status === 'cancelled' => 'border-rose-100 bg-rose-50 text-rose-700',
        filled($statusLabel) => 'border-sky-100 bg-sky-50 text-sky-700',
        default => 'border-emerald-100 bg-emerald-50 text-emerald-700',
    };
    $resolvedWireKey = $wireKey ?? 'schedule-'.$scheduleLeaf->entityType().'-'.$scheduleLeaf->id();
@endphp

<x-events.card
    :event="$event"
    :url="$scheduleLeaf->url()"
    :title="$scheduleLeaf->title()"
    :starts-at="$scheduleLeaf->startsAt()"
    :timing-text="$scheduleLeaf->timingText()"
    :is-saved="in_array((string) $event->getKey(), (array) $savedEventIds, true)"
    :status-label="$statusLabel"
    :status-class="$statusClass"
    :location-primary="is_string($locationPrimary) && trim($locationPrimary) !== '' ? $locationPrimary : null"
    :location-secondary="$locationSecondary"
    :hide-location="$hideLocation"
    :hide-reference="$hideReference"
    :occurrence="$scheduleLeaf->occurrence"
    :sessions="$sessions"
    :role-label="$roleLabel"
    :signal-component="$signalComponent"
    :signal-entity-type="$scheduleLeaf->entityType()"
    :signal-entity-id="$scheduleLeaf->id()"
    :testid-prefix="$testidPrefix"
    :wire-key="$resolvedWireKey"
/>
