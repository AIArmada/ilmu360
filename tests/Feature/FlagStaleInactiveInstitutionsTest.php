<?php

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('dry-runs by default without flagging anything', function () {
    $old = staleInactiveInstitution(now()->subDays(200));

    $this->artisan('institutions:flag-stale-inactive')
        ->expectsOutputToContain('Dry run: 1 inactive institution(s) would be flagged')
        ->assertSuccessful();

    expect($old->refresh()->getAttribute('stale_inactive_flagged_at'))->toBeNull();
});

it('flags only still-inactive rows at or past the threshold on apply', function () {
    $old = staleInactiveInstitution(now()->subDays(200));
    $boundary = staleInactiveInstitution(now()->subDays(180));
    $recent = staleInactiveInstitution(now()->subDays(10));
    $already = staleInactiveInstitution(now()->subDays(300), now()->subDay());
    $noTimestamp = staleInactiveInstitution(null);
    $verified = Institution::factory()->create(['status' => InstitutionStatus::Verified->value]);

    $this->artisan('institutions:flag-stale-inactive', ['--apply' => true])
        ->expectsOutputToContain('Flagged 2 inactive institution(s) for manual review.')
        ->assertSuccessful();

    expect($old->refresh()->getAttribute('stale_inactive_flagged_at'))->not()->toBeNull()
        ->and($boundary->refresh()->getAttribute('stale_inactive_flagged_at'))->not()->toBeNull()
        ->and($recent->refresh()->getAttribute('stale_inactive_flagged_at'))->toBeNull()
        ->and((string) $already->refresh()->getAttribute('stale_inactive_flagged_at'))
        ->toBe((string) $already->getAttribute('stale_inactive_flagged_at'))
        ->and($noTimestamp->refresh()->getAttribute('stale_inactive_flagged_at'))->toBeNull()
        ->and($verified->refresh()->getAttribute('stale_inactive_flagged_at'))->toBeNull()
        ->and($old->refresh()->status)->toBe(InstitutionStatus::Inactive);
});

it('is idempotent across repeated applies', function () {
    $old = staleInactiveInstitution(now()->subDays(200));

    $this->artisan('institutions:flag-stale-inactive', ['--apply' => true])->assertSuccessful();
    $firstFlag = (string) $old->refresh()->getAttribute('stale_inactive_flagged_at');

    $this->artisan('institutions:flag-stale-inactive', ['--apply' => true])
        ->expectsOutputToContain('Flagged 0 inactive institution(s) for manual review.')
        ->assertSuccessful();

    expect((string) $old->refresh()->getAttribute('stale_inactive_flagged_at'))->toBe($firstFlag);
});

it('honours a custom day threshold', function () {
    $old = staleInactiveInstitution(now()->subDays(40));
    $recent = staleInactiveInstitution(now()->subDays(10));

    $this->artisan('institutions:flag-stale-inactive', ['--days' => 30, '--apply' => true])
        ->expectsOutputToContain('Flagged 1 inactive institution(s) for manual review.')
        ->assertSuccessful();

    expect($old->refresh()->getAttribute('stale_inactive_flagged_at'))->not()->toBeNull()
        ->and($recent->refresh()->getAttribute('stale_inactive_flagged_at'))->toBeNull();
});

it('rejects non-positive day thresholds', function (mixed $days) {
    $old = staleInactiveInstitution(now()->subDays(200));

    $this->artisan('institutions:flag-stale-inactive', ['--days' => $days, '--apply' => true])
        ->expectsOutputToContain('must be a positive integer')
        ->assertFailed();

    expect($old->refresh()->getAttribute('stale_inactive_flagged_at'))->toBeNull();
})->with([
    'zero' => [0],
    'negative' => [-5],
    'non-numeric' => ['soon'],
]);

function staleInactiveInstitution(?CarbonInterface $inactiveAt, ?CarbonInterface $flaggedAt = null): Institution
{
    $institution = Institution::factory()->create(['status' => InstitutionStatus::Inactive->value]);

    $institution->forceFill([
        'inactive_at' => $inactiveAt,
        'stale_inactive_flagged_at' => $flaggedAt,
    ])->save();

    return $institution->refresh();
}
