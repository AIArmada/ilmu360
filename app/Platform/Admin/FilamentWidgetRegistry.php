<?php

declare(strict_types=1);

namespace App\Platform\Admin;

use Filament\Widgets\Widget;
use InvalidArgumentException;

/**
 * Collects Filament dashboard widgets registered by feature providers so
 * panel composition never imports concrete feature entrypoints.
 */
final class FilamentWidgetRegistry
{
    public const string PANEL_ADMIN = 'admin';

    public const string PANEL_AHLI = 'ahli';

    /** @var array<string, list<class-string<Widget>>> */
    private array $widgets = [];

    /**
     * @param  class-string<Widget>  $widget
     */
    public function register(string $widget, string $panel = self::PANEL_ADMIN): void
    {
        if (! is_subclass_of($widget, Widget::class)) {
            throw new InvalidArgumentException(sprintf(
                'Registered Filament widget must extend %s; %s given.',
                Widget::class,
                $widget,
            ));
        }

        if (! in_array($widget, $this->widgets[$panel] ?? [], true)) {
            $this->widgets[$panel][] = $widget;
        }
    }

    /**
     * @return list<class-string<Widget>>
     */
    public function all(string $panel = self::PANEL_ADMIN): array
    {
        return $this->widgets[$panel] ?? [];
    }
}
