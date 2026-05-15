<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Str;

final class ApiSecurityRequirementExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if ($this->operationHasSecurity($operation) || ! $this->routeRequiresAuthentication($routeInfo)) {
            return;
        }

        $operation->addSecurity(new SecurityRequirement(['sanctumBearer' => []]));
    }

    private function operationHasSecurity(Operation $operation): bool
    {
        return count($operation->security ?? []) > 0;
    }

    private function routeRequiresAuthentication(RouteInfo $routeInfo): bool
    {
        foreach ($routeInfo->route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if ($middleware === 'auth' || Str::startsWith($middleware, 'auth:')) {
                return true;
            }

            if ($middleware === Authenticate::class || Str::startsWith($middleware, Authenticate::class.':')) {
                return true;
            }
        }

        return false;
    }
}
