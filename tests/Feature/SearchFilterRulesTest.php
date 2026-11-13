<?php

declare(strict_types=1);

use App\Shared\Discovery\SearchFilterKeys;
use App\Shared\Discovery\SearchFilterRules;
use App\Support\Mcp\McpEventSearchService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

it('keeps the MCP event search validation contract on the shared filter vocabulary', function (): void {
    $ruleKeys = array_keys(app(McpEventSearchService::class)->validationRules());

    expect(array_values(array_diff(SearchFilterKeys::all(), $ruleKeys)))->toBe([]);
});

it('accepts scalar and list prayer_time values in the MCP search contract', function (): void {
    $rules = app(McpEventSearchService::class)->validationRules();

    expect(Validator::make(['prayer_time' => 'maghrib'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['prayer_time' => ['maghrib', 'isha']], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['prayer_time' => [123]], $rules)->fails())->toBeTrue();
});

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
