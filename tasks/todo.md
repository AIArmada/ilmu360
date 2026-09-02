# Design event authoring workflow for occurrences, sessions, registration, tickets, and seats

## Plan

- [x] Audit the current dashboard event form, public submission flow, event management page, routes, actions, models, and tests.
- [x] Audit the AIArmada `events`, `ticketing`, `seating`, and related Filament package contracts and integrations.
- [x] Define the event aggregate and the progressive authoring workflow, including capability gates, validation, ownership, and tracking.
- [x] Produce a phased implementation plan with open product decisions, risks, and verification coverage.

## Review

The audit confirms that the package model is `Event → Occurrence → Session`, with registrations, tickets, and seat maps attachable at each scope and effective settings inherited from the nearest configured parent. The current advanced form creates only an event plus its primary occurrence; it does not author additional occurrences or sessions, and its registration/ticket/seating controls are narrower than the package contract. A progressive Event Workspace is recommended: a short creation flow followed by checklist-driven schedule, access, ticket, seating, and operations setup. Paid selling is first-release scope: the app currently lacks the Cart/Checkout/Orders/Products/Customers/Cashier dependencies and payment is disabled by config, so implementation must install and configure the commerce stack, select an explicit `cashier-chip` gateway if CHIP remains the chosen processor, and prove buyer identity, ticket inventory reservation/commit/release, seat holds, payment callbacks, refunds, and idempotency before launch. Because ilmu360° requires verified accounts, `User` may be the canonical buyer and order subject; `aiarmada/customers` should remain optional unless CRM/customer-profile separation is needed. The event/ticketing packages already provide native TicketType-to-cart actions and order/registration fulfillment, while inventory already provides durable reservation-group reserve/release/commit semantics; however, generic checkout reservation currently resolves product/variant IDs and skips the polymorphic TicketType cart attributes, and no first-class shared inventory pool exists. The event workflow therefore needs an explicit integration bridge, preferably implemented generically in the commerce packages with event/ticketing contributors, plus a reusable shared-capacity model if the product decision requires multiple ticket types to draw from one quota. No implementation was made in this task.

The v1 scope was subsequently narrowed to general admission only: do not activate assigned seating, seat maps, seat holds, seat-level allocation, or buyer seat selection in the first release. Preserve the package-compatible data/integration seams so seating can be added later without redesigning event, ticket, or checkout contracts.

### Locked decisions

- `User` is the canonical buyer and commerce subject for ilmu360°.
- `aiarmada/customers` remains an optional commerce/CRM identity mode, not an automatic profile created for every buyer or attendee.
- For v1, ilmu360° is the merchant of record: paid event transactions go through one ilmu360° CHIP account, while organizer revenue is tracked for later settlement.
- For v1, organizer payouts are manual: ilmu360° records the organizer’s payable balance and settlement, without automatic split payouts.
- For v1, ilmu360° does not charge a separate platform commission on ticket sales; revenue, refunds, payment-processing costs, and organizer settlements remain separately visible.
- For v1, payment-processing fees are deducted from organizer settlements rather than added as an unexpected buyer charge.
- Refunds are controlled by one event-level `Refund Policy` setting that is Off by default. When Off, normal refund policy fields, buyer refund controls, and organizer refund workflows remain hidden; when On, the event chooses a buyer-visible policy from a small set of presets rather than arbitrary rules.
- When `Refund Policy` is On, the default preset is full refund until 48 hours before the event, no refund after that except approved exceptions; event cancellation triggers full refunds through the enabled workflow.
- When `Refund Policy` is On, approved refunds return the full ticket amount to the buyer; the original payment-processing cost remains an organizer-side settlement cost.
- When `Refund Policy` is On, refunds operate at individual ticket/admission level, so a buyer can refund one participant’s ticket without refunding the whole order; released ticket capacity becomes available again. Assigned seats are not part of v1.
- Platform/admin-only financial safety actions remain available outside the normal refund setting for unavoidable reversals such as duplicate charges, verified chargebacks, legal requirements, or a platform-required event cancellation. These actions are never exposed as ordinary buyer refund controls.
- V1 permits buyers to update or transfer an admission before its configured cutoff; event organizers may disable transfers for an event.
- Only the purchaser requires a v1 ilmu360° account; attendees may remain accountless, receive an admission through the purchaser or their supplied contact, and optionally claim it later.
- Each v1 admission receives a unique one-time-checkable QR code; staff have both scanner and manual name/email lookup, and access is evaluated against the admission’s event/occurrence/session scope.
- QR is a convenience, not a requirement. For an existing admission, authorized check-in staff may search by the participant’s IC number or another configured identifier such as passport number, phone, name, email, or order number, confirm the matching event/admission, and complete check-in with an audit record. A failed lookup cannot bypass capacity or create entry; a new walk-in still uses the separate Issue Admission flow.
- ID/passport collection is an independent opt-in registration setting. An organizer may request IC, passport, or another sensitive identifier for registration, eligibility, or identity purposes even when live check-in is disabled; enabling check-in does not automatically request it. Staff can use an identifier for lookup only when that field was collected, and the field remains masked and excluded from ordinary exports.
- Participant data remains event-scoped, but an `EventRegistrationParticipant` may optionally link to an existing ilmu360° `User` when that participant is safely identified. This supports the case where one purchaser buys for another person without turning the participant’s IC/passport into a global identity record or giving the purchaser access to the participant’s account.
- Existing-User linking is participant-controlled. The system may privately detect a likely match, but the link is finalized only when the participant signs in or uses their secure claim link; the purchaser is never told whether a matching account exists.
- After claiming or linking an admission, a participant’s dashboard shows only their own admissions, eligible recordings, schedule, agreement status, and participant details. It does not expose the purchaser’s receipt/payment information or other participants in the same order.
- A claimed participant does not make an admission permanently non-transferable. Before the configured cutoff, the purchaser may transfer it; the current participant is notified and loses access, the new participant receives a fresh secure claim link, and prior consent/attendance/link history remains preserved.
- Deleting a participant’s ilmu360° account removes the personal account link and dashboard access but does not delete the event registration, admission, attendance, refund, or financial history needed by the purchaser and organizer. Remaining participant data follows the platform’s privacy-retention/deletion rules; v1 does not create anonymized replacement records.
- Check-in is optional per event. An organizer may choose not to operate live check-in at all, print or export the participant list for manual use, and record attendance later. Attendance is separate from admission validity: later manual updates are scoped to the event/occurrence/session, record who made the change and when, and preserve correction history.
- When live check-in is disabled, admission views and downloads omit QR codes and check-in instructions. If the organizer enables check-in later, QR codes can be generated and updated admissions delivered without changing the underlying registration or admission identity.
- Post-event attendance uses three simple states: `Attended`, `Did not attend`, and `Not recorded yet`. The last state is the default when no attendance evidence exists; organizers may add a note, and updates remain scoped to the event/occurrence/session.
- Attendance scope is explicit. Marking a participant `Attended` at event level records overall event attendance only; it does not automatically mark every occurrence or session. Occurrence/session attendance is recorded separately when the organizer needs that detail.
- V1 records arrival/check-in only. It does not require staff to record departure/check-out; the existing package check-out seam remains available for future events that need duration or exit tracking.
- The organizer workspace provides a filtered participant list with bulk attendance actions, so staff can mark multiple participants at once after using a printed list. Each bulk operation records the actor, timestamp, selected scope, previous/new status, and optional note; CSV attendance import remains deferred.
- Attendance does not control purchased recording entitlement. A participant with a valid admission receives any recording included with that admission even if they did not attend live; refunds, cancellations, expiry, and recording-specific access rules still apply.
- V1 ticket delivery is email plus purchaser dashboard: buyers can download/print all admissions, and attendees can receive their own copy when an email is supplied; SMS/WhatsApp delivery is deferred.
- Paid orders show an authenticated online receipt in the purchaser dashboard and confirmation flow, with a Download PDF button that renders the receipt at request time from immutable order, payment, discount, and tax snapshots. PDFs are not stored by default; access is authorization-scoped and the online receipt remains the source of truth.
- Free registrations show an authenticated online confirmation in the purchaser dashboard and confirmation flow, with a Download/Print Admission action; they do not display a payment receipt or imply that a payment occurred.
- Receipt and financial-PDF access is limited to the authenticated purchaser and authorized event finance/owner roles. An emailed receipt link leads through account authentication, while an attendee may receive a separate secure admission link without an account and without access to order/payment details.
- The original receipt remains an immutable record of the purchase. A partial or full refund updates the order status and creates a separate refund confirmation/document; it never rewrites the original receipt.
- Confirmation emails link to the authenticated online receipt and admission pages rather than attaching PDF files; receipt/admission PDFs are generated only when the authorized recipient requests a download or print action.
- V1 checkout contains tickets and recording-access admissions from one event only; non-admission add-ons such as donations, meals, books, merchandise, and workshop materials are deferred. Multi-event carts are also deferred so participant questions, agreements, capacity, refunds, receipts, and organizer settlement stay unambiguous.
- Organizer-issued admissions use clear document states: complimentary admissions receive a free confirmation and admission; confirmed cash or bank-transfer admissions receive an admission and a receipt marked as offline payment; pending offline payments receive only a provisional confirmation until an authorized organizer confirms the payment.
- Checkout includes a final review step before payment. It shows every admission, occurrence/session, participant, quantity, price, discount, active tax, refund policy, and required agreement, with editing available before the buyer proceeds to the provider.
- An order reduced to RM0 by vouchers or promotions is treated as a free confirmation, not a payment receipt. The confirmation preserves the original price, discount allocation, and final zero total, and clearly states that no payment was made.
- If an order contains both paid and free/fully discounted admissions, it produces one receipt for the whole order. The receipt lists every admission, shows RM0 lines and their discounts clearly, and records the single paid total; only an entirely RM0 order uses the free-confirmation format.
- Because the CHIP processing fee is an organizer settlement cost, buyer receipts show the ticket subtotal, discounts, active tax, and amount paid but do not add or expose the provider fee as a buyer charge. Organizer settlement reports show that fee separately.
- Receipts clearly identify ilmu360° as the payment merchant/seller and the event’s organization or institution as the event host. This separates payment responsibility from event operations, support, refunds, and public presentation.
- Paid receipts are addressed to the purchaser and contain purchaser/order information. Participant names and participant-specific registration data appear on the separate admissions, not as unnecessary financial-recipient data on the receipt.
- `aiarmada/orders` already provides the stable buyer-facing `order_number`: it is unique, stored on the order, and generated from configurable prefix, date, separator, and random-suffix settings. Use that package field as the receipt/refund reference and configure an ilmu360° prefix rather than inventing an app order-number system. The package also has runtime invoice PDF generation; its invoice number is currently generated per render, so a separate immutable legal-invoice identity would be a generic package enhancement only if needed later.
- The v1 buyer-facing financial document is called a “Receipt” and confirms completed payment. “Invoice” or “e-invoice” terminology is reserved for a future tax-enabled/legal billing document, so the current receipt flow does not imply that tax invoicing is active.
- Confirmation and admission pages include Add to Calendar actions. Each applicable occurrence/session can produce an `.ics` file and calendar links, while an event-wide pass can offer an add-all option; calendar entries use the effective local schedule and do not expose protected online access URLs prematurely.
- Event reminders are recipient-aware: each participant receives reminders for their own applicable occurrence/session when an email is supplied; the purchaser receives a summary or fallback reminder, particularly for participants without email. The system avoids sending duplicate reminders unnecessarily.
- V1 QR check-in requires an internet connection. The staff check-in mode must show connection and verification status clearly, retry safely, and never mark an admission as checked in without server confirmation; offline scanning and synchronization are deferred.
- When `Refund Policy` is On, refunds within the event’s published policy are self-service and automatically processed. Late or exceptional requests require organizer approval, while platform administrators may perform an audited override for disputes or operational emergencies.
- When an enabled refund is approved, the paid refund remains `refund pending` until the payment provider confirms the money was returned. The admission stays reserved and is not silently resold during that interval; ticket capacity is released only after confirmed refund completion.
- When an enabled refund is approved, the affected admission is marked `refund pending` and blocked from check-in while the provider processes it. If the provider rejects or fails the refund, the admission is restored and the buyer is notified; a completed refund permanently invalidates that admission.
- When `Refund Policy` is On, self-service refunds are unavailable after an admission has been checked in or its applicable occurrence/session access window has started. A post-use or post-start refund is an organizer-approved exception with a required reason and audit history.
- When `Refund Policy` is On, organizer cancellation automatically starts full refunds for all eligible paid admissions without requiring buyer requests. If the setting is Off, any unavoidable buyer-money reversal is handled through the platform/admin-only safety path rather than normal refund controls.
- “Download Admissions” generates one combined PDF by default, with one page per admission; individual secure admission links and downloads remain available to each participant.
- After an event ends, it becomes read-only for public sales and registration but remains available as a public archive. Its information and eligible recordings follow their own access rules, while authorized users retain access to receipts, admissions, attendance, and reports.
- Public event pages show simple capacity labels such as “Available,” “Few spots left,” and “Sold out” by default. Organizers may optionally expose the exact remaining count; private operational counts remain available in the organizer workspace.
- For multi-occurrence events, public checkout is schedule-first: buyers choose an occurrence or session, then see the ticket types that apply to it. Event-wide passes are presented in a separate all-event section so date-specific and all-access choices are not confused.
- Checkout prevents overlapping admissions for the same participant, such as assigning both an event-wide pass and a session ticket to one person. The buyer receives a clear explanation and may still purchase those tickets for different participants.
- Within that event boundary, v1 allows a buyer to purchase tickets for different occurrences or sessions in one checkout. Each cart item clearly names its date/session, each admission has its own participant and scope, and capacity is reserved separately at the applicable occurrence/session level.
- V1 supports organizer-issued admissions for complimentary guests, speakers/staff, and offline payment, while still consuming capacity, recording the issuer, and using the same QR/check-in rules.
- Offline v1 admissions use explicit payment statuses/methods (complimentary, cash, bank transfer, or pending), with authorized confirmation, audit history, and notes/proof; they are not represented as CHIP payments.
- V1 ticket pricing, checkout, refunds, and settlement reporting offer MYR only, but every monetary amount carries an explicit currency code; provider adapters declare supported currencies so future currencies can be added without redesigning commerce or event workflows.
- Payment integration must be provider-neutral from the foundation: event/ticket workflows depend on commerce payment contracts, while `cashier-chip`/CHIP is a replaceable adapter and future providers can be registered without changing event logic.
- Future multi-currency support will be event-scoped: one event uses one configured currency across its ticket types and orders; mixed-currency carts are deferred.
- `aiarmada/tax` is the planned dormant v1 tax foundation: install its contracts/models/settings and keep checkout tax disabled initially, while making generic checkout improvements for currency propagation, polymorphic buyer/exemption context, line tax-class snapshots, and immutable order tax outcomes so activation does not require a redesign.
- When tax is activated, ilmu360° administrators own tax zones, rates, and tax-class definitions centrally; event creators may select only an approved tax category for their tickets.
- When tax is activated, exemption requests may be submitted with supporting details/documents but tax is waived only after authorized admin approval; exemptions may be global or zone-specific and time-limited.
- When tax is active and a paid taxable checkout lacks a resolvable billing zone, checkout must request the required location data rather than silently applying zero tax; inactive tax must not require those fields.
- Completed paid orders freeze the exact tax inputs and outcome (amount, rate, class, zone, currency, inclusion mode, and breakdown); later tax-rule changes never rewrite historical receipts or settlements.
- Per-ticket refunds reverse the exact tax amount captured for that ticket’s original order line; they do not recalculate tax using current rules.
- Tickets and future event add-ons use the shared checkout tax engine through line-level tax metadata; event commerce will not create a separate ticket-only tax calculator.
- Ticket tax class is inherited from the platform/event default and exposed as an advanced approved choice only when tax is active; ordinary creators do not need to understand tax setup.
- V1 uses `aiarmada/vouchers` for redeemable codes/coupons and the local `aiarmada/promotions` package for automatic and code-based campaigns; no separate `aiarmada/campaigns` package exists in the current Commerce monorepo.
- Discount integration must reserve voucher usage during checkout, commit it only after confirmed order/payment success, and release failed/expired reservations idempotently; promotion usage must likewise be committed only on the confirmed paid-order path.
- Organizer discount tools are scoped to the organizer's own event; platform administrators may create global or event-specific campaigns. Every discount must explicitly target an event, occurrence, session, or ticket type so it cannot apply to unrelated events by accident.
- Organizer-created and platform campaigns are organizer-funded by default, reducing the organizer's settlement; a platform-funded campaign is an explicit future funding mode. Settlement reporting must show gross sales, discounts, payment fees, tax, and net payable separately.
- A checkout accepts at most one manually entered voucher/coupon code; automatic promotions may stack only when explicitly marked stackable. Checkout must show each applied discount and its reason, with no unlimited code stacking.
- Discount calculation order is ticket/add-on subtotal, then voucher/promotion discount, then tax on the discounted taxable base when tax is active; payment-provider fees remain a separate organizer settlement cost. Orders preserve original prices, discount allocations, taxable bases, and tax results for accurate refunds.
- Event discounts must use the generic `aiarmada/cart` condition pipeline and the compound capabilities already provided by `aiarmada/vouchers` rather than creating a ticket-only discount engine. The authoring UI should progressively expose event-safe fixed, percentage, tiered, BOGO, bundle, and other supported rules, with event-aware validation and a future-proof extension seam.
- Discount authoring uses progressive disclosure: Basic mode covers common rules, Advanced mode provides a guided builder for tiered/BOGO/bundle/conditional rules, and a preview explains qualifying tickets and sample totals. Organizers never edit raw condition JSON; platform administrators may access the full supported rule set. Cashback/reward rules remain separate from immediate ticket-price discounts.
- A free or fully discounted ticket remains a real admission: it requires participant data, consumes general-admission capacity, receives the normal QR/check-in lifecycle, and snapshots original price, discount, and final zero price. Seat assignment is not part of v1.
- Event-wide discounts may aggregate eligible tickets across occurrences/sessions, but BOGO and bundle rules default to a compatible single occurrence/session. Cross-occurrence or cross-session eligibility is an explicit Advanced option with a preview and stored scope.
- Private-code and audience workflows must reuse existing package capabilities: `aiarmada/promotions` can issue unique one-time vouchers with prefixes, expiry, usage limits, owner scope, and promotion linkage; `aiarmada/vouchers` provides per-user limits, wallets/assignments, target definitions, and stacking. Current voucher assignment is to an assignee model such as `User`, not an arbitrary email; email-only assignment would be a generic package enhancement if required.
- Every event-commerce decision must include a package-fit audit: identify what the relevant `aiarmada/*` package already supports, what generic enhancement belongs in `/Users/Saiffil/Herd/commerce`, and what application-specific integration belongs in ilmu360°. Prefer existing package contracts, enhance packages only for reusable generic behavior, and record the boundary with focused tests.
- V1 private vouchers are assigned to registered purchaser `User` accounts through the voucher package; the purchaser may use one for another attendee. Email-only assignment is not required for v1 and, if later needed, belongs as a generic vouchers-package enhancement.
- Participant details are collected during checkout after ticket selection; the purchaser is prefilled as the first participant, one participant is required per admission before payment, and the existing event cart action stores those participants on the TicketType cart item.
- Organizers may add custom registration questions per participant, with required/optional status and scope at event, occurrence, session, or ticket level; the purchaser can have separate questions from each attendee. The existing `EventRegistrationAnswer` storage is reusable, but question definitions, validation, scoping, and a safe authoring builder should be a generic `aiarmada/events` enhancement rather than an ilmu360°-only JSON feature.
- V1 custom questions use guided types (short text, long text, number, date, yes/no, single-choice, and multiple-choice). The definition model remains extensible, but file uploads, signatures, calculations, and complex branching are deferred until generic package support is secure and reusable.
- V1 supports only one-level conditional visibility for custom questions, driven by yes/no or choice answers in the same registration scope; a visible required question is validated, while a hidden question is not. Nested rule trees remain deferred to a generic events-package capability.
- When a controlling answer changes and a conditional question becomes hidden, its unsaved answer is cleared and it is excluded from validation; completed registrations retain their submitted answers as historical records.
- After the first participant answer is submitted, custom question definitions are versioned: wording, options, requiredness, and condition semantics used by existing registrations remain immutable. Safe display corrections may be allowed, but material edits create a new question version so reporting remains accurate.
- Registration always shows the ilmu360° privacy notice; event-specific data-use consent can be required and marketing opt-in is always separate and optional. Store the exact consent text/version, subject, timestamp, and account/registration context. `aiarmada/communications` consent remains for messaging decisions; registration-data consent belongs to the event workflow boundary.
- Participant access is least-privilege and event-scoped: owners/managers see their event's operational data, check-in staff see only identity/admission/attendance fields, analysts see aggregates by default, purchasers see their own order/participants, and attendees see only their own admission/answers. Sensitive answers require explicit permission and are excluded from ordinary exports.
- Organizers can export filtered participant lists in v1 (event/occurrence/session, ticket, registration, and check-in status) with selectable columns. Sensitive columns require explicit permission; exports are audited, access-scoped, and delivered through an expiring download. The existing inventory export framework is not an event-participant export, so the event export belongs in a reusable events/Filament seam.
- V1 sends transactional email for account verification, payment states, admission delivery, transfers/participant updates, refunds, cancellations/postponements/venue or schedule changes, waitlist promotion, and event reminders (default 24 hours and 1 hour before each applicable occurrence/session). Marketing remains separate opt-in; the notification workflow must use the existing event/order/ticketing/communications seams and be provider/channel-extensible.
- Organizer email customization is limited to practical event content and safe reminder choices; platform-controlled identity, payment/refund, admission/QR, schedule, privacy/legal, and unsubscribe blocks cannot be removed or rewritten. Template/content-block integration should use the communications package while preserving transactional semantics.
- Every event has a confirmed default IANA timezone; occurrences and sessions inherit it unless explicitly overridden. Persist timestamps in UTC, display the applicable local timezone, and schedule reminders from the relevant occurrence/session timezone.
- V1 includes a recurring-schedule generator with common weekly/monthly/weekday patterns, an end date or occurrence limit, and a preview before creation. Generated occurrences remain individually editable while retaining their recurrence relationship.
- Recurring-series edits use explicit scope: one occurrence creates an exception, future unsold occurrences may be updated, and sold/registered occurrences require a protected reschedule or cancellation workflow with notices and refund handling.
- Cancelling one occurrence affects only admissions whose access depends on that occurrence/session. Occurrence/session tickets are refunded, transferred, or replaced through the change workflow; event-wide passes remain valid for unaffected occurrences and receive a clear replacement or partial-remedy option for the cancelled scope. Cancelling one occurrence does not automatically cancel the whole event.
- When one date in an event-wide pass is cancelled, the pass remains valid for its unaffected dates. The organizer must offer a clear remedy for the cancelled scope—replacement date, proportional credit/refund, or another explicitly stated option—without silently cancelling the entire pass.
- Rescheduling one occurrence moves its existing occurrence/session admissions to the new date/time by default. Affected participants are notified, calendar entries are updated, and buyers receive a deadline to keep, transfer, or refund; unaffected occurrences and admissions remain unchanged.
- Recurrence generation clones the session programme using relative times; each occurrence can override its sessions, while ticket, registration, and capacity settings inherit unless the organizer explicitly chooses a different setup. Assigned seating remains future scope.
- Parallel sessions are allowed for multi-track programmes, but the schedule validator warns about overlaps and blocks impossible conflicts (same physical room, session outside its occurrence, or invalid start/end order). `EventScheduleValidator` is the generic seam for these rules; seat-map conflicts are future scope.
- V1 uses general admission only: selecting a `VenueSpace` automatically provides its stored physical-space capacity, while a manual total is required when no usable space capacity exists. Organizers may set a lower safety ceiling but never increase the known physical capacity, and the UI separates available, held, sold, and remaining counts. Assigned, hybrid, and seat-level capacity remain future capabilities.
- Capacity reductions are blocked when the new ceiling would fall below sold or active-held admissions. Any unavoidable reduction uses a deliberate exception workflow with affected-admission review, notification, reallocation/cancellation, and refund handling; existing admissions are never silently invalidated.
- Each ticket type has optional sale start/end times, inherited from event settings unless overridden; all times use the event timezone. Sales close at the earliest configured cutoff, sold-out state, or event cancellation, and manual pause/reopen is audited. Registration windows remain separate from ticket-sale windows.
- Organizers may set both per-order quantity limits and an optional event-wide maximum per purchaser `User`; active admissions count toward the limit while refunded/cancelled admissions do not. These are separate rules, and buyer-level enforcement is a generic ticketing enhancement beyond the current per-cart `min_quantity`/`max_quantity` fields.
- Ticket sales close automatically when the applicable event, occurrence, or session starts. Organizers may close sales manually at any time or configure an earlier cutoff such as one day or five hours before; v1 does not allow automatic sales after the applicable start time.
- A manual sales closure may be reopened before the applicable start time if entity approval, capacity, ticket terms, and other readiness checks still pass. Reopening is an explicit audited action; once the applicable start time arrives, automatic closure is final for v1.
- Free registration closes automatically when the applicable event, occurrence, or session starts. Organizers may close it earlier or manually; v1 does not accept new registrations after the applicable start time. Open Door events remain unregistered and are not affected by this cutoff.
- A manual free-registration closure may be reopened before the applicable start time if capacity and event readiness still pass. Reopening is explicit and audited; after the applicable start time, registration remains closed for v1.
- Sales controls are progressive: simple mode offers one event-wide close/reopen action; Advanced mode allows closing or reopening a specific occurrence, session, or ticket type, subject to its own start time, capacity, readiness, and audit rules.
- Participant corrections and admission transfers use deadlines separate from ticket sales and registration. Sales may close earlier, while harmless name/email/details corrections remain available until a configured changes cutoff (default applicable start); organizers may set an earlier transfer cutoff to finalize their participant list.
- Correcting a participant’s name, email, or other harmless details does not invalidate the existing admission or QR. The admission display and delivery may be regenerated, but the admission identity remains the same and the correction is audited; only transfer or cancellation changes access identity/state.
- A transfer immediately revokes the previous participant’s QR/secure admission access and issues a new credential to the new participant. The original admission/order reference and transfer history remain traceable for the purchaser and authorized organizers.
- Every admission requires the participant’s full name. Participant email is optional but needed for personal admission delivery, agreement links, or direct online-access notices; participant phone is optional. The purchaser’s email remains mandatory and verified, with purchaser or organizer-assisted communication for attendees without email.
- Ordinary check-in staff may look up and check in existing admissions, including with assisted identity lookup, but may not create new walk-in admissions. Only owners, managers, or authorized ticket/registration staff may issue complimentary or offline admissions, subject to capacity, payment-state, and audit controls.
- Check-in staff may record live attendance through the check-in flow, but retrospective bulk attendance updates and corrections require an owner, manager, or authorized registration staff member. Every change records the actor, time, scope, previous/new status, and optional reason.
- Ticket visibility is explicit: `public` tickets appear in checkout; `private` tickets are unlisted and require authenticated invitation, assigned voucher, or valid access code; `hidden` tickets are unavailable to public checkout and are reserved for organizer-issued, staff, speaker, or offline admissions. Server-side authorization is required for every private/hidden path.
- Paid admissions are issued only after verified server-side provider success: create a pending order, reserve ticket inventory/capacity, redirect to the provider, treat browser return as informational, verify through webhook or provider status, then commit and issue registration/pass/QR exactly once. Free registrations may complete without a payment provider; seat assignment is future scope.
- If the buyer returns before provider confirmation, show a pending-verification state, allow refresh/status checks, and prevent a second charge while the first attempt is pending. A late success is reconciled through the same idempotent callback path; an expired hold never silently issues admissions.
- V1 uses the provider-hosted payment page: CHIP receives payment credentials, while ilmu360° owns only provider-neutral checkout state and verified callbacks. Embedded payment UI is a future adapter capability, not an event-domain dependency.
- Buyers do not choose the gateway in v1; the platform chooses the active provider (CHIP initially), while the provider-hosted page exposes its supported payment methods. The selected gateway is stored on the checkout session, and switching the primary provider affects only new checkouts; pending attempts stay with their original provider.
- Event authoring supports explicit Save Draft plus autosave after meaningful changes. Drafts remain private, incomplete fields are allowed, and the creator can resume later; publication still runs the readiness checklist. This is an ilmu360° workspace behavior over the events package's draft statuses/templates.
- Event teams use event-scoped roles (owner/manager, content/schedule editor, ticket/registration manager, check-in staff, and finance/reporting viewer) through the existing membership/authorization seams and `CanManageEventsFor`; permissions and sensitive-data access are audited.
- Every invited event team member uses their own verified ilmu360° `User` account; shared logins are not allowed. Invitations, acceptance, role changes, revocations, and sensitive-data access are auditable, and check-in staff may use a short-lived event-scoped check-in session on shared devices.
- Each event has exactly one operational owner `Organization` for team access, ticketing, payments, refunds, tax, support, and settlement reporting. An event may identify one primary `Institution` and optional co-host institutions for public presentation, but co-hosting does not split operational or financial ownership in v1.
- An `Institution` may also serve directly as the event location/venue. A hall, room, or separate venue is optional; when omitted, the event uses the institution’s public name and effective address snapshot. Organizer/host identity and physical location remain separate roles even when they point to the same institution.
- The explicit-place integration uses `aiarmada/events` `EventLocation`: the institution or package `default_venue_id` supplies the base location context, while `venue_space_id` selects a named place within the venue. `EventLocation` can be scoped to the event, occurrence, or session and preserves `space_name_snapshot` and address data; do not add an app-specific hall/room column or duplicate the package’s location model.
- A `VenueSpace` may have a default reusable seat-map template as a future-compatible package capability, but v1 does not surface, clone, or sell assigned seats. V1 always uses general admission; future seating can reuse the generic `aiarmada/events`/`seating` resolver enhancement.
- Venue and space facilities are reusable event information (for example accessibility, parking, prayer facilities, toilets, refreshments, Wi-Fi, and transport). Events inherit those defaults, may add event-specific instructions, and snapshot the displayed facilities at publication.
- Managed `VenueSpace` conflicts are checked across scheduled events using the selected event/occurrence/session location and effective times. Overlapping use blocks publication by default; only an authorized manager may override with an audit reason. External venues without a trusted calendar produce a warning rather than a false guarantee.
- Assigned-seat ticket sections, seat attributes, seat holds, accessibility-based seat allocation, buyer seat selection, and automatic seat allocation are deferred from v1. V1 may still collect a general accessibility/accommodation request for organizer follow-up, without promising a particular seat.
- Events inherit the organization’s buyer-support contact but may override it with an event-specific email, phone, or help URL. The buyer-facing event page and transactional messages show the effective contact, with ilmu360° support as the fallback when none is configured.
- Each event has one combined, versioned Event Agreement containing all organizer-provided terms, refund rules, code of conduct, participation waiver, photo/video wording, and other required notices. The purchaser accepts purchase/platform terms; each adult participant (or authorized guardian) accepts the single Event Agreement for their admission. Marketing opt-in remains separate and optional.
- Participant acceptance may remain pending after payment or free registration is completed. A simple event-level setting controls whether pending Event Agreement acceptance blocks admission activation/check-in or merely shows outstanding consent and sends reminders; acceptance history remains visible and auditable either way.
- The combined Event Agreement is accepted once per participant admission for the whole event, even when the admission covers multiple occurrences or sessions. New agreement versions apply to new registrations; historical registrations retain the exact version accepted.
- After registration, ilmu360° automatically sends each named participant a secure Event Agreement link with one-time verification. The purchaser sees each participant’s acceptance status and may resend the link or correct the email; participants do not need ilmu360° accounts.
- When an adult participant has no email, the purchaser cannot accept the Event Agreement for them. If the organizer enables assisted acceptance, the participant may read and accept on an organizer-controlled device, with the participant, staff member, device/session, and timestamp recorded; otherwise the participant remains outstanding.
- A minor remains the participant while a named parent/guardian supplies name, email, relationship, and explicit authority confirmation to accept the Event Agreement. The minor does not need an account; guardian acceptance uses the same secure-link or assisted-device paths.
- After purchase, the purchaser may update or transfer an admission until the configured cutoff; an adult participant may update their own details through the secure link, a guardian may update a minor’s details, and authorized event staff may correct records with an audit entry. Finalized consent/check-in history is never overwritten.
- Participants may decline or withdraw the combined Event Agreement. Their admission records the resulting consent status, preserves prior acceptance history, notifies the purchaser/organizer, and follows the configured event policy for blocking entry, transfer, or refund; consent history is never deleted.
- Adding an Event Agreement enables “require acceptance before admission” by default. The organizer may turn this single event-level enforcement setting off for informational/non-blocking agreements; events without an agreement have no consent step.
- Low-risk event edits may save normally, while high-impact changes (date/time/timezone, venue/space, capacity, cancellation, postponement, and schedule changes) use a guided impact workflow showing affected tickets, registrations, capacity, refunds/transfers, notifications, and a final confirmation. Existing `aiarmada/events` change/notification primitives are reused; commerce coordination is added around them. Seat-map changes are future scope.
- For postponement or rescheduling, existing tickets remain valid for the replacement date by default. Buyers receive a deadline to keep the ticket, transfer to another available occurrence, or request a refund under the event-change policy; no response keeps the ticket active, while capacity conflicts receive priority transfer or refund handling.
- The same event workflow supports in-person, online, and hybrid delivery. In-person events expose venue and capacity fields; online events expose access instructions; hybrid events expose both. V1 uses general admission only; assigned/hybrid seating remains a future capability. Online access is scoped to the event, occurrence, or session and is released only to valid registered participants; provider-specific meeting integrations remain replaceable future adapters.
- Online access is admission-specific where supported; otherwise the raw provider URL is hidden behind an ilmu360° signed access link that verifies an active admission. Access is revoked when the admission is refunded, cancelled, or transferred, and raw meeting URLs are never public.
- Online access windows inherit from the event (default 15 minutes before start through scheduled end) and may be overridden per occurrence or session; access timing follows the effective local timezone. Recordings are included in the first release, with separate recording access rules rather than inheriting live-session timing automatically.
- Recordings are first-class, scope-aware resources attached to the event, occurrence, or session, with their own publication state, availability window, audience/entitlement rule, and protected access path. The current app’s `Event.recording_url` is only a single generic link and the media collections are image-oriented; use a reusable `aiarmada/events` recording/access capability instead of extending that column.
- The first release accepts both externally hosted recording links and uploaded video files through one provider-neutral `RecordingSource` seam. External links may point to services such as YouTube, Vimeo, Zoom, or cloud storage; uploaded files use protected ilmu360° storage/playback. Source-specific credentials and storage behavior stay outside the event form.
- A recording may be included with a valid admission or sold as a separate no-seat recording-access ticket. Both paths use the normal `aiarmada/orders`/checkout/payment, voucher/promotion, tax, refund, and entitlement lifecycle; recording purchases do not consume physical event seating.
- Recording entitlement follows the event access hierarchy: an event-scoped admission may access all recordings, an occurrence-scoped admission may access that occurrence and its sessions, and a session-scoped admission may access only that session. A recording-access ticket grants only its targeted recording; narrower admissions never unlock broader event content.
- Each recording has one simple access mode: public, included with qualifying registration/ticket, sold separately through a recording-access ticket, or team-only. Custom email lists and arbitrary ACLs are out of scope for v1.
- Recordings support draft, processing, published, and archived lifecycle states; organizers may publish immediately or schedule release, set an optional expiry, and notify eligible viewers. A recording is inaccessible outside its published availability window.
- Recording playback is stream-only by default, with an organizer-controlled download option. Uploaded files use expiring protected URLs; external-provider download behavior is respected, and revoked/refunded admissions lose access immediately.
- V1 uploaded recordings use a validated, playback-friendly MP4/H.264 format with size/duration checks and a preparation step before publication. Automatic transcoding is deferred behind the provider/storage seam; external links are unaffected by upload-format rules.
- Recordings may include optional WebVTT/SRT caption tracks and a readable transcript. Captions/transcripts are versioned with the recording; automatic speech-to-text and richer multilingual tooling remain future provider capabilities.
- Organizer recording analytics are privacy-safe aggregates: views, approximate unique viewers, completion where supported, downloads, access failures, and separate-recording sales/revenue. Individual viewing histories are not exposed by default, and external-provider metrics are capability-dependent.
- Venue authoring lets organizers select an existing reusable venue, use the selected institution as the venue without a specific place, choose an explicit `VenueSpace` within that institution/venue, or create a new venue inline. The event stores an effective location snapshot; seat-map cloning remains future scope.
- Reusable venue records may contain rooms or areas, each with its own capacity and optional future seat-map template. An occurrence or session may select a specific room/area, while the event retains the effective room, address, and capacity snapshot used for admissions.
- Venue rooms/areas are first-class physical spaces with capacity; overlapping sessions cannot silently share the same room. Seat-map behavior is future scope, while occurrence/session selection determines the effective room snapshot used for admission operations.
- V1 availability is coordinated across `aiarmada/inventory` ticket-type stock and `aiarmada/events` aggregate event/occurrence/session capacity. Checkout uses one reservation reference for the active inventory/capacity resources; `aiarmada/seating` is not activated in v1 and remains an optional future integration, while polymorphic TicketType resolution is a reusable Commerce enhancement.
- Ticket types support separate quotas by default, with an optional shared capacity pool so compatible ticket types (for example VIP and Regular) can draw from one limit; event/occurrence/session capacity remains the hard ceiling. Because `aiarmada/inventory` has no first-class shared pool today, this should be a generic inventory/capacity enhancement rather than an ilmu360°-only quota table.
- V1 treats one purchased ticket as one admission for one participant. Buyers may purchase multiple tickets for multiple people, but `TicketType.admits_quantity` is not exposed as a group/family-ticket shortcut until generic participant, pass, seat, refund, and transfer semantics are complete.
- Ticket access follows the package hierarchy: an event-scoped ticket is an all-access pass for the event's occurrences and sessions, an occurrence-scoped ticket is valid only for its occurrence, and a session-scoped ticket is valid only for its session. An all-access pass may check in once per applicable occurrence/session, while duplicate scans for the same scope are rejected; strict session validation belongs in the generic events package.
- V1 supports waitlist sign-ups when an occurrence, session, ticket quota, or shared capacity pool is full. A waitlisted person supplies participant details without payment; the queue is first-come, first-served, and organizer promotion creates a short-lived invitation to complete payment or free confirmation. Released-capacity triggers, offer expiry, notifications, and inventory/capacity coordination should be reusable `aiarmada/events` workflow capabilities.
- Events may always be saved as drafts, but public publishing requires basic event/schedule/location readiness. Non-ticket informational or free-registration events do not require platform approval. Publishing ticket sales additionally requires an active ticket, valid price/currency, registration settings, general-admission capacity/inventory readiness, and an approved operational owner entity (organizer/organization/person/institution). Approval is entity-level commerce eligibility, not event-level moderation; pending entities may publish non-ticket events but cannot publish ticket sales. Assigned-seating readiness is future scope. Readiness validation should be a reusable package contract, while ilmu360° owns the checklist UI and entity approval policy.
- A pending entity may configure ticket types, prices, registration, capacity, and checkout privately, but public ticket visibility, ticket sales, and paid checkout remain locked until the operational owner is approved. Approval unlocks the already-prepared setup without requiring the event to be rebuilt.
- If an approved operational owner later loses commerce approval, new ticket sales and paid checkout pause immediately, but existing valid admissions remain honored. Receipts, refunds, support, recordings, and any necessary event review continue through the normal controlled workflows; existing buyers are not silently invalidated.
- Ticket selling requires both approved commerce eligibility and a complete settlement profile for the operational owner, including the payout destination needed for later manual organizer settlements. Pending entities may prepare events, but sales remain locked until both gates pass.
- Organizer settlement is calculated and visible per event before payout, showing gross sales, discounts, active tax, refunds, provider fees, and net payable. Manual payouts may group multiple event statements into a settlement batch, but every amount remains traceable to its source event and order.
- Organizer funds become payable only after the event has finished and its refund period/outstanding refund activity has closed. For multi-occurrence events, settlement waits until the final occurrence and unresolved refunds are completed; earlier payout can be a separately governed future capability.
- Manual payout execution follows separation of duties: ilmu360° finance/admin records and confirms the bank transfer or other payout, while organizers can view statements and status but cannot mark their own payout as completed.
- Event creation starts with a short setup choice such as Open Door, Free Registration, or Paid Tickets, then reveals only the fields relevant to that choice. Advanced controls for occurrences, sessions, discounts, recordings, capacity, and operations remain available through the workspace when needed.
- A simple event starts with one automatically created occurrence. The authoring workspace offers “Add another date” for additional occurrences and a separate recurring generator for repeated patterns, without forcing every organizer through a complex schedule builder.
- Organizers may change an event between Open Door, Free Registration, and Paid Tickets while it has no existing registrations or sales, subject to the new readiness checks. Once participation or sales exist, changing the mode uses a protected impact workflow and never silently invalidates existing people, admissions, or financial records.
- Ticket setup is fully editable before sales, but after the first reservation or sale the material terms (price, currency, scope, quota pool, and purchase limits) are frozen for existing admissions; safe descriptive edits remain allowed, and material changes create a new ticket type after hiding the old one. Seating mode is future scope. This needs generic ticketing revision/freeze support so passes and reports remain historically correct.
- The shared event checkout hold window is 15 minutes by default, matching the current inventory default. Ticket inventory/capacity, voucher/promotion reservations, and the payment attempt share one expiry reference; expiration or failure releases every applicable v1 reservation together, while provider callbacks remain idempotent and cannot resurrect an expired hold silently. Seat holds join this contract when seating is added later.
- An expired checkout is not silently renewed. The buyer sees a clear expiry state and may retry immediately; the cart selection can be retained for revalidation, but unavailable tickets or capacity require a fresh valid allocation before a new hold is created. Late payment callbacks are reconciled without automatically issuing an admission.
- Ticket purchases must use the existing `aiarmada/ticketing` `AddTicketTypeToCartAction` / `aiarmada/events` `AddEventTicketTypeToCartAction` path, which stores the TicketType as the cart item with event/occurrence/session scope and participant data. `TicketTypeProduct` is reserved for products included with a ticket; the event workflow must not create duplicate product records merely to sell tickets.
- A purchaser may cancel a free admission before the configured changes cutoff. Cancellation releases its capacity and sends an updated confirmation; it does not create a refund because no payment was made.
- V1 does not offer self-service ticket upgrades, downgrades, or exchanges after purchase. A buyer may use an eligible refund and make a new purchase, or an authorized organizer may perform a controlled exchange while preserving the audit trail.
- Buyers cannot arbitrarily move an admission to another occurrence in v1. Cross-occurrence moves are available only through an organizer-driven reschedule workflow that validates scope and capacity.
- Refunding an admission purchased with a voucher or coupon does not automatically restore the code's usage. An authorized organizer or administrator may explicitly issue a replacement when appropriate.
- Joining a waitlist does not lock a ticket price or discount. When capacity becomes available, the offer shows the current eligible ticket price and terms and includes a clear acceptance deadline.
- After an event or occurrence, authorized organizer roles may explicitly bulk-mark remaining `Not recorded yet` participants as `Did not attend`, with a warning, confirmation, and audit trail. The system never infers absence automatically.
- “Duplicate event” creates a new draft and copies reusable content and setup, including schedule, ticket, registration, or recording configuration where safe; it never copies orders, payments, participants, admissions, attendance, refunds, or historical audit records. Copied commercial terms require review before publishing.
- Unpublished events support private preview through an authenticated or expiring secure link. They are not searchable or publicly purchasable until ordinary readiness and commerce gates pass.
- Archiving preserves the event's prior visibility: public events may remain public read-only archives, while private or unlisted events remain restricted unless the organizer explicitly changes visibility.
- Open Door events do not create participant lists or attendance records by default. An organizer who needs registration, a list, or later attendance marking chooses Free Registration instead.
- Drafts may be deleted by their creator. Once an event has registrations, admissions, orders, payments, attendance, or other operational history, it becomes archive-only rather than being destructively deleted.
- A published event may be paused or hidden from new public discovery without invalidating existing admissions. Existing buyers retain their receipt and admission access, and the visibility/sales change is audited.
- Event visibility has three clear modes: `public` (searchable and discoverable), `unlisted` (the page can be viewed by anyone who has the link and is excluded from discovery), and `private` (the page is accessible only through a secure mechanism such as a temporary signed link or access code). Event visibility and ticket visibility remain separate controls; page visibility does not override the mandatory purchaser account/payment rules.
- Unlisted page access is intentionally simple: possessing the link is enough to view the event page. Private page access must pass the configured secure-link or access-code check, and a direct ordinary page URL does not reveal the protected event.
- When waitlist capacity is released, the system automatically offers it to the first eligible person by default. Organizers may pause, skip, or override the queue only through an audited operational action.
- A failed or abandoned online payment may be retried with a fresh payment attempt and fresh reservations after revalidation. It must not create duplicate orders, charges, admissions, or voucher consumption; provider callbacks remain idempotent.
- Payment-method availability is controlled by the active platform payment provider in v1. Organizers do not configure gateway-specific methods per event; future provider adapters may expose their own supported capabilities.
- Tax display and price-inclusion behavior are already settled by the existing tax decisions above. No additional tax policy is introduced in this batch; activation remains dormant until the previously defined tax foundation is configured.
- Offline-payment refunds, voids, and corrections require an authorized owner/finance role, a reason, and notes or proof. V1 does not attempt automatic bank reconciliation.
- Buyer support uses the event’s configured support contact, inherited from the operational organization when not overridden, with ilmu360° support available for escalated disputes. Support requests and outcomes remain auditable even without a separate v1 helpdesk product.
- If the active payment provider is unavailable, v1 pauses new checkout attempts until an administrator switches providers. Existing attempts remain bound to their original provider and are not silently moved to another gateway.
- Ticket selling cannot be published when the event currency is unsupported by the active provider. Readiness explains the unsupported-currency problem and the available correction.
- Pending bank-transfer admissions have a configurable confirmation deadline. If staff do not confirm payment before it expires, the provisional admission and its capacity reservation are released safely.
- Sponsored or scholarship admissions use the complimentary-admission flow with an optional funding/reason label, consume capacity, and do not create a fictional payment transaction.
- A signed-in buyer may resume their saved cart after leaving, but the 15-minute reservation is authoritative: an expired hold is revalidated and recreated only when ticket, capacity, discount, and payment conditions still pass.
- Cancellation and refund requests belong to the purchaser who made the order. A participant who is not the purchaser cannot request or initiate cancellation/refund for that admission; an authorized organizer or administrator may perform a controlled exception. A participant may still update permitted personal details or transfer through the allowed workflow.
- Organizer announcements for schedule, venue, access, or material event changes are transactional messages to affected participants, with purchaser fallback where needed, and do not depend on marketing consent.
- Public archived event pages remain available indefinitely by default, while organizers may later hide the archive. Operational and financial records follow the platform’s separate privacy-retention rules.
- Authorized owners and event-team members may duplicate an event through a reviewable checklist of reusable content and setup.
- Duplication always creates a new private draft; it never publishes or exposes the new event automatically.
- The checklist can copy public content, schedule structure, ticket configuration, registration questions, Event Agreement structure, venue setup, and recording configuration independently.
- Copied dates and times require explicit organizer confirmation before the new event can be published.
- Copied ticket types retain their configuration and prices but start with zero sales, reservations, admissions, and payment history.
- Orders, payments, participants, admissions, attendance, refunds, disputes, settlements, and other transactional history are never copied.
- Private links, access codes, voucher codes, invitation allocations, and reserved blocks are cleared or regenerated and never remain valid in the new event.
- Copied registration questions become new definitions without previous participant answers.
- A copied Event Agreement becomes a new draft version and must be reviewed before it can apply to new registrations.
- Recording configuration may be copied for review, but external sources and protected access credentials require revalidation; a copied recording is not published automatically. Duplication remains limited to the current operational owner and authorized team, while another organization must create its own event.
- Public event pages use distinct states and copy for `Registration Closed`, `Sold Out`, `Paused`, `Cancelled`, and `Archived`, rather than presenting every unavailable event as a generic failure.
- A completed registration issues its admission automatically once required participant data and any blocking Event Agreement acceptance are satisfied. Normal v1 registration has no organizer-by-organizer approval queue.
- Eligibility rules such as age, gender, membership, or required identifiers are evaluated before the final capacity reservation and admission issuance. The registration flow explains why a participant is ineligible without leaking sensitive matching details.
- Private-event invitations do not create a separate manual approval queue in v1. Authenticated invitations, access codes, vouchers, and organizer-issued admissions provide the access controls.
- A waitlist offer is for the ticket type and scope the person joined, subject to current eligibility and terms. It does not silently substitute a different ticket or broader access.
- The purchaser dashboard shows the authenticated buyer’s orders across all events, with each order retaining its own event, participant, admission, receipt, refund, and settlement context.
- Changing the purchaser’s account email does not break historical ownership. Existing orders, receipts, admissions, and audit records remain linked to the same User while new notifications use the verified replacement address.
- A payment dispute or chargeback does not invalidate an admission merely because a dispute was opened. Admission and entitlement changes occur after a verified provider reversal or other authoritative financial outcome.
- Checkout abuse prevention, idempotency, rate limits, and fraud-related payment safeguards belong in reusable Commerce/payment infrastructure; ilmu360° supplies event-specific messaging and policy decisions.
- A paid admission cannot be silently relabelled as complimentary. Any correction uses a refund/adjustment plus a separately issued complimentary admission, preserving the original financial record and audit trail.
- Once an admission is issued, an organizer cannot reject it through an ordinary registration action. A genuine exception uses the existing cancellation/refund or audited administrative workflow with notification and history.
- Events are not transferable between organizations. The event’s operational owner remains immutable; a different organization must create a new event, while the original event’s orders, admissions, payments, settlements, and history remain with the original owner.
- Changing a payout destination requires platform finance/admin verification before it can receive future settlements.
- The settlement destination is frozen once ticket sales begin. Any later change requires an audited finance/admin action and does not silently redirect already-earned event proceeds.
- If an Event Agreement changes after registrations exist, existing participants retain the exact version attached to their registration; the new version applies to new registrations unless a high-risk change is explicitly configured to require re-acceptance.
- High-risk agreement changes show an impact preview listing affected admissions, notifications, and any required re-acceptance before publication.
- If an admission email bounces, the purchaser and authorized organizer see a delivery warning and may correct or resend it. Email content and subjects do not expose sensitive participant data.
- Participants may request access, correction, or deletion of their personal event data, subject to preserving the minimum original records required for legal, financial, attendance, refund, and operational purposes. Where deletion is permitted, personal data is deleted rather than replaced with anonymized records.
- Detailed audit history is visible only to owners, managers, finance/reporting roles, and platform administrators. Purchasers, participants, and ordinary check-in staff see only the history relevant to their permitted actions.
- Check-in staff see the minimum identity, admission, and attendance fields needed for their assigned event and shift; sensitive registration answers and financial information remain restricted.
- Archived events retain reports, receipts, admissions, refunds, and attendance records for authorized users, even though public sales and registration are closed.
- Platform administrators may suspend an event for abuse, legal, or safety reasons. Suspension pauses new public activity and commerce, records the reason and actor, and does not silently erase existing history.
- During a suspension, existing admissions remain valid by default. A separate explicit cancellation or safety decision is required before admissions are invalidated.
- Settlement may be held for disputed, refunded, or unresolved amounts without freezing unrelated event revenue. Any hold and release remains traceable to the affected orders or financial cases.
- Payment-provider credentials, webhooks, and gateway configuration are platform-admin concerns. Organizers never receive provider secrets or edit raw gateway settings.
- Future currency activation does not perform automatic conversion. Each event selects one supported currency and enters ticket prices in that currency; mixed-currency event carts remain unsupported.
- Commerce defines one consistent money precision and rounding policy for ticket prices, discounts, tax, refunds, receipts, and settlement calculations.
- Participant-data retention is governed by ilmu360° privacy policy and legal/operational requirements. Organizers cannot freely erase records that must be retained for financial, attendance, refund, or dispute purposes; once retention ends, permitted personal data is deleted, with no v1 anonymization workflow.
- V1 participant exports provide a print-friendly browser view and CSV download. Additional spreadsheet formats may be added later without changing the event data contract.
- Public pages do not expose participant names, attendee lists, or attendance counts. Availability messaging remains aggregate and privacy-safe.
- Event URLs use a stable canonical identity and continue resolving safely through edits and archiving; redirects or compatibility handling preserve old links when a display slug changes.
- An event-wide pass reserves capacity across every applicable occurrence when purchased, so its holder is guaranteed access to the dates included in the pass. The reservation is coordinated across all relevant occurrence/session capacity contributors.
- Session-level admissions count toward their parent occurrence’s total capacity. The capacity engine prevents session allocations from bypassing the occurrence or event ceiling.
- An event-wide pass is purchasable only when its complete set of applicable occurrence reservations can be secured. A partial reservation cannot issue a pass.
- Event-wide-pass waitlists are separate from occurrence/session waitlists because they represent different capacity promises and ticket scopes.
- V1 does not permit ordinary sales or issuance above configured capacity. Any emergency exception is an administrator-controlled, reasoned, and audited action.
- Staff, speaker, and reserved-guest allocations consume capacity through hidden or complimentary admissions, rather than invisible manual reductions that cannot be reconciled.
- When an organizer issues an admission without a separate buyer, the issuing organizer is recorded as the order subject/issuer and the named attendee remains the participant.
- Recording-only admissions require participant details for entitlement and delivery, but consume no physical event capacity and do not require live check-in.
- Availability is shown per occurrence/session. One sold-out date does not hide another date that still has capacity, and event-wide-pass availability is shown separately.
- An event-wide pass does not require advance date selection. At arrival, staff validate the pass against the selected occurrence/session; the pass’s prior capacity reservation makes this safe.
- Authorized organizers may issue multiple complimentary or offline admissions through a bulk table that validates each participant, ticket scope, capacity, payment state, and agreement requirement.
- V1 bulk admission issuance uses a guided table; CSV import is deferred until the workflow proves it is needed.
- Organizers may reserve blocks for staff, speakers, and invited guests before public sales, represented as real hidden or complimentary allocations that consume capacity.
- Unused reserved allocations may be released back to public availability before the applicable cutoff, with the release recorded in the audit history.
- Public ticket sales and registration may be closed while explicitly enabled organizer-issued/on-site admissions remain available. Manual issuance still requires authorization, capacity, participant data, and audit history.
- If on-site/manual issuance is enabled, authorized staff may issue an admission after the event starts as an explicit operational exception. This is not public post-start selling or ordinary self-registration and must show a warning and record the reason.
- Hiding or unpublishing a ticket type does not invalidate admissions already issued for it; those admissions retain their original scope and terms.
- An event-wide pass becomes non-transferable after its holder checks in for any applicable occurrence/session. Before use, the normal transfer cutoff applies.
- A session-scoped admission is rejected when presented for another session, even when both sessions belong to the same occurrence, unless a separately issued valid admission covers that session.
- Lost QR credentials can be regenerated or resent by authorized users without changing the underlying admission, order reference, participant identity, or access scope.
- Each event or occurrence may configure when check-in opens; the v1 default is two hours before the applicable start time.
- Live check-in closes automatically at the scheduled end plus a short configurable grace period. Post-event attendance corrections remain available through the separate privileged workflow.
- V1 does not support ordinary re-entry with the same admission after it has already been checked in.
- An event-wide pass can be checked in once for each applicable occurrence, but a second check-in for the same occurrence is rejected unless a privileged correction is made.
- Presenting an occurrence- or session-scoped admission at the wrong scope is rejected with a clear explanation and no attendance record is created.
- Check-in staff access is assigned to a specific event and may be narrowed to an occurrence or session; the staff workspace does not expose unrelated event data.
- Manual lookup shows masked identity details and requires staff to confirm the matching participant/admission before completing check-in.
- Correcting an accidental check-in requires a privileged role, a reason, and an immutable correction/audit record.
- The live check-in screen shows only limited operational counts such as expected admissions, checked-in admissions, and remaining admissions.
- Printable participant lists include attendance/check-in columns and only fields allowed for the staff member’s role and event scope.
- Joining an online event does not automatically mark the participant as attended. Online access and attendance remain separate records.
- A hybrid admission permits either physical entry or online access for its valid scope; the participant is not required to use both channels.
- If an event changes from in-person to online, existing admissions remain valid and receive updated protected access instructions.
- If an event changes from online to in-person and capacity is affected, the impact workflow offers affected buyers a keep, transfer, or refund path.
- When an online access URL changes, old signed links are revoked immediately and new protected links are delivered to eligible admissions.
- Recordings added after the live event notify eligible participants only after the recording is published and its access policy is active.
- Recording-only purchases create an entitlement confirmation without a physical-event QR code or live check-in requirement.
- Recording-only products display their own refund and expiry policy separately from live-admission policy.
- If a live event is cancelled but a recording exists or will be produced, recording access is handled through an explicit organizer remedy rather than silently granted or removed.
- Recording access ends at its configured expiry. Any extension is an explicit organizer action with an updated audit record and notification where appropriate.
- An event-wide pass purchased before a new occurrence is added does not automatically include that later occurrence. Adding it to existing passes requires an explicit upgrade or remedy so capacity and commercial terms remain honest.
- New occurrences inherit the event’s registration, ticket, capacity, agreement, and recording setup by default, with a clear “Use a different setup” override.
- An event-wide pass remains valid across occurrences with different venues or capacities when each applicable occurrence reservation succeeds.
- An unsold occurrence may be removed directly from a recurring event. Removing an occurrence with registrations or admissions requires the protected impact workflow.
- V1 supports overnight events that cross midnight. Start/end ordering uses the intended local date-time rather than assuming the end must be on the same calendar date.
- An end time is required when physical capacity, check-in, or online access windows depend on it, but remains optional for simple informational events that need only a start time.
- Sessions may cross midnight when their effective start and end times are valid and their location/session conflict rules pass.
- Recurring-event generation provides a preview showing conflicts, invalid dates, and dates that cannot be generated. No date is silently skipped.
- Paid ticket sales remain behind a feature flag until the full provider, reservation, admission, refund, and settlement workflow is proven end to end.
- Development and staging use separate CHIP sandbox credentials from production credentials; live payment configuration is never used for test flows.
- Generic enhancements are implemented and tested in `/Users/Saiffil/Herd/commerce` first, then integrated into ilmu360° through stable package contracts.
- Package integrations use contracts, contributors, registrars, and resolvers rather than application-specific hard-coded package detection or duplicate domain models.
- Payment callbacks, admission issuance, capacity reservations, refunds, and notifications are idempotent and safe to retry.
- Orders, admissions, participant answers, agreements, discounts, tax outcomes, and refunds preserve immutable historical snapshots wherever later edits could change the meaning of the original transaction.
- Payment success issues admissions through a transactional, retry-safe server workflow. The browser return page can report status but cannot authorize an admission by itself.
- The verification suite covers successful payment, timeout, duplicate callbacks, provider failure, refund failure, expired holds, transfers, cancellation, chargebacks, and recovery/retry paths.
- Rollout is staged: prove free registration, organizer-issued admissions, paid CHIP checkout, refunds/settlements, and operational check-in in sequence while preserving the final v1 scope.
- Unfinished capabilities such as assigned seating remain absent from v1 UI and authoring flows even when their package contracts are installed for future activation.
- Web checkout and future mobile/API checkout use the same server-side actions and contracts; presentation layers do not create separate business rules.
- The event workspace shows a readiness checklist explaining every missing requirement for publication or ticket sales, with links to the relevant setup section.
- High-impact actions such as cancellation, capacity changes, refund overrides, suspension, and manual issuance require explicit confirmation before committing.
- Where safe, reversible actions expose an undo or recovery path; irreversible financial and historical actions remain separate audited transitions.
- Draft autosave preserves organizer work without reserving capacity, consuming vouchers, or creating orders/admissions.
- Before ticket sales are published, the workspace provides a dry-run summary of provider, currency, capacity, ticket, agreement, and settlement readiness.
- Payment, tax, seating, recordings, and future providers are activated through configuration or feature flags rather than UI assumptions or code forks.
- AIArmada package changes include focused tests, migration notes, and documented contracts before ilmu360° depends on them.
- CI verifies that application code uses the generic package paths and does not recreate or bypass reusable Commerce behavior.
- Free registration, paid checkout, organizer-issued admissions, refunds, transfers, and cancellations share one admission/order workflow; only the entry point and payment state differ.
- The organizer must deliberately choose Open Door, Free Registration, or Paid Tickets; the form does not silently preselect a participation mode.
- The first authoring screen stays small, asking for the mode, title, and basic event identity before revealing deeper choices.
- The simple path automatically creates one occurrence, while advanced schedule tools remain available when the organizer needs them.
- Advanced schedule, ticket, discount, recording, capacity, agreement, and operations controls are progressively revealed only when enabled or requested.
- Organizers can preview the public event page before publishing, using the same visibility and readiness rules that the real public page will use.
- The workspace shows clear Save Draft/autosave status so organizers know whether their work is safely persisted.
- The readiness checklist remains accessible from every workspace section and links directly to missing setup.
- Mobile authoring uses one focused step at a time; desktop may show more context without changing the underlying workflow.
- Moving backward through the workflow preserves entered state and does not silently reset dependent fields; changes clear only values that are no longer valid, with an explanation.
- The final publish review summarizes visibility, schedule, location, registration, tickets, capacity, agreements, recordings, payment, and settlement before the organizer confirms publication.
- An unauthenticated buyer is asked to sign in or create an account immediately when choosing Register or Buy; the event flow does not begin with guest checkout.
- After authentication, the buyer returns to the same event and retains their selected ticket and schedule choices, subject to fresh availability validation.
- Email verification is required before capacity is reserved or payment begins.
- Checkout labels Purchaser and Participant as separate roles, explaining that the purchaser owns the order while each participant receives their own admission.
- The purchaser is prefilled as the first participant, and additional participants are added one admission at a time.
- Each admission appears as its own participant card and reveals only the questions relevant to its ticket, occurrence, session, and event settings.
- Overlapping admissions for the same participant are flagged immediately during checkout, before payment or final capacity commitment.
- Buyers choose an occurrence or session before viewing the ticket types that apply to that schedule scope.
- The checkout order summary remains visible and shows schedule, participants, prices, discounts, tax when active, and the final total.
- The final action is explicit: `Confirm Free Registration` for a zero-total flow and `Proceed to Payment` when money is due.
- Payment remains on the provider-hosted CHIP page; ilmu360° shows a clear handoff and never collects card credentials in the event form.
- Before redirecting to payment, checkout shows the temporary reservation expiry and explains what happens if the hold expires.
- The provider return page displays `Verifying payment` until a server-side callback or status check confirms the outcome.
- Only verified provider success can commit the order, admissions, capacity, vouchers, and notifications.
- A failed payment retains the buyer’s intended selection where useful, but releases the old capacity and discount reservations and revalidates them before retry.
- A pending payment has a status page with refresh and safe retry guidance; it does not present a second-charge action while the original attempt is unresolved.
- ilmu360° never stores card numbers or other sensitive payment credentials; provider-hosted payment handles them.
- The purchaser dashboard shows provider, payment status, order number, amount, and payment date without exposing unnecessary gateway internals.
- Confirmation email and admission delivery occur only after verified payment success, except for the explicitly defined free and offline states.
- Provider outages show a specific explanation and safe recovery path, preserving the checkout intent without pretending that payment or admission succeeded.
- Voucher and promotion rules are re-evaluated at payment confirmation, not trusted solely from the initial checkout calculation.
- Pausing a campaign stops new redemptions while leaving discounts on completed orders unchanged.
- Changing ticket quantities, participants, or schedule scope recalculates all applicable discounts before the buyer can continue.
- Discounts cannot reduce an order below RM0; a zero result becomes the defined free-confirmation flow.
- Voucher usage is reserved during checkout and committed only after successful order/payment confirmation.
- Expired, failed, or abandoned checkout releases temporary voucher reservations idempotently.
- Promotion expiry is displayed in the event’s effective timezone and stored/compared using safe UTC timestamps.
- Receipts and organizer settlement reports show applied promotion/voucher names, discount amounts, and reasons.
- Disabling a campaign never rewrites discounts already granted to completed orders.
- Every discount is validated against its event, occurrence, session, and ticket scope so it cannot affect unrelated event commerce.
- Free Registration and Paid Ticket events enable an Event Agreement by default; Open Door events leave it off unless the organizer explicitly requests participant acknowledgement.
- Organizers build the Event Agreement through guided sections and approved content blocks, not raw legal JSON or an unstructured technical editor.
- Participants see a short plain-language summary first and can expand/read the complete agreement before accepting.
- One acceptance covers the complete Event Agreement, avoiding a confusing checkbox for every clause; marketing consent remains separate.
- ilmu360° purchase/platform terms remain separate from organizer-authored Event Agreement terms.
- Every named participant receives an individual secure agreement link even when someone else purchased the admission.
- For an adult without email, an organizer may use controlled-device assisted acceptance with the participant, staff member, device/session, and timestamp recorded.
- A minor requires a named parent or guardian to accept the Event Agreement on the minor’s behalf.
- The organizer chooses one simple event-level setting for whether pending agreement acceptance blocks admission activation; status and history remain visible in either mode.
- Public physical events show the exact venue address by default, while organizers may choose to show only the general area for safety or privacy.
- Unlisted/private physical events show the venue name and general area as allowed, but reveal the exact address only through the event’s configured protected access or valid admission flow.
- When an institution is selected as the venue without a hall or room, its public name and effective address are used automatically.
- Selecting a `VenueSpace` automatically shows its name and stored capacity, subject to the event’s lower safety ceiling.
- An occurrence or session may select a different room or space from its parent event/occurrence, with the effective location captured for its admissions.
- Maps and navigation links appear only when the underlying address is permitted to be public.
- Online-only events hide physical-location fields; hybrid events show both physical venue information and protected online access instructions.
- Venue or room changes after registration use the impact workflow, notify affected people, and update their admission information.
- Overlapping use of the same managed venue space blocks publication by default; an authorized manager may override with a recorded reason.
- An unlisted event with public tickets can be viewed and purchased by anyone who has its link, subject to mandatory buyer authentication and ordinary checkout rules.
- A private event hides its page and ticket choices until the configured secure access check succeeds.
- A public event may contain private ticket types for selected audiences; event visibility and ticket visibility remain independent layers.
- Hidden ticket types remain invisible even to people who can view the event page and are used only through authorized organizer/operational issuance.
- Private access links are temporary and renewable without changing the event or admission identity.
- Private access codes can be revoked and are protected by rate limits and server-side validation.
- Revoking private access removes the person’s access to the protected event page and its private ticket choices.
- Visibility changes after purchase do not remove existing receipts, admissions, or valid participant access.
- Unlisted links remain usable until the organizer changes visibility or hides/archives the event.
- Event pages and event authoring use the application’s active language/locale; v1 does not require a separate event-level primary-language selector or multilingual event-content workflow.
- Public and unlisted events may produce social-sharing previews, while private events do not expose page metadata or preview content before secure access succeeds.
- Search-engine indexing and structured SEO metadata are enabled for public events only; unlisted events remain link-accessible but are excluded from discovery and indexing.
- The authoring form explains Public, Unlisted, and Private in plain language, including who can view the page and how ticket access differs.
- The organizer workspace opens with one overview showing readiness, upcoming schedule, registrations, admissions, capacity, attendance, and sales status.
- Setup/readiness information is visually separated from live operational statistics.
- The main workspace sections are Schedule, Registration, Tickets, Capacity, Participants, Check-in, Recordings, Finance, and Settings, with unsupported sections omitted or marked as future capability where appropriate.
- Each section shows only controls relevant to the event mode, user role, and enabled capabilities.
- Organizers can filter participants and admissions by occurrence, session, ticket type, participant, agreement status, payment state, and attendance.
- Check-in staff land directly in their assigned check-in workspace rather than the full organizer dashboard.
- Finance and settlement information is hidden from content editors and check-in staff.
- The workspace includes an event timeline for important changes, notifications, admissions, enabled refunds, transfers, and attendance corrections.
- Every export and report states its scope and generation date so a printed list cannot be mistaken for current live data.
- Empty or incomplete sections explain the next recommended action instead of presenting an unhelpful blank state.
- Transactional email uses the recipient’s preferred language when available, with the application’s active language/locale as fallback.
- A purchaser receives one order-summary email rather than a separate full message for every admission; individual participant links are included where appropriate.
- Participants with their own email receive only their own admission, agreement, schedule, and recording communications.
- Participants without email rely on purchaser or organizer-assisted communication for their admission and agreement actions.
- Event reminders default to 24 hours and one hour before each applicable occurrence/session.
- Organizers may adjust reminder timing or disable reminders for an event, but cannot disable essential operational notices.
- Schedule, venue, access, cancellation, and postponement notices are transactional and are sent regardless of marketing preferences.
- Organizer-customizable templates cannot remove platform-controlled payment, admission, privacy, legal, or unsubscribe sections.
- Authorized users can resend admissions, agreement links, receipt links, and reminders without creating duplicate records or consuming new capacity.
- Important participant communications expose delivery status such as Sent, Delivered, Bounced, or Failed in the authorized workspace.
- Every admission requires only the participant’s full name by default.
- Participant email and phone remain optional unless needed for personal delivery, agreement acceptance, or online access.
- Organizers may add custom questions at event, occurrence, session, or ticket scope, with purchaser questions kept distinct from attendee questions.
- V1 custom questions use guided types: short text, long text, number, date, yes/no, single choice, and multiple choice.
- File uploads, signatures, calculations, and complex branching remain unavailable in v1 until generic package support is ready.
- Conditional questions support one simple dependency level in v1; nested rule trees remain deferred.
- A hidden conditional question is excluded from validation and its unsaved answer is cleared, while completed registration answers remain unchanged.
- A question definition is versioned once a participant has answered it; material wording, option, requiredness, and condition changes create a new version.
- Organizers can preview the registration form with sample participant answers before publishing or applying changes.
- Participants may correct permitted answers until the configured changes cutoff; original submitted answers remain preserved as historical records.
- Participant claim links expire after a limited period and can be safely resent by authorized users.
- Each claim link is single-use and is replaced by authenticated participant access after successful claiming.
- If a participant never claims an admission, the purchaser’s valid admission access remains available.
- A claimed participant can update only their own permitted details; purchaser and organizer permissions remain separately scoped.
- Purchasers cannot accept, decline, or edit another participant’s Event Agreement except through the defined guardian or assisted-acceptance paths.
- Multiple participants may share an email address without their event records being automatically merged.
- IC/passport values are encrypted or otherwise strongly protected and excluded from ordinary search indexes and exports.
- Every view or lookup of sensitive participant identity data is audit-logged.
- Participant account claiming never changes purchaser ownership of the order or admission.
- Deleting a participant account removes its claim and personal access while preserving the event, admission, attendance, refund, and financial history.
- Every event team member uses their own verified ilmu360° account; shared logins are not permitted.
- The event owner/manager controls the full event, including schedule, tickets, participants, operations, and settings.
- Content/schedule editors manage event information but not payment, refunds, finance, or sensitive participant data.
- Ticket/registration managers manage admissions, participant records, capacity, and organizer-issued tickets within their event scope.
- Check-in staff see only the identity, admission, and attendance information for their assigned event/occurrence/session.
- Finance/reporting users see receipts, settlements, and aggregate financial reports without unnecessary participant answers.
- Team invitations expire and require the invited user to accept personally through their own account.
- Removing or revoking a team member takes effect immediately for new and existing workspace access.
- IC/passport data and other sensitive answers require an additional explicit permission beyond ordinary participant management.
- Role changes, invitations, acceptances, revocations, and sensitive-data access are recorded in the event audit timeline.
- Every event receives a settlement statement, including events with zero sales.
- Settlement statements separate gross ticket sales, discounts, active tax, refunds, provider fees, disputed amounts, and net payable.
- Organizers see settlement lifecycle states such as Pending, Held, Ready, Paid, and Reconciled.
- Settlement becomes Ready only after the final occurrence and the applicable refund/chargeback exposure period have closed.
- Provider fees remain an organizer-side deduction and never become an unexpected buyer charge.
- Disputed or unresolved amounts are held separately while unrelated revenue remains eligible for settlement.
- Finance/admin records the payout date, amount, method, destination, reference, and confirming staff member.
- Organizers may download settlement statements but cannot mark their own payout as completed.
- Refunds, cancellations, and payment reversals update settlement calculations without rewriting the original order receipt.
- Financial reports use the event’s currency and preserve the original currency and amounts when future currencies are added.
- After ticket sales begin, organizers may edit public content such as the title, description, and imagery, while each published revision and editor are retained in the audit history.
- Changes to dates or times after sales begin use a rescheduling and impact-review workflow, notify affected people, and preserve the original schedule history.
- An occurrence or session with registrations or admissions cannot be deleted; it can be cancelled or archived through the scope-aware operational workflow.
- Capacity cannot be reduced below sold, confirmed, pending, or currently reserved admissions; an unsafe reduction requires a blocked change and an authorized resolution.
- Capacity may be increased after sales begin when the effective venue or organizer safety limit supports it and the normal readiness checks pass.
- Ticket price changes are versioned and apply only to future purchases; existing orders and admissions retain their original price snapshots.
- A sold ticket type may be hidden from future buyers but cannot be erased while admissions or financial history refer to it.
- New ticket types may be added after sales begin when capacity, payment, tax, agreement, and settlement readiness remain valid.
- Registration question changes create a new version for future participants; existing answers remain attached to the version answered.
- Event Agreement changes are versioned after registrations exist; existing participants retain their accepted version, and re-acceptance is required only when explicitly configured for a high-risk change.
- The default maximum quantity per checkout is 10 admissions, with event- or ticket-level configuration available for organizers who need a different limit.
- A submitted ticket line must contain at least one admission; a purchaser may buy multiple admissions of the same ticket type for different participants.
- One participant may hold multiple admissions only when their applicable schedules do not overlap; overlapping admissions are blocked during checkout.
- Free registrations, paid tickets, and organizer-issued admissions consume the same applicable capacity unless the organizer explicitly configures separate allocations.
- Complimentary, sponsored, and organizer-issued admissions always consume capacity, while planned hidden allocations may reserve capacity until their release rule is reached.
- Sold-out and availability labels are evaluated at the relevant ticket, occurrence, session, or event scope, so one exhausted ticket type does not hide other available ticket types.
- Shared capacity pools across ticket types are a reusable inventory capability; if the current package lacks that generic pool, enhance the package rather than duplicating it in the application.
- Waitlists are scoped to the buyer’s requested event-wide, occurrence, or session admission and do not silently substitute a different scope.
- Waitlist offers are FIFO, use the current price and terms, and include an explicit response/payment deadline.
- When an offer expires or its payment fails, the system releases the temporary reservation and automatically offers the next eligible person safely and idempotently.
- Tickets are open to all authenticated buyers and participants by default unless the organizer configures an eligibility restriction.
- V1 supports simple age eligibility modes such as All Ages, Adults Only, and Children/Youth; complex eligibility rule trees remain deferred.
- Exact date of birth is not collected by default. Age bands are preferred, and exact birth dates require a specific justified use case.
- A minor participant requires a named parent or guardian to accept the Event Agreement through the defined guardian path.
- A purchaser may buy a minor’s admission for another person when the required guardian details and acceptance are completed.
- Eligibility is validated before capacity reservation and payment begins, with clear guidance when the participant does not qualify.
- Invitation-only and member-only ticket types use private visibility, access codes, or secure links rather than a separate buyer identity system.
- V1 does not require uploaded proof for student, member, senior, or sponsored tickets; access codes and organizer verification are the initial controls.
- Eligibility rules use reusable access-policy contracts and resolvers from the relevant AIArmada package, with generic enhancements made in the package when required.
- A valid paid admission remains honored if a participant later stops meeting an eligibility rule; retroactive invalidation is limited to verified fraud, legal requirements, or an audited platform action.

## Implementation roadmap — v1 event commerce

### Plan

- [ ] Freeze the package contract matrix and create a written ADR for the event-commerce boundary.
- [ ] Implement and test reusable Commerce capabilities in `/Users/Saiffil/Herd/commerce` before adding application-specific orchestration.
- [ ] Build the ilmu360° Event Workspace over the existing advanced Livewire builder and package event models.
- [ ] Build the authenticated buyer flow, one-event cart, participant registration, agreements, payment, admission fulfillment, and receipts.
- [ ] Build organizer operations for issuance, capacity, waitlists, transfers, refunds, check-in, attendance, recordings, and finance.
- [ ] Run package/application security review, focused and end-to-end tests, staged sandbox/live rollout, and production readiness checks.

### Package-fit boundary

The implementation must keep this boundary visible in code, tests, and package documentation. Existing behavior is reused first; a generic behavior is enhanced in Commerce; only ilmu360° policy, institution context, UI, and application-specific composition live in the application.

| Concern | Existing fit | Generic enhancement, if required | ilmu360° responsibility |
| --- | --- | --- | --- |
| Event hierarchy | `aiarmada/events` already models `Event → Occurrence → Session`, locations, registrations, participants, passes, and attendance | Question definitions/scoping/versioning, strict session matching, and reusable event-commerce contributors | Institution-centered authoring, progressive workflow, public page, policy settings, and permissions |
| Buyer identity | `aiarmada/orders` accepts a customer subject; the application already has authenticated `User` accounts | Keep customer-subject contracts flexible; do not force a Customer record | Use verified `User` as purchaser/order subject; keep `aiarmada/customers` optional and unused for ordinary v1 buyers |
| Ticket cart lines | `aiarmada/ticketing` has `AddTicketTypeToCartAction` and stores participant attributes on cart items | Resolve polymorphic `TicketType` lines in checkout/inventory without pretending tickets are Products; preserve scope and participant data | Schedule-first ticket selection, participant cards, and event-specific validation |
| Capacity and inventory | `aiarmada/inventory` has reservation groups with reserve/release/commit semantics; events has aggregate capacity | Add a shared capacity-pool/contributor contract, event-scope reservation, and one coordinated checkout hold | Configure venue/event/occurrence/session ceilings and show availability |
| Waitlist | Events has waitlist status/primitives | Add FIFO offer, expiry, released-capacity trigger, payment/free-confirmation, and idempotent advancement | Explain queue state and provide organizer pause/skip/override controls |
| Discounts | `aiarmada/cart`, `aiarmada/vouchers`, and `aiarmada/promotions` already provide the discount/rule direction | Add event-scope target contributors only where the current engine cannot express them; preserve reservation/commit/release semantics | Configure simple/advanced event campaigns, codes, and visibility |
| Payment | `aiarmada/cashier`/`checkout` provide provider-neutral gateway contracts and explicit gateway selection; CHIP/`cashier-chip` is available | Fill capability/currency/health/refund seams only when missing; keep callbacks and idempotency generic | Select the active platform gateway, initially CHIP; configure sandbox/production, outage switch, and event readiness |
| Orders and receipts | `aiarmada/orders` has stable `order_number` and runtime PDF generation | Add stable legal-document identity only if a future invoice/e-invoice requirement needs it | Present an authenticated receipt, admission documents, and event/merchant context using immutable order snapshots |
| Tax | `aiarmada/tax` is the dormant foundation | Add generic line context, currency propagation, and immutable tax snapshots if current checkout needs them | Keep tax disabled in v1, expose only approved tax configuration when activated |
| Communications | Commerce communications/notification seams exist | Add recipient/admission-scoped transactional templates and delivery-state hooks where missing | Compose purchaser/participant notices, reminders, agreement links, and event updates |
| Recordings | Event/media foundations exist but not the required entitlement model | Add provider-neutral recording source, publication/access lifecycle, and admission entitlement contracts | Provide upload/external-link UI, protected access, captions/transcripts, and event controls |
| Assigned seating | `aiarmada/seating` exists | Preserve future resolver seams only | Do not activate seat maps, holds, allocation, or buyer seat selection in v1 |
| Settlements | Commerce contains payout/financial primitives, including affiliate payout concepts, but those must not be assumed to be event settlement | Provide a generic settlement statement/line and reconciliation contract if the existing payout model is not semantically suitable | Group event orders, apply ilmu360° merchant-of-record policy, and operate manual organizer payout review |

### Phase 0 — contract freeze and environment preparation

- [ ] Produce a package capability matrix from the installed Commerce revision, including exact model/action/contract names, configuration keys, migrations, and known gaps.
- [ ] Confirm dependency versions and installation order for `commerce-support`, `events`, `ticketing`, `inventory`, `cart`, `checkout`, `orders`, `cashier`, `cashier-chip`, `vouchers`, `promotions`, `communications`, and dormant `tax`.
- [ ] Configure separate CHIP sandbox and production credentials, webhook endpoints, signing/verification, timeout behavior, and provider health checks. Keep secrets platform-admin-only.
- [ ] Define the provider-neutral payment, currency, checkout-session, reservation, admission-fulfillment, refund, and settlement contracts before wiring CHIP into the UI.
- [ ] Define event-level status/visibility/policy enums and transition timestamps. Do not introduce boolean state where a lifecycle status or setting is required.
- [ ] Define the immutable snapshot boundary: ticket terms, participant answers, agreement version, order lines, discounts, tax result, payment provider, currency, admission scope, and settlement references.
- [ ] Add package-fit ADRs and focused package tests for every generic enhancement before ilmu360° depends on it.

### Phase 1 — reusable Commerce/package foundation

- [ ] Extend checkout line resolution so a polymorphic `TicketType` cart item can contribute price, currency, event/occurrence/session scope, inventory, capacity, participant count, eligibility, and fulfillment data without a fake Product/Variant mapping.
- [ ] Introduce one coordinated reservation context for ticket inventory, aggregate event capacity, occurrence/session capacity, voucher usage, and the payment hold. It must have one expiry and idempotent reserve/release/commit behavior.
- [ ] Add shared capacity pools for compatible ticket types while retaining event/occurrence/session hard ceilings. Ensure complimentary, free, offline, paid, and pending admissions use the same contributor path.
- [ ] Add ticket-type revision/freeze semantics for price, currency, scope, quota pool, limits, and sales windows. Existing admissions retain snapshots; future purchases use the new revision.
- [ ] Add purchaser-level and per-order quantity enforcement, participant/schedule-overlap validation, and strict session-scope check-in/fulfillment validation.
- [ ] Complete the waitlist lifecycle: join exact scope, reserve released capacity for an offer, notify, expire, accept free/paid confirmation, release on failure, and advance FIFO exactly once.
- [ ] Add reusable registration-question definitions, scope resolution, one-level conditional visibility, answer validation, versioning, and historical answer preservation.
- [ ] Add provider capability descriptors for supported currencies, hosted/embedded flow, refund support, webhook/status behavior, and health. Store the provider on each checkout/payment attempt so future switching affects new checkouts only.
- [ ] Add generic payment callback idempotency and recovery tests, including late success, duplicate webhook, provider failure, expired hold, refund failure, and chargeback/reversal signals.
- [ ] Add tax context and line-level snapshot hooks without activating tax in the application. Tax calculations must use the event currency and never mutate completed orders.
- [ ] Add a generic settlement statement/line contract only after checking whether the current payout primitives can represent event sales without importing affiliate semantics. Keep event grouping and ilmu360° payout policy outside the generic payment gateway.

### Phase 2 — ilmu360° event data and authoring workspace

- [ ] Keep the existing event aggregate and package relationships as the source of truth; add only migrations/extensions required for event-commerce settings, revisions, consent policy, refund setting, check-in/recording controls, and settlement references.
- [ ] Preserve the existing advanced builder as the route entry point, but refactor its large schema into focused workspace sections/components instead of adding more conditional fields to one method.
- [ ] Start creation with three deliberate choices: Open Door, Free Registration, or Paid Tickets. Create one occurrence automatically; reveal recurrence, sessions, ticket scopes, capacity, agreement, recordings, and operations progressively.
- [ ] Build schedule authoring for occurrences and sessions, including recurrence preview, per-occurrence overrides, overnight times, effective timezone, location/venue space, and conflict/readiness validation.
- [ ] Build registration authoring for participant basics, optional IC/passport fields, custom questions, eligibility, Event Agreement, participant-acceptance enforcement, and delivery settings.
- [ ] Build ticket authoring for general-admission ticket types, price/currency, scope, quota, shared pool, sale window, per-order/purchaser limits, private/hidden visibility, and eligibility. Do not show seating controls in v1.
- [ ] Build venue authoring around package `EventLocation` and `venue_space_id`; support institution-as-venue, explicit venue space, online, and hybrid modes without app-specific hall/room columns.
- [ ] Build the readiness checklist/dry run. It must identify missing owner approval, settlement destination, supported currency/provider, schedule, venue/capacity, ticket terms, agreement, tax readiness when active, and recording/access requirements.
- [ ] Add Save Draft/autosave, public preview, final publish review, safe dependent-state clearing, revision history, impact preview for high-risk edits, and curated Signals tracking for meaningful workflow transitions.

### Phase 3 — buyer checkout and fulfillment

- [ ] Require sign-in or account creation before checkout; verify purchaser email before capacity reservation or payment. Preserve the intended event, ticket, schedule, and participant state through authentication.
- [ ] Keep one event per cart. Add tickets through the package action, then collect one participant card per admission. Pre-fill the purchaser as the first participant and validate scope, eligibility, questions, agreement, limits, and overlaps before payment.
- [ ] Create a pending order and one coordinated reservation group. Recalculate ticket prices, voucher/promotion discounts, tax when activated, currency, and final total at the final review and again at confirmation.
- [ ] Use the hosted CHIP flow through the provider-neutral contract. Browser returns remain informational; only verified webhook/provider status can commit payment, capacity, vouchers, registrations, passes, QR credentials, recordings, and notifications.
- [ ] Support free registration and paid checkout through the same fulfillment state machine. A fully discounted order is a free confirmation; mixed paid/RM0 lines remain one paid order receipt.
- [ ] Add participant claim links, purchaser dashboard, admission delivery, agreement delivery, calendar files/links, reminders, resend, bounce handling, and least-privilege authorization.
- [ ] Render the online receipt and requested PDF from immutable order/payment/discount/tax snapshots. Use the stable order number as the v1 reference; do not rely on the package’s per-render invoice number.

### Phase 4 — organizer operations

- [ ] Build event-scoped roles and workspace sections for schedule, registration, tickets, capacity, participants, check-in, recordings, finance, and settings.
- [ ] Add organizer-issued complimentary, offline-confirmed, and pending-offline admissions through one audited issuance workflow. Enforce capacity and document/payment states.
- [ ] Add purchaser-only transfer/cancellation/refund flows behind the event-level `Refund Policy` setting, Off by default. Keep the private platform/admin reversal path separate.
- [ ] Add occurrence/session-aware reschedule, cancellation, venue change, capacity impact, notification, and remedy workflows. Never delete a scope with registrations/admissions.
- [ ] Add optional online check-in with QR plus manual IC/passport/name/email/phone/order lookup, scoped staff sessions, server confirmation, masked sensitive fields, and audited corrections. Add post-event bulk attendance updates without CSV import.
- [ ] Add recordings as separate first-release access resources: external link or upload, draft/processing/published/archived states, included or separate recording admission, protected stream/download policy, expiry, captions/transcripts, and aggregate analytics.
- [ ] Add public/unlisted/private page access, public-only discovery/SEO, social previews for public/unlisted, support contacts, accessibility/facility information, and application-locale rendering.

### Phase 5 — finance, settlement, and administration

- [ ] Configure ilmu360° as merchant of record for v1 and record the operational organization as the settlement owner without automatic split payouts.
- [ ] Generate one event settlement statement even for zero sales, with separate gross, discounts, tax, refunds, provider fees, disputes/holds, and net payable lines in the event currency.
- [ ] Freeze the settlement destination once selling begins; route changes and exceptional adjustments through finance/admin verification and an audit record.
- [ ] Gate `Ready` settlement status on the final occurrence plus closed refund/chargeback exposure. Finance/admin records payout evidence; organizers can view/download but cannot self-confirm completion.
- [ ] Add finance-only reports, payout batches that retain event/order traceability, immutable receipt/refund references, and provider reconciliation without exposing unnecessary participant data.

### Phase 6 — verification and staged rollout

- [ ] Test generic package contracts first, then application integration tests, using Pest parallel execution and PHPStan level 6.
- [ ] Cover free Open Door, free registration, paid CHIP checkout, mixed tickets, RM0 voucher result, participant-per-admission, multi-occurrence/session scope, shared capacity, waitlist, organizer issuance, transfer, refund toggle Off/On, cancellation, reschedule, check-in, attendance correction, recordings, and settlement.
- [ ] Add adversarial tests for duplicate checkout, duplicate/late callbacks, race-to-last-capacity, expired holds, voucher reuse, provider outage, unsupported currency, unauthorized private links, sensitive-field access, stale staff sessions, and post-sale mutation.
- [ ] Verify browser workflows on mobile and desktop, public/unlisted/private access, authenticated receipt/PDF download, hosted payment handoff, manual check-in, organizer workspace, and finance permission boundaries.
- [ ] Run migration checks against the real local database, package/app syntax and formatting, PHPStan, Blade/view compilation, asset build, translation coverage, diff checks, and security review.
- [ ] Roll out behind feature flags: package foundation → free registration/issuance → CHIP sandbox paid checkout → production paid checkout → refunds/settlement → check-in/recordings. Enable each stage only after its operational runbook and recovery test pass.

### Explicit v1 exclusions

Assigned seats, seat maps, seat holds, group/family tickets, donations/pay-what-you-want, meals/books/merchandise add-ons, multi-event carts, guest checkout, SMS/WhatsApp delivery, offline check-in synchronization, complex eligibility proof uploads, complex question branching, automatic tax, and event-level multilingual content are not part of v1. Their future seams must remain package-compatible but invisible in the v1 workflow.

# Dynamic event image aspect ratios

## Plan

- [x] Inspect the existing event media selection and poster aspect-ratio handling.
- [x] Add 1:1 support and apply dynamic image frame ratios to event cards and detail posters.
- [x] Add focused regression coverage and verify desktop/mobile rendering.
- [x] Document the completed change and verification.

## Review

Event imagery now uses the supported ratio that matches its source: cover artwork is landscape 16:9, posters resolve to the closest supported 1:1, 3:4, or 16:9 ratio, and logo/placeholder fallbacks are square. The Majlis card and event-detail poster both apply the selected ratio without stretching the image.

## Verification

- `./pest --parallel --compact tests/Feature/MediaConversionsTest.php` passed: 42 tests, 87 assertions.
- `npm run build`, `php artisan view:cache`, `vendor/bin/pint --test app/Models/Event.php tests/Feature/MediaConversionsTest.php`, and `git diff --check` passed.
- Browser check confirmed the live Majlis fallback image renders as a square 1024×1024 frame with no horizontal overflow.
- `vendor/bin/phpstan analyse --ansi` still reports only the two existing findings in `app/Http/Controllers/Api/EventController.php:816` and `app/Support/Location/VisitorCountryResolver.php:33`.

# Refine public Majlis discovery page

## Plan

- [x] Inspect the Majlis route, view, components, styles, and current rendered page.
- [x] Implement a compact collapsible top filter and tidy responsive event cards.
- [x] Verify desktop/mobile states, interactions, accessibility, and relevant checks.
- [x] Document the completed work and verification.

## Review

The public Majlis discovery page now uses a compact top filter disclosure that starts closed without active filters and opens automatically when a filter is present. The expanded form uses responsive Filament columns, and the event cards use a calmer two-column layout with a date lockup, clamped metadata, and a compact horizontal action row that remains touch-friendly on mobile.

## Verification

- `npm run build` passed.
- `php artisan view:cache` passed.
- `./pest --parallel --compact tests/Feature/EventSearchTest.php` passed: 104 tests, 396 assertions.
- `git diff --check` passed.
- Browser checks passed at 1280px and 390px: collapsed/default and expanded filter states, responsive filter columns, no horizontal overflow, and 44px card action targets.
- `vendor/bin/phpstan analyse --ansi` still reports two existing errors in `app/Http/Controllers/Api/EventController.php:816` and `app/Support/Location/VisitorCountryResolver.php:33`; no errors were reported in the files changed for this task.

# Auto-fit institution detail hero title

## Plan

- [x] Inspect the institution detail hero markup, responsive styles, and existing page tests.
- [x] Implement responsive title sizing and height containment for the hero content.
- [x] Add regression coverage, update task notes, and verify the live page at desktop/mobile sizes.

## Review

The institution detail hero now measures the cover image and reduces an oversized institution name on desktop until the counters and action row fit within the image boundary. The mobile title remains at its existing responsive size, and the fit logic leaves the content un-clipped if the fixed supporting content cannot fit at the readable minimum.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/InstitutionShowPageTest.php` — 35 passed, 152 assertions.
- `vendor/bin/pint --dirty --test` — passed.
- `git diff --check` — passed.
- `vendor/bin/phpstan analyse --ansi` — only the two existing unrelated baseline errors remain.
- Live browser verification at 1280×800 and 390×844; no horizontal overflow, with the desktop action row ending 16px inside the cover-image boundary.

# Make next majlis rows link to event details

## Plan

- [x] Trace the event detail route and the current next-majlis markup on both directory cards.
- [x] Expose the selected event slug and make each next-majlis row its own direct link.
- [x] Add regression coverage, update lessons, and verify both live directory pages.

## Review

The next-majlis row on both directory cards is now a sibling event link, so it opens the selected majlis detail page directly without nesting links inside the profile card link. Follow controls and profile actions remain unchanged.

## Verification

- `vendor/bin/pest --parallel --processes=4 --compact tests/Feature/InstitutionIndexTest.php` — 34 passed (161 assertions).
- `vendor/bin/pest --parallel --processes=4 --compact tests/Feature/PersonIndexTest.php` — 43 passed (177 assertions).
- `php artisan view:cache`, `npm run build`, `vendor/bin/pint --dirty --test`, and `git diff --check` — passed.
- Collaborative browser preview — live clicks from both `/institusi` and `/penceramah` reached `/majlis/...`; mobile 390×844 checks confirmed direct event hrefs, no nested anchors, and no horizontal overflow.
- PHPStan still reports the two pre-existing errors in `app/Http/Controllers/Api/EventController.php` and `app/Support/Location/VisitorCountryResolver.php`; no new errors were introduced by this change.

# Add next majlis to institution cards

## Plan

- [x] Trace the institution event-count query and the existing speaker-card next-majlis convention.
- [x] Add the nearest public upcoming majlis data and render it on institution cards.
- [x] Add regression coverage, update lessons, and verify the live institution page at desktop and mobile widths.

## Review

Institution cards now show the nearest public upcoming majlis as a compact date-and-title row above the existing footer. The row has no extra icon, and the existing event count and follow control remain unchanged.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/InstitutionIndexTest.php` — 34 passed (159 assertions).
- `php artisan view:cache`, `npm run build`, `vendor/bin/pint --dirty --test`, and `git diff --check` — passed.
- Collaborative browser preview at 1280×800 and 390×844 — next-event content renders, the follow control remains outside the institution link, and there is no horizontal overflow.
- PHPStan still reports the two pre-existing errors in `app/Http/Controllers/Api/EventController.php` and `app/Support/Location/VisitorCountryResolver.php`; no new errors were introduced by this change.

# Make institution-card follow icon interactive

## Plan

- [x] Trace the institution directory card, existing follow action, and auth/tracking conventions.
- [x] Add a separate institution-card follow toggle with persisted state and a filled active icon.
- [x] Add focused regression coverage and verify the live desktop/mobile behavior and guest redirect.

## Review

Institution cards now keep their existing content and event count while adding a bookmark button in the footer row. Authenticated users can follow or unfollow in place, the icon changes between outline and filled states, and guests are sent to login with `/institusi` as the intended destination. The button is outside the institution profile link, so it does not navigate to the profile.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/InstitutionIndexTest.php` — 33 passed (152 assertions).
- `php artisan view:cache`, `npm run build`, `vendor/bin/pint --dirty --test`, and `git diff --check` — passed.
- Collaborative browser preview at 1280×800 and 390×844 — 12 institution follow buttons render, remain outside the profile anchors, sit in the event-count footer row, and do not cause horizontal overflow; guest click goes to `/login?redirect=/institusi`.
- PHPStan still reports the two pre-existing errors in `app/Http/Controllers/Api/EventController.php` and `app/Support/Location/VisitorCountryResolver.php`; no new errors were introduced by this change.

# Make speaker-card follow icon interactive

## Plan

- [x] Trace the existing person-detail follow action, auth redirect, and tracking conventions.
- [x] Add a speaker-card follow toggle with persisted state, a filled active icon, and no profile navigation.
- [x] Add focused regression coverage and verify the live page.

## Review

The speaker-card bookmark is now a valid button outside the profile link. Authenticated users can follow or unfollow in place, with the icon changing between outline and filled states; guests are sent to login with the directory as the intended destination.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/PersonIndexTest.php` — 43 passed (175 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/PersonFollowTest.php` — 7 passed (28 assertions).
- `php artisan view:cache`, `npm run build`, `vendor/bin/pint --dirty --test`, and `git diff --check` — passed.
- Collaborative browser preview at 1280×800 and 390×844 — bookmark stays in the profile-action row; guest click goes to `/login?redirect=/penceramah`, not a profile route.
- PHPStan still reports the two pre-existing errors in `app/Http/Controllers/Api/EventController.php` and `app/Support/Location/VisitorCountryResolver.php`; no new errors were introduced by this change.

# Add next event and follow affordance to speaker cards

## Plan

- [x] Attach each speaker's nearest upcoming public event without changing the existing card data.
- [x] Add only the next-event row and follow icon to the speaker card markup.
- [x] Add focused regression coverage and verify the live desktop/mobile card rendering.

## Review

Speaker cards now show the nearest upcoming public speaker event and a bookmark-style follow icon in the same row as the profile link. Existing counts, sorting, hero artwork, and profile links remain unchanged.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/PersonIndexTest.php` — 40 passed (157 assertions).
- `php artisan view:cache`, `npm run build`, `vendor/bin/pint --dirty --test`, translation JSON validation, and `git diff --check` — passed.
- Collaborative browser preview at 1280×800 and 390×844 — next-event rows and follow icons render; the existing `29 penceramah ditemui` summary remains visible.
- PHPStan still reports the two pre-existing errors in `app/Http/Controllers/Api/EventController.php` and `app/Support/Location/VisitorCountryResolver.php`; no new errors were introduced by this change.

# Remove baked checkerboard from speaker hero artwork

## Plan

- [x] Inspect the artwork at native resolution and confirm the edge mosaic is encoded in the source image.
- [x] Generate a flat, non-gradient extraction plate and create a real alpha cutout from it.
- [x] Remove disconnected background fragments without damaging the arch, plant, microphone, books, or platform.
- [x] Replace the WebP and verify the live desktop/mobile hero plus the preserved result count.

## Review

The hero artwork now uses a cleaned, alpha-preserving WebP produced from a flat extraction plate and a conservative eroded foreground alpha. The checkerboard fragments around the arch, leaves, and platform are removed; no artwork-side gradient, blend mode, or CSS mask is used. The `29 penceramah ditemui` summary remains visible.

## Verification

- `webpinfo public/images/speakers/penceramah-hero-art.webp` — `Alpha: 1`, canvas `1254 × 1254`, no error.
- Collaborative browser preview at 1280×800 — the cutout loads with transparent corners and renders cleanly in the hero field.
- Collaborative browser preview at 390×844 — the decorative artwork remains hidden and the search/count content remains unobstructed.

# Match speaker hero artwork to the hero field

## Plan

- [x] Confirm the `x penceramah ditemui` summary remains in the results header.
- [x] Regenerate the artwork background using the hero's neutral paper palette.
- [x] Replace the WebP asset and correct its intrinsic dimensions in the markup.
- [x] Recheck desktop and mobile rendering, then run the focused verification suite.

## Review

The speaker count was not removed; it remains rendered from the results summary and displays as `29 penceramah ditemui`. The hero artwork is now an alpha-preserving cutout with no artwork-side gradient or rectangular field, so the hero background shows through naturally around the preserved arch, microphone, books, plant, and soft shadow. The existing decorative-only implementation and responsive mobile fallback remain unchanged.

## Verification

- Collaborative browser preview at 1280×800 — transparent regenerated asset loaded at 1254×1254 and blends into the hero field without an artwork-side background.
- Collaborative browser preview at 390×844 — artwork remains hidden and the search/count content is unobstructed.
- `php artisan view:cache`, `npm run build`, `vendor/bin/pint --dirty --test`, `vendor/bin/pest --parallel --compact tests/Feature/PersonIndexTest.php`, and `git diff --check` — passed.

# Add a generated speaker hero illustration

## Plan

- [x] Inspect the `/penceramah` hero structure, styles, and responsive layout.
- [x] Generate and optimize a speaker-themed arch, microphone, books, and plant illustration.
- [x] Place the artwork in the desktop hero with a responsive small-screen fallback.
- [x] Verify Blade compilation, frontend build, focused tests, static checks, and browser rendering.

## Review

The public speaker directory hero now has a dedicated right-side visual built from the ilmu360° palette: a warm plaster niche, emerald microphone, stacked books, and plant. The WebP asset is an alpha-preserving cutout shown as a full right column on desktop, reduced to a quiet tablet accent, and hidden on narrow mobile screens so the search remains the primary action. The artwork is decorative and does not add a new tracking event.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/PersonIndexTest.php` — 40 passed (152 assertions).
- `php artisan view:cache`, `npm run build`, `vendor/bin/pint --dirty --test`, and `git diff --check` — passed.
- Collaborative browser preview — artwork loaded from `/images/speakers/penceramah-hero-art.webp` at desktop width; mobile hero kept the artwork hidden and the search unobstructed.
- Full PHPStan still reports the two pre-existing errors in `app/Http/Controllers/Api/EventController.php` and `app/Support/Location/VisitorCountryResolver.php`; no new errors were introduced by this change.

# Align speaker and institution update-form copy

## Plan

- [x] Trace the shared contribution schemas and update-only media fields.
- [x] Correct Malay labels and contextual hints for speaker and institution updates.
- [x] Add regression coverage and run validation checks.

## Review

Speaker and institution update pages now use the corrected Malay name labels and guidance. Speaker owner-only media fields share the localized labels and hints from the public contribution form, while institution names, alternative names, descriptions, addresses, and media fields now provide clear localized guidance.

## Verification

- Pest focused update-form coverage — passed (2 tests, 23 assertions).
- PHPStan — no errors across 1000 files.
- Targeted Pint, PHP syntax checks, translation JSON validation, and diff checks — passed.

# Match speaker involvement cards to event-list information

## Plan

- [x] Trace the event details and relations already used by the upcoming-event cards.
- [x] Render involvement entries with the same event information and a distinct role-focused color treatment.
- [x] Add regression coverage and verify the rendered profile in Chrome.

## Review

Speaker involvement entries now render the same date, time, location, format, category, and event-link information as the upcoming-event cards. The section headings sit above the card list, each entry clearly identifies the person's role, and the cards use a violet/indigo treatment to distinguish them from the emerald upcoming-event listing.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/PersonShowPageTimingTest.php --filter='shows linked non-person roles in a separate section on the person page'` — passed (11 assertions).
- Chrome DevTools — the profile renders two role entries with `Peranan: Moderator/Khatib`, date/time, location, event category, violet/indigo card accents, no console errors, and all page requests return 200.
- `vendor/bin/phpstan analyse --ansi`, `php artisan view:cache`, `npm run build`, `git diff --check`, and targeted Pint — passed.
- The full timing suite has one unrelated existing failure in the federal-territory address formatter assertion; the role-list test passes independently.

# Localize speaker contribution form in Bahasa Melayu

## Plan

- [x] Audit every visible field, action, dependent location label, and enum option on the new speaker form.
- [x] Translate the form copy and add concise guidance for fields whose purpose or optionality is not obvious.
- [x] Add regression coverage for the localized schema and preserve required/default/conditional behavior.
- [x] Verify the rendered form in Chrome, tests, static analysis, syntax, and diff hygiene.

## Review

The new speaker contribution flow now uses Bahasa Melayu consistently across its field labels, actions, enum choices, location hierarchy, media guidance, and nested institution quick-add form. Contextual hints explain the purpose of names, affiliations, contact details, social links, location, biography, and optional media without changing the existing required or conditional rules.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/ContributionPagesTest.php` — 69 passed (484 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 1000 files.
- Targeted Pint, `php artisan view:cache`, `npm run build`, translation JSON validation, PHP syntax checks, and `git diff --check` — passed.
- Chrome verification — all tabs, dependent Malaysian location labels, contact/social options, media guidance, and institution quick-add copy render in Bahasa Melayu; Filament `Search`/`Clear selection` accessibility names are localized too.

# Restore searches for unindexed speaker records

## Plan

- [x] Reproduce `/penceramah?search=dus` in Chrome and trace the local search path.
- [x] Add a database fallback for verified profiles missing local search-term rows, including alternate names.
- [x] Invalidate the cached empty result and add regression coverage.
- [x] Verify the exact URL, no-match behavior, tests, static analysis, and diff hygiene.

## Review

The speaker search index was only populated for part of the verified directory. Searches now retain the indexed path for indexed profiles while checking canonical person and alternate-name fields for profiles without index rows. The public search cache key was versioned so prior empty `dus` responses cannot survive the fix.

## Verification

- `vendor/bin/pest --parallel --compact tests/Unit/SearchServiceFallbackTest.php` — 25 passed (35 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/PersonIndexTest.php` — 36 passed (127 assertions).
- `vendor/bin/phpstan analyse --ansi`, targeted Pint, `php artisan view:cache`, and `git diff --check` — passed.
- Chrome verification — `dus` returns `Ustaz Ahmad Dusuki Abdul Rani`, `zzz` remains no-match, and `ka` shows the neutral typing state.

# Prevent false empty state during speaker search

## Plan

- [x] Reproduce the short-query transition in Chrome and confirm the server response state.
- [x] Skip search work below the minimum query length and render a neutral typing prompt.
- [x] Add regression coverage and update the lesson notes.
- [x] Re-test slow typing, real no-match searches, and matching searches in Chrome.

## Review

The directory now treats one- and two-character input as an incomplete search rather than a failed search. The Livewire computed path returns before resolving search IDs, and the empty-state panel renders a localized typing prompt. Three-character matches and genuine no-match searches retain their existing result behavior.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/PersonIndexTest.php` — 36 passed (127 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors.
- `php artisan view:cache`, PHP syntax checks, and `git diff --check` — passed.
- Chrome DevTools — `Ka` shows the neutral typing prompt, `Kaz` returns `Ustaz Kazim Elias`, `zzz` shows the genuine no-results state, the performance trace recorded 75ms worst interaction, and no console errors were observed.

# Make speaker directory search snappy

## Plan

- [x] Reproduce live search in Chrome and inspect the Livewire request timing.
- [x] Remove unused catalog resolution and eager loading from the directory render path.
- [x] Reduce the live-search debounce while preserving live results, URL state, and loading markup.
- [x] Run focused regression coverage and re-measure in Chrome.

## Review

The speaker directory no longer resolves unused title/language/state catalogs or eager-loads title assignments on every search update. Live search now waits 150ms instead of 300ms before sending the Livewire update.

Chrome DevTools verification showed the Livewire response application timing drop from 381–415ms before the change to 197ms on the optimized path, with database timing dropping from 42–68ms to 32ms. The search still returns the expected Kazim and Ahmad results, and the performance trace recorded a 24ms INP for the optimized interaction.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/PersonIndexTest.php` — 35 passed (122 assertions).
- Chrome DevTools — live search returns `Ustaz Kazim Elias` and `3 penceramah ditemui` for `Ahmad`; no console errors observed.

# Require country on speaker contribution location and progress

## Plan

- [x] Pass the country-required option through the person contribution schema.
- [x] Enable it for the new speaker submission Location tab.
- [x] Add focused form-schema regression coverage and verify the change.
- [x] Count the default selected country in speaker form progress.

## Review

The new speaker contribution form now marks `address.country_id` as required in the `Lokasi` tab and rejects submission when the country is cleared. Both the server-rendered and Alpine progress calculations include the default country, so the indicator starts at 33% because that required field is already selected. Other person form consumers retain the existing optional-country behavior.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/ContributionPagesTest.php --filter='submission progress|requires a country|selected country'` — 3 passed (12 assertions).
- Targeted PHPStan at level 6 — no errors.
- Targeted Pint check, Blade cache, PHP syntax checks, and `git diff --check` — passed.
- Browser verification — `/sumbangan/penceramah/baru` displays 33% with Malaysia selected; clearing the country updates progress to 0%, and the Location tab shows `Negara*`.

# Fix /majlis package Venue address lookup

# Event submission category vocabulary

## Plan

- [x] Replace the Islamic-specific hierarchical event categories with a flat, activity-first vocabulary.
- [x] Update affected category fixtures and taxonomy tests without retaining legacy category codes.
- [x] Reseed the local taxonomy, clear the category catalog cache, and verify the submission form and focused tests.

## Review

The event category taxonomy is now a flat eight-option activity vocabulary. `Kuliah / Ceramah` is the primary talk category for subjects including Islamic studies, mathematics, science, technology, and IT. The manual form and poster extraction each accept one primary activity type, while the moderation workflow remains responsible for rejecting harmful submissions.

Legacy category terms and their event classifications are removed by the reseeder; no compatibility aliases are retained. The local database was reseeded and the event-category selection cache was busted.

Verification:

- AIArmada taxonomy tests: 10 passed / 30 assertions.
- Poster extraction tests: 2 passed / 23 assertions.
- Public page suite: 30 passed / 213 assertions; 3 unrelated pre-existing failures remain for poster aspect, Threads icon, and contribution-link assertions.
- Targeted PHPStan: passed with no errors.
- PHP syntax and `git diff --check`: passed.
- Pint: the new seeder import order passes; the existing `Create.php` still reports its pre-existing unrelated fixers.

Seeder audit: only `EventSeeder` required category-code behavior updates. `AdvancedEventSeeder` consumes the catalog dynamically, and no other seeder contains legacy event-category codes.

# Optional event topics and fields

## Plan

- [x] Add the broad optional topic vocabulary without conflating it with activity type.
- [x] Update seeded demo event topic defaults and the submission form/extraction labels.
- [x] Reseed topics and verify topic options, free-text detail support, and focused tests.

## Review

Added eight optional broad topics to the existing domain taxonomy: Agama & Kerohanian, Pendidikan, Sains & Matematik, Teknologi & IT, Kerjaya & Kemahiran, Kesihatan, Keluarga & Masyarakat, and Lain-lain / Tulis sendiri. The activity type remains a separate single-choice field, while the existing optional specific-topic field provides free-text detail such as Machine Learning or Matematik.

Updated seeded event defaults, submission-form labels, review copy, and extraction-backed options. Reseeded the Foundation taxonomy and cleared the application cache.

Verification:

- Foundation taxonomy tests — 11 passed (32 assertions).
- Submit-event form coverage — 5 passed (32 assertions).
- AI extraction coverage — 2 passed (23 assertions).
- Advanced event seeder coverage — 2 passed (20 assertions).
- PHPStan targeted analysis, Pint, PHP syntax checks, and `git diff --check` passed.

# Surface topics on public submission and listing filters

## Plan

- [x] Map the shared domain taxonomy through `/hantar-majlis` and `/majlis`.
- [x] Make broad topics visible immediately in both public filter controls.
- [x] Add focused Livewire/listing coverage and verify the filter query path.

## Review

The public `/hantar-majlis` topic field now uses a versioned option cache and explicitly preloads its dynamic option list, and the `/majlis` sidebar has a dedicated `Topik & rujukan` section. Its `Topik / bidang` filter preloads the same eight broad topics, while `Topik lebih khusus` remains searchable for detailed fields. The existing `domain_tag_ids` URL/query contract remains the shared backend filter path.

Verification:

- Public event filter coverage — 3 passed (16 assertions).
- Submit-event topic coverage — 3 passed (18 assertions).
- PHP syntax checks passed; the filter changes use the existing taxonomy cache and search service seams.
- Chrome verified all eight choices in both public controls and successfully applied `Pendidikan` on `/majlis`; no new console errors appeared during the filter interaction.

# Reorder submit wizard topic placement

## Plan

- [x] Place the broad topic immediately after `Jenis Majlis` in the first wizard step.
- [x] Keep specific topics and references in the follow-up step.
- [x] Verify the rendered order in Livewire tests and Chrome.

## Review

The submission wizard now follows the natural sequence `Jenis Majlis → Topik / bidang → Tajuk Majlis`. The broad topic selector is optional and visible early, while detailed topics, sources, issues, and book references remain in `Topik & Rujukan`.

Verification:

- Wizard-order regression — 1 passed (1 assertion).
- AI extraction coverage — 2 passed (23 assertions).
- Targeted PHPStan and syntax checks passed.
- Chrome confirmed the rendered field order.
- The broader PublicPages run had an intermittent existing `mkdir(): File exists` parallel-test setup collision; the isolated order test passed.


# Reusable organizations tenancy

## Plan

- [x] Create and wire `aiarmada/organizations` core package.
- [x] Create and wire `aiarmada/filament-organizations` adapter.
- [x] Hard-cut event organizer naming and table boundary.
- [x] Integrate the package Organization model into ilmu360 without a duplicate app model.
- [x] Add focused package and application tests.
- [x] Run formatting, static analysis, migration linting, Composer audit, and focused parallel tests.

## Review

Implemented reusable organization tenancy, the Filament v5 adapter, the hard-cut
event-organizer rename, application API/workspace integration, and ownership-safe
membership mutation guards. This is a clean-schema cutover: no backfill,
one-time data migration, legacy table rename, runtime alias, or fallback was
added. The audit also corrected event creator mass-assignment, panel-specific
Filament authentication, configurable membership pivot resolution, workspace
context errors and query counts, lifecycle-history retention, fail-closed owner
transfer behavior, idempotent organization migrations, model defaults, and the
required package documentation structure.

Verification:

- Organizations package: 6 parallel tests / 20 assertions.
- Filament adapter: 2 parallel tests / 3 assertions.
- ilmu360 organization API: 5 parallel tests / 14 assertions.
- Advanced event API: 4 parallel tests / 17 assertions, including creator metadata.
- Event rename and migration lint checks: 5 parallel tests / 360 assertions;
  no data migration is included.
- Commerce PHPStan targeted scope: no errors.
- ilmu360 PHPStan (988 files): no errors.
- Pint, Composer validate/audit, package discovery, legacy-reference scans, and
  `git diff --check`: passed.

## Plan

- [x] Reproduce the undefined `primaryAddress()` call through the public event index.
- [x] Add regression coverage for schedule-location venues rendered by `/majlis`.
- [x] Use the canonical address contract for both app and package venue models.
- [x] Run focused tests, view compilation, static analysis, and diff checks.

## Review

The public schedule discovery boundary now bulk-resolves package event-location
venue IDs through `App\Models\Venue`, preserving the application's address
relations and morph-map behavior for public cards. The Blade card only calls
`primaryAddress()` on the application subclass, while event-level and
schedule-level venue relations remain available for display.

Verification: `tests/Feature/PublicScheduleDiscoveryTest.php` passed 4 tests /
27 assertions; PHPStan passed on changed services; Pint, Blade view cache,
syntax checks, and `git diff --check` passed. The adjacent `PublicPagesTest`
had 28 passing tests and 3 unrelated pre-existing failures in poster aspect,
Threads icon, and contribution-link assertions.

# Event occurrence publication invariant

## Plan

- [x] Require an occurrence before event approval/public reachability.
- [x] Exclude occurrence-less events from public discovery and search fallbacks.
- [x] Prevent deleting the last occurrence from a published event.
- [x] Add focused regression coverage and verify formatting/static analysis.

## Review

Draft events may remain occurrence-less while being assembled, but the central
approval transition now rejects publication until at least one occurrence exists.
The same invariant is applied to the Event model's public reachability/search
contracts, API and Livewire public listings, Typesense/Postgres hydration,
directory counts, related events, and calendar export. Public save, going,
registration, and check-in actions also refuse orphaned event containers.

Published events cannot delete their final occurrence through the model delete
path; deleting an occurrence is still allowed when another occurrence remains.
Occurrence save/delete observers invalidate the public listing cache and
reconcile the event search index.

Verification: occurrence invariant coverage passed 4 tests / 15 assertions;
occurrence observer coverage passed 4 / 4; public visibility coverage passed
14 / 14; Event Save passed 12 / 62; Event Going passed 10 / 55; registration
safety passed 4 / 13; targeted PHPStan passed with no errors; Pint, Blade view
cache, and targeted git diff checks passed.

# Public schedule-unit discovery

## Plan

- [x] Map the current event index/detail seams and occurrence/session URL fields.
- [x] Make /majlis list sessions when present, otherwise occurrences.
- [x] Add nested event/occurrence/session routes and public page resolution.
- [x] Preserve /majlis/{event} as the programme hub with all occurrences/sessions.
- [x] Add regression coverage and verify UI, formatting, static analysis, and views.

## Review

`/majlis` now discovers public schedule leaves: meaningful public sessions are listed individually, while occurrences without meaningful sessions remain discoverable as occurrence cards. Each result uses session → occurrence → event cover fallback, schedule-owned timing/location/speaker data, and a scoped nested URL.

The public routes now support:

- `/majlis/{event-slug}` as the programme hub.
- `/majlis/{event-slug}/{occurrence-slug}` as the occurrence page.
- `/majlis/{event-slug}/{occurrence-slug}/{session-slug}` as the session page.

Occurrence and session resolution is scoped through the parent event/occurrence, with deterministic fallback slugs when package records do not have a slug. Private or incomplete child schedules are excluded from public discovery and nested pages. Existing event-level saving remains event-owned, while result navigation and sharing identify the actual schedule leaf.

Verification:

- `tests/Feature/PublicScheduleDiscoveryTest.php` — 3 passed (23 assertions).
- PHPStan on all changed PHP files — no errors.
- Pint, Blade view cache, route listing, and `git diff --check` — passed.
- Existing broader event-search/public-page suites retain unrelated pre-existing failures in the dirty worktree; the new schedule discovery coverage and changed PHP/static-analysis scope pass.

# Space model remediation

## Plan

- [x] Centralize catalog and venue-owned space eligibility
- [x] Fix selectors, event validation, and scoped slug uniqueness
- [x] Add taxonomy propagation, capacity overrides, and historical snapshots
- [x] Add venue-scoped admin space management and remove duplicate package resources
- [x] Add regression tests and complete formatting/static-analysis verification

## Review

Audited and corrected the clean-cut contract: catalog rows use venue_id IS NULL,
venue-owned rows are venue-scoped, all event write paths use the shared eligibility
resolver, and both contribution forms expose catalog plus same-venue spaces.
Scoped slug indexes and snapshots now live in the package's canonical create-table
migrations. Taxonomy validation, institution pivot capacity overrides, historical
location snapshots, referenced-space delete protection, and the app-owned venue
resource are in place. Generic package changes contain no app policy or
compatibility aliases.

Verification: remediation suite passed (6 tests / 28 assertions), submit-location
tests passed (7 / 23), admin dashboard passed (4 / 13), admin resource coverage
passed (4 / 35), application PHPStan passed (975 files), package PHPStan passed,
Pint passed, Blade view cache passed, and both repositories passed git diff checks.

# Event seeder upgrade and failure cleanup

## Plan

- [x] Make every event seeder explicitly provision canonical catalog spaces and taxonomy before writing locations.
- [x] Ensure seeded institution and venue events persist valid spaces, taxonomy IDs, and location snapshots.
- [x] Remove stale test expectations and invalid media fixtures exposed by the upgraded contracts.
- [x] Remove the addressing seeder's full-file memory spike from the test path.
- [x] Run focused seeder/API/UI verification, PHPStan, formatting, and diff checks.

## Review

Event seeders now call the idempotent `SpaceSeeder`; catalog seeding is scoped by
`venue_id IS NULL`, and event-owned venue spaces remain venue-scoped. Seeded
locations now exercise the canonical taxonomy and historical snapshot fields.
The addressing city filter reads the compressed JSON stream incrementally, so
the production seeder test no longer exceeds the worker memory limit. Stale
person media, poster-ratio, time-state, import, and hard-coded media-path test
expectations were updated to the current codebase contract.

Verification: seeder and space remediation tests passed; production seeder
coverage passed 4 tests / 11 assertions; admin API passed 81 tests / 1,091
assertions; focused frontend media/time coverage passed; PHPStan passed 975
files; Pint and diff checks passed. The full parallel run reached the
Scramble documentation worker, which exceeded its request timeout before the
test-only console timeout guard was corrected; mocked Scramble cache-path tests
now pass (3 tests / 22 assertions). A cold full Scramble generation remains
CPU-bound in this environment and was not allowed to continue concurrently.

# Task: Optimize penceramah edit loading

# Organization frontend and ticketed events

## Plan

- [x] Add frontend organization creation and workspace routing.
- [x] Add organization invitations, role management, ownership transfer, and lifecycle controls.
- [x] Add organization-owned event creation with free/paid ticket types and inventory.
- [x] Add optional assigned/general seating setup to the event builder.
- [x] Add focused Livewire and workflow tests, then run formatting and static analysis.

## Review

Added the authenticated organization workspace at `/dashboard/organisasi`: users
can create organizations, invite members by email, change non-owner roles,
remove members, transfer ownership, revoke invitations, and apply visibility or
lifecycle actions. Organization membership is checked on every workspace read
and mutation, and non-members receive a forbidden response.

Added the organization event builder at
`/dashboard/organisasi/{organization}/majlis/cipta`. It creates an
organization-owned draft with UTC-normalized schedule data, free or paid ticket
types, ticket inventory, per-order limits, registration mode, and optional
general-admission, assigned, or hybrid seating maps. General-admission tickets
are connected to their seat sections, while assigned/hybrid maps generate
owner-scoped seats. Paid events cannot disable registration/ticketing, and
seating capacity is validated in the action boundary as well as the form.

The membership subject guard was corrected so global Organization aggregates do
not require an unrelated owner context, while owner-scoped models remain
protected. Required inventory and ticket morph-map entries were also registered
for the ticketing workflow.

Verification: frontend organization suite passed 7 tests / 25 assertions;
organization tenancy passed 5 / 14; advanced event API passed 4 / 17; invitation
UI passed 8 / 22; invitation actions passed 10 / 20; Commerce membership actions
passed 8 / 15; owner isolation passed 2 / 7; organization actions passed 6 / 20;
Pint, Blade view cache, application PHPStan, and Commerce PHPStan passed.

Chrome verification initially exposed the three pending additive package
migrations in the local PostgreSQL database. After applying them, the
authenticated organization index and create form rendered successfully at
`/dashboard/organisasi` and `/dashboard/organisasi/cipta`, with no browser
console errors. No backfill or data migration was run.

## Follow-up: expose organization creation in navigation

- [x] Show the organization creation link to authenticated users before they have an organization.
- [x] Keep organization management navigation conditional on existing membership.
- [x] Add a regression test for the dashboard navigation and verify the rendered link in Chrome.

Review: the original header incorrectly gated the entire organization menu on
`organizations()->exists()`, which made the first-organization workflow
undiscoverable. The create link is now always rendered in desktop and mobile
authenticated navigation, while the management link remains membership-aware.

Chrome end-to-end testing then exposed an omitted `owner` entry in the
application membership role mapping. The owner pivot was created correctly but
could not be resolved during workspace authorization; the mapping and a
frontend create-to-workspace regression assertion were corrected.

The final Chrome pass also covered the live Filament admin resource after
clearing package metadata and restarting Herd services: Organizations appeared
in navigation, the list and record pages loaded, the owner row rendered, the
Members and Invitations relation managers opened, and Make public / Make
private completed through confirmation dialogs. The test organization was
restored to private and Chrome reported no console errors.

## Current Task: Tolerate incomplete Google geography

- [x] Resolve Google subdivisions through the provider hierarchy when the district is omitted.
- [x] Recover the district ancestor and keep normal form hierarchy strict.
- [x] Add focused regression coverage and run verification.

## Review

The generic hierarchy traversal now lives in
`aiarmada/addressing` as `AddressAreaHierarchyResolver`. It supports arbitrary
provider-defined depth through `ancestorsOf()` and typed ancestor selection
through `ancestorOfTypes()`. Provider roles can also resolve non-administrative
branches such as Federal Territory `postal_locality` nodes. The Google place
resolver only supplies provider-derived names/types/roles and consumes the
package result. The normal provider-backed form cascade remains strict and
unchanged.

Verification: `vendor/bin/pest --parallel
tests/Unit/ResolveGooglePlaceSelectionActionTest.php` (8 tests / 46
assertions), Pint, application PHPStan, package PHPStan for the new resolver,
and `git diff --check` passed.

## Current Task: Speaker profile repeaters

- [x] Add alternate-name repeater to the speaker update form.
- [x] Replace single affiliated institution editing with an optimized repeater.
- [x] Update quick-add institution language and address fields.
- [x] Run focused regression coverage and static analysis.

## Review

Verification: `vendor/bin/pest --parallel tests/Feature/ContributionPagesTest.php` (59 tests / 403 assertions), Pint, PHPStan on all changed PHP files, and `git diff --check` passed.

## Current Task: Improve speaker contact section

- [x] Locate the public “Hubungi Penceramah” section.
- [x] Add contact-type icons and improve contact card hierarchy.
- [x] Verify Blade rendering and focused UI coverage.

## Review

The public speaker contact cards now show type-specific icons for phone,
WhatsApp, and email, with a neutral link fallback for other contact types.
Each card keeps its existing destination/value while gaining clearer hierarchy
and hover feedback.

Verification: `php artisan view:cache`, `git diff --check`, live response check
for `/penceramah/idris-ahmad`, and `vendor/bin/pest --parallel
tests/Feature/PersonShowSocialPlacementTest.php` (6 tests / 25 assertions)
passed.

- [x] Trace the speaker edit route/component, query dependencies, and family-name field.
- [x] Establish a reproducible baseline for request timing/query count and add regression coverage.
- [x] Implement query optimization and fix family-name loading.
- [x] Run focused tests, PHPStan, and targeted verification.

## Review

The person update form now hydrates `family_name`. Initial page load no longer
preloads every institution and title record; both catalogs use bounded,
search-backed queries and selected-value label lookups. The initial profile
query inventory was 34 queries and included an unbounded institution catalog;
the catalog path is now deferred to user search/selection.

Verification: `vendor/bin/pest --parallel tests/Feature/ContributionPagesTest.php`
passed 56 tests / 394 assertions; Pint passed; PHPStan passed all 955 files;
all modified PHP files pass syntax checks.

## Media hydration follow-up

- [x] Trace repeated Spatie media relationship hydration in direct-edit fields.
- [x] Override the upload relationship loader to use `loadMissing('media')`.
- [x] Avoid forced media reloads while comparing direct-edit changes on save.
- [x] Re-run person media functional coverage and static checks.

Result: the direct-edit upload fields retain the vendor behavior while reusing
the loaded media relationship and keeping preview URL resolution safe during
Livewire hydration. Focused media coverage passed 4 tests / 28 assertions;
Pint and PHPStan passed for the changed implementation files.

## Admin and shared media audit

- [x] Verify the admin person edit form uses the shared optimized loader.
- [x] Inventory all application `SpatieMediaLibraryFileUpload` usages.
- [x] Confirm the admin person form hydrates five media collections with one media query in isolation.

The same provider-level optimization covers person, institution, reference,
event, venue, report, inspiration, series, donation-channel, membership
evidence, and submission forms. Admin person tests passed 5 tests / 21
assertions; the person tab test passed; Pint and PHPStan passed.

## Institution label hydration

- [x] Reproduce the missing selected institution on the speaker update form.
- [x] Separate institution search visibility from selected-value label lookup.
- [x] Add regression coverage for non-public existing affiliations.

The form now displays an existing affiliated institution even when it is not
currently in the public verified/pending search catalog. Full contribution
coverage passed 58 tests / 405 assertions.

## Institution dropdown search

- [x] Reproduce the dropdown and search behavior through the browser.
- [x] Fix case-sensitive institution and alternate-name matching.
- [x] Verify lowercase `masjid` search returns live institution options.

Browser verification returned institution options for lowercase `masjid` with
no console errors. Institution-focused contribution coverage passed 21 tests /
126 assertions; Pint, PHPStan, and diff checks passed.

## Institution dropdown initial options

- [x] Reproduce the empty list when opening the institution field without typing.
- [x] Add a bounded initial list while preserving server-side search.
- [x] Verify opening and selecting an institution directly in the browser.

The institution catalog now shows bounded initial options immediately on click
and successfully selects an institution without requiring prior text entry.

## Institution search latency

- [x] Trace `/institusi?search=shah+alam` from route to search service, SQL, eager loads, and render.
- [x] Capture baseline query count, timings, and PostgreSQL query plan.
- [x] Remove the redundant scoped-ID round trip from direct search pagination.
- [x] Cache the country catalog and default country lookup through the existing address catalog cache.
- [x] Add focused query-count regression coverage and verify the Livewire page.
- [x] Review Livewire deferred/island loading behavior against official documentation.

## Review

The direct institution search now applies the current location scope, directory ordering, page slice, and total in one hydration query. The total is read from `COUNT(*) OVER ()`; only an out-of-range page falls back to a count query. The country selector and default country resolution reuse the existing address catalog cache.

## Institution search interaction pass

- [x] Create a checkpoint commit before continuing; no stashes were present.
- [x] Validate a deferred results island against the existing public-page contract.
- [x] Keep initial result HTML server-rendered while isolating subsequent result updates in one island.
- [x] Add stable institution card keys and a persistent result wrapper for Livewire morphing.
- [x] Remove the redundant `active()` status predicate from verified public search queries.
- [x] Verify institution rendering, fallback search, Blade compilation, formatting, and PHPStan.

The full deferred island was not retained: it replaced the initial public result
HTML with a skeleton and broke the directory's server-rendered result contract.
The retained design keeps the batched hydration query and existing loading
skeleton, while stable keys and transition wrappers improve search/filter
interactions without sacrificing first-response content.

# Task: Livewire 4 optimization review (2026-08-02)

- [x] Index and map Livewire pages/components, routes, config, and hotspots.
- [x] Read the requested Livewire 4 documentation topics through Context7.
- [x] Compare hydration, navigation, loading, lazy/island, pagination, URL, and JS patterns against the app.
- [x] Validate candidate optimizations with focused code/config/test checks.
- [x] Record prioritized recommendations and review evidence.

## Review

Completed. Public result HTML remains SSR-first; `always` islands isolate result updates without deferring the first response. Stable result keys, transition wrappers, and expanded loading targets cover search, sorting, pagination, filters, and saved-state updates.

## Penceramah and majlis optimization pass (2026-08-02)

- [x] Baseline the four public SSR paths and inspect their database query shapes.
- [x] Update the Laravel query optimization skill with the public Livewire listing lessons.
- [x] Create and validate the Livewire query optimization skill with SSR, hydration, island, loading-state, and morphing guidance.
- [x] Remove redundant verified-status scopes from public person and reference search paths.
- [x] Remove duplicate pivot predicates already supplied by `withPivotValue()` on event speakers and references.
- [x] Eager-load event classification terms to prevent card-level lazy-loading.
- [x] Memoize the event paginator for the current Livewire request so saved IDs do not re-run event hydration.
- [x] Add SSR-first result islands, stable event keys, transitions, reserved result height, and loading targets for both directories.
- [x] Add regression coverage for the verified predicate and event paginator reuse.
- [x] Run PHPStan, Pint, Blade view compilation, focused tests, and isolated HTTP smoke checks.

### Review and audit

- Baseline captured before this pass: `/penceramah` 12 queries / 40.65 ms DB time; `/majlis` 29 queries / 122.70 ms DB time cold; `/majlis?search=halaqah` 33 queries / 103.57 ms DB time.
- Post-change isolated smoke checks: `/penceramah` 12 queries / 21.40 ms; `/penceramah?search=Samad` 5 queries / 5.38 ms; `/majlis` 29 queries / 64.69 ms; `/majlis?search=halaqah` 30 queries / 52.39 ms. Wall-clock and cache state are environment-sensitive, so SQL-shape and regression assertions remain the primary acceptance criteria.
- No new tracking event was needed: this pass changes loading, hydration, and DOM stability for existing search/filter intent rather than introducing a new user workflow.
- Remaining opportunity: measure browser-side interaction latency with production-sized data and a real authenticated session before considering deferred result islands, since fully deferred public results would remove useful initial HTML.

## Livewire 4 documentation review (2026-08-02)

### Already aligned with Livewire 4

- Homepage lower sections already use `lazy.bundle` / `defer.bundle` and matching `@placeholder` views.
- Search/list pages already use `#[Computed]`, `#[Url]`, `WithPagination`, stable `wire:key` values, targeted `wire:loading`, and `wire:navigate`.
- The events, institutions, and persons lists already use islands around result regions; the submit-event editor already uses `wire:ignore` for third-party DOM ownership.
- `Route::livewire()` and v4 config keys (`component_layout`, `component_placeholder`, `smart_wire_keys`) are already in place.

### Prioritized opportunities

1. **High — isolate event-detail engagement actions.** The event detail view and eager relation graph are broad; move action controls into a child component or named island while preserving authorization and outcome tracking.
2. **High — reduce filter request frequency on the events index.** Keep immediate updates only where cascades require them; debounce or batch range and multi-select filters after measuring request/query latency.
3. **Medium — split/defer below-the-fold event detail data.** Defer galleries, announcements, and related context while keeping SEO-critical event information in the initial response.
4. **Medium — reduce unified-search fan-out.** Consider a slightly longer debounce, a minimum query length, or explicit submit mode on slower connections.
5. **Low — fix the homepage institution count query.** Replace PHP-side `pluck()->unique()` with a database-side distinct count.

### Verification

- Livewire 4 docs were reviewed for lazy/defer/bundling, islands/nesting, loading, computed properties, navigation, URL state, JavaScript, pagination, and directives.
- PHP syntax and focused Livewire tests passed; two existing asset assertions still expected `/flux/flux.js` to be absent.

# Majlis filters audit

## Plan

- [x] Inspect project guidance, package contracts, and the `/majlis` filter/query implementation
- [x] Map every current filter to the adopted Events and Addressing schema; identify obsolete, missing, and ambiguous user-facing filters
- [x] Implement the filter/query/UI changes with focused tests and tracking review
- [x] Run focused tests, PHPStan, schema/legacy scans, and verify the rendered route behavior
- [x] Document the audit and verification results in this file

## Review

### Canonical filter mapping

- Schedule/date/time filters now query the package `event_occurrences` primary occurrence, using the package ordering (`starts_at`, `created_at`, `id`) rather than event-table date columns.
- Country, state, and city use `addresses.country_id`, `addresses.state_id`, and `addresses.city_id`.
- Administrative geography uses `address_area_assignments` keyed by package roles: `administrative_division`, `administrative_district`, `administrative_subdivision`, and `postal_locality`. There are no `admin_area_1_id` or `admin_area_2_id` fields.
- Location filters are evaluated against the event's owning address: the primary institution address when `institution_id` is set, otherwise the primary venue address from `default_venue_id`; all state/city/area criteria stay on that one address.
- Categories and knowledge fields use the Events package classification/taxonomy relations. The obsolete `topic_ids` contract is replaced by `discipline_tag_ids`.
- Audience, speaker/PIC roles, references, languages, links, delivery mode, and event categories remain relation/column-backed by the adopted Events schema.

### Product decisions

- Kept the useful filters users need to find a majlis: date scope/range, time mode/prayer relation, geography, institution/venue, people and roles, disciplines/domains/sources/issues, references, audience, languages, delivery, URLs, and distance.
- Added city, division, postal/locality, and “has end time” support.
- District is hidden when the selected geography profile does not provide it (federal territories); locality/subdivision remains available.
- Removed the duplicate advanced-filter component and its event synchronization bridge; the page form is the single filter state owner.
- Retained high-signal Signals attributes on filter controls and sort changes; no blanket click tracking was added.

### Verification

- `vendor/bin/pest --parallel tests/Feature/EventSearchTest.php --compact`: 90 passed, 311 assertions.
- `vendor/bin/pest --parallel tests/Feature/EventSearchTypesenseFilterTest.php --compact`: 10 passed, 20 assertions.
- `vendor/bin/pest --parallel tests/Feature/SavedSearchPageTest.php --compact`: 24 passed, 95 assertions.
- `vendor/bin/pest --parallel tests/Feature/SavedSearchApiTest.php --compact`: 26 passed, 102 assertions.
- `vendor/bin/pest --parallel tests/Feature/Api/EventApiContractTest.php --compact`: 29 passed, 193 assertions.
- `vendor/bin/pest --parallel tests/Unit/EventTest.php --compact`: 9 passed, 56 assertions.
- `vendor/bin/phpstan analyse --ansi`: passed with no errors.
- `php artisan route:list --path=majlis`: confirmed the public listing route.
- Live requests to `https://ilmu360.test/majlis` and a real country/state-filtered URL returned HTTP 200; rendered HTML contains canonical filter state and no obsolete event filter keys.
- A request using the removed API key `filter[administrative_district_id]` returns HTTP 400 (`filter not allowed`), confirming no backward-compatibility alias remains in the event search contract.
- `git diff --check` and modified PHP syntax checks passed.

## Review update: event location ownership

- Read the installed `aiarmada/events` migrations and models: `events.default_venue_id` is package-owned; `event_locations.venue_id` and `event_locations.venue_space_id` describe the concrete venue/place rows; the package has no `events.venue_id` column.
- The app migration `2026_07_18_000002_add_institution_id_to_events.php` therefore adds the missing alternate location owner only. No second venue column should be added.
- Institution-owned events resolve public geography, nearby distance, prayer coordinates, and card address display from `institution_id`. Venue-owned events resolve them from `default_venue_id`. A `VenueSpace`/`Space` is a specific place detail and does not replace the owning address.
- Updated searchable payloads, database location predicates, nearby SQL, event-card eager loading/display, and search-index invalidation to follow that ownership rule.
- Added regression coverage for an institution event using a space linked to a different venue, canonical event schema columns, and institution-based prayer coordinates. Updated an invalid dual-owner nearby fixture and a stale test call that still used removed geography parameter names.
- Final verification: EventSearchTest 90/311, EventSearchTypesenseFilterTest 10/20, EventApiContractTest 29/193, SubmitEventLocationTest 7/23, EventTest 9/56, PHPStan 964 files with no errors, `git diff --check`, route resolution, live `/majlis` HTTP 200, and live schema inspection showing only `default_venue_id` + `institution_id` (not `venue_id`).

## Review update: real Chrome verification

- Chrome MCP opened `https://ilmu360.test/majlis` and exercised the rendered filters, not only HTTP/backend requests.
- Country → state → city cascades selected Malaysia, Selangor, and Petaling Jaya and produced canonical `country_id`, `state_id`, and `city_id` URL parameters.
- Institution selection produced an `institution_id` URL filter after applying and venue selection produced a `venue_id` URL filter backed by the canonical `events.default_venue_id`; selecting an event-backed Balai Islam venue reduced the visible result count to 1.
- The UI rendered no legacy geography keys or labels. Division/district/subdivision controls are data-dependent and were absent because the current local dataset returned no matching address-area options.
- Chrome console error logs were empty after the exercised filter flows.
- Browser review found duplicate same-name institution options (for example, two Akademi Tahfiz Ar-Rahman records in different cities). The IDs map correctly, but labels need locality context before the picker is fully understandable to users.

# Expose hidden Person data in admin View page

## Context
- `ViewPerson` extends package `filament-persons` `PersonInfolist` which shows only Identity (name, family_name, middle_name, gender, date_of_birth, status).
- The edit form has 5 tabs of data the view hides: Profil (bio, languages, titles), Media (avatar/main/profile/cover/gallery), Lokasi (address), Hubungan (contactMethods, socialProfiles), Status (speaker_status, allow_public_event_submission, lifecycle timestamps).
- Hidden relationships: CredentialAssignments (filtered out of `getRelations()` in app PersonResource since refactor 46a20fdd; pre-refactor app had its own RM), MemberInvitations (permission-gated via `person.manage-members` — intentional, leave).
- Data for the seeded person (Azhar): 4 names, 1 title, 1 address, 3 social profiles, 10 events, 1 member, 1 report, speaker_status=active, allow_public_event_submission=true, bio (TipTap JSON).
- `InstitutionInfolist` is the established rich-infolist pattern (Malay labels, Tabs). Its `address.*` (singular) entries DON'T resolve (no singular relation) — use `primaryAddress()` state closures instead.
- Owner context: `EditPerson`/`ViewInstitution` wrap `OwnerContext::withOwner(null, ...)`; package `ViewPerson` doesn't — needed once contactMethods/socialProfiles render in the infolist.

## Plan
- [ ] Create `app/Filament/Resources/Persons/Schemas/PersonInfolist.php` (tabs: Profil, Media, Lokasi, Hubungan, Status, Statistik) modeled on InstitutionInfolist
- [ ] Wire `infolist()` override in app `PersonResource`
- [ ] Re-add package `CredentialAssignmentsRelationManager` to `getRelations()`
- [ ] Add `OwnerContext::withOwner(null, ...)` wrapping to admin + ahli `ViewPerson` pages
- [ ] Add focused pest test asserting view page shows hidden data
- [ ] Run pint, phpstan, focused tests; verify page in browser

## Review update: event seeder venue spaces

- Added the shared `SeedsEventLocations` seeder concern used by `EventSeeder`, `AdvancedEventSeeder`, and `SpeakerEventSeeder`.
- Institution-owned seeded events now receive an institution-linked `Dewan Utama` space; venue-owned seeded events receive a venue-owned `Dewan Utama` space. Both persist through `event_locations.venue_space_id`; online events remain without a physical location.
- `EventSeeder` backfills missing spaces and keeps venue-owned spaces valid instead of clearing them as if spaces were institution-only.
- Focused verification: `AdvancedEventSeederTest` passed 2 tests / 18 assertions; PHPStan passed 966 files; the final local `EventSeeder` run produced 223/223 physical events with spaces, 15/15 venue events with matching spaces, and 0 online events with spaces.
- Browser verification: Chrome rendered `Dewan Utama` on `/majlis` event cards and on a venue-owned event detail page alongside its venue; console errors were empty.
- The existing `ProductionSeederTest` remains blocked by its unrelated 512 MB memory exhaustion in `AddressingSeeder` while decoding the city fixture.

## Follow-up: institutions

- [x] Audit InstitutionInfolist vs hidden data (address.* broken — only primaryAddress() closures resolve; no Status tab; missing names/languages/display_name/reports_count)
- [ ] Rewrite InstitutionInfolist: Profil (display_name, names, languages), fix Lokasi via primaryAddress(), add Status tab (lifecycle + submission lock + created/updated), add reports_count to Statistik
- [ ] Add focused test for institution view infolist
- [ ] Run pint, phpstan, focused tests; verify page in browser
# Task: Rebuild the public event detail view around the AI Armada domain model

## Plan

- [x] Map the current public event route, Livewire component, view, and eager-loaded relationships.
- [x] Audit the installed AI Armada packages and their event-facing contracts (addressing, events, communications, references, persons, contacting, engagement, seating, ticketing, membership, signals, moderation, inventory).
- [x] Define and implement a coherent event detail information architecture with responsive, accessible UI.
- [x] Add or update focused regression coverage for the public event detail surface.
- [x] Run focused tests, browser verification, PHPStan/Pint where applicable, and review the final diff.

## Review

Audited the installed `aiarmada/*` packages as one event-domain graph. The event aggregate remains the public page's root; addressing supplies venue/institution address hierarchy and navigation data, persons supply identities and event involvements, references supply source citations, and contacting supplies organizer contact methods. Engagement supplies bookmark/response/reminder/share actions, while ticketing and seating contribute only when public ticket types or active seat maps exist. Communications, moderation, Signals, inventory, membership, affiliates, authz, and commerce-support remain supporting infrastructure rather than invented page content.

Rebuilt the page as a programme dossier with a date/reference-led hero, schedule timeline, compact speaker and role identities, references, address/map/contact rail, registration/ticket/seating states, related events, sharing, and guest-safe engagement actions. The view now reads relationship-owned data instead of duplicating package concepts.

Verification:

- `vendor/bin/pest --parallel tests/Feature/EventShowPageTest.php --compact` — 30 passed (89 assertions).
- Focused event-search detail contracts — 6 passed (17 assertions).
- `vendor/bin/pest --parallel tests/Feature/SignalsIntegrationTest.php --compact` — 7 passed (35 assertions).
- PHP syntax, Blade view cache, PHPStan, Pint, browser rendering, and console checks passed.

# Follow-up: occurrence and session cover media

## Plan

- [x] Add first-class `cover` media collections to event occurrences and sessions while preserving package event-media records.
- [x] Load public occurrence/session covers and render every public occurrence with all of its public sessions.
- [x] Add resilient cover inheritance and designed fallbacks for image-free schedules.
- [x] Add regression coverage and verify desktop/mobile rendering.

## Review

`EventOccurrence` and `EventSession` now implement Spatie media support with single-file `cover` collections. Their existing package-owned `EventMedia` relations are preserved as `mediaRecords`, and the application morph map now includes `event_occurrence` and `event_session` for media persistence.

The public event schedule now displays all public occurrences and nested sessions, ordered by session `sort_order`, with cover priority `session → occurrence → event` and a typographic fallback when no cover exists.

Verification:

- `vendor/bin/pest --parallel tests/Feature/EventShowPageTest.php --compact` — 31 passed (102 assertions).
- PHPStan, Pint, Blade view cache, syntax checks, diff checks, desktop/mobile browser review, and console checks passed.

# Follow-up: admin edit action on the public event page

## Plan

- [x] Mirror the existing speaker/institution admin edit affordance.
- [x] Restrict the control to `super_admin` and `admin` viewers.
- [x] Add focused authorization regression coverage and verify the public page.

## Review

Added an amber `Edit` action that opens the package-owned Filament event edit page in a new tab. Non-admin viewers do not receive the link or its URL.

Verification:

- Focused event edit contract — 2 passed (7 assertions).
- PHPStan, Pint, Blade view cache, diff checks, browser reload, and console checks passed.

# Follow-up: relationship-aware admin event resources

## Plan

- [x] Map the package-owned event, occurrence, and session resource seams.
- [x] Add app-level event context fields and relationship managers for references, ticket types, and seat maps.
- [x] Add independent occurrence/session cover uploads, schedule fields, and ticket/seat relationship managers.
- [x] Add focused admin resource regression coverage.
- [x] Run focused tests, PHPStan, Pint, syntax, and diff verification.

## Review

The admin Event resource now exposes the event domain graph through its existing extension seam: institution/venue context, speaker involvements, references, schedule type/timezone, delivery links, audience rules, and the existing event media collections. Event-level References, Ticket Types, and Seat Maps are now manageable from relationship tabs. The same ticket and seat-map managers are available from occurrence and session resources.

Occurrence and session resources now support their own 16:9 `cover` collection, optimized `thumb` conversion, timezone, delivery mode, capacity, and session ordering. Existing package event-media records remain available through their original `mediaRecords` relationships.

Verification:

- `vendor/bin/pest --parallel tests/Feature/FilamentEventResourceTest.php --compact` — 2 passed (31 assertions).
- `vendor/bin/phpstan analyse --ansi` — 979 files, no errors.
- Pint passed for all application/config/test files and the linked package files.
- PHP syntax checks and `git diff --check` passed.
- Chrome reached the supplied admin URL but the current browser context was unauthenticated and redirected to the admin login page; authenticated resource rendering is covered by the focused feature tests.

# Follow-up: location-picker fix review

## Plan

- [x] Audit every claim in `docs/location-picker-fix-review.md` against current code and runtime contracts.
- [x] Trace all picker entry points, hierarchy cascades, hidden-field dehydration, and persistence paths for additional gaps.
- [x] Add regression coverage for every confirmed defect and edge case.
- [x] Implement fixes without disturbing unrelated dirty-worktree changes.
- [x] Run focused verification, static analysis, formatting, a bounded full-suite attempt, and document the result.

## Review

Verified the three reported fixes against the resolver, Filament state dehydration, and address persistence paths. Found and fixed an additional gap in `SubmitEvent/Create`: its duplicate picker handler omitted the area-assignment defaults even though nested event location forms use the shared area fields. It now reuses `InteractsWithLocationPickerSelection`, with a regression test covering the missing-key state.

Focused location suites passed: event location 8 tests / 24 assertions, institution picker 9 / 56, admin infolists 7 / 29, and resolver unit coverage 8 / 46. PHPStan, targeted Pint, Blade view cache, and `git diff --check` passed. A fresh full parallel run was attempted but one worker remained CPU-bound without progress for approximately 27 minutes; it was stopped, so that run has no final consolidated result. The report was corrected in `docs/location-picker-fix-review.md`.

# Follow-up: simpler free-event submission entry point

## Plan

- [x] Keep the existing manual Livewire form and AI extraction workflow as the implementation seam.
- [x] Make `/hantar-majlis` the canonical destination for manual submissions instead of linking with `mode=manual`.
- [x] Improve the form header, poster-assisted extraction card, stepper treatment, loading states, and submission tracking markup.
- [x] Run focused tests, Blade/static checks, and browser verification.

## Review

The clean `/hantar-majlis` route now opens the same manual submission form previously reached with `?mode=manual`. The form keeps the existing validation, moderation review, media uploads, and AI poster extraction behavior while making the free-submission purpose clearer and the upload path easier to discover.

Verification:

- `vendor/bin/pest --parallel tests/Feature/SubmitEventAiExtractionTest.php --compact` — 2 passed (23 assertions).
- `vendor/bin/pest --parallel tests/Feature/SubmitEventReviewPreviewTest.php --compact` — 4 passed (15 assertions).
- The selected-locale upload-copy test — 1 passed (8 assertions).
- `php artisan view:cache`, targeted PHPStan for `Create.php`, Pint, translation JSON validation, and `git diff --check` passed.
- Browser verification confirmed one form and matching poster-assist UI at both `/hantar-majlis` and `/hantar-majlis?mode=manual`; desktop rendering was reviewed visually.
- The broader public-page and media suites still report unrelated existing failures outside this change: 3 public detail assertions and 1 poster-ratio assertion. They do not touch the updated submit-event view, route entry link, or translation keys.

# Follow-up: adaptive submit-event form flow

## Plan

- [x] Audit the current `/hantar-majlis` Livewire form, field dependencies, defaults, and tests.
- [x] Make event type and broad topic required driver fields, with dependent sections shown only when relevant.
- [x] Add sensible defaults for downstream options and a progress indicator that reflects completed/defaulted form state.
- [x] Ensure Livewire field bindings initialize and the progress indicator advances after the driver selections change.
- [x] Add regression coverage for validation, defaults, conditional visibility, progress, and at least one representative topic path.
- [x] Verify the browser flow on desktop/mobile, console/network health, formatting, static analysis, and focused parallel tests.

## Review

`event_category_ids` and `domain_tags` now act as the required driver selections. Broad topics are limited to three and explain that they control the follow-up questions. Religious context is detected from taxonomy codes, so the Muslim-only audience toggle and prayer-relative time choices appear only for religious events; changing away from that context clears stale religious state and restores a direct start-time default. The review preview now follows the same context, explicit custom times are preserved when the category changes, taxonomy lookups are memoized per Livewire request, organization memberships are eager-loaded for admin role management, and Feature/Browser Pest scopes share the test bootstrap correctly.

Downstream choices begin with useful defaults: physical format, public visibility, all genders, all ages, children allowed, Malay, institution organizer, same-as-institution location, and a sensible start-time fallback. The progress card now counts each required field relevant to the current form state, so valid defaults contribute individually while factual inputs such as title/date remain incomplete. Conditional required fields are added or removed from the denominator as the user chooses a religious time, online delivery, a person organizer, or a speaker-dependent category. Guest contact validation is represented as one name check plus one email-or-phone check, matching the form's conditional rules. Guest contact fields are live, and quick-added titles are normalized server-side so their progress updates cannot depend on generated client-side JavaScript.

The blank form now selects Kuliah / Ceramah and Agama & Kerohanian by taxonomy code. Because that topic is religious, the initial prayer-time default is Selepas Maghrib and the custom-time field remains empty until Lain Waktu is chosen.

The standalone CSS block was moved into the layout head stack so the Livewire component has one actual root element. Before that, the style tag became the component root and the form's `wire:model.live` bindings were rendered inert in the browser.

Verification:

- `./pest --parallel --compact tests/Feature/SubmitEventAdaptiveFormTest.php` — 10 passed (59 assertions).
- `./pest --parallel --compact tests/Feature/RefactorTest.php` — 3 passed (23 assertions).
- `./pest --parallel --compact tests/Feature/AuthzUserResourceTest.php` — 8 passed (57 assertions).
- `./pest --parallel --compact tests/Browser/PlaywrightSmokeTest.php` — 1 passed (2 assertions).
- Targeted PHPStan for the changed Livewire component and adaptive-form test — no errors.
- Targeted PHPStan, Pint, PHP syntax checks, Blade view cache, and git diff --check passed.
- Browser verification confirmed the Livewire root is the form container, category + broad-topic selection produced live requests, and Chrome MCP completed a real form path from 53% to 67% to 73% to 80% to 85% and finally 100% after organizer, title, date, and guest contact fields were filled.
- The quick-add title path previously produced a generated-script syntax error and left the visible value out of Livewire state; server-side normalization removed that error. The final Chrome 100% path reported no console errors and the form was not submitted.
- A fresh full `./pest --parallel --compact` run emitted failures but was stopped after approximately 27 minutes before a consolidated result; targeted suites above are the completed verification.

## Follow-up: client-side progress research

Context7's Filament 5 documentation confirms that `afterStateUpdatedJs()` runs in the browser with `$state`, `$get()`, and `$set()` without a Livewire request. The current progress section is Blade-rendered from `formProgress()`, so replacing `live()` with `afterStateUpdatedJs()` alone would not update it; the progress markup must also move to Alpine/client-side state (for example, watching the form's client state with `$wire.watch()`). Server-side required validation remains authoritative, while `live()` should remain only for PHP-dependent options or conditional schema.

## Follow-up: client-side progress implementation

- [x] Move progress rendering and recalculation to Alpine/client-side state.
- [x] Remove `live()` bindings that only existed to refresh the progress counter.
- [x] Preserve Livewire bindings needed for PHP-dependent options, conditional schema, and server synchronization.
- [x] Add regression coverage and verify network/console behavior in Chrome.

Implementation review:

The progress card now uses Alpine state inside a `wire:ignore` region. Relevant Filament fields dispatch a native `afterStateUpdatedJs()` progress event without a network request; `$wire.watch()` also covers programmatic/server-synchronised state changes. It mirrors the server-side required-field rules, including religious time, online location, organizer, speaker, repeater, and guest-contact conditions, while taxonomy/policy IDs are cached for five minutes. Independent fields such as format, visibility, gender, language, and speakers no longer use `live()` solely for progress; guest contact fields sync on blur. Server validation and the existing `formProgress()` calculation remain authoritative on submit.

Verification:

- `./pest --parallel --compact tests/Feature/SubmitEventAdaptiveFormTest.php` — 11 passed (69 assertions).
- `./pest --parallel --compact tests/Browser/PlaywrightSmokeTest.php` — 1 passed (2 assertions).
- Targeted PHPStan, Pint, Blade view cache, PHP syntax checks, and `git diff --check` passed.
- Chrome MCP verified that changing the client-side event format state updated the progress value from 61% to 63% without a Livewire network request, and the client calculator reached 100% when all active required values were populated; the form was not submitted and the refreshed page had no JavaScript errors.

# Follow-up: conditional topic-reference wizard step

## Plan

- [x] Make `Topik & Rujukan` visible only when `Agama & Kerohanian` is selected in `Topik / bidang`.
- [x] Add regression coverage for religious, non-religious, and restored topic selections.
- [x] Run focused tests, static checks, and review whether the visibility-only UI change needs Signals tracking.

## Review

The `Topik & Rujukan` wizard step now uses the canonical `agama_kerohanian` topic code and is hidden for other `Topik / bidang` selections. The existing Muslim-only audience field reuses the same predicate so the contextual controls remain consistent. No Signals event was added because this is a visibility-only adjustment without a new user-intent or completed workflow transition.

Verification:

- `vendor/bin/pest tests/Feature/SubmitEventAdaptiveFormTest.php --compact` — 12 passed (77 assertions).
- Targeted PHPStan — no errors.
- Targeted Pint, PHP syntax checks, and `git diff --check` — passed.

# Follow-up: Malaysia-relevant language field options

## Plan

- [x] Trace the language catalog, submit form options, related filters, and cache behavior.
- [x] Add a shared Malaysia-relevant language catalog using supported ISO 639-1 records.
- [x] Reuse the catalog in the submit form, event language filter, and review preview.
- [x] Add regression coverage for the complete curated option list.
- [x] Run focused tests, static analysis, formatting, syntax, and diff checks.

## Review

The `/hantar-majlis` `Bahasa` field now offers 28 relevant languages: Malay, Arabic, English, Indonesian, Chinese, Tamil, Javanese, Punjabi, Hindi, Malayalam, Telugu, Bengali, Nepali, Thai, Myanmar, Vietnamese, Tagalog, Urdu, Sinhala, Khmer, Gujarati, Kannada, Odia, Sindhi, Persian, Sundanese, Japanese, and Korean. These use the existing commerce-support ISO 639-1 catalog, so no duplicate language records or migration are required.

The shared `MalaysiaLanguageCatalog` keeps labels and ordering consistent across event submission, public event filtering, and the submission review preview. The event filter cache key was bumped to `v3` so existing cached seven-language payloads expire immediately. No Signals event was added because expanding select options does not create a new meaningful workflow transition.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventLanguageTest.php` — 4 passed (19 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/Laravel13CacheSerializationTest.php` — 4 passed (23 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventAdaptiveFormTest.php` — 12 passed (77 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, package-code availability check, and `git diff --check` — passed.

# Follow-up: client-side age-group selection normalization

## Plan

- [x] Trace the existing `Kumpulan Umur` state logic and no-request form patterns.
- [x] Collapse all four specific age groups into `Semua Peringkat Umur` in the browser.
- [x] Remove `Semua Peringkat Umur` when another specific age group is selected afterward.
- [x] Keep a server-side normalization fallback for submitted/programmatic state.
- [x] Add regression coverage for the behavior and absence of live synchronization.
- [x] Run focused tests, PHPStan, Pint, syntax, and diff checks.

## Review

The submit-event age field no longer uses `->live()`, so selecting age groups does not trigger a Livewire request. Its `afterStateUpdatedJs()` watcher compares `$state` with `$old`: selecting all four specific groups selects only `all_ages`; selecting a specific group after `all_ages` removes `all_ages`; and selecting `all_ages` keeps only that sentinel without clearing the field. The children toggle’s disabled state follows the same client-side state. Server-side normalization remains in place before submission as a defensive fallback.

The browser watcher explicitly preserves `all_ages` when it is the only selected value. This prevents watcher re-entry from interpreting the normalized `[all_ages]` state as a request to remove the sentinel itself.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventAgeGroupTest.php` — 5 passed (17 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventAdaptiveFormTest.php` — 12 passed (77 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, and `git diff --check` — passed.
- Chrome verified that choosing `Semua Peringkat Umur` leaves that single selection visible, and choosing `Dewasa` afterward removes it.

# Follow-up: audit remaining submit-form live bindings

## Plan

- [x] Inspect every `->live()` and `wire:model.live` binding in the submit-event form.
- [x] Move pure browser state synchronization and conditional required/disabled behavior to `afterStateUpdatedJs()` and Alpine bindings.
- [x] Preserve live bindings whose PHP callbacks provide database-backed lookup, contextual defaults, dynamic options, or server-driven schema.
- [x] Defer the Turnstile token until submit because it is only consumed by the server during submission.
- [x] Add regression coverage and review whether the UI behavior needs Signals tracking.

## Review

The organizer selectors, linked key-person repeater, guest contact requirement toggles, and Turnstile token no longer cause intermediate Livewire requests. Their server-side callbacks and validation rules remain as fallbacks/authorities for programmatic state and submission.

The six remaining live fields are intentional: event category, title, country, event date, prayer time, and religious domain topic. Each drives PHP-side defaults, database-backed title lookup, country/date-dependent prayer options, or conditional schema and contextual religious behavior. No Signals event was added because this is a request/performance refactor without a new user-intent or completed workflow transition.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventReactiveFieldTest.php` — 3 passed (38 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventCaptchaTest.php` — 2 passed (9 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventAdaptiveFormTest.php` — 12 passed (77 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventOrganizerAutoSelectTest.php` — 3 passed (12 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, and `git diff --check` — passed.
- A broader `tests/Feature` run still has unrelated existing failures in fixtures/seeders, geography, prayer-option data, and other admin/public workflows; the focused submit-form suites above pass.

# Follow-up: audit contribution forms and public majlis filters

## Plan

- [x] Trace the create/edit person and institution forms, their shared schemas, and the `/majlis` filter form.
- [x] Reduce social-handle normalization from every keystroke to blur-only server synchronization.
- [x] Preserve live bindings for address cascades, Google Maps normalization, dynamic social/contact fields, and server-side event filtering.
- [x] Add regression coverage and review whether the interaction changes need Signals tracking.

## Review

The dedicated contribution page classes and Blade views already use deferred state; no unnecessary page-level `wire:model.live` bindings were found. Their shared schema still uses live state only where PHP must update dependent address options/visibility, normalize Google Maps input, or render dynamic social/contact fields. Social-media handle parsing now runs on blur instead of every keystroke.

The `/majlis` page intentionally keeps its live bindings: search, location, date/time, taxonomy, audience, language, format, and availability controls all change the server-side event query. Search and radius already use debounce, and the PIC text search already updates on blur. No Signals event was added because this preserves existing filter behavior and only reduces redundant normalization requests.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/ContributionReactiveFieldTest.php` — 1 passed (3 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/PersonContributionOptimizationTest.php` — 6 passed (17 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/InstitutionContributionLocationPickerTest.php` — 9 passed (56 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, and `git diff --check` — passed.
- `tests/Feature/EventSearchTest.php` — 90 passed, 1 unrelated existing fixture assertion failed (`Domain Hidden Filter Payload Test`).
- `vendor/bin/pest --parallel --compact tests/Feature/ContributionPagesTest.php` — 65 passed, 1 unrelated existing assertion failed because the test expects initial speaker progress `0` while the current component returns `50`.

# Follow-up: direct membership claiming from public profiles

## Plan

- [x] Trace the existing membership application route, claimability rules, and public speaker/institution page actions.
- [x] Add the direct membership claim action to public institution pages using the canonical institution identifier.
- [x] Extend regression coverage for unclaimed and already-managed institution profiles while preserving speaker behavior.
- [x] Review responsive presentation, browser errors, and whether the new navigation action needs Signals tracking.

## Review

Public institution profiles now show the same membership claim card already used by speaker profiles when no admin member exists. The action opens the existing membership application form directly with the institution preselected; it does not send users through the general `/sumbangan` selector. Profiles with only non-admin members continue to show the claim action, while profiles with an admin member hide it.

No Signals event was added: this is a navigational entry point into the existing claim workflow, while the membership application submission remains the meaningful server-confirmed outcome.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 11 passed (61 assertions).
- Chrome checked the institution profile at desktop and mobile widths: the direct claim URL rendered, the card remained responsive, and there was no horizontal overflow.
- Chrome console check found no errors.
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, Blade view caching, PHP syntax checks, and `git diff --check` — passed.

# Follow-up: position profile membership CTAs after feedback

## Plan

- [x] Move the institution claim card after `Bantu Semak Maklumat Ini`.
- [x] Move the speaker claim card after the same feedback section.
- [x] Add order assertions for both public profile types and re-run verification.

## Review

Both public profile pages now present the information-feedback actions first and the “Tuntut Pengurusan” card immediately afterward. The existing direct claim routes and approved-member visibility guards are unchanged, and speaker profiles retain the claim CTA for unclaimed records.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 11 passed (61 assertions).
- Chrome confirmed the institution order at desktop and mobile widths, with no horizontal overflow or console errors.
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, Blade view caching, and `git diff --check` — passed.

# Follow-up: simplify membership claim submission

## Plan

- [x] Inspect the claim page navigation and evidence upload configuration.
- [x] Remove the “Tuntutan Saya” action from the claim form page.
- [x] Preserve and regression-test multi-file evidence uploads.
- [x] Verify the focused membership tests, static checks, Blade compilation, and browser rendering.

## Review

The claim form now keeps only the submit action; claim history remains available through its separate authenticated route. The evidence field already used Filament's `multiple()` configuration, and the submission regression now uploads two valid files and verifies both are persisted in the `evidence` collection.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 12 passed (67 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, Blade view caching, and `git diff --check` — passed.
- Chrome navigation to the protected URL correctly redirected the unauthenticated browser session to `/login`; authenticated Livewire coverage verified the form behavior.

# Follow-up: institution claim-page parity

## Plan

- [x] Confirm the institution route uses the shared membership claim form.
- [x] Add an institution-specific assertion that the claims-history button is absent.
- [x] Re-run the focused membership tests and final checks.

## Review

Institution claims use the same Livewire component and evidence field as speaker claims, so the removed “Tuntutan Saya” action and multi-file upload behavior apply consistently to both subject types. The institution-specific page test now explicitly verifies the history button is absent.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 13 passed (70 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, and `git diff --check` — passed.

# Follow-up: admin-role membership CTA visibility

## Plan

- [x] Trace the membership pivot role values and existing permission conventions.
- [x] Show the claim CTA when only non-admin members exist.
- [x] Hide the claim CTA when an admin member exists on either profile type.
- [x] Verify focused tests, static checks, Blade compilation, and browser rendering.

## Review

The public institution and speaker profile guards now query the membership pivot for `MemberRole::Admin` instead of treating any member as a reason to hide the CTA. This keeps “Tuntut Pengurusan” available for profiles with no members or only non-admin members, while an existing admin can reliably invite and manage members without a duplicate claim entry point.

No Signals event was added: this changes visibility of the existing navigation CTA; the membership application submission remains the meaningful server-confirmed outcome.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 12 passed (67 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, Blade view caching, and `git diff --check` — passed.
- Chrome confirmed Kazim Elias shows the direct speaker claim link, the CTA follows the feedback section, there is no horizontal overflow, and no console errors.

# Follow-up: unified institution and speaker workspaces

## Plan

- [x] Extend `/dashboard/organisasi` with institution and speaker records the signed-in user belongs to.
- [x] Add a speaker management workspace with scoped member management and profile editing.
- [x] Scope institution member-management checks to the selected institution.
- [x] Add focused regression coverage and complete code, view, and template verification.

## Review

The authenticated workspace entry point now groups organization, institution, and speaker memberships. Institution cards open the existing institution dashboard with the selected institution preserved; speaker cards open the new member-management workspace, where authorized admins can invite, change, and remove non-owner members and edit the public profile.

Institution member-management authorization now evaluates the selected institution itself, so an admin role on institution A does not grant management access to institution B. The institution table location column was also aligned with its existing test contract without changing the displayed location value.

No new Signals event was added: this is a navigation and authorization-surface change, while the existing invitation/profile-update workflows remain the meaningful actions to track.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 5 passed (26 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/OrganizationFrontendTest.php` — 8 passed (29 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/DashboardPagesTest.php` — 30 passed (304 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 996 files.
- `php artisan view:cache`, targeted Pint, PHP syntax checks, and `git diff --check` — passed.

# Follow-up: review latest commit and working tree

## Plan

- [x] Audit the latest commit and uncommitted workspace changes against their form, public-page, and authorization contracts.
- [x] Normalize single-select category/topic state in duplicate and AI extraction flows.
- [x] Restore the public event poster aspect marker and add shared event feedback actions.
- [x] Verify focused behavior, static analysis, Blade compilation, formatting, and final diff hygiene.

## Review

The review found and fixed stale array hydration for the new single-select event category/topic fields, an invalid duplicate-event eager-load (`tags` is not an Event relationship), and the missing poster aspect data attribute on the event detail page. Test fixtures were aligned with the intentionally hidden non-religious reference step, and the remaining affiliated-institution visibility toggle now updates locally in the browser.

Verification:

- Focused form, AI extraction, media, public-page, contribution, and workspace tests passed; the media file passes sequentially and with a single parallel worker, while the full parallel media invocation showed the existing shared fake-storage race.
- `vendor/bin/phpstan analyse --ansi` — no errors across 996 files.
- `php artisan view:cache`, Pint, and `git diff --check` — passed.

Additional review finding fixed after the broad feature run: `SaveSpaceAction` treated Filament's empty default `institution_space_overrides` state as an explicit empty sync and detached all institutions. The action now ignores an empty overrides-only payload while still syncing explicit institution IDs and non-empty overrides.

Additional verification:

- `vendor/bin/pest --parallel --compact tests/Feature/AdminAuditFollowUpTest.php` — 6 passed (57 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SpaceModelRemediationTest.php` — 6 passed (28 assertions).
- The broad `tests/Feature` run was stopped after confirming the known Scramble documentation worker memory problem; focused reviewed-path suites remain the reliable verification set.

The same review also found that `EventLocation`'s model save hook rewrote an existing historical space-name snapshot while `Event::syncLocation()` recreated the row. Existing snapshots are now restored with a quiet update after creation, while new locations continue to receive the current space name.

# Follow-up: Scramble documentation contract review

## Plan

- [x] Trace the Scramble test suite, API documentation route filter, cache resolver, and generated route set.
- [x] Fix the real route-alignment failure without removing the contract suite.
- [x] Verify the complete suite at the normal 512 MB PHP memory limit.

## Review

`ScrambleDocsTest` is a 33-case API documentation contract suite. It covers API-host-only exposure, lazy UI loading, cached/stale/ETag behavior, route/auth alignment, operation summaries and responses, schemas, tags, security metadata, request examples, and documentation copy. The suite is valuable coverage, so it was retained.

The failure was genuine: the four organization API endpoints had no `Endpoint` metadata, leaving their generated OpenAPI summaries empty. `OrganizationController` now defines an `Organizations` group and explicit endpoint titles/descriptions for listing, viewing, creating, and opening an organization workspace.

Verification:

- `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php -d memory_limit=512M vendor/bin/pest --compact tests/Feature/ScrambleDocsTest.php` — 33 passed (402 assertions).

# Follow-up: owner-aware profile claim CTA

## Plan

- [x] Trace the institution and speaker profile claim guards and scoped membership roles.
- [x] Hide “Tuntut Pengurusan” when either an admin or owner member exists.
- [x] Add owner-role regression coverage for both profile types and verify the change.

## Review

The institution and speaker profile pages now query their scoped membership pivots for both `admin` and `owner` roles. Viewer/editor-only members still leave “Tuntut Pengurusan” visible, while either management role hides it. The computed property and Blade references were renamed to reflect the broader rule.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 14 passed (76 assertions).
- PHPStan — no errors.
- Pint, Blade cache, and `git diff --check` — passed.

# Speaker workspace event management

## Plan

- [x] Add speaker-scoped event visibility and mutation authorization.
- [x] Add event management cards, filters, and actions to the speaker workspace.
- [x] Preselect the managed speaker in the existing event submission workflow.
- [x] Add regression coverage for role boundaries, event scoping, and create context.
- [x] Run focused tests, formatting, Blade compilation, static analysis, and diff checks.

## Review

Speaker members can now see events linked to their profile, while only owner/admin roles receive event edit/create controls under the existing event permission thresholds. Event policy and member API mutation/listing paths use the same speaker scope, and the event wizard preserves the originating speaker context with a validated preselected speaker.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 7 passed (47 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 996 files.
- `php artisan view:cache`, targeted Pint, and `git diff --check` — passed.

# Managed event builder for member workspaces

## Plan

- [x] Map the existing managed event, organization authorization, ticketing, and session seams.
- [x] Add shared managed-event context and scoped member access for institutions, speakers, and organizations.
- [x] Reuse ticketing/seating configuration for all managed event owners.
- [x] Expose the managed builder and session-ready workflow from each member workspace.
- [x] Add regression tests and run focused/full verification.
- [x] Review the final diff and document results.

## Review

Managed event creation now has one shared transaction workflow for the event container, first occurrence, registration policy, ticket types, quotas, and optional seating maps. Institution and speaker members use the advanced builder; organization members use the organization builder, with creation authorization available to every active member role.

Organization-owned events now participate in the same scoped event policy and member resource listing. Organization owners/admins can manage those events, while the creating member can continue working on their own draft. Existing public \`/hantar-majlis\` submission behavior remains unchanged, and the advanced builder continues into the existing session submission workflow.

## Verification

- \`vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php\` — 8 passed (55 assertions).
- \`vendor/bin/pest --parallel --compact tests/Feature/OrganizationFrontendTest.php\` — 9 passed (36 assertions).
- \`vendor/bin/pest --parallel --compact tests/Feature/EventPolicyTest.php\` — 30 passed (30 assertions).
- \`vendor/bin/pest --parallel --compact tests/Feature/DashboardPagesTest.php\` — 30 passed (304 assertions).
- \`vendor/bin/phpstan analyse --ansi\` — no errors across 999 files.
- \`vendor/bin/pint --dirty\`, \`php artisan view:cache\`, and \`git diff --check\` — passed.

# Advanced event builder UX refresh

## Plan

- [x] Simplify the page header and make the four-step journey explicit.
- [x] Reorganize fields into clear sections with progressive disclosure for optional ticketing and seating.
- [x] Replace technical workflow copy with concise, user-facing guidance and a clearer final handoff.
- [x] Preserve all existing field bindings, validation, authorization, and submission behavior.
- [x] Verify Blade compilation, formatting, focused tests, and the final diff.

## Review

The advanced builder now uses one calm, single-column workspace with four focused steps: Event basics, Date & details, Registration, and Review & create. The duplicated step navigation, technical workflow sidebar, and dense default panels were removed. Templates and advanced ticket metadata are tucked behind optional disclosure controls, category selection uses touch-friendly checkboxes, and seating configuration appears only when a ticket actually needs it.

Organizer switching now uses deferred Livewire state plus local Alpine presentation for the optional location field, avoiding a server request solely to change the visible form fields. All existing form keys, authorization, validation, ticketing, seating, and post-create session handoff remain intact.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 8 passed (55 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 1000 files.
- `vendor/bin/pint --dirty`, `php artisan view:cache`, `npm run build`, and `git diff --check` — passed.

# Advanced/public event submission parity

## Plan

- [x] Map the public submission fields to the advanced event and first-session model boundaries.
- [x] Define which values are derived and locked for institution- and speaker-originated advanced flows.
- [x] Add the complete public event metadata set to the advanced builder and persist it through the managed-event workflow.
- [x] Keep registration, ticket, package, quota, seating, and program timeframe controls as advanced-only additions.
- [x] Add regression coverage for parity, contextual defaults, and server-side context enforcement.
- [x] Run focused tests, Blade/build checks, static analysis, formatting, and diff validation.

## Review

The advanced builder now contains the public event profile fields: title, description, category, topic taxonomy, references, first-session date/time, country, format, visibility, audience, languages, speakers, other key people, location, links, and media. The public-only submitter and captcha fields remain out of the authenticated managed flow.

Institution-originated creation locks the institution as organiser and location context. Speaker-originated creation locks the speaker as organiser and adds that speaker to the event people list; it does not guess an institution venue from a speaker membership. Venue, format, audience, content, and session details remain editable because they are not reliably known from the source page.

The advanced-only controls remain separate for program timeframe, registration, ticket/package definitions, quotas, and seating. The created parent event stores the public profile and first-session metadata, then opens the existing public session submission flow with those values prefilled.

The advanced timing rules now match the public flow for Friday/Ramadhan prayer options and end-time ordering, and the first session must fall inside the program timeframe.

## Verification

- \`vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php\` — 10 passed (77 assertions).
- \`vendor/bin/pest --parallel --compact tests/Feature/EventActionsTest.php\` — 8 passed (39 assertions).
- \`vendor/bin/pest --parallel --compact tests/Feature/AdvancedEventApiTest.php\` — 4 passed (17 assertions).
- \`vendor/bin/phpstan analyse --ansi\` — no errors.
- \`vendor/bin/pint\` on modified PHP files, testing-environment \`php artisan view:cache\`, \`npm run build\`, and \`git diff --check\` — passed.

# Advanced builder Filament parity refresh

## Plan

- [x] Compare the public Filament wizard structure and field presentation with the advanced builder.
- [x] Convert the advanced form to Filament schema components and the same friendly wizard shell.
- [x] Preserve context locking, local conditional behavior, registration extras, and existing persistence.
- [x] Add or update UI regression coverage.
- [x] Run Blade, focused tests, static analysis, build, and diff checks.

## Review

The advanced event builder now follows the public Hantar Majlis interaction model: the custom multi-panel HTML was replaced with a responsive Filament wizard, grouped sections, helper text, native Filament validation presentation, repeaters for tickets/people/seating, and the same asset shell and scrollable step header. Managed-event context remains authoritative, while the registration, ticket, seating, program timeframe, and media controls remain available.

The refactor also accounts for Filament-specific state: RichEditor JSON is accepted and persisted, empty single-file upload arrays are normalized before validation, and repeaters retain numeric state keys for the existing workflow. Age-group normalization remains client-local, and the seating review panel is client-local as well.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 10 passed (80 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/EventActionsTest.php` — 8 passed (39 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/AdvancedEventApiTest.php` — 4 passed (17 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors.
- `php artisan view:cache`, `npm run build`, targeted `vendor/bin/pint`, and `git diff --check` — passed.

# Advanced feature discoverability follow-up

## Plan

- [x] Make registration, ticketing, quota, and seating capabilities visible before the wizard.
- [x] Clarify how ticket seating activates the seating-map fields.
- [x] Add regression assertions and verify the corrected UI path.

## Review

The advanced capabilities were not removed from the workflow, but the Filament refactor made them too easy to miss: registration and tickets were only visible on a later wizard step, while seating was conditionally hidden until a ticket selected a seating mode. The form now advertises these capabilities before the wizard, uses the clearer `Pendaftaran & tiket` step label, and explains the seating activation rule.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 10 passed (82 assertions).
- `php artisan view:cache`, targeted Pint, and `git diff --check` — passed.

# Advanced builder browser fill-through verification

## Plan

- [x] Fill the managed builder with harmless event, speaker, ticket, quota, and seating values.
- [x] Verify registration, ticket, quota, seating, and seating-map controls in the rendered browser DOM.
- [x] Correct the ticket-to-seating mode mismatch discovered during the fill-through.
- [x] Leave the form at review without submitting an event.

## Review

Browser verification found that choosing an `Assigned` ticket left the seating map on its default `General Admission` mode, which would make the completed form fail later. Ticket seating now updates the seating-map mode locally: assigned-only tickets select `Assigned`, general-admission-only tickets select `General Admission`, and mixed modes select `Hybrid`.

Verification:

- Browser fill-through reached the final review step with registration enabled, a paid ticket at RM25.00, quota 50, assigned seating, a 50-seat map, and all seating fields visible; no event was submitted.
- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 10 passed (82 assertions).
- `vendor/bin/phpstan analyse --ansi`, `php artisan view:cache`, targeted Pint, and `git diff --check` — passed.

# Membership claim contact input and applicant notes

## Plan

- [x] Trace the membership claim form and existing account phone input.
- [x] Add the Ysfkaya phone field and persist optional applicant notes in application metadata.
- [x] Surface applicant notes to the claimant and reviewer, then add regression coverage.
- [x] Run focused tests and verification checks.

## Review

The membership claim form now uses the same Ysfkaya phone configuration as Tetapan Akaun: Malaysia as the initial country, international display format, and E.164 submission format. Applicants can optionally add a Catatan up to 2,000 characters; the note is stored in the existing application metadata and shown in both the applicant history and admin review page.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 16 passed (99 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationAdminResourceTest.php` — 5 passed (32 assertions).
- Full PHPStan and targeted PHPStan — no errors.
- Targeted Pint, `php artisan view:cache`, JSON validation, PHP syntax checks, and `git diff --check` — passed.
- Chrome verification — Malay page shows the Ysfkaya telephone input and Catatan textarea with the expected placeholder and 2,000-character limit.

# Majlis filters and search cleanup

## Plan

- [x] Trace every Majlis filter, search scope, URL state, and active-filter summary.
- [x] Align filter state updates, search scopes, date shortcuts, and saved/share links.
- [x] Represent every active filter with a contextual label and complete the visible Malay vocabulary.
- [x] Add focused regression coverage for the filter vocabulary and state-preserving search flows.
- [x] Run browser, test, formatting, Blade, translation, diff, and PHPStan verification.
- [x] Migrate the remaining sidebar and toolbar fields to Filament while preserving the existing `filterData` state and location cascade.

## Review

The Majlis page now keeps all filter controls in the same Livewire `filterData` state, so search scopes and secondary filters reset pagination and stay synchronized with the URL. Date shortcuts preserve the current search and filters. Active-filter chips now cover location, event type, language, format, audience, speakers and roles, topics, references, timing, links, and search scopes with clear field labels. The unused Apply button was replaced with an automatic-update message, and language choices now use a searchable, preloaded Filament multi-select showing full names alongside their codes.

The remaining location, date, event-type, format, radius, search-scope, hero-search, and result-sort controls are now Filament schemas as well. Dependent geography fields keep their existing cascade and canonical address keys, while the branded search toolbar, Escape-to-clear behavior, nearby permission gate, date shortcuts, and distance-sort availability remain intact.

Existing Signals intent tracking for search, filter changes, nearby search, clearing, saving, and sharing remains in place; no blanket cosmetic click tracking was added.

## Verification

- Focused filter/search regression tests — 6 passed (59 assertions).
- Filament field-set regression test — passed for location, dates, category, format, radius, search scopes, hero search, and sorting.
- Full `EventSearchTest` — 95 passed; the existing hidden domain payload test remains failing because broad domain options are preloaded into the initial response.
- `vendor/bin/phpstan analyse --ansi` — no errors across 998 files.
- Targeted Pint, `php artisan view:cache`, JSON validation, and `git diff --check` — passed.
- Chrome verification — Malay filter options render correctly; search scopes and date shortcuts preserve state in the URL.
# Commit audit through 26 Aug 2026

## Plan

- [x] Define the cutoff, inspect repository state, and establish a test/static-analysis baseline.
- [x] Review the cutoff commits and trace changed behavior through the code graph and tests.
- [x] Reproduce confirmed defects and add focused regression tests.
- [x] Fix confirmed bugs; explain and pause on ambiguous logic or workflow choices.
- [x] Run verification and document findings, fixes, and any decisions still needed.

## Review

Scope: `a339e092`, `e82eec9f`, and `55c94af2`, through 2026-08-26 23:59:59 (+08:00). Later commits were checked only to avoid duplicating fixes that had already landed.

Confirmed bugs fixed:

- Membership claims now share one guard across the web, API, and MCP paths. Already-members, duplicate pending applications, and pending invitations are rejected before a new application is created.
- The web claim form now validates role and relationship values server-side, shows claim conflicts on a visible field, validates unique phone numbers, and only saves a new phone number after the application succeeds.
- Empty institution capacity overrides now clear an existing pivot override when the institution remains selected.
- Person workspaces now detect existing members without email-case sensitivity, count an event once even when multiple speaker rows exist, normalize invalid URL filters, render state-object labels, and avoid links to private/draft events that the public route cannot open.
- Membership relationship and role labels are translated in the claim form.
- Claim evidence is now mandatory on the web form as well as the API and MCP contracts, with a regression test for an empty submission.
- Hidden-event duplication now preserves the source visibility server-side, even if the hidden form field is omitted or tampered with.
- A current-head PHPStan warning in the membership application resource was removed; PHPStan is clean.

Findings already repaired by commits after the cutoff, so no duplicate patch was needed:

- `laravel/ai` is in runtime `require`, not only `require-dev`.
- AI-extracted taxonomy values are normalized to the scalar shape expected by the form.
- Public institution/person claim buttons are limited to records the viewer can actually claim.
- The advanced event builder preserves the requested person as the primary organizer context.

Decisions resolved during this review:

- Claim evidence is required consistently across web, API, and MCP. A claim without at least one supporting file is rejected.
- Duplicating a hidden event keeps it hidden. The duplicate cannot silently become public because a hidden form field was not submitted.
- A person-profile admin is a user with the `admin` membership role on that one person record. They can manage/delete the profile and its linked events at owner level; an owner can remove an admin, but an admin cannot remove the owner.
- Any member of a person profile, including `viewer` and `editor`, can see that profile's private and draft linked events in the member workspace. Guests still cannot.
- Only the exact `Topik / bidang` value `Agama & Kerohanian` activates religion-specific questions and the topic/reference step. `Jenis Majlis` does not determine religious behavior.
- `Waktu` is always shown. Prayer-relative choices such as `Selepas Asar` and `Sebelum Maghrib` are treated as scheduling/cultural labels, not as evidence that an event is religious.

Further logic and workflow decisions still needed:

- Space override API semantics: an omitted override field preserves existing values; the web form now sends an empty field when an override is removed and clears that pivot. Decide whether an explicitly empty override list in every API client should also mean “clear all.”
- Invitation history and viewer wording are UX choices: the workspace currently shows invitation history in one list and uses “management” wording for viewers. Decide whether to split active/history invitations and use neutral wording for non-managers.
- Topic optionality: the form currently requires a `Topik / bidang` value even though the topic is otherwise an optional classifier. Decide whether events may be submitted without a broad topic; if yes, the form will simply skip religion-specific behavior.
- Religious default time: when the form starts with `Agama & Kerohanian`, it currently preselects `Selepas Maghrib`; decide whether that helpful default should remain, or whether every event should start at `Lain waktu` and let the submitter choose.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 21 passed (124 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationActionsTest.php` — 8 passed (28 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SpaceModelRemediationTest.php` — 7 passed (29 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 12 passed (91 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventAdaptiveFormTest.php` — 14 passed (83 assertions).
- Member API parity — 5 passed (37 assertions); member MCP server — 40 passed (599 assertions).
- `vendor/bin/phpstan analyse --ansi`, `vendor/bin/pint --dirty`, `php artisan view:cache`, JSON validation, and `git diff --check` — passed.

No new Signals event was added: these fixes preserve existing workflow intent tracking and do not introduce a new user intent path.

# Follow-up decisions: member permissions and event classification

## Plan

- [x] Trace the permission hierarchy, member-removal authorization, linked-event visibility, and religious/time form behavior.
- [x] Implement the confirmed owner/admin, member-visibility, topic-based religious, and always-visible time decisions.
- [x] Add regression tests for each changed rule and flow.
- [x] Run focused tests, formatting, static analysis, and final diff review.
- [x] Document the remaining product decisions in plain language.

## Review

Person-profile admins now receive owner-level deletion for the person profile and events linked through that profile without widening deletion rights for admins of other resource types. The person model also protects the owner membership at the shared mutation boundary, so a direct or Filament action cannot remove the owner accidentally.

Person workspaces deliberately expose all linked event statuses and visibility levels to members, while public event links remain gated by the event's public reachability rules. The submission wizard now uses only the exact broad topic `Agama & Kerohanian` for religion-specific behavior and keeps `Waktu` available for every event.

## Verification

- `vendor/bin/pest --parallel tests/Feature/ManagedWorkspacesTest.php` — 13 passed (101 assertions).
- `vendor/bin/pest --parallel tests/Feature/MemberPermissionGateTest.php` — 5 passed (34 assertions).
- `vendor/bin/pest --parallel tests/Feature/SubmitEventAdaptiveFormTest.php` — 14 passed (91 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 1,005 files.
- `vendor/bin/pint --dirty --test`, `php artisan view:cache`, translation JSON validation, and `git diff --check` — passed.

The full parallel suite also completed with 2,156 passing tests and 26 failures. The failures were existing unrelated/parallel-sensitive cases (geography fixture setup, locale-sensitive copy, cache/search expectations, public-page behavior, and other pre-existing tests); the two event mutation paths that looked potentially related passed when isolated.

# Person ownership transfer and timing clarification

## Plan

- [x] Add a formal owner-only transfer workflow for person profiles.
- [x] Make `Sebelum Maghrib` available every day and keep `Selepas Tarawih` Ramadan-only.
- [x] Add regression coverage for transfer authorization, membership roles, and timing options.
- [x] Run focused tests, formatting, static analysis, and document the space-override question with a concrete example.

## Review

The person workspace now has a formal ownership transfer action. The current owner can transfer ownership to an existing profile member; the old owner becomes an admin, the new member becomes the sole owner, and direct owner removal or role changes remain blocked. The transfer is intentionally owner-only until the product decides whether an admin may initiate this security-sensitive operation.

`Sebelum Maghrib` is now a daily scheduling label in the public submit form, advanced builder, and contribution form. `Selepas Tarawih` remains Ramadan-only. There is no `Sebelum Tarawih` option in the current taxonomy.

Space API semantics now match the documented contract: omitting `institution_space_overrides` preserves existing per-institution capacities, while sending `[]` clears those capacities without unlinking the institutions. Sending `institutions` still controls the institution links themselves.

## Verification

- Focused timing, workspace, contribution-form, space, and admin API tests passed: 52 tests, 311 assertions.
- `vendor/bin/phpstan analyse --ansi` — no errors across 1,006 files.
- `vendor/bin/pint --dirty --test` and `git diff --check` — passed.

# Pest 5 test-impact audit and remediation

## Plan

- [x] Run Pest 5 Test Impact Analysis with coverage over the uncommitted-change impact set.
- [x] Audit each reported failure against the current application source and contracts.
- [x] Fix genuine regressions and update stale expectations or unstable fixtures.
- [x] Replay the residual failures, rerun TIA, and complete static checks.
- [x] Prove the changed submission flow in live Chrome DevTools MCP.

## Review

The initial TIA run reported 22 failures. The audit separated stale expectations from real regressions: canonical geography traversal, API null-field parity, generated Filament JavaScript encoding, public-page robots semantics, lazy taxonomy search, event-change rendering, report/subject translations, production seeder expectations, membership cleanup and race safety, and event timing behavior. Tests were updated only where the current codebase intentionally defines a different contract. The final residual failures were caused by an admin fixture combining explicit absolute timestamps with randomized prayer-relative metadata and a Tarawih test that was being normalized by the UI before it reached the shared submit guard; both are now covered by stable, source-aligned setup.

## Verification

- `XDEBUG_MODE=coverage vendor/bin/pest --parallel --tia --compact` — 2,193 passed (14,079 assertions; 102 directly affected, 2,091 replayed), 0 failed.
- Residual replay — 5 passed (39 assertions), parallel.
- `vendor/bin/phpstan analyse --ansi` — no errors across 1,007 files.
- `vendor/bin/pint --dirty --test` — passed.
- `git diff --check` — passed.
- Live Chrome DevTools MCP at `https://ilmu360.test/hantar-majlis` — page title `Hantar Majlis - ilmu360°`; date and prayer controls updated through Livewire; an invalid end time was cleared client-side; five Livewire XHR requests returned HTTP 200; no console errors or warnings; generated validation JavaScript contained encoded message text and no raw `@js(` directive.

No additional Signals event was needed: the event replacement navigation retains its explicit existing intent-tracking attributes.

# Final hard-cut verification

## Plan

- [x] Apply the canonical geography contract at every catalog boundary and caller.
- [x] Remove legacy geography parameter names without compatibility aliases or remapping.
- [x] Re-run Pest 5 Test Impact Analysis after the hard cut.
- [x] Re-run static, formatting, Blade, and repository-integrity checks.

## Review

Catalog controllers, the admin mutation service, and their tests now use the canonical `administrative_district` parameter. No legacy alias, translation layer, or backward-compatibility path was added. The source contract is authoritative.

## Verification

- `XDEBUG_MODE=coverage vendor/bin/pest --parallel --tia --compact` — 2,193 passed (14,079 assertions; 419 directly affected, 1,774 replayed), 0 failed.
- Canonical catalog replay — 2 passed (22 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 1,007 files.
- `vendor/bin/pint --dirty --test`, `php artisan view:cache`, and `git diff --check` — passed.
- Legacy geography, SoftDeletes, and debug-call scans — no matches.
- No `packages` directory exists for the package migration constraint scan.

# Pest 5 tooling and AI guidance

## Plan

- [x] Verify the Pest 5 Agent, PHPStan, and Rector packages are required, locked, and installed.
- [x] Verify the Pest PHPStan extension, Pest Rector set, and local TIA configuration.
- [x] Document plugin usage, coverage-backed TIA commands, and agent-probe rules in the AI guidance.
- [x] Validate the installed commands and repository diff.

## Review

The requested Pest 5 plugins were already present in `composer.json`, `composer.lock`, and `vendor/`: Agent `v5.0.0`, PHPStan `v5.2.0`, Rector `v5.0.4`, and Rector core `2.6.4`. No dependency churn was needed. The AI guidance now points agents to the `./pest` wrapper for Xdebug-backed TIA, the Agent plugin's safe one-off syntax, the Pest PHPStan extension, and the Pest Rector coding-style set.

## Verification

- `composer validate --no-check-publish` — valid.
- `vendor/bin/pest --version` — Pest 5.1.3.
- `vendor/bin/pest --help` — exposes `--tia`, `--filtered`, `--locally`, `--baselined`, and `--baseline`.
- `vendor/bin/rector --version` — Rector 2.6.4.
- `phpstan.neon` resolves `vendor/pestphp/pest-plugin-phpstan/extension.neon`.
- `vendor/bin/pest --agent='expect(true)->toBeTrue();'` — 1 passed (1 assertion).
- `./pest --parallel --tia --filtered --compact --filter='requires an explicit administrative district'` — 1 passed (4 assertions); the runner correctly bypasses TIA for a partial filtered selection.
- `vendor/bin/rector process --dry-run --no-progress-bar tests/Feature/Api/Frontend/CatalogApiTest.php` — no changes proposed.

# Block duplicate pending contribution requests

## Plan

- [x] Trace the contribution routes/components, request model/status semantics, and existing duplicate-submission tests.
- [x] Implement a shared pending-request guard for speaker, institution, event, and reference contributions.
- [x] Add focused Pest coverage for each resource type and allowed non-pending cases.
- [x] Run focused tests, PHPStan/format checks, and review the final diff.

## Review

- Added an entity-wide pending-request guard shared by the web page, update-request action, and frontend API.
- Hid the update form while blocked, placed the Malay alert beneath the heading with responsive spacing, and localized the new message across supported locales.
- Preserved the existing proposer-scoped API request details while exposing only a boolean block indicator for other pending requests.

## Verification

- Focused parallel Pest run: 7 passed, 24 assertions.
- `vendor/bin/phpstan analyse --ansi`: no errors.
- Targeted Pint check, Blade cache compilation, PHP syntax checks, locale JSON validation, and `git diff --check`: passed.

## Live verification

- Submitted a speaker update in the browser, confirmed the Malay alert appeared beneath the heading with a 16px gap, and confirmed the update form was hidden.
- Approved the request through the admin panel, revisited the exact speaker URL, and confirmed the alert disappeared while the `Hantar Permintaan Kemas Kini` form returned.
- Restored the speaker's original test value after verification so the provided URL remained valid; the test request remains approved and no pending request remains.

# Remove unused institution unverified status

## Plan

- [x] Audit institution status usages and confirm whether existing unverified institution rows need migration.
- [x] Remove unverified from institution form, filters, admin API choices, and supporting documentation.
- [x] Add regression coverage for the reduced institution status set.
- [x] Run focused tests, static checks, and review the diff.

## Review

- Institution status is now limited to `pending`, `verified`, `rejected`, and `inactive` in the admin form, table filter, and admin API.
- Legacy institution rows with `unverified` are normalized to `pending` for review by the migration; the current local database has no such rows.
- This follow-up extends the removal to donation-account and venue status contracts.

## Verification

- Focused parallel Pest run: 2 passed, 22 assertions.
- `vendor/bin/phpstan analyse --ansi`: no errors.
- Pint check, Blade cache compilation, migration syntax check, and `git diff --check`: passed.
- The live admin URL returned `Forbidden` because the attached browser session is not an administrator; form/API regression coverage passed instead.

# Remove unverified record status globally

## Plan

- [x] Inventory record-status usages and separate them from unrelated email/address-validation terminology.
- [x] Remove the status from donation channels and venue copies, normalizing defaults and validators to `pending`.
- [x] Update tests, moderation labels, and status documentation.
- [x] Run the complete relevant test slice, static checks, and review the final diff.

## Review

- Removed `unverified` from all supported record-status contracts: institutions, speakers, references, venues, and donation channels.
- Legacy institution, venue, and donation-channel rows are normalized to `pending`; new donation-channel defaults and admin schemas now use `pending`.
- Preserved unrelated verification concepts for email, phone, address validation, and historical migration compatibility.

## Verification

- Relevant parallel Pest slice: 35 passed, 296 assertions.
- `vendor/bin/phpstan analyse --ansi`: no errors.
- Pint, Blade cache compilation, PHP syntax checks, locale JSON validation, and `git diff --check`: passed.

# Use custom Filament selects throughout the application

## Plan

- [x] Inventory application Select fields, SelectFilters, and explicit native overrides.
- [x] Configure Filament Select and SelectFilter components to use `native(false)` by default.
- [x] Replace the remaining explicit native Select override and add regression coverage.
- [x] Run representative frontend tests, static checks, and review the final diff.

## Review

- Added an application-wide Filament configuration so new form selects and table select filters use the JavaScript select automatically.
- Preserved `native()` on date and time picker components, which are separate controls and not Select fields.

## Verification

- Global configuration test: 1 passed, 2 assertions.
- Representative public and dashboard form tests: 9 passed, 106 assertions.
- PHPStan, Pint, Blade cache compilation, PHP syntax checks, and `git diff --check`: passed.

# Align public reference visibility and contribution snapshots

## Plan

- [x] Enforce `published_at` plus `verified`/`pending` for every public reference surface.
- [x] Align pending institution/reference detail authorization and public child-reference rendering.
- [x] Use the locked entity when capturing contribution-request original data.
- [x] Update factories, seed data, documentation, and regression tests.
- [x] Run focused Pest, static-analysis, formatting, and diff checks.

## Review

Public reference visibility is now consistently `published_at IS NOT NULL` plus `status IN ('verified', 'pending')` across directories, search/index payloads, event relations, detail authorization, family expansion, follow/share resolution, and public catalogs. Contribution updates now lock and re-read the target before snapshotting and reject duplicate pending requests across entity types.

## Verification

- Focused reference, event API, public-read, event-show, search, and searchable-model suites passed after the final fixes.
- `vendor/bin/phpstan analyse --ansi` — no errors across 1,011 files.
- Targeted Pint, syntax, translation JSON, view-cache, and `git diff --check` verification completed.
- Broad parallel suites previously showed nondeterministic unrelated failures; the affected tests were rerun individually or with focused filters and passed.
# Rebuild event detail page hierarchy and admission experience

## Plan

- [x] Inspect the target event’s real occurrence, session, access, ticket, seating, and location data.
- [x] Audit the events package and its commerce, ticketing, seating, media, and registration integrations.
- [x] Rebuild the public detail view with seamless single-occurrence/single-session presentation and explicit multi-level schedules.
- [x] Make registration, ticketing, capacity, and seating states appear only when supported and verify responsive behavior.
- [x] Run focused regression coverage, static checks, asset build, and browser verification.

## Review

The event detail page now presents the package graph as a coherent programme folio. A lone public occurrence is merged into the event’s date, time, location, time-expression, and admission presentation; a lone session is shown without redundant “Occurrence 1”/“Sessions” scaffolding. Multiple occurrences retain date boundaries, and each occurrence can show its session programme, scoped resources, speakers, location, and capacity.

Admission is now data-driven across event, occurrence, and session scopes. Public ticket types expose price, quantity, seating mode, inventory, sales windows, and scope; registration exposes opening/closing/full states; access policies expose approval, waitlist, capacity, notes, and walk-in status; seat maps expose sections and capacity. Unsupported blocks stay absent. Paid ticket states remain informative because the current application has no enabled public paid checkout integration.

## Verification

- vendor/bin/pest --parallel --compact tests/Feature/EventShowPageTest.php — passed (37 tests, 138 assertions).
- vendor/bin/pest --parallel --compact tests/Feature/EventSearchTest.php --filter=... — passed (14 tests, 39 assertions).
- vendor/bin/phpstan analyse --ansi app/Support/Events/EventDetailPresenter.php app/Livewire/Pages/Events/Show.php — passed.
- php artisan view:cache, npm run build, PHP syntax checks, and git diff --check — passed.
- Supplied event URL — HTTP 200; live HTML shows the location/reference content and omits schedule, admission, registration, and seating blocks because the stored event has no sessions or admission configuration.
- Full-project PHPStan still reports two pre-existing errors in app/Http/Controllers/Api/EventController.php and app/Support/Location/VisitorCountryResolver.php, both outside this change.
- Collaborative preview navigation/snapshot timed out repeatedly after reconnect; live HTTP verification was used instead.
