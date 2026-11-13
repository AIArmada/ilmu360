<?php

declare(strict_types=1);

use App\Actions\ShareTracking\ClaimGuestShareProfileAction;
use App\Features\ShareTracking\Actions\GuestClaims\ClaimGuestProfileAction;
use App\Features\ShareTracking\Api\GuestShareClaims;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Shared\ShareTracking\ClaimRecipients;
use App\Shared\ShareTracking\GuestClaims;
use App\Shared\ShareTracking\ShareGuestClaimResult;
use App\Support\ShareTracking\ClaimRecipientLookup;
use App\Support\ShareTracking\GuestClaimLookup;
use Illuminate\Http\Request;

it('binds the feature claim api and the shared recipient seam', function (): void {
    expect(app(GuestShareClaims::class))->toBeInstanceOf(ClaimGuestProfileAction::class)
        ->and(app(ClaimRecipients::class))->toBeInstanceOf(ClaimRecipientLookup::class)
        ->and(app(GuestClaims::class))->toBeInstanceOf(GuestClaimLookup::class);
});

it('passes only scalars from the root action to the feature api', function (): void {
    $claimant = User::factory()->create();

    $fake = new class implements GuestShareClaims
    {
        /**
         * @var list<array{claimantId: string, claimantMorphType: string, presentedCapability: string, confirmedConsent: bool}>
         */
        public array $calls = [];

        public function claim(
            string $claimantId,
            string $claimantMorphType,
            string $presentedCapability,
            bool $confirmedConsent,
        ): ShareGuestClaimResult {
            $this->calls[] = [
                'claimantId' => $claimantId,
                'claimantMorphType' => $claimantMorphType,
                'presentedCapability' => $presentedCapability,
                'confirmedConsent' => $confirmedConsent,
            ];

            return ShareGuestClaimResult::RecipientIneligible;
        }
    };

    app()->instance(GuestShareClaims::class, $fake);

    $result = app(ClaimGuestShareProfileAction::class)->handle($claimant, 'presented-capability', true);

    expect($result)->toBe(ShareGuestClaimResult::RecipientIneligible)
        ->and($fake->calls)->toBe([[
            'claimantId' => (string) $claimant->getKey(),
            'claimantMorphType' => $claimant->getMorphClass(),
            'presentedCapability' => 'presented-capability',
            'confirmedConsent' => true,
        ]]);
});

it('preserves the explicit consent refusal inside the feature api', function (): void {
    $claimant = User::factory()->create();

    expect(fn () => app(GuestShareClaims::class)->claim(
        (string) $claimant->getKey(),
        $claimant->getMorphClass(),
        str_repeat('a', 64),
        false,
    ))->toThrow(
        InvalidArgumentException::class,
        'Linking past anonymous shares needs your explicit confirmation.',
    );
});

it('neutralizes invalid and other-claimant decisions behind one public code', function (): void {
    expect(ShareGuestClaimResult::ClaimedByOther->publicCode())->toBe('unverified')
        ->and(ShareGuestClaimResult::ExpiredOrInvalid->publicCode())->toBe('unverified')
        ->and(ShareGuestClaimResult::Claimed->publicCode())->toBe('claimed')
        ->and(ShareGuestClaimResult::AlreadyClaimedByYou->publicCode())->toBe('already_claimed_by_you')
        ->and(ShareGuestClaimResult::RecipientIneligible->publicCode())->toBe('recipient_ineligible');
});

it('keeps the guest claim result in the shared contract layer', function (): void {
    $reflection = new ReflectionClass(ShareGuestClaimResult::class);

    expect($reflection->getNamespaceName())->toBe('App\Shared\ShareTracking')
        ->and($reflection->getFileName())->toBe(realpath(base_path('app/Shared/ShareTracking/ShareGuestClaimResult.php')))
        ->and(class_exists('App\Enums\ShareGuestClaimResult', false))->toBeFalse();
});

it('claims and replays a guest history through the feature api', function (): void {
    $claimant = User::factory()->create();
    $payload = app(AffiliatesShareTrackingService::class)->sharePayload(
        null,
        route('events.index'),
        'Share this page',
        'Shared Page',
        'web',
        Request::create(route('events.index')),
    );

    $claim = app(GuestShareClaims::class);
    $arguments = [
        (string) $claimant->getKey(),
        $claimant->getMorphClass(),
        (string) $payload['guest_claim_capability'],
        true,
    ];

    expect($claim->claim(...$arguments))->toBe(ShareGuestClaimResult::Claimed)
        ->and($claim->claim(...$arguments))->toBe(ShareGuestClaimResult::AlreadyClaimedByYou);
});
