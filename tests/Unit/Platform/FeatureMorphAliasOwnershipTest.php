<?php

declare(strict_types=1);

use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('registers owned morph aliases from their feature and platform providers', function (): void {
    $aliases = [
        'contribution_request' => App\Features\Contributions\Models\ContributionRequest::class,
        'donation_channel' => App\Features\DonationChannels\Models\DonationChannel::class,
        'event' => App\Features\Events\Models\Event::class,
        'event_key_person' => App\Features\Events\Models\EventKeyPerson::class,
        'event_submission' => App\Features\Events\Models\EventSubmission::class,
        'media_link' => App\Features\Events\Models\MediaLink::class,
        'series' => App\Features\Events\Models\Series::class,
        'institution' => App\Features\Institutions\Models\Institution::class,
        'institution_name' => App\Features\Institutions\Models\InstitutionName::class,
        'institution_import_exclusion' => App\Features\Institutions\Models\InstitutionImportExclusion::class,
        'member_invitation' => App\Features\Membership\Models\MemberInvitation::class,
        'membership_application' => App\Features\Membership\Models\MembershipApplication::class,
        'moderation_review' => App\Features\Moderation\Models\ModerationReview::class,
        'person' => App\Features\Persons\Models\Person::class,
        'person_name' => App\Features\Persons\Models\PersonName::class,
        'affiliation' => App\Features\Persons\Models\Affiliation::class,
        'space' => App\Features\Places\Models\Space::class,
        'venue' => App\Features\Places\Models\Venue::class,
        'reference' => App\Features\References\Models\Reference::class,
        'checkin' => App\Features\Registration\Models\EventCheckin::class,
        'registration' => App\Features\Registration\Models\Registration::class,
        'reward_digest_slot' => App\Features\Rewards\Models\RewardDigestSlot::class,
        'share_browser_selection' => App\Features\ShareTracking\Models\ShareBrowserSelection::class,
        'share_cohort_projection' => App\Features\ShareTracking\Models\ShareCohortProjection::class,
        'share_effect_receipt' => App\Features\ShareTracking\Models\ShareEffectReceipt::class,
        'share_outcome_correction' => App\Features\ShareTracking\Models\ShareOutcomeCorrection::class,
        'share_signal_reader_cursor' => App\Features\ShareTracking\Models\ShareSignalReaderCursor::class,
        'share_signal_reader_skip' => App\Features\ShareTracking\Models\ShareSignalReaderSkip::class,
        'share_source_attribution_receipt' => App\Features\ShareTracking\Models\ShareSourceAttributionReceipt::class,
        'language' => App\Platform\Language\Models\Language::class,
        'address' => AIArmada\Addressing\Models\Address::class,
        'contact' => AIArmada\Contacting\Models\ContactMethod::class,
        'customer' => AIArmada\Customers\Models\Customer::class,
        'user' => App\Models\User::class,
        'package_event' => AIArmada\Events\Features\Catalog\Models\Event::class,
        'event_access_policy' => AIArmada\Events\Features\Catalog\Models\EventAccessPolicy::class,
        'event_location' => AIArmada\Events\Features\Venues\Models\EventLocation::class,
        'event_occurrence' => AIArmada\Events\Features\Catalog\Models\EventOccurrence::class,
        'event_session' => AIArmada\Events\Features\Catalog\Models\EventSession::class,
        'checkout_session' => AIArmada\Checkout\Models\CheckoutSession::class,
        'order' => AIArmada\Orders\Models\Order::class,
        'order_item' => AIArmada\Orders\Models\OrderItem::class,
        'order_note' => AIArmada\Orders\Models\OrderNote::class,
        'order_payment' => AIArmada\Orders\Models\OrderPayment::class,
        'order_refund' => AIArmada\Orders\Models\OrderRefund::class,
        'event_registration_participant' => AIArmada\Events\Features\Registrations\Models\EventRegistrationParticipant::class,
        'social_media' => AIArmada\Contacting\Models\SocialProfile::class,
        'organization' => AIArmada\Organizations\Models\Organization::class,
        'inventory_location' => AIArmada\Inventory\Models\InventoryLocation::class,
        'inventory_level' => AIArmada\Inventory\Models\InventoryLevel::class,
        'inventory_allocation' => AIArmada\Inventory\Models\InventoryAllocation::class,
        'inventory_movement' => AIArmada\Inventory\Models\InventoryMovement::class,
        'ticket_type' => AIArmada\Ticketing\Models\TicketType::class,
    ];

    foreach ($aliases as $alias => $modelClass) {
        expect(Relation::getMorphedModel($alias))->toBe($modelClass)
            ->and(MorphModelResolver::resolve($alias))->toBe($modelClass)
            ->and((new $modelClass)->getMorphClass())->toBe($alias);
    }
});
