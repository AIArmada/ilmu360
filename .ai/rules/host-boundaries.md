---
paths:
  - app/Host/**
---

# Host & External Boundaries

## Keep Privileged Runtime & SDKs in the Box
- All third-party HTTP/SDK integrations (Stripe, LLM providers, mail/SMS APIs, webhooks), filesystem gateways, and OS/process execution (`Process::run`) live in `app/Host/<Integration>/`.
- Each Host integration implements a pure interface defined in `app/Shared/Contracts/` and is bound in `App\Providers\AppServiceProvider` (or a dedicated Host provider).

## Direction & Isolation
- `app/Host/**` MUST NEVER import any class under `App\Features\*`.
- Parse raw third-party responses/webhooks at the Host boundary into immutable contract DTOs or domain exceptions—never leak vendor SDK objects or raw HTTP arrays into Features.
- Features call Host behavior exclusively through the typed interface in `App\Shared\Contracts\*`, making external boundaries trivially fakeable in tests without mocking internals.
