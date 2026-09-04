<?php

declare(strict_types=1);

namespace App\Support\Events;

use Illuminate\Support\Facades\Crypt;

final class ParticipantIdentity
{
    /**
     * @return array{type: string, value_encrypted: string, lookup_hash: string, last4: string}
     */
    public static function protect(string $type, string $value): array
    {
        $normalized = self::normalize($value);

        return [
            'type' => $type,
            'value_encrypted' => Crypt::encryptString($value),
            'lookup_hash' => self::lookupHash($normalized),
            'last4' => mb_substr($normalized, -4),
        ];
    }

    public static function normalize(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }

    public static function lookupHash(string $normalizedValue): string
    {
        return hash_hmac('sha256', $normalizedValue, (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>|null  $identity
     */
    public static function masked(?array $identity): ?string
    {
        if (! is_array($identity)) {
            return null;
        }

        $last4 = (string) ($identity['last4'] ?? '');

        return $last4 === '' ? null : '••••'.$last4;
    }
}
