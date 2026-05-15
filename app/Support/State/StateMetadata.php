<?php

namespace App\Support\State;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;

trait StateMetadata
{
    /**
     * @param  Model|class-string<Model>  $model
     * @return array<string, string>
     */
    public static function getStatesLabel(Model|string $model): array
    {
        $modelInstance = static::resolveModelInstance($model);

        return self::getStateMapping()->mapWithKeys(function (string $stateClass) use ($modelInstance): array {
            $state = new $stateClass($modelInstance);
            $morphClass = $stateClass::getMorphClass();

            return [
                $morphClass => $state instanceof HasLabel
                    ? $state->getLabel()
                    : $morphClass,
            ];
        })->toArray();
    }

    /**
     * @param  Model|class-string<Model>  $model
     * @return array<string, string|array<string, mixed>|null>
     */
    public static function getStatesColor(Model|string $model): array
    {
        $modelInstance = static::resolveModelInstance($model);

        return self::getStateMapping()->mapWithKeys(function (string $stateClass) use ($modelInstance): array {
            $state = new $stateClass($modelInstance);

            return [
                $stateClass::getMorphClass() => $state instanceof HasColor
                    ? $state->getColor()
                    : null,
            ];
        })->toArray();
    }

    /**
     * @param  Model|class-string<Model>  $model
     * @return array<string, string|null>
     */
    public static function getStatesDescription(Model|string $model): array
    {
        $modelInstance = static::resolveModelInstance($model);

        return self::getStateMapping()->mapWithKeys(function (string $stateClass) use ($modelInstance): array {
            $state = new $stateClass($modelInstance);

            return [
                $stateClass::getMorphClass() => $state instanceof HasDescription
                    ? $state->getDescription()
                    : null,
            ];
        })->toArray();
    }

    /**
     * @param  Model|class-string<Model>  $model
     * @return array<string, string|null>
     */
    public static function getStatesIcon(Model|string $model): array
    {
        $modelInstance = static::resolveModelInstance($model);

        return self::getStateMapping()->mapWithKeys(function (string $stateClass) use ($modelInstance): array {
            $state = new $stateClass($modelInstance);

            return [
                $stateClass::getMorphClass() => $state instanceof HasIcon
                    ? $state->getIcon()
                    : null,
            ];
        })->toArray();
    }

    /**
     * @param  Model|class-string<Model>  $model
     */
    protected static function resolveModelInstance(Model|string $model): Model
    {
        if ($model instanceof Model) {
            return $model;
        }

        return app($model);
    }
}
