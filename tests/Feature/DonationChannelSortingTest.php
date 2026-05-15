<?php

use App\Models\DonationChannel;
use App\Models\Institution;

it('orders donation channels with the default one first', function () {
    // 1. Create an institution.
    $institution = Institution::factory()->create();

    // 2. Create two donation channels for it.
    // We create the first one as non-default.
    $firstChannel = $institution->donationChannels()->create(DonationChannel::factory()->raw([
        'is_default' => false,
    ]));

    // We create the second one also as non-default.
    $secondChannel = $institution->donationChannels()->create(DonationChannel::factory()->raw([
        'is_default' => false,
    ]));

    // 3. Set the second one as default.
    $secondChannel->update(['is_default' => true]);

    // 4. Verify that $institution->donationChannels->first() is the default one.
    // The relationship is ordered by is_default DESC, then created_at ASC.
    expect($institution->donationChannels->first()->id)->toBe($secondChannel->id);
    expect($institution->donationChannels->first()->is_default)->toBeTrue();

    // 5. Also check $institution->load('donationChannels')->donationChannels->first() is the default one.
    $institution->unsetRelation('donationChannels'); // Clear any potential relation cache
    expect($institution->load('donationChannels')->donationChannels->first()->id)->toBe($secondChannel->id);
    expect($institution->load('donationChannels')->donationChannels->first()->is_default)->toBeTrue();
});
