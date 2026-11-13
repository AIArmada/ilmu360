<?php

declare(strict_types=1);

use App\Contracts\ShareTrackingContract;
use App\Services\ShareTracking\AffiliatesShareTrackingService;

it('removes the pass-through share tracking wrapper and unused null object', function (): void {
    expect(file_exists(app_path('Services/ShareTrackingService.php')))->toBeFalse()
        ->and(file_exists(app_path('Contracts/NullShareTrackingService.php')))->toBeFalse();
});

it('resolves the share tracking contract to the affiliates service as a singleton', function (): void {
    $contract = app(ShareTrackingContract::class);

    expect($contract)->toBeInstanceOf(AffiliatesShareTrackingService::class)
        ->and(app(ShareTrackingContract::class))->toBe($contract);
});

it('keeps the affiliates service behind the share tracking contract surface', function (): void {
    expect(class_implements(AffiliatesShareTrackingService::class))->toContain(ShareTrackingContract::class);
});

it('preserves the supported provider and channel contract behaviour', function (): void {
    $contract = app(ShareTrackingContract::class);

    expect($contract->supportedProviders())->toBe([
        'whatsapp',
        'telegram',
        'threads',
        'facebook',
        'x',
        'instagram',
        'tiktok',
        'email',
    ])->and($contract->supportedChannels())->toBe([
        'whatsapp',
        'telegram',
        'threads',
        'facebook',
        'x',
        'instagram',
        'tiktok',
        'email',
        'copy_link',
        'native_share',
    ]);
});
