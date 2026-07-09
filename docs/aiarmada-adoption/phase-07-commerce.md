# Phase 7 - Commerce Capabilities

State: **`Superseded`** (assessment-era plan)

> **Do not implement from open checkboxes in this file.**  
> Live product path: [`paid-commerce-productization.md`](paid-commerce-productization.md) + ADR-013 · Gap **G11** in [`status.md`](status.md) · Matrix: [`phase-reconciliation.md`](phase-reconciliation.md) §7.  
> **Already done:** inventory / seating / ticketing + Filament adapters installed. **Still open:** public paid checkout (flag off until payment bound), mode-matrix tests, full cart/checkout chain only when product ships paid.

## Objective

Assess the AIArmada commerce ecosystem for the fresh-schema rewrite. Historical assessment assumed zero commerce; product later approved paid tickets (ADR-013).

## Target Packages

### Core Commerce

| Package | Status | Dependency chain |
|---------|--------|-----------------|
| `commerce-support` | Installed | Foundation for all commerce packages |
| `cart` | Not installed | Standalone |
| `checkout` | Not installed | Depends on: cart, customers, docs, orders, pricing, products, shipping |
| `orders` | Not installed | Standalone (suggests: inventory, affiliates, docs) |
| `products` | Not installed | Standalone |
| `pricing` | Not installed | Standalone |
| `customers` | Not installed | Depends on: contacting |
| `cashier` | Not installed | Unified gateway facade (Stripe + CHIP) |
| `cashier-chip` | Not installed | CHIP subscription billing |
| `chip` | Not installed | CHIP payment gateway client |
| `vouchers` | Not installed | Depends on: cart |
| `promotions` | Not installed | Standalone |
| `inventory` | Not installed | Standalone |
| `shipping` | Not installed | Standalone |
| `tax` | Not installed | Standalone |
| `docs` | Not installed | Document generation |
| `growth` | Not installed | A/B testing (depends on: signals) |

### Ticketing (in Events package)

| Package | Status | Notes |
|---------|--------|-------|
| `events` | Not installed | Full ticketing: ticket types, components, products, seating, passes, waitlists |

### Affiliates (partially relevant)

| Package | Status | Notes |
|---------|--------|-------|
| `affiliates` | Installed | Share tracking integration with events |
| `filament-affiliates` | Not installed | Admin UI for affiliates |
| `affiliate-network` | Not installed | Multi-merchant extension |

### Filament Admin Packages

Each core commerce package has a matching `filament-*` package for admin CRUD. These would be installed during Phase 8 for admin panel integration.

## Package Assessment

### Events Ticketing (in `aiarmada/events`)

The events package provides a complete ticketing system:

| Component | Package Model(s) | Coverage |
|-----------|-----------------|----------|
| Ticket types | `EventTicketType` | Full: name, description, pricing mode (free/paid/donation), capacity, visibility |
| Ticket components | `EventTicketTypeComponent` | Full: line-item breakdown for ticket pricing (fee, tax, add-on) |
| Ticket products | `EventTicketTypeProduct` | Full: link ticket types to product/variant SKUs for inventory tracking |
| Seating | `EventSeatMap`, `EventSeatSection`, `EventSeat`, `EventSeatHold`, `EventSeatAllocation` | Full: seat maps with sections/rows/seats, timed holds, allocations |
| Passes | `EventPass` | Full: PDF ticket issuance per registration, QR codes |
| Registrations | `EventRegistration`, `EventRegistrationParticipant`, `EventRegistrationItem` | Full: per-registration attendees, line items |
| Check-in | `EventAttendance`, `EventAttendanceLog`, `EventWalkIn` | Full: attendance tracking with walk-in support |
| Waitlists | `EventRegistration` (Waitlisted state) | Full: waitlist promotion, automatic promotion |
| Pricing modes | `PricingMode` enum | Free, Paid, Donation, Tiered |
| Cart integration | `AddEventTicketTypeToCartAction`, `CreateOccurrenceCartLineAction` | Add tickets to cart for checkout |
| Order fulfillment | `FulfillEventOrderAction`, `SyncEventOrderRegistrationsAction` | Auto-create registrations from paid orders |

### Commerce Core (cart → checkout → orders)

**`aiarmada/cart`:**
- `CartModel`, `CartItem`, `Condition` models
- Guest/user cart with `MigrateGuestCartToUserAction`
- Conditions engine for discounts/fees
- TTL-based expiry (30 day default)

**`aiarmada/checkout`:**
- `CheckoutSession` model with 8-state machine
- 12 configurable steps: validate cart, resolve customer, calculate pricing, apply discounts, calculate shipping, calculate tax, reserve inventory, process payment, persist customer, create order, dispatch documents
- Payment gateway abstraction (Chip/Stripe)
- Promo code validation
- Inventory reservation

**`aiarmada/orders`:**
- `Order`, `OrderItem`, `OrderAddress`, `OrderPayment`, `OrderRefund`, `OrderNote` models
- 13-state machine (Created → PendingPayment → Processing → Completed/Canceled/Refunded/etc.)
- PDF invoice/receipt generation
- Events for inventory deduction, affiliate commission attribution

### Payment Gateways

**`aiarmada/cashier`** (Unified Multi-Gateway):
- Abstract gateway interface
- StripeGateway (via `laravel/cashier`)
- ChipGateway (via `aiarmada/cashier-chip`)
- Swap gateways via config

**`aiarmada/cashier-chip`** (CHIP Billing):
- Subscription management (create, cancel, retry)
- Payment method management
- Webhook sync
- Grace period (7 days), retry logic (3 days, 3 retries)

**`aiarmada/chip`** (CHIP Client):
- Collect API (payments): purchase creation, FPX/eWallet support
- Send API (payouts): bank transfers
- Webhook handling with signature verification

### Pricing Engine

**`aiarmada/pricing`:**
- `PriceList`, `Price`, `PriceTier` models
- Multi-list pricing (default, promotional, customer-group-specific)
- Tiered pricing (quantity breaks, customer segments)
- Promotional price adjustments

### App Commerce State

**The app currently has zero commerce:**
- All events are free registrations
- `DonationChannel` stores payment info (bank accounts, DuitNow, e-wallet handles) but processes no payments
- No cart, checkout, orders, invoices, payments, tickets, or passes
- `Registration` model has no pricing/payment fields
- Share tracking uses `ShareTrackingService` (attribution, not commerce)

## WP-17 Migration Plan

Effort: **Low** for migration (nothing to migrate). **High** for adding paid ticketing as a new capability.

### What gets added (new capability, not migration):

**Ticketing (via events package):**
- `EventTicketType` + components for paid/free/donation ticket tiers
- `EventPass` for PDF ticket issuance
- `EventSeatMap` / `EventSeat` for assigned seating (if needed)
- Cart integration for ticket selection → checkout
- Order fulfillment: auto-create registrations from paid orders

**Payment processing:**
- CHIP gateway for Malaysia payments (FPX, e-wallet, card)
- Checkout pipeline: select tickets → calculate pricing → process payment → create order → issue passes
- Refund workflow via orders package

**Donations:**
- No dedicated donations package exists
- Options:
  a) Use CHIP Collect API directly via `chip` package (simple single-payment flow)
  b) Use events package `PricingMode::Donation` ticket type (free-form donation amount)
  c) Build a lightweight donation flow into checkout pipeline
- Recommendation: **Option a** via Chip Collect API. A donation is just a single payment without order/fulfillment. Add a lightweight `Donation` model or use `ChipPurchase` directly.

### What stays app-owned:
- `DonationChannel` model — stores payment info for display. Package has no equivalent.
- `ShareTrackingService` — attribution tracking. Package's `affiliates` system is for commission-based affiliate marketing, which is overkill for simple share tracking. Keep app's lighter implementation.

### What gets replaced:
- `Registration` model → `EventRegistration` + `EventRegistrationParticipant`
- `EventCheckin` model → `EventAttendance` + `EventAttendanceLog`
- `RegisterForEventAction` → events package actions + checkout pipeline for paid events
- `RecordEventCheckInAction` → package equivalents

### Key decisions:
1. **Payment gateway**: Use CHIP (Malaysia-focused, supports FPX/eWallet). The app is Malaysia-centric. CHIP is the natural fit. Stripe as backup for international users.
2. **Ticket types**: The events package ticket type system maps well to the app's future needs (free community events → free ticket type; paid workshops → paid ticket type with components).
3. **Donations**: Use Chip Collect API with a simple `Donation` model (not the full checkout pipeline). A donation is a single payment, not an order lifecycle.
4. **No physical goods**: Skip inventory/shipping/tax packages. Not needed for an event platform.
5. **Vouchers/promotions**: Skip for Phase 8. Add when paid ticketing launches and promo codes are required.
6. **Checkout config**: The checkout pipeline has 12 steps — many won't apply (shipping, tax, inventory). Disable irrelevant steps via config.

### Steps:
- [ ] Determine whether to install commerce packages during Phase 8 or defer to after launch
- [ ] Configure CHIP gateway (`config/chip.php`, `config/cashier.php`)
- [ ] Configure checkout pipeline (`config/checkout.php`) — disable shipping, tax, inventory steps
- [ ] Register ticket types per event in the fresh schema
- [ ] Implement donation flow via Chip Collect API (or use events package donation ticket type)
- [ ] Wire `FulfillEventOrderAction` to create registrations + issue passes after payment
- [ ] Build admin UI for order management, refunds

## Verification

```bash
vendor/bin/pest --parallel --compact --filter=Registration
vendor/bin/pest --parallel --compact --filter=Checkin
```

## Exit Criteria

- Paid ticket types can be created per event with pricing components.
- Checkout flow processes payment via CHIP and creates registrations/orders.
- Free registrations still work (no payment required).
- Passes issued after successful payment.
- Donations processable via CHIP.
- Refund flow exists for cancellations.
- DonationChannel info display preserved.

## Stop And Re-plan Triggers

- Commerce packages require features that conflict with free event registration flow.
- CHIP gateway cannot meet Malaysia-specific requirements (FPX, e-wallet).
- Ticket type system cannot represent the app's planned event pricing model.
