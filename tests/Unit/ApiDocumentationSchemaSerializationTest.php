<?php

use App\Support\ApiDocumentation\Schemas\AddressSelection;
use App\Support\ApiDocumentation\Schemas\Country;
use App\Support\ApiDocumentation\Schemas\EventParticipation;
use App\Support\ApiDocumentation\Schemas\EventSummary;
use App\Support\ApiDocumentation\Schemas\Institution;
use App\Support\ApiDocumentation\Schemas\InstitutionDetailPage;
use App\Support\ApiDocumentation\Schemas\InstitutionDetailResponse;
use App\Support\ApiDocumentation\Schemas\Person;
use App\Support\ApiDocumentation\Schemas\PersonDetailPage;
use App\Support\ApiDocumentation\Schemas\PersonDetailResponse;
use App\Support\ApiDocumentation\Schemas\PersonDirectoryResponse;
use App\Support\ApiDocumentation\Schemas\PersonListItem;

it('serializes institution detail response schemas to nested arrays', function () {
    $response = new InstitutionDetailResponse(
        data: new InstitutionDetailPage(
            institution: sampleInstitutionSchema(),
            upcoming_events: [sampleEventSummarySchema()],
            upcoming_total: 1,
            past_events: [sampleEventSummarySchema(id: 'event-2', slug: 'past-tafsir')],
            past_total: 1,
        ),
        meta: ['request_id' => 'req-institution'],
    );

    $payload = $response->toArray();

    expect($payload['data']['institution']['slug'])->toBe('masjid-biru')
        ->and($payload['data']['institution']['country']['iso2'])->toBe('MY')
        ->and($payload['data']['upcoming_events'][0]['slug'])->toBe('weekly-tafsir')
        ->and($payload['data']['past_events'][0]['slug'])->toBe('past-tafsir')
        ->and($payload['meta']['request_id'])->toBe('req-institution');
});

it('serializes person detail and directory schemas to nested arrays', function () {
    $detailResponse = new PersonDetailResponse(
        data: new PersonDetailPage(
            person: samplePersonSchema(),
            upcoming_events: [sampleEventSummarySchema()],
            upcoming_total: 1,
            past_events: [],
            past_total: 0,
            other_role_upcoming_participations: [
                new EventParticipation(
                    id: 'participation-1',
                    role: 'moderator',
                    role_label: 'Moderator',
                    display_name: 'Moderator',
                    event: sampleEventSummarySchema(),
                ),
            ],
            other_role_upcoming_total: 1,
            other_role_past_participations: [],
            other_role_past_total: 0,
        ),
        meta: ['request_id' => 'req-person'],
    );

    $directoryResponse = new PersonDirectoryResponse(
        data: [
            new PersonListItem(
                id: 'person-1',
                slug: 'ustaz-adam',
                name: 'Adam Yusuf',
                gender: 'male',
                formatted_name: 'Ustaz Adam Yusuf',
                status: 'verified',
                verified_by: null,
                events_count: 4,
                avatar_url: 'https://example.test/person-avatar.jpg',
                country: sampleCountrySchema(),
                is_following: true,
            ),
        ],
        meta: [
            'pagination' => ['page' => 1, 'per_page' => 12, 'total' => 1],
            'following' => ['total' => 1],
            'cache' => ['version' => 'v1'],
            'request_id' => 'req-person-directory',
        ],
    );

    $detailPayload = $detailResponse->toArray();
    $directoryPayload = $directoryResponse->toArray();

    expect($detailPayload['data']['person']['slug'])->toBe('ustaz-adam')
        ->and($detailPayload['data']['other_role_upcoming_participations'][0]['event']['slug'])->toBe('weekly-tafsir')
        ->and($detailPayload['meta']['request_id'])->toBe('req-person')
        ->and($directoryPayload['data'][0]['country']['iso2'])->toBe('MY')
        ->and($directoryPayload['meta']['following']['total'])->toBe(1)
        ->and($directoryPayload['meta']['request_id'])->toBe('req-person-directory');
});

function sampleAddressSelectionSchema(): AddressSelection
{
    return new AddressSelection(
        country_id: 1,
        state_id: 10,
        city_id: 15,
        area_assignments: [
            'administrative_district' => '20',
            'administrative_subdivision' => '30',
        ],
    );
}

function sampleCountrySchema(): Country
{
    return new Country(
        id: 1,
        name: 'Malaysia',
        iso2: 'MY',
        key: 'my',
    );
}

function sampleEventSummarySchema(string $id = 'event-1', string $slug = 'weekly-tafsir'): EventSummary
{
    return new EventSummary(
        id: $id,
        slug: $slug,
        title: 'Weekly Tafsir',
        starts_at: '2026-04-17T19:30:00+00:00',
        starts_at_local: '2026-04-18T03:30:00+08:00',
        starts_on_local_date: '2026-04-18',
        ends_at: '2026-04-17T21:00:00+00:00',
        ends_at_local: '2026-04-18T05:00:00+08:00',
        timing_display: 'Fri, 17 Apr 2026 · 7:30 PM',
        prayer_display_text: 'Selepas Isyak',
        end_time_display: '9:00 PM',
        visibility: 'public',
        status: 'approved',
        status_label: 'Approved',
        event_categories: [['id' => 'term-tafsir', 'code' => 'tafsir', 'name' => 'Tafsir', 'path' => 'Tafsir', 'is_primary' => true]],
        event_format: 'physical',
        event_format_label: 'Physical',
        reference_study_subtitle: 'Tafsir Surah Al-Baqarah',
        location: 'Shah Alam, Selangor',
        is_remote: false,
        is_pending: false,
        is_cancelled: false,
        has_poster: true,
        poster_url: 'https://example.test/poster.jpg',
        card_image_url: 'https://example.test/card.jpg',
        institution: [
            'id' => 'institution-1',
            'name' => 'Masjid Biru',
            'slug' => 'masjid-biru',
            'display_name' => 'Masjid Biru',
            'public_image_url' => 'https://example.test/institution.jpg',
            'logo_url' => 'https://example.test/logo.jpg',
        ],
        venue: [
            'id' => 'venue-1',
            'name' => 'Dewan Utama',
            'slug' => 'dewan-utama',
        ],
        persons: [[
            'id' => 'person-1',
            'name' => 'Adam Yusuf',
            'formatted_name' => 'Ustaz Adam Yusuf',
            'slug' => 'ustaz-adam',
            'avatar_url' => 'https://example.test/person-avatar.jpg',
        ]],
    );
}

function sampleInstitutionSchema(): Institution
{
    return new Institution(
        id: 'institution-1',
        slug: 'masjid-biru',
        name: 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        nickname: 'Masjid Biru',
        display_name: 'Masjid Sultan Salahuddin Abdul Aziz Shah (Masjid Biru)',
        description: 'Pusat komuniti dan kuliah.',
        status: 'verified',
        verified_by: null,
        type: 'mosque',
        type_label: 'Masjid',
        address_line: 'Persiaran Masjid, Seksyen 14',
        address: sampleAddressSelectionSchema(),
        country: sampleCountrySchema(),
        map_url: 'https://maps.google.com/?q=masjid-biru',
        followers_count: 120,
        person_count: 8,
        is_following: true,
        media: [
            'public_image_url' => 'https://example.test/institution.jpg',
            'logo_url' => 'https://example.test/logo.jpg',
            'cover_url' => 'https://example.test/cover.jpg',
        ],
        contacts: [['label' => 'Office', 'value' => '+60300000000']],
        social_media: [['platform' => 'facebook', 'url' => 'https://facebook.com/masjidbiru']],
        waze_url: 'https://waze.com/ul/masjid-biru',
        donation_channels: [['type' => 'bank', 'label' => 'Tabung Masjid']],
    );
}

function samplePersonSchema(): Person
{
    return new Person(
        id: 'person-1',
        slug: 'ustaz-adam',
        name: 'Adam Yusuf',
        gender: 'male',
        formatted_name: 'Ustaz Adam Yusuf',
        bio: 'Penceramah jemputan mingguan.',
        address: sampleAddressSelectionSchema(),
        country: sampleCountrySchema(),
        location: 'Shah Alam, Selangor',
        status: 'verified',
        verified_by: null,
        is_following: true,
        media: [
            'avatar_url' => 'https://example.test/person-avatar.jpg',
            'cover_url' => 'https://example.test/person-cover.jpg',
            'share_image_url' => 'https://example.test/person-share.jpg',
        ],
        gallery: [['url' => 'https://example.test/gallery-1.jpg']],
        institutions: [['id' => 'institution-1', 'name' => 'Masjid Biru']],
        contacts: [['label' => 'Email', 'value' => 'person@example.test']],
        social_media: [['platform' => 'facebook', 'url' => 'https://facebook.com/ustazadam']],
    );
}
