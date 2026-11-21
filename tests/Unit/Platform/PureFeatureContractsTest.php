<?php

declare(strict_types=1);

use App\Features\Events\Api\AdminEventModeration;
use App\Features\Events\Api\EventDirectoryProjectionProvider;
use App\Features\Events\Api\EventRegistrationNotifications;
use App\Features\Registration\Api\RegistrationConfirmationFactsReader;
use App\Features\Registration\Api\RegistrationNotificationAudience;
use App\Features\ShareTracking\Api\ActiveGuestClaimants;
use App\Features\ShareTracking\Api\ShareTrackingAnalytics;
use ReflectionClass;
use ReflectionNamedType;

it('keeps corrected cross-feature contracts pure and free of mutable Eloquent types', function (): void {
    $interfaces = [
        AdminEventModeration::class,
        EventDirectoryProjectionProvider::class,
        EventRegistrationNotifications::class,
        RegistrationConfirmationFactsReader::class,
        RegistrationNotificationAudience::class,
        ActiveGuestClaimants::class,
        ShareTrackingAnalytics::class,
    ];

    foreach ($interfaces as $interface) {
        $reflection = new ReflectionClass($interface);
        expect($reflection->isInterface())->toBeTrue();

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (! $type instanceof ReflectionNamedType) {
                    continue;
                }

                expect($type->getName())->not->toStartWith('Illuminate\\')
                    ->not->toStartWith('App\\Features\\Registration\\Models\\')
                    ->not->toStartWith('App\\Features\\Events\\Models\\');
            }

            $type = $method->getReturnType();
            if ($type instanceof ReflectionNamedType) {
                expect($type->getName())->not->toStartWith('Illuminate\\');
            }
        }
    }
});

it('does not retain the deleted ORM boundary declarations', function (): void {
    foreach ([
        'app/Features/Events/Api/Orm/AdminEventModeration.php',
        'app/Features/Events/Api/Orm/EventRegistrationNotifications.php',
        'app/Features/Events/Api/Orm/EventOccurrenceDeletionPreflight.php',
        'app/Features/ShareTracking/Api/Orm/GuestClaimScope.php',
        'app/Features/ShareTracking/Api/Orm/ShareTrackingAnalytics.php',
    ] as $oldFile) {
        expect(file_exists(base_path($oldFile)))->toBeFalse();
    }
});
