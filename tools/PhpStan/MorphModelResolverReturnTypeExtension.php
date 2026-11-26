<?php

declare(strict_types=1);

namespace Tools\PhpStan;

use App\Platform\Models\MorphModelResolver;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericClassStringType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Types literal morph-alias resolution at the call site.
 *
 * Runtime maps live in application and feature service providers; this
 * analysis-only map mirrors them so Builder/model generics survive helper
 * classes that resolve feature-owned models through stable morph aliases.
 */
final class MorphModelResolverReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public const MORPH_MODELS = [
        'address' => \AIArmada\Addressing\Features\AddressLifecycle\Models\Address::class,
        'ai_model_pricing' => \App\Features\Ai\Models\AiModelPricing::class,
        'contact' => \AIArmada\Contacting\Models\ContactMethod::class,
        'customer' => \AIArmada\Customers\Models\Customer::class,
        'user' => \App\Models\User::class,
        'event' => \App\Features\Events\Models\Event::class,
        'package_event' => \AIArmada\Events\Features\Catalog\Models\Event::class,
        'event_access_policy' => \AIArmada\Events\Features\Catalog\Models\EventAccessPolicy::class,
        'event_key_person' => \App\Features\Events\Models\EventKeyPerson::class,
        'event_location' => \AIArmada\Events\Features\Venues\Models\EventLocation::class,
        'event_occurrence' => \AIArmada\Events\Features\Catalog\Models\EventOccurrence::class,
        'event_submission' => \App\Features\Events\Models\EventSubmission::class,
        'event_session' => \AIArmada\Events\Features\Catalog\Models\EventSession::class,
        'checkin' => \App\Features\Registration\Models\EventCheckin::class,
        'checkout_session' => \AIArmada\Checkout\Models\CheckoutSession::class,
        'order' => \AIArmada\Orders\Models\Order::class,
        'order_item' => \AIArmada\Orders\Models\OrderItem::class,
        'order_note' => \AIArmada\Orders\Models\OrderNote::class,
        'order_payment' => \AIArmada\Orders\Models\OrderPayment::class,
        'order_refund' => \AIArmada\Orders\Models\OrderRefund::class,
        'contribution_request' => \App\Features\Contributions\Models\ContributionRequest::class,
        'event_registration_participant' => \AIArmada\Events\Features\Registrations\Models\EventRegistrationParticipant::class,
        'reward_digest_slot' => \App\Features\Rewards\Models\RewardDigestSlot::class,
        'membership_application' => \App\Features\Membership\Models\MembershipApplication::class,
        'moderation_review' => \App\Features\Moderation\Models\ModerationReview::class,
        'media_link' => \App\Features\Events\Models\MediaLink::class,
        'institution' => \App\Features\Institutions\Models\Institution::class,
        'institution_name' => \App\Features\Institutions\Models\InstitutionName::class,
        'institution_import_exclusion' => \App\Features\Institutions\Models\InstitutionImportExclusion::class,
        'donation_channel' => \App\Features\DonationChannels\Models\DonationChannel::class,
        'member_invitation' => \App\Features\Membership\Models\MemberInvitation::class,
        'registration' => \App\Features\Registration\Models\Registration::class,
        'person' => \App\Features\Persons\Models\Person::class,
        'person_name' => \App\Features\Persons\Models\PersonName::class,
        'affiliation' => \App\Features\Persons\Models\Affiliation::class,
        'series' => \App\Features\Events\Models\Series::class,
        'social_media' => \AIArmada\Contacting\Models\SocialProfile::class,
        'space' => \App\Features\Places\Models\Space::class,
        'venue' => \App\Features\Places\Models\Venue::class,
        'language' => \App\Platform\Language\Models\Language::class,
        'reference' => \App\Features\References\Models\Reference::class,
        'organization' => \AIArmada\Organizations\Models\Organization::class,
        'inventory_location' => \AIArmada\Inventory\Models\InventoryLocation::class,
        'inventory_level' => \AIArmada\Inventory\Models\InventoryLevel::class,
        'inventory_allocation' => \AIArmada\Inventory\Models\InventoryAllocation::class,
        'inventory_movement' => \AIArmada\Inventory\Models\InventoryMovement::class,
        'ticket_type' => \AIArmada\Ticketing\Models\TicketType::class,
    ];

    public function getClass(): string
    {
        return MorphModelResolver::class;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), ['resolve', 'tryResolve'], true);
    }

    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        $arguments = $methodCall->getArgs();

        if ($arguments === []) {
            return null;
        }

        $constantStrings = $scope->getType($arguments[0]->value)->getConstantStrings();

        if (count($constantStrings) !== 1) {
            return null;
        }

        $modelClass = self::MORPH_MODELS[$constantStrings[0]->getValue()] ?? null;

        if ($modelClass === null) {
            return null;
        }

        $classString = new GenericClassStringType(new ObjectType($modelClass));

        return $methodReflection->getName() === 'tryResolve'
            ? TypeCombinator::addNull($classString)
            : $classString;
    }
}
