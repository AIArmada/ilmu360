<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('documents validate-only remediation details in the admin api reference', function () {
    $markdown = file_get_contents(base_path('docs/ilmu360_mobile_api_reference.md')) ?: '';

    expect($markdown)
        ->toContain('validate_only=true')
        ->toContain('Validation failures in validate-only mode return machine-readable remediation details')
        ->toContain('`error.details.fix_plan`')
        ->toContain('`error.details.remaining_blockers`')
        ->toContain('`error.details.normalized_payload_preview`')
        ->toContain('`error.details.can_retry`');
});

it('documents mixed speaker venue and reference mutation semantics in the admin api reference', function () {
    $markdown = file_get_contents(base_path('docs/ilmu360_mobile_api_reference.md')) ?: '';

    expect($markdown)
        ->toContain('Speaker-specific update rules:')
        ->toContain('`address = {}` returns HTTP `422` for speakers.')
        ->toContain('The array-style speaker fields `honorific`, `pre_nominal`, `post_nominal`, `qualifications`, `language_ids`, `contacts`, and `social_media` all use replacement semantics when present')
        ->toContain('### Venue-specific update rules')
        ->toContain('`address = {}` is destructive for venues: it deletes the existing stored address.')
        ->toContain('`facilities` is a replacement set, not a patchable map')
        ->toContain('### Reference-specific update rules')
        ->toContain('`author`, `publication_year`, and `publisher` are normalized string scalars')
        ->toContain('For Twitter / X, use the canonical write value `twitter`.');
});

it('documents event series and donation-channel mutation semantics in the admin api reference', function () {
    $markdown = file_get_contents(base_path('docs/ilmu360_mobile_api_reference.md')) ?: '';

    expect($markdown)
        ->toContain('### Event-specific update rules')
        ->toContain('Event `PUT` is sparse on the raw admin API.')
        ->toContain('`speakers` and `other_key_people` also preserve on omission, but any submitted array rebuilds the underlying `key_people` rows.')
        ->toContain('### Series-specific update rules')
        ->toContain('Series `PUT` still requires `title`, `slug`, and `visibility`.')
        ->toContain('`languages` is a replacement relation: omit to preserve, send `null` or `[]` to clear, and send the full list when changing it.')
        ->toContain('### Donation-channel-specific update rules')
        ->toContain('Donation channel `PUT` still requires `donatable_type`, `donatable_id`, `recipient`, `method`, and `status`.')
        ->toContain('Switching `method` clears unrelated method-specific fields.')
        ->toContain('`clear_qr=true` is supported on the raw HTTP admin API');
});

it('documents inspiration space and report mutation semantics in the admin api reference', function () {
    $markdown = file_get_contents(base_path('docs/ilmu360_mobile_api_reference.md')) ?: '';

    expect($markdown)
        ->toContain('### Inspiration-specific update rules')
        ->toContain('Inspiration `PUT` still requires `category`, `locale`, `title`, and `content`.')
        ->toContain('`main` is a single-file media collection')
        ->toContain('### Space-specific update rules')
        ->toContain('Space `PUT` still requires `name` and `slug`.')
        ->toContain('`institutions` is a replacement relation: omit to preserve, send `null` or `[]` to clear')
        ->toContain('### Report-specific update rules')
        ->toContain('Report `PUT` still requires `entity_type`, `entity_id`, `category`, and `status`.')
        ->toContain('`evidence` is a replacement file collection on raw HTTP writes')
        ->toContain('`evidence: []` clears the media collection while `evidence: null` preserves the current uploads.');
});

it('documents tag and administrative-area mutation semantics in the admin api reference', function () {
    $markdown = file_get_contents(base_path('docs/ilmu360_mobile_api_reference.md')) ?: '';

    expect($markdown)
        ->toContain('### Tag-specific update rules')
        ->toContain('Tag `PUT` still requires `name.ms`, `type`, and `status`.')
        ->toContain('`name.en` is optional and falls back to `name.ms`')
        ->toContain('Sending `null` or `""` does not clear it to `null`; it hands ordering back to the sortable scope')
        ->toContain('### Administrative-area update rules')
        ->toContain('Administrative-area `PUT` still requires `country_id`, an applicable parent, and `name`.')
        ->toContain('The parent and level are determined by the selected country address profile')
        ->toContain('parent fields are optional only when the selected country profile defines that level as a root');
});

it('documents the public reference directory in the mobile api reference', function () {
    $markdown = file_get_contents(base_path('docs/ilmu360_mobile_api_reference.md')) ?: '';

    expect($markdown)
        ->toContain('| `GET` | `/references` | Public reference listing filters; default directory pages show root/standalone references')
        ->toContain('`/speakers`, `/institutions`, and `/references` return **only** records where `is_active = true` AND `status = \'verified\'`')
        ->toContain('Public reference directory list items expose `display_title`, `parent_reference_id`, `part_type`, `part_number`, `part_label`, `is_part`, `author`, `type`, `publisher`, `publication_year`, `is_active`, `events_count`, `front_cover_url`, and `is_following` by default.')
        ->toContain('Unified search accepts `search` as the canonical query parameter and `q` as a compatibility alias')
        ->toContain('The `/institutions/near` alias requires either `near=lat,lng` or both `lat` and `lng`; calling it without coordinates intentionally returns a validation error.')
        ->toContain('There are no plural follow-list routes such as `/follows/speakers`')
        ->toContain('Public `/events`, `/institutions`, `/institutions/near`, `/speakers`, and `/references` list endpoints accept `fields=`');
});

it('documents admin api search parity and scope differences versus the public and MCP surfaces', function () {
    $markdown = file_get_contents(base_path('docs/ilmu360_mobile_api_reference.md')) ?: '';

    expect($markdown)
        ->toContain('For `speakers`, `institutions`, and `references`, the admin HTTP API now reuses the same specialized search services')
        ->toContain('admin/member MCP `*list-records` tools')
        ->toContain('the main difference is which records each surface is allowed to return.');
});

it('documents event change projections and unlisted direct-detail access in the mobile api reference', function () {
    $markdown = file_get_contents(base_path('docs/ilmu360_mobile_api_reference.md')) ?: '';

    expect($markdown)
        ->toContain('| `GET` | `/events/{eventOrSlug}` | Event detail by UUID or slug for active public events, plus active unlisted events when the client already has the direct identifier |')
        ->toContain('The detail endpoint returns active `public` events plus active `unlisted` events when the client already has the UUID or slug.')
        ->toContain('Event detail payloads now include `active_change_notice`, `change_announcements`, and `replacement_event`')
        ->toContain('`change_announcements` is the published history ordered newest-first.')
        ->toContain('falls back to the last reachable target or omits the field entirely.');
});
