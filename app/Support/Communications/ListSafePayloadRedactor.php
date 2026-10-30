<?php

declare(strict_types=1);

namespace App\Support\Communications;

use AIArmada\Communications\Contracts\PayloadRedactor;
use AIArmada\Communications\Support\PayloadRedactorService;

/**
 * Payload redactor that tolerates list values.
 *
 * Notification metadata legitimately contains lists (matched entity labels,
 * saved search ids, changed fields). The package redactor assumes every key
 * is a string and throws a TypeError on integer keys, which breaks inbox
 * writes for any notification carrying list metadata. Integer keys can never
 * match sensitive key names, so they pass through untouched while every
 * string-keyed scalar is still judged by the package implementation.
 *
 * A sensitive key redacts its whole subtree before any value is visited, so
 * array-valued shapes (HeaderBag::all(), nested transports) under a
 * sensitive name can never leak through recursion.
 */
final class ListSafePayloadRedactor implements PayloadRedactor
{
    /**
     * App-level sensitive key substrings beyond the package list.
     *
     * Covers the guest share credential in every transport shape (issued
     * payload field, POST body input, dedicated request header, stored
     * verifier column) plus the single-purpose guest claim capability in
     * its canonical transports (POST body input, request header, claim
     * cookie, stored verifier column). Deliberately narrow: generic words
     * such as "claim" or "capability" alone would swallow unrelated
     * diagnostics.
     */
    private const ADDITIONAL_SENSITIVE_SUBSTRINGS = [
        'credential',
        'guest_claim',
        'claim_capability',
        'claim-capability',
        'verifier_hash',
    ];

    /**
     * Mirror of the package redactor's sensitive list, used only to redact
     * array-valued subtrees before visiting their values. Scalar package
     * keys keep delegating to the package implementation below, so package
     * behavior stays canonical; this list only closes the recurse-before-
     * check gap for package-sensitive parents at this boundary.
     */
    private const PACKAGE_SENSITIVE_SUBSTRINGS = [
        'password', 'secret', 'token', 'authorization', 'api_key', 'api_secret',
        'access_key', 'private_key', 'credit_card', 'cvv', 'ssn', 'sin',
        'bank_account', 'routing_number', 'pin', 'passcode',
    ];

    public function __construct(
        private readonly PayloadRedactorService $inner,
    ) {}

    /**
     * @param  array<int|string, mixed>  $payload
     * @return array<int|string, mixed>
     */
    public function redact(array $payload): array
    {
        return $this->redactArray($payload);
    }

    /**
     * @param  array<int|string, mixed>  $request
     * @return array<int|string, mixed>
     */
    public function redactRequest(array $request): array
    {
        return $this->redactArray($request);
    }

    /**
     * @param  array<int|string, mixed>  $response
     * @return array<int|string, mixed>
     */
    public function redactResponse(array $response): array
    {
        return $this->redactArray($response);
    }

    public function redactText(?string $text): ?string
    {
        return $this->inner->redactText($text);
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private function redactArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = '**[REDACTED]**';

                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->redactArray($value);

                continue;
            }

            if (! is_string($key)) {
                $result[$key] = $value;

                continue;
            }

            $redacted = $this->inner->redact([$key => $value]);
            $result[$key] = $redacted[$key] ?? $value;
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = mb_strtolower($key);

        foreach ($this->configuredSecretKeys() as $configured) {
            if ($lower === $configured) {
                return true;
            }
        }

        foreach (self::ADDITIONAL_SENSITIVE_SUBSTRINGS as $sensitive) {
            if (str_contains($lower, $sensitive)) {
                return true;
            }
        }

        foreach (self::PACKAGE_SENSITIVE_SUBSTRINGS as $sensitive) {
            if (str_contains($lower, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exact configured private-transport names for the guest credential and
     * the claim capability (headers, body inputs, cookies), lowercased for
     * case-insensitive comparison. Exact matching covers configured names
     * that carry no sensitive substring of their own.
     *
     * @return list<string>
     */
    private function configuredSecretKeys(): array
    {
        $names = [
            (string) config('dawah-share.guest_credential.header', 'X-Dawah-Guest-Credential'),
            (string) config('dawah-share.guest_credential.input', 'guest_credential'),
            (string) config('dawah-share.guest_cookie.name', 'mi_dawah_guest'),
            (string) config('dawah-share.claim_capability.header', 'X-Dawah-Claim-Capability'),
            (string) config('dawah-share.claim_capability.input', 'guest_claim_capability'),
            (string) config('dawah-share.claim_cookie.name', 'mi_dawah_guest_claim'),
        ];

        return array_values(array_filter(array_map(
            static fn (string $name): string => mb_strtolower(trim($name)),
            $names
        ), static fn (string $name): bool => $name !== ''));
    }
}
