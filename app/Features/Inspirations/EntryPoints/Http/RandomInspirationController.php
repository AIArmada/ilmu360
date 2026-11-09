<?php

declare(strict_types=1);

namespace App\Features\Inspirations\EntryPoints\Http;

use App\Features\Inspirations\Api\InspirationReader;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class RandomInspirationController extends Controller
{
    public function __construct(
        private readonly InspirationReader $inspirationReader,
    ) {}

    #[Group('Inspiration', 'Public inspiration discovery endpoints for random featured inspiration content.')]
    #[Endpoint(
        title: 'Get a random inspiration',
        description: 'Returns one random active inspiration record, localized when a `locale` query value is provided.',
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $locale = $this->normalizedLocale($request->query('locale'));
        $card = $this->inspirationReader->random($locale);

        return response()->json([
            'data' => $card?->toArray(),
            'meta' => [
                'locale' => $locale ?? app()->getLocale(),
            ],
        ]);
    }

    private function normalizedLocale(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
