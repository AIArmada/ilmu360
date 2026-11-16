<?php

declare(strict_types=1);

use App\Support\Passport\PassportKeyProvisioner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use phpseclib4\Crypt\RSA;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('keeps valid raw passport keys in config', function (): void {
    $sandboxPath = storage_path('framework/testing/passport-raw-'.Str::uuid());
    File::ensureDirectoryExists($sandboxPath);

    $key = RSA::createKey(2048);

    $originalPrivateKey = config('passport.private_key');
    $originalPublicKey = config('passport.public_key');
    $originalKeyPath = Passport::keyPath('oauth-private.key');

    Passport::loadKeysFrom($sandboxPath);
    config()->set('passport.private_key', (string) $key);
    config()->set('passport.public_key', (string) $key->getPublicKey());

    try {
        PassportKeyProvisioner::ensure();

        expect(config('passport.private_key'))->toBe((string) $key)
            ->and(config('passport.public_key'))->toBe((string) $key->getPublicKey())
            ->and(File::exists(Passport::keyPath('oauth-private.key')))->toBeFalse()
            ->and(File::exists(Passport::keyPath('oauth-public.key')))->toBeFalse()
            ->and(app(AuthorizationServer::class))->toBeInstanceOf(AuthorizationServer::class);
    } finally {
        config()->set('passport.private_key', $originalPrivateKey);
        config()->set('passport.public_key', $originalPublicKey);
        Passport::loadKeysFrom(dirname($originalKeyPath));
        File::deleteDirectory($sandboxPath);
    }
});

it('normalizes configured passport key file paths', function (): void {
    $sandboxPath = storage_path('framework/testing/passport-path-'.Str::uuid());
    File::ensureDirectoryExists($sandboxPath);

    $key = RSA::createKey(2048);
    $privatePath = $sandboxPath.'/oauth-private.key';
    $publicPath = $sandboxPath.'/oauth-public.key';

    File::put($privatePath, (string) $key);
    File::put($publicPath, (string) $key->getPublicKey());

    $originalPrivateKey = config('passport.private_key');
    $originalPublicKey = config('passport.public_key');
    $originalKeyPath = Passport::keyPath('oauth-private.key');

    Passport::loadKeysFrom($sandboxPath);
    config()->set('passport.private_key', $privatePath);
    config()->set('passport.public_key', $publicPath);

    try {
        PassportKeyProvisioner::ensure();

        expect(config('passport.private_key'))->toBe((string) $key)
            ->and(config('passport.public_key'))->toBe((string) $key->getPublicKey())
            ->and(app(AuthorizationServer::class))->toBeInstanceOf(AuthorizationServer::class);
    } finally {
        config()->set('passport.private_key', $originalPrivateKey);
        config()->set('passport.public_key', $originalPublicKey);
        Passport::loadKeysFrom(dirname($originalKeyPath));
        File::deleteDirectory($sandboxPath);
    }
});

it('generates passport keys when none exist', function (): void {
    $sandboxPath = storage_path('framework/testing/passport-generate-'.Str::uuid());
    File::ensureDirectoryExists($sandboxPath);

    $originalPrivateKey = config('passport.private_key');
    $originalPublicKey = config('passport.public_key');
    $originalKeyPath = Passport::keyPath('oauth-private.key');

    Passport::loadKeysFrom($sandboxPath);
    config()->set('passport.private_key');
    config()->set('passport.public_key');

    try {
        PassportKeyProvisioner::ensure();

        expect(File::exists(Passport::keyPath('oauth-private.key')))->toBeTrue()
            ->and(File::exists(Passport::keyPath('oauth-public.key')))->toBeTrue()
            ->and(config('passport.private_key'))->toContain('BEGIN PRIVATE KEY')
            ->and(config('passport.public_key'))->toContain('BEGIN PUBLIC KEY')
            ->and(app(AuthorizationServer::class))->toBeInstanceOf(AuthorizationServer::class);
    } finally {
        config()->set('passport.private_key', $originalPrivateKey);
        config()->set('passport.public_key', $originalPublicKey);
        Passport::loadKeysFrom(dirname($originalKeyPath));
        File::deleteDirectory($sandboxPath);
    }
});
