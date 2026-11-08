<?php

declare(strict_types=1);

use App\Shared\Discovery\SearchFilterRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

it('validates category ids from the supplied primitive filters', function (): void {
    $validCategoryId = eventCategoryId('kuliah_ceramah');
    $rules = app(SearchFilterRules::class)->rules(['event_category_ids' => [$validCategoryId]]);

    $valid = Validator::make(
        ['filters' => ['event_category_ids' => [$validCategoryId]]],
        $rules,
    );

    $invalid = Validator::make(
        ['filters' => ['event_category_ids' => [(string) Str::uuid()]]],
        $rules,
    );

    expect($valid->passes())->toBeTrue()
        ->and($invalid->errors()->keys())->toContain('filters.event_category_ids.0');
});
