<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AIArmada\Events\Models\EventOccurrence;
use App\Models\Event;
use App\Services\ShareCards\ShareCardService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Public share-card delivery.
 *
 * Serves the deterministic 1200x630 generated card for a publicly
 * reachable event, selected occurrence, locale, and published display
 * timezone. Revision-pinned responses are immutable; stale pins redirect
 * to the canonical current URL. Any render failure — including an
 * unsupported title script — redirects to the existing cover image as
 * the honest fallback, never a dead image URL and never a card that
 * silently drops the required title.
 */
#[Group('Share', 'Public generated share cards for events.')]
final class ShareCardController extends Controller
{
    public function __construct(
        private readonly ShareCardService $shareCards,
    ) {}

    #[Endpoint(
        title: 'Show a generated share card',
        description: 'Returns the 1200x630 generated PNG for a publicly reachable event, or redirects to the cover fallback when rendering is unavailable. Responses pinned to the current revision are immutable.',
    )]
    #[QueryParameter('occurrence', 'Optional occurrence id or slug. Defaults to the primary occurrence; an unknown key 404s.', required: false, type: 'string', infer: false, example: 'kuliah-maghrib-okt-12')]
    #[QueryParameter('locale', 'Card locale for shaping and chrome copy. Defaults to the application locale when supported, else Malay.', required: false, type: 'string', infer: false, example: 'ms')]
    #[QueryParameter('v', 'Content revision pin from the canonical card URL. Missing or stale pins redirect to the current URL.', required: false, type: 'string', infer: false, example: 'a1b2c3d4e5f60718')]
    #[QueryParameter('download', 'Set to 1 to download the card as an attachment instead of displaying it.', required: false, type: 'boolean', infer: false, example: 1)]
    public function show(Request $request, Event $event): Response
    {
        if (! $event->isPubliclyReachable()) {
            abort(404);
        }

        $localeParam = $request->query('locale');

        if (is_string($localeParam) && $localeParam !== '' && ! $this->shareCards->isSupportedLocale($localeParam)) {
            abort(404);
        }

        $locale = $this->shareCards->normalizeLocale(is_string($localeParam) ? $localeParam : null);
        $occurrenceParam = $request->query('occurrence');
        $occurrence = $this->shareCards->resolveOccurrence(
            $event,
            is_string($occurrenceParam) ? $occurrenceParam : null
        );

        if (is_string($occurrenceParam) && $occurrenceParam !== '' && ! $occurrence instanceof EventOccurrence) {
            abort(404);
        }

        $displayTimezone = $this->shareCards->resolveDisplayTimezone($event);
        $revision = $this->shareCards->contentRevision($event, $occurrence, $locale, $displayTimezone);
        $requestedRevision = $request->query('v');

        if (! is_string($requestedRevision) || $requestedRevision !== $revision) {
            $canonical = $this->shareCards->cardUrl(
                $event,
                is_string($occurrenceParam) && $occurrenceParam !== '' ? $occurrenceParam : null,
                $locale
            );

            if (is_string($canonical) && $request->boolean('download')) {
                $canonical .= '&download=1';
            }

            return redirect()->to((string) $canonical, 302);
        }

        try {
            $rendered = $this->shareCards->renderForRequest($event, $occurrence, $locale, $displayTimezone);
        } catch (Throwable) {
            // Reachable public boundary: any operational failure below
            // (storage, cache, presenter, render) falls back to cover art
            // with no-store, never a 500. The eligibility 404s above are
            // outside this boundary and are never caught here.
            return redirect()->to($this->shareCards->coverFallbackUrl($event), 302)
                ->header('Cache-Control', 'no-store');
        }

        if (! is_string($rendered['bytes'] ?? null) || $rendered['bytes'] === '') {
            // Honest fallback, never cached as a card: the next request
            // retries the render while crawlers keep a working image.
            return redirect()->to($this->shareCards->coverFallbackUrl($event), 302)
                ->header('Cache-Control', 'no-store');
        }

        $response = response($rendered['bytes'], 200, ['Content-Type' => 'image/png']);

        if ($request->boolean('download')) {
            $response->header(
                'Content-Disposition',
                'attachment; filename="'.$this->shareCards->downloadFilename($event).'"'
            );
        }

        return $response->header('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
