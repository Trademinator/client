# M3: exchange connectivity and authoritative state

This release builds on GitHub `Trademinator/client` at
`f37294bfd53c490f6d0f9f35550995cff38e5e10` (M2 plus console events).
It reads exchanges through the locked CCXT 4.5.57 dependency. No Server API change
is required. There is **no live order submission, cancellation, withdrawal or
wallet-transfer capability** in the M3 adapter. Execution remains M5.

## Upgrade

Stop the existing daemon, keep your private configuration and data directory, then:

```bash
git apply --check /path/to/trademinator-client-m3-f37294b.patch
git apply /path/to/trademinator-client-m3-f37294b.patch
composer install
php -d memory_limit=512M tools/lint.php
php -d memory_limit=512M tests/m1.php
php -d memory_limit=512M tests/m2.php
composer test:m3
```

Schema 4 retains the eight existing tables and the schema-3 acquisition-fragment
key in `buys_vs_sells`. It extends `orders` to preserve verified historical
executions whose original order details are unavailable. The existing verified pre-migration backup is created
before changing an existing database. Quantities remain decimal strings. No
runtime, settings, or reconciliation tables are added.

## Connect an account

Use your existing M2 local account UUID (`accounts`), or start the daemon with
its existing M2 configuration and run:

```bash
php bin/client.php account-add --exchange=bitso --label=Bitso --config=config.m2.local.json
php bin/client.php accounts --config=config.m2.local.json
php bin/client.php stop --config=config.m2.local.json
```

Copy `config.m3.example.json` to `config.m3.local.json`. Replace the account UUID
and retain your existing Server settings. Set `BITSO_API_KEY`, `BITSO_API_SECRET`,
and `TRADEMINATOR_API_KEY` in your service environment. Private `api_key_file`,
`secret_file`, `password_file`, and `uid_file` references are also supported; Unix
files must be mode 0600. Credentials stay local and are excluded from status,
financial evidence, and event output. Use exchange read permissions for this
milestone. Unsupported account/permission configurations are `AUTH_BLOCKED`.

`history_since_ms` is explicit. Zero requests all accessible history; an older
Hard H0 raises the lower bound automatically. Do not move it forward to conceal
missing history. Existing wallet money is never manufactured from a balance
response. A truncated exchange history produces a discrepancy requiring
investigation and an explicitly approved adjustment if appropriate.

```bash
php -d memory_limit=512M bin/client.php run --config=config.m3.local.json
php bin/client.php exchange-status --config=config.m3.local.json
php bin/client.php exchange-refresh --account=UUID --config=config.m3.local.json
```

M3 synchronizes every configured account, including stopped markets, and discovers
manual spot trading from exchange history. It does not activate Server
subscriptions for discovered markets. Multiple quote markets sharing a base need
an explicit `currency_markets` choice for transfers. M1's prohibition on spending
one market's base inventory as another market's quote is retained; such activity
fails closed and needs an account-inventory extension before it can be reconciled.
It is not silently assigned to a different market.

## Transport and history

CCXT Pro is preferred independently for balances, orders, fills and ticker updates
where supported. Private updates invalidate the prior reconciliation and trigger
REST confirmation. REST snapshots capture free/used/total balances, order states,
fill identities, fees, deposits, withdrawals and market limits. A wallet change
while collecting a snapshot, incomplete fills, a missing order, a saturated
history page or non-progressing cursor rejects that snapshot. Absence from
`fetchOpenOrders` never implies cancellation or fulfillment.

The pinned CCXT version has **no Bitso Pro adapter**. Bitso therefore uses REST.
Its advertised ledger endpoint returned HTTP 404 during live validation, and the
pinned adapter lacks unified `fetchWithdrawals`. The local `BitsoReadClient`
extension reads transfer history using CCXT's existing authenticated GET methods
for `/fundings` and `/withdrawals/`. CCXT still owns signing, HTTP, timeouts and
rate limits. No separate HTTP/WebSocket implementation or vendor edit is needed.
Other exchanges retain the generic ledger fallback when required. `mode: all` is
only for an endpoint documented to return the full set (Bitso order trades).

REST-only operation can satisfy exchange readiness. REST failure, stale state,
unknown cost, open/uncertain orders or inconsistent accounting prevents readiness.
Reconnect attempts continue with bounded exponential backoff. Authentication and
permission failures require corrected credentials and `reload`. Neither failure
changes an `ACTIVE` subscription into `STOPPED`.

Successful synchronization advances an in-memory overlap cursor; restart and
pending H1 verification read from the configured/Hard H0 history boundary.
Account and quote state is in memory and reconstructed after restart. The M3
`ready()` result is an exchange gate for later milestones, not permission from the
Server and not a live-trading switch.

## Synchronization diagnostics

At the default `log_level: info`, `EXCHANGE_SYNC_STARTED` announces each attempt.
While an attempt is pending, `EXCHANGE_SYNC_PROGRESS` reports progress at most once
every 30 seconds. `exchange-status` also exposes `sync.in_progress`, the current
stage, elapsed time, and CCXT call counts and metadata (operation, symbol,
page and whether a request is in flight). A request's elapsed time includes CCXT
rate-limit waiting. Request counts cover the snapshot, not later deposit pricing;
the stage changes to `deposit_pricing` while historical prices are estimated.
Counts track unified method calls, including cached calls, rather than individual
HTTP requests. These fields describe activity; they do not imply that
reconciliation succeeded.

`RECONCILING` with `at_ms: null` means no snapshot has yet been applied. An initial
scan visits all available spot markets to find manual trades and may take time.
An empty discrepancy list before that scan finishes is not evidence of matching
balances. A pending `exchange-refresh` does not start a parallel snapshot.

`EXCHANGE_SYNC_FAILED` and `last_error` retain a numeric `http_status` when the
locked CCXT HTTP handler provides one. For example, `fetch_ledger (HTTP 404)`
identifies a missing endpoint response, `HTTP 403` identifies denied HTTP access,
and `HTTP 503` identifies a server/gateway error. CCXT can classify all three as
`ExchangeNotAvailable`; that class alone does not establish an exchange outage.
Unknown formats stay generic. Signed URLs, request parameters, response bodies
and headers are excluded. Existing authentication handling and retry backoff are
unchanged; a failed ledger read still blocks reconciliation.

## Bitso transfer history

The documented [fundings](https://docs.bitso.com/bitso-payouts-funding/docs/fundings)
and [withdrawals](https://docs.bitso.com/bitso-payouts-funding/docs/list-your-withdrawals)
endpoints paginate backward using funding/withdrawal IDs, with a maximum of 100
rows per page. M3 sends the actual page limit, retains pending and failed rows for
pagination, and imports only confirmed transfers. The last server row supplies
the next marker, including when several rows share a timestamp. Repeated full
pages, conflicting duplicates, malformed responses and exhausted page budgets
reject the snapshot.

Existing local configurations work without editing: Bitso transfer reads default
to `backward_id` with `cursor_param: marker`, and the old `fetch_ledger` policy is
unused. Optional `fetch_deposits` and `fetch_withdrawals` policies must retain that
mode and cursor. Initial markers and currency/method/status/identifier filters
are rejected because they can omit financial activity. Each sync re-reads
transfers from the configured/Hard H0 boundary, independently of the advancing
trade cursor, so older pending transfers can be observed when they complete.

Bitso's withdrawal `amount` is the total balance debit, including the fee. M3
uses `details.amount_to_send` as net principal and `details.fee` as a separate
expense, verifying that they sum to the total. If one is missing, the other and
the documented total determine it. If both are missing, M3 performs one GET of
`/withdrawals/{wid}` and requires the same ID, currency, total amount, creation
timestamp and completed status. Its fee/net fields must still sum to the total.
Explicit zero fees are accepted. Supported invoice evidence and the documented
MXN SPEI response are handled below. Other missing or inconsistent evidence
blocks reconciliation; today's `/fees` values are not historical transaction
evidence. Deposit `amount` is the
amount received. Raw transfer `info` is not stored; CCXT's normalized transfer
ID, transaction hash, address and network remain available for matching.

If the individual lookup also lacks both fields, `EXCHANGE_SYNC_FAILED` and
`exchange-status` identify `withdrawal_created_at` (UTC), `withdrawal_currency`
and `withdrawal_total_debit`, along with a hashed `withdrawal_ref`. The warning
text includes the date and amount so the withdrawal can be found in Bitso's
console. Credentials, raw withdrawal IDs, addresses and raw response bodies are
excluded from diagnostics. `withdrawal_taxonomy` reports the actual public
method/network/protocol/integration codes from both responses, including methods
that the accounting adapter does not yet support. Codes are bounded to 64 ASCII
letters/digits/underscores/dots/dashes with an initial letter and normalized to
lowercase. Missing values report `missing`; nonstrings report `invalid_type`.
Other values, hash/invoice-like strings, and configured credentials report
`redacted`. Only these four fields and presence flags for a SPEI tracking
reference and receipt are exposed; bank details and receipt URLs are excluded.
Displaying an unknown code does not enable a fee rule. If synchronization is
still blocked, include the `withdrawal_taxonomy` block when reporting the error.

To inspect the first withdrawal page without waiting for the full market scan:

```bash
php tools/m3-bitso-withdrawal-check.php --config=config.m3.local.json --account=UUID
```

Select the configured Bitso account. This uses its existing private credential
references, loads public market metadata and checks only the newest 100
withdrawals, with individual lookups where necessary. It does not open or alter
the local database or submit exchange operations. Exit 1 and `needs_review`
identify an error; exit 0 and `page_checked` do not establish full reconciliation.
For a missing-fee record, compare its UTC date, currency and total debit with
Bitso's console and record the displayed fee and amount received.

Bitso's [MXN withdrawal API documentation](https://docs.bitso.com/bitso-payouts-funding/docs/mxn-withdrawals)
defines its SPEI methods as fee-free and shows completed responses without
`fee` or `amount_to_send`. After the individual lookup, M3 recognizes that format
only for MXN, `method: praxis`, `network: spei` and a `protocol` of `clabe`,
`debitcard` or `phonenum`. Supplied asset/integration values must be `mxn`/`praxis`,
and classification fields supplied by both responses must agree. It records the
full debit as principal with zero fee. Any explicit fee/net amount takes
precedence, including a nonzero fee.

This fallback is an interpretation of Bitso's documented method contract,
including historical records of that format; it is not an independently verified
historical receipt. Audit metadata records `source: bitso_spei_contract`,
`basis: exchange_documentation`, `rule: mxn_spei_praxis_v1`, the documentation URL
and protocol. The check tool lists these under `documented_spei_zero_fees`.
The rule does not apply to all MXN transactions, MXNB, Juno, unidentified methods
or conflicting metadata. Those still need transaction-specific evidence. No
account IDs, withdrawal references or customer fee overrides are configured.

For internal Bitso Transfer withdrawals, M3 also recognizes the `bt` tuple:
`method`, `network`, `protocol` and `integration` must all be `bt` in both the
completed list record and its matching individual lookup. This maps the observed
API format to the service; the public fee documentation describes the service,
not these four API enum values. Bitso describes
[Bitso Transfer](https://bitso.com/products/transfer) as fee-free for every
supported currency. This rule applies to that transfer format across accounts
and currencies, including historical records. It does not depend on a customer
ID, withdrawal reference, amount or date. Explicit fee/net amounts still take
precedence, including a nonzero fee.

The full debit becomes principal, with zero fee and network `BT`. Audit evidence
uses `source: bitso_transfer_contract`, `basis: exchange_documentation` and
`rule: bitso_transfer_bt_v1`, with the currency and four classification codes.
As with SPEI, this records a documented service rule, not an independently
verified historical receipt. The checker reports accepted records under
`documented_bitso_transfer_zero_fees`. Missing or conflicting classification
fields remain unresolved. This does not initiate a transfer, identify its
recipient, or establish a link to another managed wallet by itself.

Completed BTC Lightning withdrawals can establish zero charged expense using a
BOLT11 invoice attached to the authenticated withdrawal record. M3 verifies its
Bech32 checksum and secp256k1 signature and requires its amount to equal the total
balance debit exactly. The completed withdrawal record supplies the association
between its ID and its invoice. The result is the full principal and zero fee.
An amount difference remains unresolved; it is not automatically assigned as a
fee or an overpayment.

The supported invoice fields are `invoice`, `payment_request`, `paymentRequest`,
`bolt11`, `bolt_11`, `lightning_invoice` and `withdrawal_address`, either at the
record root or in `details`. This fallback supports amount-bearing Bitcoin
mainnet invoices with an explicit payee public key (`n` tag). Other invoice forms
still require explicit fee/net evidence. A network name or today's fee table
alone does not establish a fee for historical accounting.

The pinned CCXT adapter maps only `details.tx_hash` to `txid`. The invoice
fallback also checks explicit `tx_hash` and `payment_hash` fields at the record
root or inside `details`. Every supplied nonempty hash must be a 64-character
hexadecimal value equal to the verified invoice's payment hash. Malformed or
conflicting values block reconciliation even when another field matches.

When a separate hash is absent, null or empty, the adapter makes one lookup by
withdrawal ID. It verifies ID, completion status, currency, creation time and
total debit before combining the two responses. All attached invoices must
agree. If both responses omit the hash, the verified invoice supplies it
automatically. The invoice may appear in either response. This same rule applies
to every account and withdrawal, with no account-specific transaction mapping.
Explicit API fee/net amounts remain the preferred accounting evidence.

Hash conflicts have distinct reasons: `withdrawal_payment_hash_invalid` and
`withdrawal_payment_hash_mismatch`. `hash_fields` identifies each checked field
and its `missing`, `invalid_format`, `match` or `mismatch` state. `invoice_fields`
identifies invoice locations; `ccxt_txid_state` describes the original listed
CCXT value. `hash_ref` and `invoice_payment_hash_ref` are short SHA-256 references
for comparing evidence. Diagnostics exclude raw payment hashes and invoices.
`withdrawal_lookup_attempted` shows whether the individual request ran.

The normalized operation records `source=bitso_lightning_invoice`, the invoice
SHA-256 digest, payment hash, amount and zero fee. `payment_hash_source` is
`exchange` when a separate API hash corroborates the invoice, or `invoice` when
the matched withdrawal's signed invoice supplies the hash. The check tool reports
both the evidence source and hash source under `invoice_verified_zero_fees`.
Raw invoices, payment secrets and descriptions are not persisted, and invoice
values are removed from address fields. If both monetary fields and invoice are
absent, the withdrawal still needs additional evidence.

Remove `bitso_lightning_payment_hashes` from configurations that used the earlier
workaround. For upgrade compatibility it is accepted and discarded; its contents
have no effect on accounting. New records use automatic exchange evidence.
Previously saved operator audit records remain intact when current evidence is
updated; replay never duplicates the withdrawal's financial effect.

References: [Bitso fees](https://bitso.com/fees/transactions) and the
[BOLT11 specification](https://github.com/lightning/bolts/blob/master/11-payment-encoding.md).

The extension also guards CCXT 4.5.57's JSON detector against empty HTTP bodies,
preventing `Uninitialized string offset 0` warnings while retaining the original
HTTP exception and its safe diagnostic. It does not suppress other PHP warnings.
After applying this update, run `composer dump-autoload` and `composer test:m3`,
then restart the daemon. No database migration is added by this update.

## Bitso historical orders (schema 4)

Bitso's [order lookup](https://docs.bitso.com/bitso-api/docs/look-up-orders)
retains completed/cancelled order details for approximately one hour. M3 can now
import older execution evidence when lookup returns `0312` or `OrderNotFound`.
It verifies the full [order trade list](https://docs.bitso.com/bitso-api/docs/list-user-trades)
through CCXT, requires agreement with the account's observed trades, and confirms
that the order is absent from a freshly fetched complete open-order list.

These records use `status=history_only`, `order_type=UNKNOWN`, and NULL original
and remaining quantities. Actual executed quantities, fill IDs, acquisition
prices and fees are retained. The stored price is the executed weighted average;
metadata explicitly leaves the original order price and terminal status unknown.
No filled or cancelled status is inferred from missing order details. Later
authoritative details can replace unknown fields; known details are not erased.

The console emits `EXCHANGE_HISTORICAL_ORDERS_IMPORTED` after importing these
records. Locally open/uncertain orders, recent fills, mismatched evidence,
authorization errors and incomplete history remain blocked. A missing lookup
without independently verified executions is not sufficient for this fallback.

Stop the daemon/soak process before applying the historical-order update, run
`composer dump-autoload` and `composer test:m3`, then restart it. On first opening
the data directory, schema 4 creates the standard verified pre-migration backup,
rebuilds the orders table transactionally without changing existing rows/IDs, and
checks foreign keys. An interrupted/failed rebuild rolls back; permanent Hard H0
archives are not rewritten. Older Client code cannot open schema 4.

## Accounting and transfers

* Manual and Client-origin activity use the same inventory. Actual fills and
  their fee currencies are retained; a future submission intent must be persisted
  before sending an order. An unresolved intent survives restart and blocks its
  market until a unique matching `clientOrderId` confirms its outcome.
* Each acquisition fragment retains its original quantity, time, BUY price and
  cost. Observed SELLs are attributed to available acquisitions in deterministic
  LIFO order, including manual loss sales; this records an actual exchange action
  and does not authorize the Client to sell at a loss. M4/M5 still own profitable
  Comptability-based order selection and sizing.
* Unmatched crypto deposits obtain an **estimated** basis from historical OHLCV
  on the receiving CCXT exchange, configured alternative CCXT exchanges (including
  direct/inverse/bridge quote conversion), CoinGecko history, or the user. Source,
  quote and timestamp are audited. Today's ticker is never substituted for a
  historical acquisition price. CoinGecko IDs must be explicitly configured.
* Withdrawals consume newest BUY lots first. Principal and confirmed fees are
  separate debits, including fees in another inventory currency. No withdrawal is
  initiated by the Client.
* Configurable confidence uses hash/address/network/tag, quantity and timing.
  Unique high-confidence matches auto-link; ambiguous medium-confidence matches
  require confirmation. Low-confidence deposits retain their estimated basis.
  Hash/network/address conflicts do not auto-link.
* A linked transfer preserves acquisition fragments. Net received quantity moves
  to the destination; confirmed transfer fees remain expenses, not SELLs and not
  added to original BUY prices. Different valuation quotes require an attributed
  historical conversion, retaining original currency and unit cost. An unexplained
  principal difference blocks linking; no fee is guessed from the difference.
* Late matches rebuild cost attribution from the immutable archive chain plus
  live history. Audited cost overlays and carried lots are updated transactionally;
  original Hard H0 files and their hashes remain unchanged. A correction invalidates
  pending candidates and requires a fresh reconciliation.

```bash
php bin/client.php deposit-basis --operation=UUID --price=9.75 \
  --reason='Acquisition record reviewed' --config=config.m3.local.json
php bin/client.php transfer-link --withdrawal=UUID --deposit=UUID \
  --approve=yes --config=config.m3.local.json
```

Unexplained wallet discrepancies are displayed with expected/actual amounts. To
explicitly authorize an accounting adjustment, review `exchange-status`, then use
its current `adjustment_token`; the token covers the observed wallet and financial
revision. This records a local, audited adjustment and never moves exchange funds:

```bash
php bin/client.php exchange-adjust --account=UUID --currency=ATOM --price=9.75 \
  --reason='Opening inventory confirmed from exchange statement' \
  --approve=DISCREPANCY_TOKEN --config=config.m3.local.json
```

A positive crypto adjustment without a supplied cost remains cost-unknown and
blocks readiness. A stale token or unsupported inventory attribution is rejected.

## Candidate H, full H1, Hard H0

For connected M3 accounts, the old Hard T1 shortcut is disabled. Candidate H
requires reconciled wallets, current checkpoints, no open or uncertain orders,
no dust and known, attributed inventory. Capture publishes an immutable SQLite
archive and `hard.json` associated with a specific cutoff time. Later activity is
allowed to continue.

The next complete REST history verifies the frozen event identities and quantities,
then reconstructs its wallet and lots. A failed verification discards the candidate;
a successful H1 marks it verified. Neither automatically promotes it.

```bash
php bin/client.php hard-h-propose --account=UUID --config=config.m3.local.json
# Wait for a subsequent successful sync; inspect again for verification=verified.
php bin/client.php hard-h-promote --account=UUID --approve=CANDIDATE_TOKEN \
  --config=config.m3.local.json
```

Promotion removes only the captured prefix, carries its remaining lots, and
rebuilds attribution for all post-snapshot activity. Corrections to the captured
prefix invalidate approval. The existing manifest/activation-nonce recovery
protocol completes or rolls back interrupted promotion after restart. Permanent
archives remain under `hard-points/<account>/<unix-ms>/`.

## Acceptance on your machines

Local synthetic tests cannot establish the 24-hour exchange or native-toolchain
acceptance gates. See `M3-VALIDATION.md` for the checks performed for this patch.
For the read-only Bitso ATOM/USD soak, stop the normal daemon first; use the same
configured local accounts and preferably an isolated test data directory:

```bash
php -d memory_limit=512M tools/m3-soak.php --config=config.m3.local.json --seconds=86400
```

The tool writes sanitized event evidence and a summary in the chosen data directory.
Test a network interruption and restart separately; verify that reconciliation
recovers, no unexplained discrepancy remains, and no orders/withdrawals were sent.
It does not run the M2 subscription poller. Normal daemon integration should also
be checked using `bin/client.php run`, `status`, `reload`, and `stop`.

`project-m1.yml` now embeds `m3` along with the existing core, M2 and Composer
runtime. Rebuild and exercise this entry point on Linux and Windows with your
TypePHP toolchain; the legacy `project-client-aot.yml` still targets M0 and is not
M3 acceptance. Existing compiled executables do not acquire M3 from a source patch.
