<?php

declare(strict_types=1);

namespace App\Platform;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\Addressable;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaRelationship;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\CommerceSupport\Models\Language as CommerceLanguage;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Contacting\Models\SocialProfile;
use AIArmada\Customers\Models\Customer;
use AIArmada\Events\Models\Event;
use AIArmada\Events\Models\EventAccessPolicy;
use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventRegistrationParticipant;
use AIArmada\Events\Models\EventSession;
use AIArmada\Inventory\Models\InventoryAllocation;
use AIArmada\Inventory\Models\InventoryLevel;
use AIArmada\Inventory\Models\InventoryLocation;
use AIArmada\Inventory\Models\InventoryMovement;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use AIArmada\Orders\Models\OrderNote;
use AIArmada\Orders\Models\OrderPayment;
use AIArmada\Orders\Models\OrderRefund;
use AIArmada\Organizations\Models\Organization;
use AIArmada\Ticketing\Models\TicketType;
use App\Models\User;
use App\Platform\Addressing\Observers\AddressableObserver;
use App\Platform\Addressing\Observers\AddressAreaObserver;
use App\Platform\Addressing\Observers\AddressAreaRelationshipObserver;
use App\Platform\Addressing\Observers\AddressCatalogObserver;
use App\Platform\Addressing\Observers\AddressCountryObserver;
use App\Platform\Addressing\Observers\AddressObserver;
use App\Platform\Console\CacheCommand;
use App\Platform\Console\ClearCommand;
use App\Platform\Language\Models\Language;
use App\Platform\Language\Observers\LanguageObserver;
use App\Platform\Routing\PublicSlugRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PublicSlugRegistry::class, static fn (): PublicSlugRegistry => new PublicSlugRegistry([]));

        $catalog = FeatureCatalog::load($this->app->basePath());

        $this->app->singleton(
            ExtensionRegistry::class,
            fn ($app): ExtensionRegistry => new ExtensionRegistry($app, $catalog['extensions']),
        );

        foreach (array_keys($catalog['features']) as $feature) {
            $provider = "App\\Features\\{$feature}\\FeatureServiceProvider";
            if (! is_subclass_of($provider, FeatureModule::class)) {
                throw new RuntimeException("{$provider} must extend ".FeatureModule::class.'.');
            }

            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        // Canonical package and root morph types are infrastructure-owned.
        Relation::morphMap([
            'address' => Address::class,
            'contact' => ContactMethod::class,
            'customer' => Customer::class,
            'user' => User::class,
            'package_event' => PackageEvent::class,
            'event_access_policy' => EventAccessPolicy::class,
            'event_location' => EventLocation::class,
            'event_occurrence' => EventOccurrence::class,
            'event_session' => EventSession::class,
            'checkout_session' => CheckoutSession::class,
            'order' => Order::class,
            'order_item' => OrderItem::class,
            'order_note' => OrderNote::class,
            'order_payment' => OrderPayment::class,
            'order_refund' => OrderRefund::class,
            'event_registration_participant' => EventRegistrationParticipant::class,
            'social_media' => SocialProfile::class,
            'organization' => Organization::class,
            'inventory_location' => InventoryLocation::class,
            'inventory_level' => InventoryLevel::class,
            'inventory_allocation' => InventoryAllocation::class,
            'inventory_movement' => InventoryMovement::class,
            'ticket_type' => TicketType::class,
        ]);

        Relation::morphMap([
            'language' => Language::class,
        ]);

        Address::observe(AddressObserver::class);
        AddressArea::observe(AddressAreaObserver::class);
        AddressAreaRelationship::observe(AddressAreaRelationshipObserver::class);
        Addressable::observe(AddressableObserver::class);
        AddressCountry::observe(AddressCountryObserver::class);
        City::observe(AddressCatalogObserver::class);
        State::observe(AddressCatalogObserver::class);
        Language::observe(LanguageObserver::class);
        CommerceLanguage::observe(LanguageObserver::class);

        $isProduction = $this->app->isProduction();

        Model::shouldBeStrict(! $isProduction);
        DB::prohibitDestructiveCommands($isProduction);
        Date::use(CarbonImmutable::class);

        if ($this->app->runningInConsole()) {
            $this->commands([CacheCommand::class, ClearCommand::class]);
            $this->optimizes(optimize: 'platform:cache', clear: 'platform:clear', key: 'platform');
        }
    }
}
