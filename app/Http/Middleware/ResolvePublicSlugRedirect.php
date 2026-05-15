<?php

namespace App\Http\Middleware;

use App\Actions\Slugs\ResolvePublicSlugAction;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Models\SlugRedirect;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolvePublicSlugRedirect
{
    public function __construct(
        private readonly ResolvePublicSlugAction $resolvePublicSlugAction,
        private readonly SyncSlugRedirectAction $syncSlugRedirectAction,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        foreach (['event', 'institution', 'speaker', 'venue', 'reference'] as $parameter) {
            if (! $route->hasParameter($parameter)) {
                continue;
            }

            /** @var array{model: Model, redirect_to: string|null, slug_redirect: SlugRedirect|null}|null $resolvedFromBinding */
            $resolvedFromBinding = $request->attributes->get("public_slug_resolution.{$parameter}");

            if (is_array($resolvedFromBinding)) {
                if ($resolvedFromBinding['redirect_to'] !== null && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
                    if ($resolvedFromBinding['slug_redirect'] !== null) {
                        $this->syncSlugRedirectAction->markRedirectUsed($resolvedFromBinding['slug_redirect']);
                    }

                    return redirect()->to($resolvedFromBinding['redirect_to'], 301);
                }

                $route->setParameter($parameter, $resolvedFromBinding['model']);

                continue;
            }

            $value = $route->parameter($parameter);

            if ($value instanceof Model) {
                continue;
            }

            if (! is_string($value) || trim($value) === '') {
                abort(404);
            }

            $resolved = $this->resolvePublicSlugAction->handle($parameter, trim($value));

            if ($resolved['redirect_to'] !== null && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
                if ($resolved['slug_redirect'] !== null) {
                    $this->syncSlugRedirectAction->markRedirectUsed($resolved['slug_redirect']);
                }

                return redirect()->to($resolved['redirect_to'], 301);
            }

            $route->setParameter($parameter, $resolved['model']);
        }

        return $next($request);
    }
}
