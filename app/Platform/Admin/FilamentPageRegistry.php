<?php

declare(strict_types=1);

namespace App\Platform\Admin;

use Filament\Pages\Page;
use InvalidArgumentException;

/**
 * Collects Filament pages registered by feature providers so panel
 * composition never imports concrete feature entrypoints.
 */
final class FilamentPageRegistry
{
    public const string PANEL_ADMIN = 'admin';

    public const string PANEL_AHLI = 'ahli';

    /** @var array<string, list<class-string<Page>>> */
    private array $pages = [];

    /**
     * @param  class-string<Page>  $page
     */
    public function register(string $page, string $panel = self::PANEL_ADMIN): void
    {
        if (! is_subclass_of($page, Page::class)) {
            throw new InvalidArgumentException(sprintf(
                'Registered Filament page must extend %s; %s given.',
                Page::class,
                $page,
            ));
        }

        if (! in_array($page, $this->pages[$panel] ?? [], true)) {
            $this->pages[$panel][] = $page;
        }
    }

    /**
     * @return list<class-string<Page>>
     */
    public function all(string $panel = self::PANEL_ADMIN): array
    {
        return $this->pages[$panel] ?? [];
    }
}
