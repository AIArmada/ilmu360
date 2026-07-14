<?php

namespace App\Settings;

use Illuminate\Support\Str;
use Spatie\LaravelSettings\SettingsRepositories\DatabaseSettingsRepository;

class UuidDatabaseSettingsRepository extends DatabaseSettingsRepository
{
    /**
     * @param  mixed  $payload
     */
    #[\Override]
    public function createProperty(string $group, string $name, $payload, bool $locked = false): void
    {
        $this->getBuilder()->create([
            'id' => (string) Str::uuid(),
            'group' => $group,
            'name' => $name,
            'payload' => $this->encode($payload),
            'locked' => $locked,
        ]);
    }
}
