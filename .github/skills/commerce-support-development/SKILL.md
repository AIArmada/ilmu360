---
name: commerce-support-development
description: Integrate AIArmada Commerce Support tenant ownership, queued owner context, payment subjects, webhook envelopes, reference data, reporting, and user preferences using Laravel Dune boundaries.
---

# Commerce Support development

## Architecture

- ReferenceData owns language, currency and timezone reference data. Inject `AIArmada\CommerceSupport\Features\ReferenceData\Api\ReferenceDataApi` for scalar lookups and seed operations.
- Reporting owns report creation and state changes. UserPreferences owns saved searches and notification preferences.
- Webhooks owns the `webhook_calls` writes; extend `Features\Webhooks\EntryPoints\CommerceWebhookProcessor` for provider jobs. Use `Features\SignedDownloads\Api\SignedDownloadApi` for owner-bound temporary download issuance and authorization.
- Keep durable writes in owning Actions or Persistence classes, declared in the feature manifest where applicable.
- Keep Contracts and Data pure, and map HTTP, Eloquent and external SDK types at framework/driver boundaries.
- Never import host application classes or mutate Laravel host-global settings.

## Owner context

```php
use AIArmada\CommerceSupport\Contracts\OwnerResolverInterface;
use AIArmada\CommerceSupport\Data\RecordReference;

final readonly class HostOwnerResolver implements OwnerResolverInterface
{
    public function __construct(private TrustedTenantContext $tenancy) {}

    public function resolve(): ?RecordReference
    {
        return $this->tenancy->currentReference();
    }
}
```

Bind the interface in the host. `TrustedTenantContext` is the application's trusted tenant selection service. Do not build an owner reference directly from unvalidated request parameters. An unresolved owner is not global; explicitly global operations require a deliberate authorized owner context.

Jobs implement `OwnerScopedJob::ownerContext(): OwnerJobContext`. Use `OwnerJobContext::explicitGlobal()` only for deliberately global jobs, and `OwnerContextJob` to restore context before `performJob()`. An owner type and ID must be supplied together.

## Payment and webhook boundaries

- `Contracts\Payment\PaymentSubjectContext` accepts actor data and record references. Use `Support\EloquentRecordReference::actor()` and `fromModel()` at the Eloquent boundary.
- `PaymentSubjectResolverInterface::resolve()` returns a `ResolvedPaymentSubject` with a record reference and gateway customer data.
- The webhook handler contract receives `Data\WebhookRequestData`. Build it with `EntryPoints\Http\WebhookRequestMapper::fromRequest()`. Verify signatures against the original body; repeated signature headers must not be treated as one valid value.
- Use `Support\PublicHttpUrlGuard` and `Drivers\Http\PinnedHttpClient` for validated public outbound targets. Do not follow unvalidated redirects.

## Persistence

Use `Persistence\OwnerCache`, `OwnerFilesystem`, `VersionedCache`, and `CanonicalSlug` instead of duplicating shared storage behavior. Preserve the owner identity throughout queued, HTTP and administrative writes.

## Verification

From the commerce monorepo root:

```sh
php packages/commerce-support/tools/package-check.php --root=packages/commerce-support
./vendor/bin/phpstan analyse -c packages/commerce-support/tools/package-phpstan.neon --autoload-file=packages/commerce-support/tools/Architecture/PackageOwnedWriteRule.php --no-progress
./vendor/bin/pint --test packages/commerce-support
./vendor/bin/pest tests/src/CommerceSupport
```

Run affected sibling-package tests, especially payments, tenancy, webhooks and queued jobs. No compatibility shims, backfills, or architecture suppressions.
