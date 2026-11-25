<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Core text-search, GIN/GiST indexing, and aiarmada/addressing spatial extensions.
     * Order matters: `postgis` and `h3` must precede `h3_postgis`.
     *
     * @var list<string>
     */
    private const array EXTENSIONS = [
        'pg_trgm',
        'unaccent',
        'btree_gin',
        'btree_gist',
        'postgis',
        'h3',
        'h3_postgis',
    ];

    public function up(): void
    {
        $this->enableExtensions(Schema::getConnection());
    }

    public function enableExtensions(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        if ($connection->pretending()) {
            foreach (self::EXTENSIONS as $extension) {
                $connection->statement("CREATE EXTENSION IF NOT EXISTS \"{$extension}\"");
            }

            return;
        }

        $installed = [];

        foreach ($connection->select('SELECT extname FROM pg_extension') as $row) {
            if (is_object($row) && isset($row->extname) && is_string($row->extname)) {
                $installed[$row->extname] = true;
            }
        }

        $isSuperuser = (bool) $connection->scalar('SELECT rolsuper FROM pg_roles WHERE rolname = current_user');

        $placeholders = implode(', ', array_fill(0, count(self::EXTENSIONS), '?'));
        $available = [];

        $rows = $connection->select(
            "SELECT e.name, v.trusted FROM pg_available_extensions AS e JOIN pg_available_extension_versions AS v ON v.name = e.name AND v.version = e.default_version WHERE e.name IN ({$placeholders})",
            self::EXTENSIONS,
        );

        foreach ($rows as $row) {
            if (is_object($row) && isset($row->name) && is_string($row->name)) {
                $available[$row->name] = (bool) ($row->trusted ?? false);
            }
        }

        foreach (self::EXTENSIONS as $extension) {
            if (isset($installed[$extension])) {
                continue;
            }

            $trusted = $available[$extension] ?? null;

            if ($trusted !== null && ($isSuperuser || $trusted)) {
                $connection->statement("CREATE EXTENSION IF NOT EXISTS \"{$extension}\"");

                continue;
            }

            $message = $trusted === null
                ? "PostgreSQL extension [{$extension}] is not available on this host. Install its server package and run: CREATE EXTENSION IF NOT EXISTS \"{$extension}\";"
                : "PostgreSQL extension [{$extension}] is not trusted for non-superuser creation. Have a PostgreSQL superuser run: CREATE EXTENSION IF NOT EXISTS \"{$extension}\";";

            if ($this->isExtensionRequired($extension)) {
                throw new RuntimeException($message);
            }

            Log::warning($message, ['extension' => $extension]);
        }
    }

    private function isExtensionRequired(string $extension): bool
    {
        return match ($extension) {
            'pg_trgm' => true,
            'postgis' => config('addressing.features.spatial.postgis') === true
                || config('addressing.features.spatial.geohash') === true,
            'h3' => config('addressing.features.spatial.h3') === true,
            default => false,
        };
    }
};
