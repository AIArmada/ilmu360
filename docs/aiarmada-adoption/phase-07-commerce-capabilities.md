# Phase 7 - Commerce Capability Adoption

State: `Assessing`

## Objective

Evaluate commerce packages by actual ilmu360 workflows and adopt only when a concrete workflow exists. Paid event tickets are now an approved ilmu360 workflow, so the minimum paid-ticket package chain must be assessed during this phase instead of deferred indefinitely.

## Candidate Packages

- `cart`
- `checkout`
- `orders`
- `products`
- `pricing`
- `promotions`
- `vouchers`
- `shipping`
- `inventory`
- `tax`
- `cashier`
- `chip`
- `cashier-chip`
- `jnt`
- `customers`
- `docs`
- `feedback`
- `affiliate-network`
- Related `filament-*` adapters

## Workflow Criteria

Adopt a package only if it supports at least one approved workflow:

- Paid tickets for ilmu360 events.
- Event products.
- Donation checkout.
- Invoice/document generation.
- Payment provider integration.
- Logistics/fulfillment.
- Affiliate conversion beyond zero-value share tracking.
- Taxable commerce.
- Voucher/credit workflows.

## Checklist

- [ ] List proposed commerce workflows.
- [ ] Treat paid event tickets as an approved workflow and identify the minimum package chain required for cart, checkout, orders, pricing, products/inventory, payment, pass issuance, and registration fulfillment.
- [ ] Decide whether donation checkout shares the same commerce path or remains QR/channel-only for now.
- [ ] Map each workflow to required package chain.
- [ ] Identify routes/providers/migrations that would activate.
- [ ] Decide which packages become `Ready`, `Deferred`, or `Removed`.
- [ ] Record activation criteria for every `Deferred` package.
- [ ] Avoid installing packages just because they exist.

## Verification

```bash
composer show aiarmada/cart aiarmada/checkout aiarmada/orders aiarmada/products aiarmada/pricing aiarmada/inventory --available
vendor/bin/phpstan analyse --ansi
```

Add focused workflow tests for paid event ticket purchase-to-registration-to-pass-to-check-in.

## Exit Criteria

- Every commerce package is either adopted for a workflow or explicitly `Deferred`.
- Paid event ticket capability is either implemented through the package chain or blocked with a concrete package/dependency issue.
- No unrelated storefront routes, migrations, or admin resources are active by accident.

## Stop And Re-plan Triggers

- Commerce adoption changes donation semantics without product approval.
- Checkout/order packages require customer/payment concepts that cannot be represented generically for event attendees.
- Meta package installation becomes tempting as a shortcut.
