<?php

declare(strict_types=1);

namespace App\Platform\Mcp;

use InvalidArgumentException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;

/**
 * Collects the admin and member MCP surfaces registered by feature providers
 * so server classes can compose their capabilities without importing concrete
 * feature entrypoints.
 *
 * Owners register in provider boot order; each audience keeps its own ordered
 * lists so a feature can contribute to one surface without affecting the other.
 */
final class McpSurfaceRegistry
{
    public const string ADMIN = 'admin';

    public const string MEMBER = 'member';

    /** @var array<string, list<class-string<Tool>>> */
    private array $tools = [self::ADMIN => [], self::MEMBER => []];

    /** @var array<string, list<class-string<Prompt>>> */
    private array $prompts = [self::ADMIN => [], self::MEMBER => []];

    /** @var array<string, list<class-string<resource>>> */
    private array $resources = [self::ADMIN => [], self::MEMBER => []];

    /** @var array<string, array<string, class-string<Method>>> */
    private array $methods = [self::ADMIN => [], self::MEMBER => []];

    /**
     * @param  class-string<Tool>  $tool
     */
    public function registerAdminTool(string $tool): void
    {
        $this->registerTool(self::ADMIN, $tool);
    }

    /**
     * @param  class-string<Tool>  $tool
     */
    public function registerMemberTool(string $tool): void
    {
        $this->registerTool(self::MEMBER, $tool);
    }

    /**
     * @param  class-string<Prompt>  $prompt
     */
    public function registerAdminPrompt(string $prompt): void
    {
        $this->registerPrompt(self::ADMIN, $prompt);
    }

    /**
     * @param  class-string<Prompt>  $prompt
     */
    public function registerMemberPrompt(string $prompt): void
    {
        $this->registerPrompt(self::MEMBER, $prompt);
    }

    /**
     * @param  class-string<resource>  $resource
     */
    public function registerAdminResource(string $resource): void
    {
        $this->registerResource(self::ADMIN, $resource);
    }

    /**
     * @param  class-string<resource>  $resource
     */
    public function registerMemberResource(string $resource): void
    {
        $this->registerResource(self::MEMBER, $resource);
    }

    /**
     * Replace a JSON-RPC method handler for the admin surface.
     *
     * @param  class-string<Method>  $handler
     */
    public function registerAdminMethod(string $method, string $handler): void
    {
        $this->registerMethod(self::ADMIN, $method, $handler);
    }

    /**
     * Replace a JSON-RPC method handler for the member surface.
     *
     * @param  class-string<Method>  $handler
     */
    public function registerMemberMethod(string $method, string $handler): void
    {
        $this->registerMethod(self::MEMBER, $method, $handler);
    }

    /**
     * @return list<class-string<Tool>>
     */
    public function adminTools(): array
    {
        return $this->tools[self::ADMIN];
    }

    /**
     * @return list<class-string<Tool>>
     */
    public function memberTools(): array
    {
        return $this->tools[self::MEMBER];
    }

    /**
     * @return list<class-string<Prompt>>
     */
    public function adminPrompts(): array
    {
        return $this->prompts[self::ADMIN];
    }

    /**
     * @return list<class-string<Prompt>>
     */
    public function memberPrompts(): array
    {
        return $this->prompts[self::MEMBER];
    }

    /**
     * @return list<class-string<resource>>
     */
    public function adminResources(): array
    {
        return $this->resources[self::ADMIN];
    }

    /**
     * @return list<class-string<resource>>
     */
    public function memberResources(): array
    {
        return $this->resources[self::MEMBER];
    }

    /**
     * @return array<string, class-string<Method>>
     */
    public function adminMethods(): array
    {
        return $this->methods[self::ADMIN];
    }

    /**
     * @return array<string, class-string<Method>>
     */
    public function memberMethods(): array
    {
        return $this->methods[self::MEMBER];
    }

    private function registerTool(string $audience, string $tool): void
    {
        if (! is_subclass_of($tool, Tool::class)) {
            throw new InvalidArgumentException(sprintf(
                'Registered MCP tool must extend %s; %s given.',
                Tool::class,
                $tool,
            ));
        }

        /** @var class-string<Tool> $toolClass */
        $toolClass = $tool;

        if (! in_array($toolClass, $this->tools[$audience], true)) {
            $this->tools[$audience][] = $toolClass;
        }
    }

    private function registerPrompt(string $audience, string $prompt): void
    {
        if (! is_subclass_of($prompt, Prompt::class)) {
            throw new InvalidArgumentException(sprintf(
                'Registered MCP prompt must extend %s; %s given.',
                Prompt::class,
                $prompt,
            ));
        }

        /** @var class-string<Prompt> $promptClass */
        $promptClass = $prompt;

        if (! in_array($promptClass, $this->prompts[$audience], true)) {
            $this->prompts[$audience][] = $promptClass;
        }
    }

    private function registerResource(string $audience, string $resource): void
    {
        if (! is_subclass_of($resource, Resource::class)) {
            throw new InvalidArgumentException(sprintf(
                'Registered MCP resource must extend %s; %s given.',
                Resource::class,
                $resource,
            ));
        }

        /** @var class-string<resource> $resourceClass */
        $resourceClass = $resource;

        if (! in_array($resourceClass, $this->resources[$audience], true)) {
            $this->resources[$audience][] = $resourceClass;
        }
    }

    private function registerMethod(string $audience, string $method, string $handler): void
    {
        if (! is_subclass_of($handler, Method::class)) {
            throw new InvalidArgumentException(sprintf(
                'Registered MCP method handler must implement %s; %s given.',
                Method::class,
                $handler,
            ));
        }

        /** @var class-string<Method> $handlerClass */
        $handlerClass = $handler;

        $this->methods[$audience][$method] = $handlerClass;
    }
}
