<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Actions;

use AIArmada\Addressing\Support\AddressCountryResolver;
use App\Features\DonationChannels\Api\Enums\DonationBankStatus;
use App\Features\DonationChannels\Models\DonationBank;
use App\Features\DonationChannels\Queries\DonationBankReferences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Create or update one country-scoped bank catalog entry.
 *
 * The global-admin-only policy is enforced here so no entrypoint can bypass
 * it. A referenced entry may be renamed, but its identifying country and code
 * stay locked because stored donation channels derive their payout identity
 * from them. Duplicate codes are rejected per country before the unique row
 * is written.
 */
final readonly class SaveDonationBankAction
{
    public function __construct(
        private AddressCountryResolver $countries,
        private DonationBankReferences $references,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?DonationBank $bank = null): DonationBank
    {
        $creating = ! $bank instanceof DonationBank;
        $bank ??= new DonationBank;

        Gate::authorize($creating ? 'create' : 'update', $creating ? DonationBank::class : $bank);

        $countryId = $this->countryId($data, $bank);
        $code = $this->code($data, $bank);
        $name = $this->name($data, $bank);
        $sourceUrl = $this->sourceUrl($data, $bank);

        if (! $creating && $this->references->exists($bank)) {
            if ($countryId !== (string) $bank->getAttribute('country_id')) {
                throw ValidationException::withMessages([
                    'country_id' => __('A bank that donation channels reference cannot change its country; retire it and add the replacement bank instead.'),
                ]);
            }

            if ($code !== (string) $bank->getAttribute('code')) {
                throw ValidationException::withMessages([
                    'code' => __('A bank that donation channels reference cannot change its code; retire it and add the replacement bank instead.'),
                ]);
            }
        }

        $this->assertUniqueCode($countryId, $code, $bank);

        return DB::transaction(function () use ($bank, $countryId, $code, $name, $sourceUrl, $creating): DonationBank {
            $bank->fill([
                'country_id' => $countryId,
                'code' => $code,
                'name' => $name,
                'source_url' => $sourceUrl,
            ]);

            if ($creating) {
                $bank->setAttribute('status', DonationBankStatus::Active);
            }

            $bank->save();

            return $bank->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function countryId(array $data, DonationBank $bank): string
    {
        $value = $data['country_id'] ?? $bank->getAttribute('country_id');
        $countryId = is_scalar($value) ? trim((string) $value) : '';

        if ($countryId === '') {
            throw ValidationException::withMessages([
                'country_id' => __('Select the country this bank belongs to.'),
            ]);
        }

        $country = $this->countries->resolve($countryId);

        if (! $country instanceof Model) {
            throw ValidationException::withMessages([
                'country_id' => __('The selected country is invalid.'),
            ]);
        }

        return (string) $country->getKey();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function code(array $data, DonationBank $bank): string
    {
        $value = $data['code'] ?? $bank->getAttribute('code');
        $code = is_scalar($value) ? mb_strtoupper(trim((string) $value)) : '';

        if ($code === '') {
            throw ValidationException::withMessages([
                'code' => __('Enter the bank code used by the country clearing system.'),
            ]);
        }

        if (preg_match('/^[A-Z0-9][A-Z0-9-]{0,31}$/', $code) !== 1) {
            throw ValidationException::withMessages([
                'code' => __('The bank code may only contain letters, digits and dashes.'),
            ]);
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function name(array $data, DonationBank $bank): string
    {
        $value = $data['name'] ?? $bank->getAttribute('name');
        $name = is_scalar($value) ? trim((string) $value) : '';

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('Enter the bank display name.'),
            ]);
        }

        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages([
                'name' => __('The bank display name may not be longer than 255 characters.'),
            ]);
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sourceUrl(array $data, DonationBank $bank): string
    {
        $value = $data['source_url'] ?? $bank->getAttribute('source_url');
        $url = is_scalar($value) ? trim((string) $value) : '';

        if ($url === '') {
            throw ValidationException::withMessages([
                'source_url' => __('Provide the authoritative source URL this bank was verified against.'),
            ]);
        }

        if (mb_strlen($url) > 2048) {
            throw ValidationException::withMessages([
                'source_url' => __('The source URL may not be longer than 2048 characters.'),
            ]);
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::withMessages([
                'source_url' => __('The source URL must be a valid http or https URL.'),
            ]);
        }

        return $url;
    }

    private function assertUniqueCode(string $countryId, string $code, DonationBank $bank): void
    {
        $exists = DonationBank::query()
            ->where('country_id', $countryId)
            ->where('code', $code)
            ->when(
                $bank->exists,
                static fn (Builder $query): Builder => $query->whereKeyNot($bank->getKey()),
            )
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => __('This country already has a bank with that code.'),
            ]);
        }
    }
}
