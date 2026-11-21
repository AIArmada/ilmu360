<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Contracts\Container\Container;
use RuntimeException;

final readonly class ExtensionRegistry
{
    /** @param array<string, list<class-string>> $slots */
    public function __construct(
        private Container $container,
        private array $slots,
    ) {}

    /** @return list<class-string> */
    public function classesFor(string $slot): array
    {
        return $this->slots[$slot] ?? [];
    }

    /** @return list<object> */
    public function resolve(string $slot, ?string $expectedInterface = null): array
    {
        $resolved = [];
        foreach ($this->classesFor($slot) as $class) {
            $instance = $this->container->make($class);
            if ($expectedInterface !== null && ! $instance instanceof $expectedInterface) {
                throw new RuntimeException("Extension {$class} in slot '{$slot}' must implement {$expectedInterface}.");
            }
            $resolved[] = $instance;
        }

        return $resolved;
    }

    /** @return list<string> */
    public function slots(): array
    {
        return array_keys($this->slots);
    }
}
