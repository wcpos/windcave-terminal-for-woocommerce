# Lessons from the sibling terminal plugins

Lessons from the Stripe, SumUp, Mollie, PayArc and Square terminal plugins, checked against this plugin.
Checked on **2026-10-05** against the [research notes](/Users/claude/agent/handoff/windcave-terminal-2026-10-05/lessons-research.md).
Observed: the code pointers below were inspected. Verdicts describe implementation coverage; live terminal, POS WebView and real WooCommerce storage behaviour are not verified here. Versions or commit IDs come from the research; where it gives no version, that is stated explicitly.

## Legend

- `[x]` covered, with a code or test pointer.
- `[~]` partial; the remaining work is stated.
- `[ ]` open.
- `n/a` does not apply to this HIT integration; the reason is stated.

## Top 15

These verdicts are the reviewer's. The topic sections distinguish the wider research recommendations from these reviewed checks.

- [x] **1. Converge completion paths so competing requests cannot complete an order twice.** `OrderCompletion::complete()` is the only `payment_complete()` caller. AJAX poll, `Gateway::process_payment()`, FPRN and sweep reach it; earlier-transaction recovery remains partial under #5. See `OrderCompletionTest::test_already_paid_fresh_copy_is_not_completed_again`.
- [x] **2. Use an atomic completion claim, owner-only release and a fresh order inside it.** `PaymentLock` uses `INSERT IGNORE` and compare-and-delete expiry/release. `OrderCompletion::reload_order()` clears posts, HPOS `OrderCache`, data-store and meta caches. See `PaymentLockTest`.
- [x] **3. Authorise POS AJAX with an order-scoped credential without relying on the rendering user's nonce, and explain refusals in logs.** `AjaxHandler::can_access_order()` accepts `PaymentRequestToken`; refusals now log a reason and a boolean `credential_sent`, never the credential value.
- [x] **4. Save the TxnRef before Purchase and keep it after an uncertain response.** `PaymentAttempt::record_new()` precedes `HitClient::purchase()`; see `test_start_records_attempt_before_purchase`. Transport and parse failures leave the attempt pending for Status against the same TxnRef.
- [~] **5. Resolve a recorded earlier transaction before starting another when HIT reports PC.** `HitPaymentService::start()` returns `station_busy` and asks the cashier to finish the earlier transaction on the terminal. It does not locate and resolve another order's earlier TxnRef; the sweep resolves set-aside attempts.
- [x] **6. Resume a non-final attempt and its prompts after order-pay reloads.** `Gateway::payment_fields()` emits `data-resume`; `assets/js/payment.js` resumes polling and renders DL1/DL2 and enabled B1/B2.
- [x] **7. Complete approved payments even if FPRN never arrives.** Browser polling calls `HitPaymentService::poll()`; `PaymentSweeper` provides a 10-minute backstop after the browser closes.
- [x] **8. Treat an authenticated notification as a reason to fetch Status, never as proof of payment.** `FprnHandler::handle()` checks the HMAC and a recorded TxnRef, then calls `resolve_txn()`. `HitPaymentService::apply()` completes only on `Complete=1`; signature verification at status 7 is not final.
- [x] **9. Verify payment on order-pay submit and redirect a paid order to its receipt.** `Gateway::process_payment()` polls once and completes only through `apply()` verification; otherwise it shows a notice. Already-paid orders return the order-received redirect.
- [~] **10. Match the collected amount and currency to the order, with correct currency precision and under-collection handling.** Purchase uses `number_format( total, 2 )`; `AmtA` must match the attempt amount and the current order total. This assumes two-decimal currencies (NZD/AUD); tips and surcharge are not enabled. A mismatch returns `verification_failed` and adds a note, without moving the order on-hold. See `HitPaymentService::start()` and `apply()`.
- [x] **11. Keep attempts bound to their environment and make endpoint selection diagnosable.** Attempts record the environment and approval verification checks it. `HitPaymentService::check()` now refuses Status across environments, and `start()` preserves that pending attempt. `HitClient::send()` logs the endpoint.
- [x] **12. Allow POS-only enablement and keep settlement available after disabling the gateway.** `Settings::active()` gates start only through `AjaxHandler::require_gateway_enabled()`. Poll, answer, cancel, FPRN and sweep are ungated.
- [x] **13. Claim the gateway immediately before completing, never on start or abandon.** `PaymentAttempt::claim_order_gateway()` runs inside `OrderCompletion::complete()` just before `payment_complete()`, allowing POS per-gateway order status handling.
- [x] **14. Sweep only relevant orders across both storage modes, in batches, and unschedule on deactivation.** `PaymentSweeper::find_orders()` uses `type => shop_order` and a `meta_key` EXISTS query honoured by both stores. `PaymentSweeper::unschedule()` is called by `wctwc_deactivate()`.
- [x] **15. Preserve useful HIT diagnostics and decline codes while masking credentials.** `HitClient::send()` logs request/response XML and response `elapsed_ms`. `HitPaymentService::apply()`/`result()` log ReCo, Complete, TxnStatusId and DL1/DL2; decline notes include RC.

## Pairing / station / reader configuration errors

- [~] **SumUp 0.0.10: longer pairing codes were rejected.** Pairing itself is n/a: HIT uses issued Station IDs. `Settings::station_ids()` trims and accepts opaque strings, but does not enforce the documented 32-character maximum (O1).
- [x] **PayArc 0.1.7–0.1.12: local serial assumptions and misleading metadata warnings rejected working terminals.** `Settings::station_ids()` imposes no digit/fixed-length pattern; `HitPaymentService::start()` checks the configured choices and logs local failures at warning. HIT response codes and display text are retained by `HitResponse::to_array()`.
- [ ] **Square 0.4.0/0.4.1: an empty discovery result was indistinguishable from discovery never running.** Station discovery is n/a for HIT, but the recommended station diagnostic is absent from `Gateway::init_form_fields()`; no authenticated Test station action exists (O2).
- [x] **Mollie 0.3.0/0.4.0: inactive or unintended terminals were offered.** `HitPaymentService::start()` enforces the configured allowlist and lock; `Settings::station_ids()` includes the default. `Gateway::payment_fields()` requires an explicit choice for multiple Stations without a default (O3); `GatewayTest::test_payment_fields_requires_explicit_choice_with_multiple_stations_and_no_default` and `test_payment_fields_single_station_is_preselected` cover selection. HIT supplies no discovery/active-reader list.
- [~] **Stripe 0.0.15–0.0.22: recovery cancelled another register's transaction.** `HitPaymentService::start()` reports PC without cancelling an unknown transaction. Automatic reconciliation of a recorded earlier TxnRef is missing, as described in Top 15 #5.
- [x] **Stripe 0.0.17–0.0.23: stale reader liveness timestamps aborted valid payments.** `HitPaymentService::start()` has no last-seen preflight heuristic; `apply()` uses HIT results. The browser deadline in `assets/js/payment.js` offers manual recovery rather than automatically cancelling.
- [x] **Stripe 0.0.27: a keep-warm display command could replace a live payment.** `HitClient` sends only explicit Purchase, Refund, Status and UI commands; `PaymentSweeper` resolves Status and sends no display ping.

## Auth and API key errors (key type, test vs live, UAT vs production)

- [x] **Stripe 0.0.22: restricted keys failed a prefix check.** `Settings::hit_user()` and `hit_key()` trim opaque credentials; `HitPaymentService::start()` requires non-empty values without guessing their format.
- [ ] **Stripe 0.0.34: a format-valid indicator hid a broken key.** `Gateway::init_form_fields()` has no real authenticated settings-validation action; it cannot diagnose authentication, PK or PO before checkout (O2).
- [x] **Stripe 0.0.30: a failed account lookup became a false USD-only restriction.** `HitPaymentService::start()` takes currency from the order, and `HitClient::payment_fields()` sends it to HIT. There is no account-country lookup or fallback currency restriction.
- [~] **PayArc 0.1.6: credentials and cached state crossed test/live boundaries.** `PaymentAttempt::record_new()` records the environment and `HitPaymentService::check()` now blocks cross-environment polling. `Gateway` still allows settings changes during pending payments; credentials are not fingerprinted or bound to an attempt (O4).
- [~] **PayArc 0.1.13: production requests used the wrong authentication.** `Settings::endpoint_url()` selects UAT or production and `HitClient::send()` logs that endpoint. The returned HTTP error is generic, so the cashier does not receive the endpoint host and environment in the auth error (O5).
- n/a **Square 0.6.1: OAuth connected to the saved mode and stale webhook health survived a switch.** HIT has no OAuth flow, token registry or stored health cache here; `Settings::endpoint_url()` selects the endpoint directly and `FprnHandler` stores no last-delivery health state.
- [~] **Square 0.3.1: SDK scoping broke every request in the release ZIP.** `HitClient::send()` uses `wp_remote_post` without an SDK. `.github/workflows/release.yml` builds the ZIP but does not install it and execute a mocked HIT round trip (O6).
- [~] **Mollie 0.2.0: test credentials could not reach live-only terminals.** `Gateway::init_form_fields()` explains separate UAT credentials and certification; `README.md` records uncertainty about UAT without hardware. Settings do not explain that no simulated terminal is documented (O7).

## Polling cadence, timeouts and stuck "pending"

- [x] **SumUp 0.0.11: a paid card stayed pending because the callback never arrived.** `HitPaymentService::poll()` and `PaymentSweeper::sweep_order()` fetch authoritative Status without requiring FPRN; covered by Top 15 #7.
- [~] **SumUp commits 7710dab/25fcd80: a throttled final read allowed timeout cancellation after success.** `HitPaymentService::cancel()` fetches Status first, but `assets/js/payment.js` stops at its deadline without a forced fresh read, and local abandon does not query HIT (O8).
- [x] **PayArc 0.1.13: briefly invisible sales and an unrecognised ABORTED state caused errors and hangs.** `HitPaymentService::apply()` gives PJ a 30-second grace period; `test_poll_pj_within_grace_stays_pending_without_writes` covers it. `start()` declines incomplete Purchase refusals and `apply()` surfaces Status ReCo errors without changing attempts, preserving network, PC/PJ and complete handling (O9); `HitPaymentServiceTest::test_start_po_reco_declines_with_code_in_message`, `test_poll_unknown_error_reco_returns_error_without_changing_attempt`, `test_poll_network_reco_stays_pending`, `test_poll_pc_status_reply_stays_pending` and `test_start_pj_purchase_reply_uses_grace` cover these cases.
- [x] **Stripe 0.0.14/0.0.27: overlapping polls and old callbacks disrupted retries.** `assets/js/payment.js` uses `pollInFlight`, a request sequence and cleared timers. `HitClient::send()` sets a 30-second HTTP timeout, longer than the research's 20-second Tmo example; it does not send a custom Tmo.
- [x] **Mollie 0.4.0: an unreachable terminal trapped the cashier on a stuck order.** `HitPaymentService::abandon()` clears the current pointer through `PaymentAttempt::abandon_current()` while preserving the TxnRef for `PaymentSweeper`; transport failure during cancel also sets it aside.
- [x] **Square 0.2.0/audit: short checkout deadlines were unsuitable for terminal interaction.** `Gateway::enqueue_payment_scripts()` defaults to 300000 ms; `assets/js/payment.js` caps request-error backoff at 10000 ms and supports resume. The missing forced final read is tracked in O8.

## Double completion and race conditions

- [x] **Mollie 0.5.7–0.5.9, Stripe 0.0.35–0.0.36 and Square 0.8.6–0.8.7: simultaneous completions reduced stock twice.** `OrderCompletion::complete()` claims, reloads and checks paid state before the sole completion call; see `test_already_paid_fresh_copy_is_not_completed_again` and Top 15 #1–2.
- [x] **PayArc, 2026-10-01 audit (local history through 0.1.16): stale objects and non-atomic locks left the same race open.** `PaymentLock::acquire()` uses atomic insertion and compare-and-delete takeover; `release()` can delete only its own value.
- [x] **SumUp 0.0.13: an early form submit treated a transaction ID as proof of payment.** `Gateway::process_payment()` polls and shows a notice unless the order is paid; `HitPaymentService::apply()` verifies a final approval.
- [x] **Stripe 0.0.32/SumUp 0.0.10: a stale result overwrote a retry.** `PaymentAttempt::update()` only changes the current pointer for its owning TxnRef. `HitPaymentService::resolve_txn()` can reconcile an older recorded approval through `OrderCompletion`, with a conflict note when another transaction paid the order.
- [x] **Square 0.2.1: retrying after a partial capture over-collected.** `PaymentAttempt::update()` preserves DpsTxnRefs in history and `HitPaymentService::apply()` flags duplicate approvals. `start()` blocks a new Purchase after an approved-but-unreconciled result (O10), covered by `HitPaymentServiceTest::test_start_refuses_new_purchase_when_current_attempt_approved_but_unpaid`; on-hold handling remains partial under Top 15 #10.
- [x] **Mollie 0.4.0/Square 0.2.0: cancel raced with approval.** `HitPaymentService::cancel()` checks Status, sends only an enabled CANCEL button and checks again; `apply()` honours approval. A UI answer alone never marks the order cancelled or paid.
- [x] **PayArc audit (version not stated): an uncertain sale was retried with a fresh idempotency key.** `HitPaymentService::start()` persists the attempt before Purchase and reuses its Status after transport/parse errors instead of creating a replacement while pending.

## HPOS and object-cache staleness

- [~] **Stripe 0.0.36, Mollie 0.5.8/0.5.9 and Square 0.8.7: a reload retained stale row/meta caches.** `OrderCompletion::reload_order()` explicitly clears all named caches. However, `HitPaymentService::apply()` reads/verifies attempt data before entering the completion claim rather than from the reloaded object (O11).
- [~] **Mollie 0.5.6/Square 0.8.3: the posts store ignored a sweep filter and returned refunds.** `PaymentSweeper::find_orders()` uses the reviewed shop-order/meta-key query (Top 15 #14). It returns objects rather than IDs; `PaymentSweeperTest` checks query arguments with mocks, not both real stores (O12).
- [x] **Square commit ffc7762: option-cache staleness affected attempt state.** `PaymentLock::acquire()` reads claims directly with `$wpdb->get_var()` and uses SQL compare-and-delete; it does not use cached `get_option()` values.

## Incomplete-transaction recovery after a browser refresh or network drop

- [x] **Mollie 0.4.0: refreshing lost the controls for an open payment.** `Gateway::payment_fields()` emits `data-resume`; `assets/js/payment.js` immediately polls. `PaymentSweeper::sweep_order()` also resolves abandoned TxnRefs without relying on a close-page beacon.
- [x] **Stripe 0.0.32: closing the browser left a captured payment on an unpaid order.** `FprnHandler::handle()` and `PaymentSweeper::sweep_order()` reach server-side Status verification and `OrderCompletion::complete()` without a browser submit.
- [~] **Square 0.2.0/0.2.1: an uncertain start needed recovery using the same attempt.** `HitPaymentService::start()` rechecks its current attempt and `abandon()` retains it for the sweep. The wider PC recovery recommendation is still partial under Top 15 #5.
- [x] **Square/WP-Cron, wiki (version not stated): quiet stores ran reconciliation late.** WP-Cron depends on site traffic, so ten minutes is a schedule, not a delivery guarantee. `PaymentSweeper::sweep()` defaults to 25 orders per query (current and abandoned); `sweep_order()` isolates exceptions, and `wctwc_deactivate()` unschedules the job.

## Prompt / signature / cancel edge cases

- [x] **SumUp 0.0.10: the cashier could not see terminal prompts.** `HitPaymentService::result()` returns DL1/DL2 and only enabled B1/B2; `assets/js/payment.js` renders text and keeps polling after `answer()`.
- [~] **Stripe 0.0.12/0.0.14/0.0.22: cancellation and declines were not surfaced reliably.** `HitResponse::approved()` requires final AP=1; `HitPaymentService::apply()` treats final AP=0 as declined and permits retry. Notes/logs contain RC, but the panel message contains DL1 without a dedicated RC field (O13).
- [~] **PayArc 0.1.13: ABORTED was not recognised as final.** `HitPaymentService::cancel()` follows the HIT final result, with cancel-button/unavailable/transport tests in `HitPaymentServiceTest`. Separate terminal-user-cancel and card-timeout fixtures/mapping tests are missing (O14).
- [x] **Mollie 0.3.0: the provider refused cancellation after dispatch.** `HitPaymentService::cancel()` explains when no enabled CANCEL button exists, offers terminal cancellation or set-aside, and leaves polling active.
- [~] **Square 0.2.0: signature collection prolonged the transaction.** `HitResponse::complete()` and `HitPaymentService::apply()` do not infer finality from TxnStatusId 7. `answer()` uses the current pending attempt, but does not compare a submitted TxnRef/prompt version or freshly verify the enabled button across tabs (O15).
- [ ] **Mollie 0.4.0: switching payment method left a terminal attempt running.** The payment-method change listener in `assets/js/payment.js` only updates the place-order button; it does not stop the controller and cancel/set aside the attempt (O16).

## Receipts

- n/a **Stripe 0.0.29: an unrecorded on-reader tip made the receipt smaller than the charge.** This integration enables neither tipping, surcharge nor cash-out; `HitClient::payment_fields()` sends the order amount only. `HitPaymentService::apply()` compares AmtA without tip reconciliation (Top 15 #10).
- [~] **Mollie 0.3.0/Stripe 0.0.25: a paid order was submitted again instead of opening its receipt.** `assets/js/payment.js` navigates to `redirect_url`, and `Gateway::process_payment()` returns a paid-order redirect. There is no plugin hook to recover a duplicate paid POST rejected before that method runs (O17).
- [~] **Square/wiki (version not stated): card receipt handling needed an explicit policy.** `PaymentAttempt::store_receipt()` stores Rcpt/RcptW as meta and a private note; the panel does not inject receipt HTML. Storage has no plugin-level escaping/PAN normalization, and no terminal-versus-POS printing setting is defined (O18); `Logger::redact_xml()` masks receipt digits in logs.

## Refunds (matched vs unmatched, card-present)

- [~] **Stripe 0.0.28: a manual WooCommerce refund never reached the card.** `HitClient::refund()` builds a matched refund and `PaymentAttempt::update()` retains the original DpsTxnRef, but no gateway refund workflow persists refund attempts, polls uncertain outcomes or supports card-present prompts (O19).
- n/a **Mollie 0.5.4: refund handling created a duplicate WooCommerce refund record.** `Gateway` advertises only `products` and implements no `process_refund()` or `wc_create_refund()` path; integrated refunds are absent in this HIT version.
- [ ] **Mollie 0.1.0: refund limits had to account for prior provider refunds.** `HitClient::refund()` is only a transport primitive; it has no approved-minus-refunded ledger or refund limit enforcement (O19).
- [x] **Stripe 0.0.33/Mollie 0.5.5: refunds were routed to the wrong gateway.** `PaymentAttempt::claim_order_gateway()` records this gateway immediately before `OrderCompletion::complete()` calls `payment_complete()`. Actual integrated refunds remain unimplemented.
- [x] **Square/PayArc, wiki (versions not stated): missing refund support left only cash/manual options.** `Gateway::__construct()` does not advertise refunds; `README.md` explicitly directs merchants to Payline or the terminal, then manual recording in WooCommerce.

## Amount rounding and currency

- [~] **Stripe 0.0.32: payout precision rules caused HUF/TWD undercharges.** `HitPaymentService::start()` formats two decimals using a float, with no currency-specific precision or JPY rejection test. Covered by the limitation in Top 15 #10.
- [~] **Stripe 0.0.20: a retry sent an amount from before a cart edit.** `HitPaymentService::start()` reads the supplied order total and currency, never a browser amount, but does not reload the order inside the creation lock (O20).
- [~] **SumUp review (version not stated): minor units were hard-coded to two.** `HitPaymentService::start()` and `apply()` likewise assume two decimals; see Top 15 #10.
- [~] **Mollie 0.1.0/0.5.0: payment attributes needed verification before completion.** `HitPaymentService::apply()` verifies TxnRef, AmtA, attempt environment, unchanged total and order currency. Tip/surcharge adjustments and on-hold under-collection handling are absent, as recorded in Top 15 #10.
- [x] **Stripe 0.0.30: currency rules inferred from failed lookups rejected valid payments.** `HitClient::payment_fields()` sends the order's currency directly; `HitPaymentService::start()` adds no guessed country/currency restriction.

## Settings validation (empty or invalid values)

- [~] **Stripe 0.0.22/PayArc 0.1.8: saved secrets needed masking and blank-preserves-value semantics.** `Gateway::init_form_fields()` uses a password field, but defines no custom masked rendering or blank-preserving save logic (O21).
- [~] **Square 0.6.0/0.7.0: manually entered callback URLs broke signature checks.** `FprnHandler::url()` generates the signed URL passed by `HitClient::purchase()`; no merchant-entered URL is needed. A last-verified-FPRN health row is missing (O22).
- [~] **PayArc 0.1.6/0.1.14: live callbacks needed HTTPS and a usable secret.** `FprnHandler::signature()` derives an order HMAC from WordPress's auth salt and `handle()` fetches Status after verification. `url()` follows `admin_url()` without enforcing HTTPS for production (O23).
- [~] **Square 0.8.0: malformed IDs launched an unusable flow.** `HitPaymentService::start()` rejects empty credentials or an unconfigured Station. `Gateway` does not override `is_available()` to check these fields, so configuration errors can still reach Start (O24).
- [x] **Square 0.8.4/0.8.5: settings styling and missing titles broke gateway presentation.** `Gateway::__construct()` sets `method_title`, `title` and `description`; `init_form_fields()` uses standard WooCommerce field definitions. The actual POS settings appearance is not verified here.
- [x] **Mollie 0.3.0/0.4.0: an allowlist save during an outage could disable checkout.** `Settings::station_ids()` always includes the configured default and derives choices only from saved settings, with no remote validation that can empty them.

## PHP version and extension gaps

- [x] **SumUp 0.0.9: a PHP 8.2 SDK conflicted with PHP 7.4 support.** `wctwc_activate()` enforces PHP 7.4; `HitClient` uses WordPress HTTP without an SDK. `.github/workflows/test.yml` configures PHP 7.4, 8.2, 8.3 and 8.4 tests.
- [x] **Mollie commit 01b0a9b: PHP 8-only syntax broke PHP 7.4.** `.phpcs.xml.dist` enables PHPCompatibilityWP for 7.4+, and `.github/workflows/test.yml` includes PHP 7.4. The matrix is inspected, not executed by this checklist.
- n/a **Square ADR 0002 (release version not stated): its SDK required PHP 8.1.** HIT here has no vendor SDK; `HitClient::send()` calls `wp_remote_post` and `composer.json` requires PHP 7.4+.
- [x] **Stripe 0.0.13/0.0.29: namespaced WP_Error and case-variant paths caused fatals.** `HitClient` and `HitResponse` use `\WP_Error`; the inspected `includes/` paths have no case-variant duplicates. PHP 7.4 CI and the compatibility rules cover the source tree.
- [x] **Windcave XML requirements, plus SumUp 0.0.10's empty-2xx lesson.** `wctwc_activate()` now checks DOMDocument; `composer.json` requires DOM/libxml, not SimpleXML. `HitResponse::from_xml()` rejects DOCTYPE and uses LIBXML_NONET. `HitClient::send()` checks HTTP status first; empty or malformed successful bodies become parse errors, leaving Purchase/Status pending rather than inventing approval.

## The POS iframe / order-pay page and redirect issues

- [~] **Stripe 0.0.20–0.0.24, Square 0.7.1 and PayArc 0.1.12: customer-rendered nonces rejected cashier AJAX.** `AjaxHandler::can_access_order()` accepts an order-bound `PaymentRequestToken` without a nonce and now logs refusal reasons. It does not accept raw order keys, and its capability-only branch has no nonce requirement from the wider research recommendation (O25).
- [~] **Mollie 0.3.0/Stripe 0.0.25: repeated paid submits trapped receipt navigation.** The direct navigation in `assets/js/payment.js` addresses the normal completion path; duplicate POST interception remains missing (O17).
- [~] **SumUp 0.0.12: checkout DOM replacement or method switching left an empty reader panel.** `assets/js/payment.js` boots on DOM ready and `updated_checkout`. Most controls use direct listeners; method selection does not reinitialise a panel or dispose an old controller after replacement (O26).
- [x] **Square 0.8.1: iframe app launching failed and the host pay button wiped state.** External reader-app launching is n/a for server-side HIT. `assets/js/payment.js` hides `#place_order` while Windcave is selected and navigates in the current window; `Gateway::process_payment()` uses a friendly unpaid notice.
- [~] **Mollie 0.3.0/0.5.2: theme CSS distorted the panel and QR display.** QR is n/a for HIT. `assets/css/payment.css` uses plugin selectors, and `assets/js/payment.js` renders DL text safely; there is no hostile-theme preview harness (O27).
- [ ] **Stripe 0.0.26: classic checkout had no order ID and dead-ended.** `Gateway::payment_fields()` renders only logs outside order-pay, while `process_payment()` verifies instead of redirecting a newly created unpaid order to order-pay. The web-checkout switch is still offered (O28).
- [ ] **WCPOS host 1.10.3: guest-cookie and early-submit bugs occurred only in app WebViews.** `assets/js/payment.js` uses the signed credential and optional X-WCPOS header, but `tests/js/controller.test.js` is a fake-DOM suite, not a POS iOS/Android WebView test (O29).

## The gateway disabled in WooCommerce but enabled in POS

- [x] **Mollie 0.5.1–0.5.3: the WooCommerce switch blocked POS-only merchants.** `Settings::active()` accepts either switch; `AjaxHandler::require_gateway_enabled()` gates only start. Poll, UI answers, cancel, FPRN and sweep can settle existing attempts.
- [x] **Stripe 0.0.16, SumUp 0.0.7, Square 0.5.0 and PayArc 0.1.14: merchants mistook the web-checkout switch for POS enablement.** `Gateway::init_form_fields()` explicitly labels it for web checkout and explains the separate POS setting.
- [x] **Stripe 0.0.33, Mollie 0.5.5, Square 0.8.2 and PayArc 0.1.16: the configured POS order status was ignored.** `PaymentAttempt::claim_order_gateway()` sets the method and title only inside `OrderCompletion::complete()`, immediately before completion.

## Logging and support diagnostics

- [~] **PayArc 0.1.11/0.1.12: silent payment and auth paths made failures undiagnosable.** `HitClient::send()` and `HitPaymentService` log exchanges and state; `AjaxHandler::with_order()`/`require_gateway_enabled()` now log reason, operation, order, credential presence and login state. `FprnHandler::handle()` logs rejected callbacks without the research's rejection throttling (O30).
- [~] **PayArc 0.1.15: a bare decline hid processor details.** `HitPaymentService::apply()` records RC and DL1 in the note; `HitResponse::to_array()` logs RC and card type. Dedicated cashier RC/card-entry details and an entry-method summary are missing (O13).
- [~] **Square 0.3.1: broad redaction removed diagnostic codes.** `Logger::redact_context()` preserves ReCo/RC and HTTP/error codes, but `is_sensitive_key()` still uses substring needles rather than an exact key list (O31). This change uses `credential_sent` so its boolean survives without editing Logger.
- [~] **Stripe 0.0.32/0.0.34: the UI discarded errors and log formatting/severity obscured them.** `assets/js/payment.js` displays server messages; `Logger::log()` appends JSON context and passes severity. `Logger::xml()` emits multiline XML and `log()` retains a print_r branch for non-string messages (O32).
- [~] **Stripe 0.0.27: missing timing hid reader delays.** `HitClient::send()` logs each round trip and `AjaxHandler::with_order()` logs request duration, but no explicit attempt-start-to-Complete metric is emitted (O33).
- [x] **Mollie 0.3.1: option-backed diagnostics bloated storage and raced.** `Logger::log()`/`xml()` use WC_Logger with the `windcave-terminal` source and `wctwc_logging` filter rather than an option-based log store.
- [~] **PayArc 0.1.11/0.1.14 and Mollie 0.4.0: cashier logs needed correlation, copy/clear and manageable repetition.** `assets/js/payment.js` timestamps browser requests/results, and `Gateway::payment_fields()` hides log tools by default. There is no server event list, consecutive-duplicate folding or automatic per-attempt log reset (O34).

## Anything else that recurred

- [~] **Stripe 0.0.28/0.0.31 and SumUp audit (version not stated): public routes and client-controlled payment facts weakened security.** `FprnHandler::handle()` authenticates its hint; `HitPaymentService::start()` derives amount/currency server-side and `apply()` verifies results. The broader capability-only AJAX nonce recommendation remains open (O25); `HitClient::send()` does not disable TLS verification.
- [x] **Stripe audit (version not stated): an unwired duplicate completion handler and a missing method risked fatals/double completion.** `OrderCompletion::complete()` is the single completion path; `FprnHandlerTest` and `PaymentSweeperTest` exercise notification and sweep routes.
- [~] **PayArc 0.1.6/Mollie 0.5.1: mid-payment settings changes could interrupt settlement.** `Settings::active()` gates only start and `HitPaymentService::check()` now refuses the wrong environment. Preventing credential/environment edits during pending payments remains open (O4).
- [x] **Mollie 0.5.7–0.5.9, Stripe 0.0.35–0.0.36 and Square 0.8.6–0.8.7: fixing completion did not repair previously doubled stock reductions.** `OrderCompletion::complete()` prevents a repeat completion; it performs no historical stock repair. Any existing discrepancy needs manual correction.
- [~] **Square/Stripe/PayArc audit (versions not stated): AJAX handlers were registered outside AJAX requests.** `AjaxHandler::__construct()` checks `wp_doing_ajax()`, but `FprnHandler::__construct()` registers its AJAX notification hooks whenever constructed by `init()` (O35).

## Open items

Top 15 #5 (earlier-transaction PC recovery) and #10 (currency precision and under-collection policy) already describe their remaining work. Additional partial/open findings above are listed here; none is implemented by this change.

- [ ] **O1 — Station length:** enforce only the documented maximum while retaining opaque IDs (`Settings::station_ids()`).
- [ ] **O2 — Settings diagnostics:** add a real authenticated station/settings check with HTTP/ReCo feedback (`Gateway::init_form_fields()`).
- [x] **O3 — Explicit Station choice:** `Gateway::payment_fields()` requires a choice for multiple terminals without a default; `GatewayTest::test_payment_fields_requires_explicit_choice_with_multiple_stations_and_no_default` and `test_payment_fields_single_station_is_preselected` cover it.
- [ ] **O4 — Pending settings changes:** define/enforce credential and environment change restrictions and attempt credential binding (`Gateway`, `PaymentAttempt::record_new()`).
- [ ] **O5 — Authentication message:** surface the selected endpoint host/environment on auth failures (`HitClient::send()`).
- [ ] **O6 — Release artifact test:** install the built ZIP and exercise a mocked HIT exchange (`.github/workflows/release.yml`).
- [ ] **O7 — UAT guidance:** expose the documented simulation/hardware limitations in settings (`Gateway::init_form_fields()`).
- [ ] **O8 — Deadline reconciliation:** force a fresh final Status read at the deadline before any set-aside/cancel decision (`assets/js/payment.js`).
- [x] **O9 — ReCo coverage:** `HitPaymentService::start()` declines incomplete Purchase refusals; `apply()` surfaces Status errors without changing attempts. `HitPaymentServiceTest::test_start_po_reco_declines_with_code_in_message`, `test_poll_unknown_error_reco_returns_error_without_changing_attempt`, `test_poll_network_reco_stays_pending`, `test_poll_pc_status_reply_stays_pending` and `test_start_pj_purchase_reply_uses_grace` cover the error, network and PC/PJ paths.
- [x] **O10 — Approved-result retry guard:** `HitPaymentService::start()` blocks new Purchase while an approved result remains unreconciled; `HitPaymentServiceTest::test_start_refuses_new_purchase_when_current_attempt_approved_but_unpaid` covers it.
- [ ] **O11 — Fresh verification:** verify attempt metadata from the reloaded order inside the completion claim (`HitPaymentService::apply()`, `OrderCompletion::complete()`).
- [ ] **O12 — Sweep storage evidence:** use ID-only queries and test both real WooCommerce stores (`PaymentSweeper::find_orders()`, `PaymentSweeperTest`).
- [ ] **O13 — Cashier decline details:** expose RC, card type and available entry method alongside the display text (`HitResponse::to_array()`, `HitPaymentService::result()`).
- [ ] **O14 — Cancel outcome fixtures:** cover terminal-user-cancel and card-timeout final results (`HitPaymentServiceTest`).
- [ ] **O15 — Prompt ownership:** compare the submitted attempt/prompt against the current enabled prompt across tabs (`HitPaymentService::answer()`).
- [ ] **O16 — Method-switch recovery:** stop the loop and cancel/set aside the terminal attempt when changing methods (`assets/js/payment.js`).
- [ ] **O17 — Duplicate paid POST:** recover WooCommerce's early paid-order rejection to order-received (`Gateway::process_payment()`).
- [ ] **O18 — Receipt policy:** define stored-receipt escaping/masking and terminal/POS printing behaviour (`PaymentAttempt::store_receipt()`).
- [ ] **O19 — Integrated refunds:** persist refund attempts before dispatch, reconcile uncertain outcomes, enforce remaining refundable amounts and support required terminal prompts (`HitClient::refund()`, `Gateway`).
- [ ] **O20 — Fresh Purchase amount:** reload the order under the creation claim before deriving amount/currency (`HitPaymentService::start()`).
- [ ] **O21 — Saved-key handling:** add masked rendering and blank-preserving updates (`Gateway::init_form_fields()`).
- [ ] **O22 — FPRN health:** show verified-delivery health bound to environment, Station and credentials if stored (`FprnHandler::handle()`).
- [ ] **O23 — Production HTTPS:** require HTTPS for live notification URLs (`FprnHandler::url()`).
- [ ] **O24 — Availability validation:** hide/unavailable-state the gateway when required configuration is missing and explain the missing fields (`Gateway`, `HitPaymentService::start()`).
- [ ] **O25 — Full credential contract:** address raw-order-key support and nonce protection for capability-only AJAX (`AjaxHandler::can_access_order()`).
- [ ] **O26 — Panel lifecycle:** cover gateway-selection initialisation, delegated controls and old-controller disposal during checkout replacement (`assets/js/payment.js`).
- [ ] **O27 — Theme verification:** add a hostile-theme panel preview/check (`assets/css/payment.css`).
- [ ] **O28 — Classic checkout:** provide an order-pay redirect for unpaid checkout or explicitly restrict the gateway to POS (`Gateway::process_payment()`).
- [ ] **O29 — POS app verification:** exercise real iOS/Android WebView payment flows (`tests/js/controller.test.js` currently covers a fake DOM).
- [ ] **O30 — Callback refusal volume:** throttle repeated rejected-notification logs (`FprnHandler::handle()`).
- [ ] **O31 — Redaction keys:** review the substring-based sensitive-key policy against the exact-key recommendation (`Logger::is_sensitive_key()`).
- [ ] **O32 — Log format:** review multiline XML/non-string log output against the one-line diagnostic recommendation (`Logger::xml()`, `Logger::log()`).
- [ ] **O33 — Completion duration:** emit an explicit elapsed duration for the whole attempt (`HitPaymentService::apply()`).
- [ ] **O34 — Shared activity history:** return server attempt events, fold duplicates and reset panel logs per attempt (`assets/js/payment.js`, `HitPaymentService::result()`).
- [ ] **O35 — Notification hook registration:** restrict FPRN AJAX hook registration to AJAX requests (`FprnHandler::__construct()`).
