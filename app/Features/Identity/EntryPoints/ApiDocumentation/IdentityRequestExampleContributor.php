<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\RequestExampleContributor;

final class IdentityRequestExampleContributor implements RequestExampleContributor
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function examples(): array
    {
        return [
            'post auth/login' => [
                'login' => 'superadmin@ilmu360.com',
                'password' => 'password',
                'device_name' => 'OpenClaw Production',
            ],
            'post auth/register' => [
                'name' => 'OpenClaw User',
                'email' => 'openclaw@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'device_name' => 'OpenClaw iPhone',
            ],
            'put account-settings' => [
                'name' => 'Super Admin',
                'email' => 'superadmin@ilmu360.com',
                'phone' => '+60111222333',
                'timezone' => 'Asia/Kuala_Lumpur',
                'daily_prayer_institution_ids' => [],
                'friday_prayer_institution_ids' => [],
            ],
        ];
    }
}
