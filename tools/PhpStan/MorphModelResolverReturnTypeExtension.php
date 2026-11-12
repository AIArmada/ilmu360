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
 * The runtime map lives in AppServiceProvider::boot(); this analysis-only map
 * mirrors it so Builder/model generics survive helper classes that resolve
 * feature-owned models through their stable morph aliases.
 */
final class MorphModelResolverReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public const MORPH_MODELS = [
        'address' => \AIArmada\Addressing\Models\Address::class,
        'ai_model_pricing' => \App\Models\AiModelPricing::class,
        'contact' => \AIArmada\Contacting\Models\ContactMethod::class,
        'customer' => \AIArmada\Customers\Models\Customer::class,
        'user' => \App\Models\User::class,
        'event' => \App\Models\Event::class,
        'package_event' => \AIArmada\Events\Models\Event::class,
        'event_access_policy' => \AIArmada\Events\Models\EventAccessPolicy::class,
        'event_key_person' => \App\Models\EventKeyPerson::class,
        'event_location' => \AIArmada\Events\Models\EventLocation::class,
        'event_occurrence' => \AIArmada\Events\Models\EventOccurrence::class,
        'event_submission' => \App\Models\EventSubmission::class,
        'event_session' => \AIArmada\Events\Models\EventSession::class,
        'checkin' => \App\Models\EventCheckin::class,
        'checkout_session' => \AIArmada\Checkout\Models\CheckoutSession::class,
        'order' => \AIArmada\Orders\Models\Order::class,
        'order_item' => \AIArmada\Orders\Models\OrderItem::class,
        'order_note' => \AIArmada\Orders\Models\OrderNote::class,
        'order_payment' => \AIArmada\Orders\Models\OrderPayment::class,
        'order_refund' => \AIArmada\Orders\Models\OrderRefund::class,
        'contribution_request' => \App\Models\ContributionRequest::class,
        'event_registration_participant' => \AIArmada\Events\Models\EventRegistrationParticipant::class,
        'membership_application' => \App\Models\MembershipApplication::class,
        'moderation_review' => \App\Models\ModerationReview::class,
        'media_link' => \App\Models\MediaLink::class,
        'institution' => \App\Models\Institution::class,
        'institution_name' => \App\Models\InstitutionName::class,
        'institution_import_exclusion' => \App\Models\InstitutionImportExclusion::class,
        'donation_channel' => \App\Models\DonationChannel::class,
        'member_invitation' => \App\Models\MemberInvitation::class,
        'registration' => \App\Models\Registration::class,
        'person' => \App\Models\Person::class,
        'person_name' => \App\Models\PersonName::class,
        'affiliation' => \App\Models\Affiliation::class,
        'series' => \App\Models\Series::class,
        'social_media' => \AIArmada\Contacting\Models\SocialProfile::class,
        'space' => \App\Models\Space::class,
        'venue' => \App\Models\Venue::class,
        'language' => \App\Models\Language::class,
        'reference' => \App\Models\Reference::class,
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
