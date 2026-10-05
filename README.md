# Windcave Terminal for WooCommerce

Take in-person card payments on a Windcave terminal using HIT from the WooCommerce POS order-pay page. The plugin sends a Purchase, follows terminal progress and reconciles the WooCommerce order from the HIT result.

## Status: pre-release, not certified

- Not yet run against a Windcave account. Every request and response shape comes from Windcave's published HIT documentation and is covered by unit tests against those examples.
- Not yet run on a physical terminal.
- Windcave QA certification is required before production use. All POS integrations must be officially certified by Windcave QA before the solution can be used in production.
- The `VendorId` is agreed with Windcave.

## Untested until hardware and a Windcave account are available

- Card present, PIN and contactless.
- The signature-verification prompt and its YES/NO answers.
- Real prompt wording and timing.
- Receipt text from a real terminal.
- The `DpsTxnRef` location in real responses (the parser falls back to `Result/TR`).
- FPRN query parameter names (the receiver currently accepts `txnRef` or `TxnRef`).
- Whether a UAT Station answers without a device; there is no documented simulated terminal.
- Tipping, surcharge and cash-out (not supported).
- Refunds (see below).

## How it works

On the order-pay page, the cashier:

1. Picks a Station and starts the payment (or uses the locked default Station).
2. The plugin records a unique `TxnRef` on the order before sending a HIT Purchase.
3. The page polls HIT Status while the customer uses the terminal. Terminal display lines and enabled buttons are mirrored on the page, including YES/NO and CANCEL prompts.
4. Cancel uses the terminal's enabled CANCEL button when offered. Otherwise the cashier can cancel on the terminal or set the payment aside for follow-up.
5. The order completes only after approval, a match between the requested `TxnRef` and an attempt recorded on this order, an amount match in cents, and an environment match. The order total and currency must also still match the attempt.
6. A non-empty receipt is stored in the order meta and a private order note.

If the Station reports an existing transaction (`PC`), the new attempt does not start: finish or cancel the earlier transaction on the terminal before trying again. Optional FPRN result notifications provide a backstop to browser polling: Windcave sends an HTTP GET to `UrlSuccess`/`UrlFail` at signature verification and result display, with up to six retries; the receiver checks the recorded `TxnRef` by querying HIT Status. A scheduled 10-minute sweep checks stale pending attempts and payments set aside by the cashier, including when the browser has closed. The FPRN query parameter names and real-world delivery remain unconfirmed.

## Setup

You need WordPress, WooCommerce, WooCommerce POS, PHP 7.4 or newer, and HIT credentials from Windcave. Windcave issues a HIT username, key and one Station ID per terminal. Request a development account through Windcave's [Integration Requirements form](https://www.windcave.com/merchant-attended-developer-hit). The plugin uses `https://uat.windcave.com/hit/pos.aspx` for UAT and `https://sec.windcave.com/hit/pos.aspx` for production; production use requires certification.

In **WooCommerce → Settings → Payments → Windcave Terminal**, set:

- **Enable/Disable** — Enables the gateway for online store checkout; it is not needed for WooCommerce POS.
- **Title** — Customer-facing payment method name.
- **Description** — Checkout description of the payment method.
- **Environment** — UAT (default) or production HIT endpoint; use credentials for the selected environment.
- **HIT username** — Username issued by Windcave for HIT.
- **HIT key** — Key issued by Windcave for HIT.
- **Station IDs** — Windcave-issued Station IDs, one per line, one for each terminal.
- **Default Station ID** — Preselected Station, also used when no Station is selected.
- **Lock terminal selection** — Always uses the default Station and prevents cashier changes when a default is set.
- **Vendor ID** — `VendorId` agreed with Windcave during certification; leave empty until issued.
- **POS name** — Name sent as `DeviceId` and `PosName` (defaults to `WCPOS`).
- **Result notifications (FPRN)** — Sends signed `UrlSuccess` and `UrlFail` URLs with the Purchase as a backup to polling; off by default.
- **Checkout logs** — Shows log tools on the order-pay panel; off by default.

Enable the gateway separately in **WooCommerce POS → Settings → Checkout** to use it in POS. This works without enabling it for online store checkout.

## Sending diagnostics

The **Log level** setting defaults to **Debug (everything, recommended while testing)**. Logs are available at **WooCommerce → Status → Logs**, source `windcave-terminal`.

If a payment fails, open **WooCommerce → Settings → Payments → Windcave Terminal** and click **Download support bundle**. The JSON file includes the environment, plugin settings with the HIT key masked (and its length recorded) and the HIT username masked after its first three characters, attempts from up to 20 recent orders without stored receipts, and the last 1000 lines from the two newest `windcave-terminal` log files. Log lines may include terminal receipt text with card numbers masked.

Download the bundle and send it with the order number to WCPOS support. The **View logs in WooCommerce → Status → Logs** link opens the logs; if WooCommerce uses the database log handler, export those logs there as well.

## Refunds

Not supported from WooCommerce in this version. Refund in the Windcave portal (Payline) or on the terminal, then record the refund in WooCommerce manually.

## Development

```sh
composer install
composer test
composer lint
npm test
```

HIT XML fixtures live in `tests/fixtures/hit/`. `status-approved.xml` and `status-in-progress.xml` preserve Windcave's published approved and in-progress Status examples; the other fixtures exercise declined, signature, CANCEL, `PC` and `PJ` cases based on the HIT documentation. These tests do not replace testing with a Windcave account and hardware.

## Releasing

The release workflow is manual (`workflow_dispatch`) until Windcave certifies the integration. It does not release on merge or push.

## Licence

GPL-3.0-or-later.
