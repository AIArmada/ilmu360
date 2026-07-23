<?php

use App\Actions\DonationChannels\SaveDonationChannelAction;
use App\Models\DonationChannel;
use App\Models\Institution;
use App\Models\Speaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates a bank account donation channel', function () {
    $institution = Institution::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'bank_account',
        'bank_code' => 'MBB',
        'bank_name' => 'Maybank',
        'account_number' => '123456789',
        'status' => 'unverified',
    ]);

    expect($channel)->toBeInstanceOf(DonationChannel::class)
        ->and($channel->donatable_type)->toBe($institution->getMorphClass())
        ->and($channel->donatable_id)->toBe((string) $institution->getKey())
        ->and($channel->method)->toBe('bank_account')
        ->and($channel->bank_code)->toBe('MBB')
        ->and($channel->bank_name)->toBe('Maybank')
        ->and($channel->account_number)->toBe('123456789')
        ->and($channel->duitnow_type)->toBeNull()
        ->and($channel->ewallet_provider)->toBeNull()
        ->and($channel->status)->toBe('unverified');
});

it('creates a duitnow donation channel', function () {
    $institution = Institution::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'duitnow',
        'duitnow_type' => 'mobile',
        'duitnow_value' => '+60123456789',
        'status' => 'unverified',
    ]);

    expect($channel->method)->toBe('duitnow')
        ->and($channel->duitnow_type)->toBe('mobile')
        ->and($channel->duitnow_value)->toBe('+60123456789')
        ->and($channel->bank_code)->toBeNull()
        ->and($channel->account_number)->toBeNull();
});

it('creates an ewallet donation channel', function () {
    $institution = Institution::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'ewallet',
        'ewallet_provider' => 'tng',
        'ewallet_handle' => '+60123456789',
        'status' => 'unverified',
    ]);

    expect($channel->method)->toBe('ewallet')
        ->and($channel->ewallet_provider)->toBe('tng')
        ->and($channel->ewallet_handle)->toBe('+60123456789')
        ->and($channel->bank_code)->toBeNull()
        ->and($channel->duitnow_type)->toBeNull();
});

it('updates an existing donation channel', function () {
    $institution = Institution::factory()->create();

    $channel = DonationChannel::factory()->bankAccount()->create([
        'donatable_type' => $institution->getMorphClass(),
        'donatable_id' => (string) $institution->getKey(),
    ]);

    $updated = app(SaveDonationChannelAction::class)->handle([
        'recipient' => 'Updated Recipient',
        'method' => 'bank_account',
        'bank_code' => 'CIMB',
        'bank_name' => 'CIMB Bank',
        'account_number' => $channel->account_number,
        'status' => 'verified',
    ], $channel);

    expect($updated->fresh()->recipient)->toBe('Updated Recipient')
        ->and($updated->fresh()->bank_code)->toBe('CIMB')
        ->and($updated->fresh()->status)->toBe('verified');
});

it('assigns a channel to a speaker owner', function () {
    $speaker = Speaker::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'speaker',
        'donatable_id' => (string) $speaker->getKey(),
        'recipient' => 'Ustaz Ahmad',
        'method' => 'bank_account',
        'bank_code' => 'MBB',
        'bank_name' => 'Maybank',
        'account_number' => '987654321',
        'status' => 'unverified',
    ]);

    expect($channel->donatable_type)->toBe($speaker->getMorphClass())
        ->and($channel->donatable_id)->toBe((string) $speaker->getKey());
});

it('rejects an invalid donation method', function () {
    $institution = Institution::factory()->create();

    app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Test',
        'method' => 'crypto',
        'status' => 'unverified',
    ]);
})->throws(ValidationException::class);

it('rejects an invalid owner type', function () {
    app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'unknown_type',
        'donatable_id' => 'some-id',
        'recipient' => 'Test',
        'method' => 'bank_account',
        'bank_code' => 'MBB',
        'bank_name' => 'Maybank',
        'account_number' => '123456789',
        'status' => 'unverified',
    ]);
})->throws(ValidationException::class);

it('rejects empty recipient', function () {
    $institution = Institution::factory()->create();

    app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => '',
        'method' => 'bank_account',
        'bank_code' => 'MBB',
        'bank_name' => 'Maybank',
        'account_number' => '123456789',
        'status' => 'unverified',
    ]);
})->throws(ValidationException::class);
