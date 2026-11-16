<?php

declare(strict_types=1);

namespace App\Forms\Components;

use Filament\Forms\Components\Select as FilamentSelect;

class Select extends FilamentSelect
{
    public function closeOnSelect(bool $condition = true): static
    {
        if ($condition) {
            $this->extraAttributes([
                'x-close-on-select' => true,
            ]);
        }

        return $this;
    }
}
