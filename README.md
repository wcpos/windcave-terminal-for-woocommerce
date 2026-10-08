# Windcave Terminal for WooCommerce 1.0.0

Windcave HIT payments on WooCommerce POS Pro's shared payments base. **Requires WooCommerce POS Pro 2.0 or newer**, WooCommerce and PHP 7.4+ with DOM/libxml. The WordPress dependency header names WooCommerce only; without compatible Pro the gateway is not registered and an administrator notice explains why.

## Pre-release — not certified

Mocks are not Windcave certification. No Windcave account or physical terminal has been tested. Production release remains manual and requires Windcave QA approval. UAT station availability without hardware, actual prompt timing, receipts, FPRN delivery and the parser's `Result/TR` fallback for DpsTxnRef remain unverified.

The `next` lane is 1.0; `main` remains the 0.x line for older POS installations. Do not upgrade an older POS installation to this release without Pro 2.0.

## Setup

In **WooCommerce → Settings → Payments → Windcave Terminal**, enter the HIT username and key, UAT/production environment, Station IDs (one per line), certified Vendor ID and POS name. Blank key submissions preserve the saved secret; the settings form never renders it. Windcave issues separate credentials for each environment.

Enable the gateway in **WooCommerce POS → Settings → Checkout**. Pro owns the allowed-reader list, default Station and selection lock. The extension no longer has separate default/lock Station settings or checkout log controls. Reconfigure those selections in Pro when upgrading.

## Taking a payment

The app-started server flow and Pro's order-pay panel use the same adapter and ledger row. On the POS order-pay panel, select a Station, start the payment and answer the terminal's enabled prompts (for example signature YES/NO). The native POS terminal view's prompt UI is a separate app rollout; this extension does not add app UI.

Pro owns polling, deadlines, locking, pending-balance reservation, completion, activity history and reconciliation after a browser closes. The adapter sends the row's amount, not the order total. When HIT reports the currency it charged, the adapter uses it; the sent currency is only a fallback when the reply omits currency. Free rejects a reported amount or currency mismatch.

Cancel requires the terminal's currently enabled **CANCEL** button. If none is offered, cancel on the terminal. Cancel acknowledgement is not a payment result: a completed sale can still win. There is no local “set aside and take another payment” escape.

A busy Station (`PC`) asks the cashier to finish or cancel its earlier transaction. A lost Purchase response retains the pending reservation. Replay queries Status with the same deterministic TxnRef before considering another Purchase. Existing actions keep their original HIT settings and environment when the gateway settings change.

## Result notifications (FPRN)

Enable FPRN to send these generated GET callback URLs with Purchase:

`https://your-store.example/wp-json/wcpos/v2/payments/webhook?provider=windcave&txnRef=<TxnRef>`

FPRN is **an unsigned hint**, not proof of payment. Unknown TxnRefs are rejected before any outbound HIT request. Known references trigger authoritative Status; query-supplied payment results are never trusted. Pro settles the resulting ledger patch. Keep the route publicly reachable.

## Refunds

WooCommerce refunds use HIT matched Refund: the original DpsTxnRef, a deterministic refund TxnRef and the requested amount. Historical webview sales use the order transaction reference and Pro's configured default Station. Configure that default before refunding historical sales.

Partial refunds send the requested partial amount, so the fixture declares `partial_refund` supported. This follows the amount-bearing matched-refund request in [Windcave's HIT guide, §5.4.1](https://www.windcave.com/Document/Windcave-HIT.pdf); live partial-refund acceptance is unverified. An indeterminate refund remains pending with its TxnRef: check the Windcave portal before any further refund. Manual capture, tips, surcharge, cash-out and provider expiry are unsupported.

## Diagnostics

The settings page shows configured/missing credentials and environment (not a remote credential validation), plus a link to WooCommerce logs, source `windcave-terminal`. HIT requests/responses pass through XML masking and Pro's redactor. Pro supplies the **support bundle row on this page**; the extension no longer has its own bundle download. Send that bundle and the order number to support.

## Upgrade

The upgrade routine adopts pending 0.x current attempts into Pro's ledger without another Purchase, preserving their amount, currency, Station and environment. Old meta remains inert and the old sweeper cron is cleared. Adoption failures are logged and leave the upgrade unfinished rather than marking it complete.

## Development

Use an installed sibling `../woocommerce-pos-pro` at `next` and this worktree's running wp-env. Always filter PHPUnit:

```sh
npx wp-env run --env-cwd='wp-content/plugins/95-wc-1.0' tests-cli -- vendor/bin/phpunit -c phpunit.xml.dist --filter 'Tests\\Conformance\\'
npx wp-env run --env-cwd='wp-content/plugins/95-wc-1.0' tests-cli -- vendor/bin/phpunit -c phpunit.xml.dist --filter 'Tests\\Includes\\'
```

The conformance fixture fakes only HIT HTTP transport using the XML examples in `tests/fixtures/hit`. Golden adapter-operation transcripts (including actual Purchase/recovery and UI details) live in `tests/includes/Conformance/transcripts`. Recording is explicit via `env WCPOS_RECORD_TRANSCRIPTS=1` inside the PHPUnit container; review the files and rerun without recording. CI compares, never records. A green transcript is not certification.

GPL-3.0-or-later.
