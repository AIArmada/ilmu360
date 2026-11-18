<?php

declare(strict_types=1);

namespace App\Platform\Admin\Filament\Stats;

final readonly class AdminStat
{
    public function __construct(
        public string $label,
        public int $count,
        public string $description,
        public ?string $url = null,
        public int $sort = 0,
    ) {}
}
