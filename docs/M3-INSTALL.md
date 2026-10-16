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

Schema 3 adds a nullable acquisition-fragment key to `buys_vs_sells`; it retains
the eight existing tables. The existing verified pre-migration backup is created
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

The pinned CCXT version has **no Bitso Pro adapter** and no unified
`fetchWithdrawals`. Bitso therefore uses REST, with unified `fetchLedger`
transaction entries as its transfer-history source. It may supply less transfer
metadata than another exchange, so automatic matches can be unavailable. No
exchange-specific HTTP/WebSocket implementation is included. The example's
`marker` pagination is passed through CCXT's unified methods. `mode: all` is only
for an endpoint documented to return the full set (Bitso order trades).

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
