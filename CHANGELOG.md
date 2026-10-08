# Changelog

All notable changes to Windcave Terminal for WooCommerce will be documented in this file.

## 1.0.0 — Unreleased

### Breaking changes

- Requires WooCommerce POS Pro 2.0 for app and legacy order-pay payments. The 0.x line remains on `main`.
- Pro/Free now own the ledger, payment panel, prompts, locks, reconciliation, refunds and support bundle. Removed the extension AJAX/token/lock/sweeper, set-aside flow, order-pay assets and private logger/bundle.
- Reader allowlist/default/lock settings move to Pro. Removed `default_station`, `lock_station`, `show_logs` and `log_level` settings.
- FPRN now targets Pro's GET webhook route. Unknown references are refused before HIT traffic; unsigned notifications only trigger authoritative Status.

### Added

- HIT provider adapter with deterministic TxnRefs, Status-before-replay, action-specific settings, stale prompt protection, enabled-button cancellation, masked receipts and matched full/partial refunds.
- Pending 0.x current-attempt adoption; old metadata retained and old cron cleared.
- Real WordPress adapter/gateway/adoption tests, HTTP-backed shared conformance fixture and pinned transcripts including the first HIT prompt exchange.
- CI against a sibling Pro `next` checkout using the wcpos-mini GitHub App token.

### Verification status

- Hardware, UAT and certification remain unverified. Release remains manual.
- Conformance records adapter entries and Status recovery; reply currency is verified when present, and outcome-aware FPRN IDs distinguish conflicting finals from identical replays.

## 0.1.0 - 2026-10-05

### Added

- Gateway settings for UAT or production HIT credentials, Station IDs, the default terminal and checkout options.
- A HIT XML client for Purchase, Status and terminal button requests.
- A cashier payment flow that polls terminal status and mirrors prompts and YES/NO answers on the order-pay page.
- Handling for `PC` when a terminal is still finishing an earlier transaction, so a new payment is not started.
- Approval verification against the recorded attempt, amount, environment, order total and currency, with single order completion.
- Terminal receipts stored on the order and in a private order note.
- Optional FPRN result notifications as a backstop to polling.
- A 10-minute sweep for stale and set-aside payments.
- An order-pay panel for Station selection, payment progress, cancellation and logs.
- A Log level setting (off, errors only or debug; debug by default while testing). Every Windcave request and response, terminal prompt, cashier answer and status change is logged to WooCommerce → Status → Logs under `windcave-terminal`, with the HIT key, card numbers except the last four digits and cardholder names masked.
- A "Download support bundle" button on the gateway settings page with the environment, settings with the key masked, recent payment attempts without receipts and recent log lines. Send it with the order number to WCPOS support.
- Safeguards that keep UAT payments from being checked against production (and vice versa), prevent a second Purchase while an approved payment has not been applied to the order, show Windcave errors such as an invalid Station ID at once instead of waiting, require cashiers to choose a terminal when several exist and none is the default, and require the PHP DOM extension for activation.
- `docs/LESSONS.md` with lessons from the other terminal extensions, checked against this plugin.
