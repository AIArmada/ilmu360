<?php

declare(strict_types=1);

use App\Features\Events\Queries\PublicReferenceConstraint;
use App\Features\References\Api\ReferenceQueries;
use App\Features\References\Models\Reference;
use Illuminate\Support\Facades\DB;

it('applies owner-defined public reference status and publication rules without an ORM API', function (): void {
    $statuses = app(ReferenceQueries::class)->publiclyVisibleStatuses();
    $constraints = app(PublicReferenceConstraint::class);

    expect($statuses)->toBe(Reference::PUBLIC_STATUSES);

    foreach ([Reference::query(), DB::table('references')] as $query) {
        $filtered = $constraints->apply($query);

        expect($filtered)->toBe($query)
            ->and($filtered->toSql())->toContain('"references"."published_at" is not null')
            ->and($filtered->toSql())->toContain('"references"."status" in (')
            ->and($filtered->getBindings())->toBe($statuses);
    }
});
