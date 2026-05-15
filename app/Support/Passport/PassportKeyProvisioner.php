<?php

declare(strict_types=1);

namespace App\Support\Passport;

use Illuminate\Support\Facades\File;
use Laravel\Passport\Passport;
use League\OAuth2\Server\CryptKey;
use phpseclib3\Crypt\RSA;
use Throwable;

final class PassportKeyProvisioner
{
    public static function ensure(): void
    {
        $privateKey = config('passport.private_key');
        $publicKey = config('passport.public_key');

        if (self::hasUsableRawKeyPair($privateKey, $publicKey)) {
            return;
        }

        if (self::loadKeyPairFromConfiguredPaths($privateKey, $publicKey)) {
            return;
        }

        if (self::loadKeyPairFromDefaultPaths()) {
            return;
        }

        self::generateKeyPair();
    }

    private static function hasUsableRawKeyPair(mixed $privateKey, mixed $publicKey): bool
    {
        if (! is_string($privateKey) || ! is_string($publicKey)) {
            return false;
        }

        if (trim($privateKey) === '' || trim($publicKey) === '') {
            return false;
        }

        return self::isValidRawKey($privateKey) && self::isValidRawKey($publicKey);
    }

    private static function loadKeyPairFromConfiguredPaths(mixed $privateKey, mixed $publicKey): bool
    {
        $privatePath = self::resolveFilePath($privateKey);
        $publicPath = self::resolveFilePath($publicKey);

        if (! is_string($privatePath) || ! is_string($publicPath)) {
            return false;
        }

        return self::loadKeyPairFromPaths($privatePath, $publicPath);
    }

    private static function loadKeyPairFromDefaultPaths(): bool
    {
        return self::loadKeyPairFromPaths(
            Passport::keyPath('oauth-private.key'),
            Passport::keyPath('oauth-public.key'),
        );
    }

    private static function loadKeyPairFromPaths(string $privatePath, string $publicPath): bool
    {
        if (! File::exists($privatePath) || ! File::exists($publicPath)) {
            return false;
        }

        try {
            $privateKey = File::get($privatePath);
            $publicKey = File::get($publicPath);
        } catch (Throwable) {
            return false;
        }

        if (! self::isValidRawKey($privateKey) || ! self::isValidRawKey($publicKey)) {
            return false;
        }

        config()->set('passport.private_key', $privateKey);
        config()->set('passport.public_key', $publicKey);

        return true;
    }

    private static function generateKeyPair(): void
    {
        $privatePath = Passport::keyPath('oauth-private.key');
        $publicPath = Passport::keyPath('oauth-public.key');

        File::ensureDirectoryExists(dirname($privatePath));

        $lockPath = dirname($privatePath).DIRECTORY_SEPARATOR.'.passport-key.lock';
        $lockHandle = @fopen($lockPath, 'c+');

        if ($lockHandle === false) {
            self::writeKeyPair($privatePath, $publicPath);

            return;
        }

        try {
            if (! flock($lockHandle, LOCK_EX)) {
                self::writeKeyPair($privatePath, $publicPath);

                return;
            }

            if (self::loadKeyPairFromDefaultPaths()) {
                return;
            }

            self::writeKeyPair($privatePath, $publicPath);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private static function writeKeyPair(string $privatePath, string $publicPath): void
    {
        $key = RSA::createKey(4096);
        $publicKey = (string) $key->getPublicKey();
        $privateKey = (string) $key;

        File::put($publicPath, $publicKey);
        File::put($privatePath, $privateKey);

        if (! windows_os()) {
            chmod($publicPath, 0660);
            chmod($privatePath, 0600);
        }

        config()->set('passport.private_key', $privateKey);
        config()->set('passport.public_key', $publicKey);
    }

    private static function resolveFilePath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $path = trim($value);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'file://')) {
            $path = substr($path, 7);
        }

        return is_file($path) ? $path : null;
    }

    private static function isValidRawKey(string $key): bool
    {
        try {
            new CryptKey($key, null, Passport::$validateKeyPermissions);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
