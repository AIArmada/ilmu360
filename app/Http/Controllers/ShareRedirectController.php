<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\Links\Models\Link;
use App\Services\ShareTracking\ShareTrackingUrlService;
use App\Support\ShareTracking\ShareDestinationPolicy;
use App\Support\ShareTracking\ShareTrackingProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * App share short-link adapter.
 *
 * Resolves the single links-managed tracked row behind an emitted /k/
 * short URL and forwards to the revalidated public destination. This
 * adapter is a pure redirect: it records no landing, writes no click
 * row, sets no cookie, and distinguishes no user agent. The destination
 * page records exactly once through the standard capture path, which
 * already excludes preview bots and re-locks eligibility at write time.
 *
 * Only share-subject rows are served here. Commercial links keep their
 * generic /go/ behavior untouched; unknown slugs, branded rows, and
 * non-share subjects 404 honestly because no verified destination
 * exists for them under this entry.
 */
final class ShareRedirectController extends Controller
{
    public function __construct(
        private readonly ShareTrackingUrlService $shareTrackingUrlService,
        private readonly ShareDestinationPolicy $destinationPolicy,
    ) {}

    public function show(Request $request, string $slug): RedirectResponse
    {
        $slug = trim($slug);

        if ($slug === '' || mb_strlen($slug) > 100 || preg_match('/^[A-Za-z0-9_-]+$/', $slug) !== 1) {
            abort(404);
        }

        $tracked = Link::query()->withoutOwnerScope()->where('slug', $slug)->first();

        // Inactive rows resolve too: disabled credit still opens the public
        // destination. Branded rows never resolve under the short entry.
        if (! $tracked instanceof Link || $tracked->slug_prefix !== null) {
            abort(404);
        }

        if ($tracked->subject_type !== (new AffiliateLink)->getMorphClass()) {
            abort(404);
        }

        $shareLink = AffiliateLink::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereKey((string) $tracked->subject_id)
            ->first();

        if (! $shareLink instanceof AffiliateLink) {
            // Detached share redirect: the owner deleted their profile, so
            // the managed row was scrubbed into a minimal public mapping.
            // The destination still opens; no share context is forwarded.
            return $this->redirectToPublicDestination($tracked->destination_url, []);
        }

        if (! $this->isEligibleShareLink($shareLink)) {
            // Disabled credit behaves like a plain URL: the public
            // destination opens with no share token, so a dead token can
            // never clear the visitor's unrelated valid selection.
            return $this->redirectToPublicDestination($shareLink->destination_url, []);
        }

        $token = is_string($shareLink->custom_slug) ? trim($shareLink->custom_slug) : '';

        if ($token === '') {
            return $this->redirectToPublicDestination($shareLink->destination_url, []);
        }

        $forwarded = [
            (string) config('dawah-share.query_parameter', 'share') => $token,
        ];

        $origin = $this->shareTrackingUrlService->normalizeOrigin($shareLink->origin) ?? 'web';

        if ($origin !== 'web') {
            $forwarded['origin'] = $origin;
        }

        $channel = $this->shareTrackingUrlService->normalizeProvider(
            is_string($request->query((string) config('dawah-share.provider_query_parameter', 'channel')))
                ? (string) $request->query((string) config('dawah-share.provider_query_parameter', 'channel'))
                : null
        );

        if (is_string($channel)) {
            $forwarded[(string) config('dawah-share.provider_query_parameter', 'channel')] = $channel;
        }

        // Only the canonical validated channel and allowlisted campaign
        // slugs cross the hop. Arbitrary UTM spellings, ad click IDs, and
        // anything resembling a secret are dropped, never forwarded.
        foreach ($this->shareTrackingUrlService->sanitizedCampaignParams($request) as $key => $value) {
            $forwarded[$key] = $value;
        }

        return $this->redirectToPublicDestination($shareLink->destination_url, $forwarded);
    }

    /**
     * @param  array<string, string>  $forwarded
     */
    private function redirectToPublicDestination(string $destinationUrl, array $forwarded): RedirectResponse
    {
        if (! $this->destinationPolicy->isPubliclyReachable($destinationUrl)) {
            return redirect()->route('share.unavailable');
        }

        $target = $forwarded === []
            ? $destinationUrl
            : $this->shareTrackingUrlService->appendQueryParameters($destinationUrl, $forwarded);

        return redirect()->to($target, 302);
    }

    private function isEligibleShareLink(AffiliateLink $link): bool
    {
        if (! $link->isEnabled()) {
            return false;
        }

        $affiliate = Affiliate::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereKey($link->affiliate_id)
            ->first();

        return ShareTrackingProfile::isEligibleForNewCredit($affiliate);
    }
}
