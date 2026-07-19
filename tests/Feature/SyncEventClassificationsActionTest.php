<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventClassification;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Actions\Events\SyncEventClassificationsAction;
use App\Enums\EventTaxonomyCode;
use App\Models\Event;

it('writes package classifications from domain and discipline fields', function () {
    OwnerContext::withOwner(null, function (): void {
        $event = Event::factory()->create();

        $synced = app(SyncEventClassificationsAction::class)->handle($event, [
            'domain_tags' => ['Aqidah'],
            'discipline_tags' => ['Tafsir'],
            'source_tags' => [],
            'issue_tags' => [],
        ]);

        expect($synced)->toBe(2)
            ->and(EventTaxonomy::query()->where('code', EventTaxonomyCode::Domain->value)->exists())->toBeTrue()
            ->and(EventTerm::query()->where('code', 'aqidah')->exists())->toBeTrue()
            ->and(EventClassification::query()->where('event_id', $event->getKey())->count())->toBe(2);
    });
});
