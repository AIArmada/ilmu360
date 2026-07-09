# Paid Commerce Productization

Status: `In Progress` (ADR-013)  
Last updated: 2026-07-09

## Goal

Productize the installed AIArmada chain **events ↔ ticketing ↔ inventory ↔ seating** (and order/cart packages when checkout is enabled) so ilmu360 is not free-only at the schema/workflow layer.

## Package modes (must all be valid)

| Mode | Package primitives |
| --- | --- |
| Free open / walk-in | `PricingMode::Free`, registration mode none/open, walk-in/headcount actions |
| Free RSVP | Free pricing + `RegistrationMode` required/optional + engagement response if needed |
| Free ticketed | Free ticket types, pass issuance (`issue_passes_for_free`), check-in attendance |
| Paid ticketed | Paid ticket types, cart line actions, order paid → registration sync |
| Mixed | `PricingMode::Mixed` + consistent ticket type prices |
| Seated / capacity | seating allocation listeners, inventory quotas, capacity enforcement flags |

## Installed packages already

- `aiarmada/events`, `ticketing`, `inventory`, `seating`
- Filament: `filament-events`, `filament-ticketing`, `filament-inventory`, `filament-seating`
- Transitive: cart, checkout, orders, products (when paid checkout is bound)

## App rules

1. **No free-only domain model** in app code that rejects paid/mixed pricing.
2. Prefer package actions: `AddEventTicketTypeToCartAction`, pass issuance, seat allocation, order-paid listeners.
3. Public UX may hide paid checkout until payment provider is wired; **admin/API/MCP contracts still expose pricing + ticket types**.
4. Feature flags control **public exposure**, not schema:
   - `EVENTS_PUBLIC_PAID_CHECKOUT_ENABLED` (default false until Chip/cashier bound)
   - package flags in `config/events.php` for auto pass/seats/inventory

## Implementation slices

1. **Contracts** — admin schema + event show payload include `pricing_mode`, `registration_mode`, ticket types summary.
2. **Admin** — Filament ticketing/seating/inventory plugins already registered; verify ticket type CRUD against event scope.
3. **Free ticketed path** — ensure free ticket + pass/check-in works without cart.
4. **Paid path** — bind cart/checkout/orders + payment provider; wire public buy flow.
5. **Seating** — enable seating mode only when ticket type requires allocation.
6. **Tests** — package-mode matrix: free walk-in, free RSVP, free ticketed pass, paid order registration.

## Explicit non-goals for first public ship

- Full affiliate monetization of tickets
- Multi-currency tax engine beyond package defaults
- Physical shipping of merch
