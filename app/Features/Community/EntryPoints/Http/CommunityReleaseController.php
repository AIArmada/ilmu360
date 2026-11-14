<?php

declare(strict_types=1);

namespace App\Features\Community\EntryPoints\Http;

use App\Features\Community\Queries\CommunityReleaseReader;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Community', 'Public community release aggregates.')]
final class CommunityReleaseController
{
    #[Endpoint(title: 'Get the latest public community release')]
    public function __invoke(CommunityReleaseReader $reader): JsonResponse
    {
        return response()->json(['data' => $reader->latest()]);
    }
}
