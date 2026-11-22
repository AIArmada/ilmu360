<?php

namespace App\Platform\Diagnostics\EntryPoints\Http\Requests;

use App\Platform\Diagnostics\NetworkDiagnosticsEnvironment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class NetworkDiagnosticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->diagnosticsEnabled()) {
            return false;
        }

        $configuredToken = trim((string) $this->envString('NETWORK_DIAGNOSTICS_TOKEN', ''));

        if ($configuredToken === '') {
            return false;
        }

        $providedToken = $this->header('X-Diagnostics-Token') ?? $this->query('token');

        return is_string($providedToken) && $providedToken !== '' && hash_equals($configuredToken, $providedToken);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['nullable', 'string'],
        ];
    }

    /**
     * @throws AuthorizationException
     */
    protected function failedAuthorization(): void
    {
        if (! $this->diagnosticsEnabled()) {
            throw new AuthorizationException('Network diagnostics are disabled for this environment.');
        }

        if (trim((string) $this->envString('NETWORK_DIAGNOSTICS_TOKEN', '')) === '') {
            throw new AuthorizationException('A diagnostics token must be configured to access this route.');
        }

        throw new AuthorizationException('A valid diagnostics token is required to access this route.');
    }

    protected function diagnosticsEnabled(): bool
    {
        return $this->envBoolean('NETWORK_DIAGNOSTICS_ENABLED', false);
    }

    protected function envBoolean(string $key, bool $default = false): bool
    {
        return $this->diagnosticsEnvironment()->boolean($key, $default);
    }

    protected function envString(string $key, ?string $default = null): ?string
    {
        return $this->diagnosticsEnvironment()->string($key, $default);
    }

    protected function diagnosticsEnvironment(): NetworkDiagnosticsEnvironment
    {
        return app(NetworkDiagnosticsEnvironment::class);
    }
}
