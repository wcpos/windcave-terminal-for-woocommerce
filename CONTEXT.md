# Windcave Terminal for WooCommerce

Windcave Terminal for WooCommerce connects a WooCommerce order-pay page to a Windcave Station through HIT. This glossary names the parts of the integration without implying it has been tested with a Windcave account or terminal.

## Language

**HIT**:
Windcave's Host Initiated Transaction interface. The plugin sends XML Purchase, Status and UI commands to the selected UAT or production HIT endpoint.
_Avoid_: browser payment API, terminal SDK

**Station**:
A Windcave-issued Station ID identifying one terminal for HIT requests. Cashiers choose from configured IDs unless selection is locked to the default.
_Avoid_: device, reader ID

**TxnRef**:
The plugin-generated reference for one attempted Purchase, recorded on the WooCommerce order before sending it to HIT and used for Status queries.
_Avoid_: transaction id, DpsTxnRef

**DpsTxnRef**:
A Windcave transaction reference read from the HIT result and used as the WooCommerce transaction ID when available. The parser's `Result/TR` fallback is inferred from a published example and unconfirmed with an account.
_Avoid_: TxnRef, order ID

**ReCo**:
A HIT response code in the XML reply; `PC` and `PJ` have specific handling in this plugin.
_Avoid_: card approval code, `Result/RC`

**`PC`**:
A ReCo indicating an existing transaction is still in progress on the Station. The plugin does not start the new attempt and asks the cashier to finish or cancel the earlier one on the terminal.
_Avoid_: card decline

**`PJ`**:
A ReCo indicating Windcave has no matching TxnRef. For the first 30 seconds the plugin keeps the attempt pending because Status may precede Purchase registration; afterward it marks the attempt declined.
_Avoid_: immediate card decline

**Complete**:
The HIT reply's `Complete` field. `1` means the transaction has a final result, not necessarily an approval; the plugin also checks `Result/AP`.
_Avoid_: WooCommerce order completed, approved

**TxnStatusId**:
A numeric status field from the HIT reply, passed to the page with polling results; the plugin does not use it alone to approve an order.
_Avoid_: approval flag

**DL1/DL2**:
HIT display lines mirrored on the order-pay page as terminal prompts.
_Avoid_: fixed cashier instructions

**B1/B2**:
HIT button slots with enabled state and label; the page mirrors them for YES/NO or CANCEL, and the service sends an enabled CANCEL response when offered.
_Avoid_: permanent YES and NO buttons

**UI request**:
A HIT command returning a YES, NO or CANCEL value for a named terminal button; answering a prompt is followed by a Status query.
_Avoid_: browser-only confirmation

**FPRN**:
Windcave Fail Proof Result Notification, an optional HTTP GET to a signed `UrlSuccess`/`UrlFail` URL. The receiver treats it as a hint and queries HIT Status for a TxnRef recorded on the order; query parameter names are unconfirmed.
_Avoid_: webhook, source of payment approval

**UAT**:
Windcave's testing environment selected in settings, using `https://uat.windcave.com/hit/pos.aspx`. Whether a UAT Station answers without hardware is unconfirmed.
_Avoid_: simulated terminal, certified production

**Attempt**:
One recorded order payment with its TxnRef, Station, amount, currency, environment and status; an order can have a history of attempts.
_Avoid_: order, terminal session

**Set aside (abandon)**:
Detaching a pending attempt from the active checkout without cancelling it at Windcave. It remains recorded for follow-up, and the cashier can start another payment.
_Avoid_: refund, terminal cancellation

**Sweep**:
The scheduled 10-minute follow-up that checks stale pending attempts and set-aside TxnRefs through HIT Status, including after the browser closes.
_Avoid_: automatic cancellation

## Avoid

- Use **TxnRef** for the plugin's per-attempt reference and **DpsTxnRef** for the reference returned by Windcave, rather than the ambiguous “transaction id”.
- Use **Station** for Windcave's terminal identifier, not “device” when referring to the HIT Station ID.
- Use **FPRN** for Windcave's GET notification, not “webhook”.
- Do not describe UAT as a simulated terminal or any payment path as certified before Windcave QA certification.
