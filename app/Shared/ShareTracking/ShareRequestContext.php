<?php

declare(strict_types=1);

namespace App\Shared\ShareTracking;

use Illuminate\Http\Request;

/**
 * HTTP boundary adapter for share tracking calls.
 *
 * ShareTracking needs the inbound request for cookies, query parameters,
 * client metadata and redirect behaviour, but that framework detail is not
 * part of its published API. Callers hand over an opaque context instead.
 */
final readonly class ShareRequestContext
{
    private function __construct(
        private Request $request,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self($request);
    }

    public static function current(): self
    {
        return self::fromRequest(request());
    }

    public function toRequest(): Request
    {
        return $this->request;
    }
}
