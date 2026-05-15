<?php

namespace App\Filament\Tables\Filters;

use Closure;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Spatie\ModelStates\State;

class ModelStateSelectFilter extends SelectFilter
{
    protected string|Closure|null $attribute = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options(function (Table $table): array {
            $modelClass = $table->getModel();
            $model = app($modelClass);
            $attribute = $this->getAttribute();
            $castClass = $model->getCasts()[$attribute] ?? null;

            if (! is_string($castClass) || ! class_exists($castClass) || ! is_subclass_of($castClass, State::class)) {
                return [];
            }

            if (! method_exists($castClass, 'getStatesLabel')) {
                return [];
            }

            return $castClass::getStatesLabel($modelClass);
        });
    }

    #[\Override]
    public function attribute(string|Closure|null $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    #[\Override]
    public function getAttribute(): string
    {
        return $this->evaluate($this->attribute ?? $this->getName());
    }
}
