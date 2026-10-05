# Changelog

All notable changes to Windcave Terminal for WooCommerce will be documented in this file.

## 0.1.0 - Unreleased

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
