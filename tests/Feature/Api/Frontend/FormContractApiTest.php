<?php

use App\Enums\MemberSubjectType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('exposes the advanced event field contract to authenticated clients', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson(route('api.client.forms.advanced-events'))
        ->assertOk()
        ->assertJsonPath('data.flow', 'advanced_event')
        ->assertJsonPath('data.method', 'POST')
        ->assertJsonPath('data.auth_required', true);

    $fields = collect($response->json('data.fields'))->keyBy('name');

    expect($fields->has('title'))->toBeTrue()
        ->and($fields->get('title')['required'] ?? null)->toBeTrue()
        ->and($fields->has('program_starts_at'))->toBeTrue();
});

it('requires authentication for the advanced event field contract', function (): void {
    $this->getJson(route('api.client.forms.advanced-events'))
        ->assertUnauthorized();
});

it('exposes the institution workspace field contract to authenticated clients', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson(route('api.client.forms.institution-workspace'))
        ->assertOk()
        ->assertJsonPath('data.flow', 'institution_workspace');

    $addFields = collect($response->json('data.member_add_fields'))->keyBy('name');

    expect($addFields->has('email'))->toBeTrue()
        ->and($response->json('data.workspace_endpoint'))->toBe(route('api.client.institution-workspace.show'));
});

it('exposes the membership claim field contract for a claimable subject type', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $segment = MemberSubjectType::Institution->publicRouteSegment();

    $this->getJson(route('api.client.forms.membership-applications', ['subjectType' => $segment]))
        ->assertOk()
        ->assertJsonPath('data.flow', 'membership_claim')
        ->assertJsonPath('data.auth_required', true)
        ->assertJsonPath('data.endpoint_template', '/api/v1/membership-applications/'.$segment.'/subject');
});

it('rejects membership claim contracts for unknown subject types', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/forms/membership-applications/not-a-subject')
        ->assertNotFound();
});
