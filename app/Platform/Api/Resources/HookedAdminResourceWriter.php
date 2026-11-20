<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use App\Models\User;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Admin\Contracts\AdminMutationHooks;
use Closure;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Admin writer for owner resources whose domain behaviour is published through
 * the existing {@see AdminMutationHooks} contract.
 */
class HookedAdminResourceWriter extends AbstractAdminResourceWriter
{
    /**
     * @param  class-string<resource>  $resourceClass
     * @param  (Closure(Model): array<string, mixed>)|null  $recordDefaults
     */
    public function __construct(
        ResourceDefinitionContext $context,
        string $resourceClass,
        private readonly AdminMutationHookRegistry $hooks,
        private readonly ?Closure $recordDefaults = null,
    ) {
        parent::__construct($context, $resourceClass);
    }

    /** @return array<string, mixed> */
    public function schemaFor(string $resourceKey, string $operation, ?Model $record): array
    {
        $updating = $operation === 'update';
        $defaults = $updating && $record instanceof Model
            ? $this->defaultsForRecord($record)
            : $this->defaultsForCreate();

        $schema = $this->hook()->schema($this->resourceClass, $resourceKey, $operation, $record, $defaults);

        if ($schema !== null) {
            return $schema;
        }

        throw new RuntimeException('Unsupported admin write resource.');
    }

    /** @return array<string, mixed> */
    public function rulesFor(bool $updating): array
    {
        return $this->hook()->rules($this->resourceClass, $updating) ?? [];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeFor(array $validated, ?Model $record): array
    {
        return $this->hook()->normalize($this->resourceClass, $validated, $record);
    }

    /** @return array<string, mixed> */
    public function defaultsForCreate(): array
    {
        return $this->hook()->defaultsForCreate($this->resourceClass) ?? [];
    }

    /** @return array<string, mixed> */
    public function defaultsForRecord(Model $record): array
    {
        $defaults = $this->recordDefaults instanceof Closure
            ? (($this->recordDefaults)($record) ?? [])
            : [];

        foreach ($this->hooks->all() as $hooks) {
            $defaults = $hooks->augmentDefaultsForRecord($record, $defaults);
        }

        return $defaults;
    }

    /** @param array<string, mixed> $validated */
    public function storeModel(User $actor, array $validated): Model
    {
        $record = $this->hook()->store($this->resourceClass, $validated, $actor);

        if (! $record instanceof Model) {
            throw new RuntimeException('Unsupported admin write resource.');
        }

        return $record;
    }

    /** @param array<string, mixed> $validated */
    public function updateModel(User $actor, Model $record, array $validated): Model
    {
        $updated = $this->hook()->update($this->resourceClass, $record, $validated, $actor);

        if (! $updated instanceof Model) {
            throw new RuntimeException('Unsupported admin write resource.');
        }

        return $updated;
    }

    private function hook(): AdminMutationHooks
    {
        $hook = $this->hooks->for($this->resourceClass);

        if (! $hook instanceof AdminMutationHooks) {
            throw new RuntimeException('Unsupported admin write resource.');
        }

        return $hook;
    }
}
