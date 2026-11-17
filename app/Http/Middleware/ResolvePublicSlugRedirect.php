<?php

namespace App\Http\Middleware;

use App\Features\SlugRedirects\Api\PublicSlugLookup;
use App\Features\SlugRedirects\Api\SlugRedirectCommands;
use App\Platform\Routing\PublicSlugRegistry;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolvePublicSlugRedirect
{
    public function __construct(
        private readonly PublicSlugLookup $publicSlugLookup,
        private readonly SlugRedirectCommands $slugRedirectCommands,
        private readonly PublicSlugRegistry $publicSlugRegistry,
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

        foreach ($this->publicSlugRegistry->parameters() as $parameter) {
            if (! $route->hasParameter($parameter)) {
                continue;
            }

            /** @var array{model: Model, redirect_to: string|null, slug_redirect_id: string|null}|null $resolvedFromBinding */
            $resolvedFromBinding = $request->attributes->get("public_slug_resolution.{$parameter}");

            if (is_array($resolvedFromBinding)) {
                if ($resolvedFromBinding['redirect_to'] !== null && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
                    if ($resolvedFromBinding['slug_redirect_id'] !== null) {
                        $this->slugRedirectCommands->markUsed($resolvedFromBinding['slug_redirect_id']);
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

            $resolved = $this->publicSlugLookup->resolve($parameter, trim($value));

            if ($resolved->redirectTo !== null && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
                if ($resolved->slugRedirectId !== null) {
                    $this->slugRedirectCommands->markUsed($resolved->slugRedirectId);
                }

                return redirect()->to($resolved->redirectTo, 301);
            }

            $resource = $this->publicSlugRegistry->resourceForType($resolved->modelType);

            if (! $resource instanceof \App\Platform\Routing\PublicSlugResource) {
                abort(404);
            }

            $route->setParameter($parameter, $resource->modelClass::query()->findOrFail($resolved->modelId));
        }

        return $next($request);
    }
}
