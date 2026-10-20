# M4 — automatic Client setup, paper execution and replay

This incremental follow-up targets the Client with the original M4 patch and
`trademinator-client-m4-unified-config.patch` already applied. It also applies
on top of Client commit `965ae393c024de5d4708021743a560898caf1b0c` with those
unified daemon/configuration changes installed locally.
Use it instead of `trademinator-client-auto-setup.patch` on this baseline;
there is no need to reverse the earlier unified-config patch.
The already-applied M4 Server API is sufficient; no new Server patch is needed.

```bash
git apply --check /path/to/trademinator-client-auto-setup-incremental.patch
git apply /path/to/trademinator-client-auto-setup-incremental.patch
composer dump-autoload
php -d memory_limit=512M tests/m4.php
php -d memory_limit=512M tests/m4-config.php
php -d memory_limit=512M tests/m4-discovery.php
```

## Credentials are the required input

`config.example.json` is the canonical minimal template:

```json
{
    "server_api_key_env": "TRADEMINATOR_API_KEY",
    "exchange": {
        "accounts": [
            {
                "exchange": "bitso",
                "api_key_env": "BITSO_API_KEY",
                "secret_env": "BITSO_API_SECRET"
            }
        ]
    },
    "paper_trading": { "enabled": true }
}
```

Copy it to `config.local.json`. Set the values of those environment variables to
your keys; the JSON contains references, not secret values. Existing private
`server_api_key_file`, `api_key_file` and `secret_file` references also work.
The standard Server URL is automatic. Keep an existing custom `server_url` and
`data_dir` override when migrating. The default data directory remains the same
per-user Client directory used by M3. Bitso is the template's credential provider;
for other exchanges, the credential entry uses that exchange's CCXT name.

Run the normal daemon, with no separate paper-create step:

```bash
php -d memory_limit=512M bin/client.php run --config=config.local.json
php bin/client.php status --config=config.local.json
php -d memory_limit=512M bin/client.php paper-report --config=config.local.json
php bin/client.php stop --config=config.local.json
```

The Server API key must belong to your account. Your subscribed/active markets
remain managed on the Server. Setting `exchange.accounts` to `[]` runs paper
trading using public exchange data and only the Server key. Setting
`paper_trading.enabled` to `false` keeps paper trading off.

## What the client determines

| Item | Automatic source |
| --- | --- |
| Local exchange account UUID | Reuse saved credential binding or sole existing ledger; otherwise create a local account |
| Subscriptions and exact pairs | Authenticated Server active-subscription catalog |
| Candle period | Server-selected period for each subscription |
| Current model UUID | Server's unique current production model for that exchange, exact pair and period |
| Risk factor | Server questionnaire/default risk endpoint |
| Starting paper funds | Server's `paper_initial_quote`; one shared budget per quote currency per exchange |
| Reporting currency | Most frequent quote in that paper account, with deterministic alphabetical ties |
| Fees and fee currency | Public CCXT market metadata, including received/spent-asset fee rules |
| Price/amount precision and available limits | CCXT market metadata |
| Paper run ID | Stable hash of exchange, subscription identities and selected periods |
| Remaining simulator parameters | Two Order Rule, three evaluations per candle, 250 ms submit/cancel delays, 30 s stale limit, 1% volume participation, 500 warmup candles |

When different markets specify different starting amounts for the same quote
currency, the shared paper account uses the largest amount once. It never adds a
new budget for every pair. Starting crypto inventory is zero unless that currency
also funds another subscribed pair; no acquisition cost is fabricated. Public fee
metadata represents the published tier, not a claim about account-specific
volume discounts or rebates. Unsupported precision, fee rules or missing model
metadata produce a visible setup warning rather than guessed exchange rules.

Private exchange history defaults to the beginning (`history_since_ms: 0`), with
existing exchange-specific pagination. Registration does not approve checkpoints,
resolve discrepancies, manufacture historical events, or place real orders.
Existing explicit account IDs remain supported. If several legacy wallets exist
for one exchange without a saved credential binding, preserve their existing
account configuration; the client cannot safely guess which ledger owns a key.

## Resume and discovery lifecycle

Automatic discovery runs in a separate process so local control remains responsive.
The daemon refreshes subscription discovery every minute, including retries for
unavailable intelligence. Per-exchange workers drive the paper engine. `status`
shows their run IDs and each market's state under `ledger.paper_trading`.
`paper-report` returns all current automatic runs without requiring their IDs.

The same subscription/period set resumes the same saved run, including balances,
fees, risk settings and resolved model IDs. Model/rule changes on the Server do
not reset that run or silently change its experiment. Changing the active market
set or selected periods creates a distinct run with an explicit creation event;
old runs remain available using `paper-report --run=ID`. No real ledger balances
are used as paper starting capital. Storage is `<data_dir>/paper/paper.sqlite`.

Stop closes all paper workers. Loss of the supervising daemon's heartbeat also
stops paper writes. A live-session lock prevents concurrent workers advancing one
run. Reload still handles ordinary logging settings; switching paper setup or
rotating its Server credentials requires a restart.

## Optional custom research configuration

The complete M3 and M4 milestone templates and explicit options remain available
for deliberate parameter experiments or cross-exchange replay. Custom paper
settings can be placed under `paper_trading` with a `run_id` and `markets`.
`intelligence.subscription` and `intelligence.model_id` may be omitted (or `auto`)
for automatic lookup; resolved IDs are persisted separately with the run. The
explicit `--paper-config` form remains backward compatible. Changed parameters
need a new custom run ID. Examples below using `trial-01` refer to such a custom
run, not an ID needed for ordinary automatic startup.

## Models and current intelligence

```bash
php -d memory_limit=512M bin/client.php paper-models \
  --config=config.local.json --subscription=SUBSCRIPTION_UUID \
  --model-exchange=bitso --period=15m
```

For an explicit research override, select and pin a `model_id`. Ordinary automatic startup performs this discovery for you. `family=production` executes the existing production
Outcome/Action KNN, human fusion and decision matrix. `family=shadow` executes a
published research KNN, tree, forest or neural network. The model's pair and period
must match the subscription; its training exchange can differ from the data
source. A shadow Outcome-only model returns HOLD with
`outcome_only_requires_action`; it is not silently interpreted as an Action model.

Historical mode requires the model to have been published and trained before the
replay decision. Current mode uses the explicitly pinned model on the supplied
candles and is always labeled **not out-of-sample**. The response records model,
artifact and OHLCV hashes, training/publication times and feature version. All
features are rebuilt causally from supplied closed OHLCV. Missing warmup features
produce abstention. Technical models requiring 30-day returns need that much
contiguous input; `--warmup` can be raised up to 50000 candles. Unsupported lead-lag
context abstains; it is never silently substituted from another market. None of
these requests publish production signals or overwrite market features.

## Live paper

With automatic setup enabled, the ordinary command runs live paper trading:

```bash
php -d memory_limit=512M bin/client.php run \
  --config=config.local.json --seconds=86400
```

Public order books use CCXT WebSocket support when available, with REST fallback;
public trades and OHLCV use REST. Exchange rate limiting stays enabled. Output uses
the existing colored time/severity/event console. One configured source exchange
supplies the market stream; the execution exchange supplies the simulation rules
captured in the run. Exact base/quote identities are required (USD is not USDT).
The configured rules are deliberately frozen for reproducible comparisons.

At each evaluation, old residual orders are cancelled before intelligence refresh.
A replacement waits for cancellation acknowledgement. The order expiry timer also
requests cancellation at the next evaluation deadline if no new signal arrives. A failed feed or inference
pauses that market; an unproven interval is recorded as unverified. Historical
REST trades received late cannot fill an order accepted after their source time.
REST does not prove complete event coverage; this is a visible-depth simulation,
not exchange matching-engine reconstruction.

The 24-hour acceptance run must be performed on your machine with the patched
Server and selected models. `live_observations`, gaps and `live_24h_observed` are
reported. This implementation's automated tests are not a completed live soak.
There is no profitability threshold for M4 acceptance.

## Historical OHLCV replay

```bash
php -d memory_limit=512M bin/client.php paper-history \
  --config=config.local.json --subscription=SUBSCRIPTION_UUID \
  --source-exchange=binance --period=15m \
  --from-ms=START_MS --to-ms=END_MS --output=history.json
```

The Server supplies existing collected/archive history; it does not start an
exchange backfill through this endpoint. `source_exchange` in the paper config
must match the history export. Set `execution_exchange` independently, e.g. Bitso.

```bash
php -d memory_limit=512M bin/client.php paper-backtest \
  --config=config.local.json --run=trial-01 --input=history.json \
  --symbol=ATOM/USD --intelligence=current --subscription=SUBSCRIPTION_UUID \
  --model-id=MODEL_UUID --family=production --model-exchange=bitso \
  --period=15m --path=low_first --warmup=500
```

Use `--intelligence=historical` for a pinned model that existed then. Use
`--intelligence=recorded` for the recorded signals exported with the history;
missing/stale signals produce HOLD. Recorded signals cannot appear before their
recording time. Model publication times are not confused with candle timestamps.

OHLC-only execution is explicitly approximate: four deterministic points per
candle, configurable low-first/high-first path, one tick half-spread and a bounded
volume participation budget. Decisions see the candle only after its close;
future extremes do not enter inference. Gaps reset warmup and are reported.
These assumptions are retained in the report. This is not a historical order book.

For higher fidelity, use JSONL `book`/`trade` events from a complete source capture:

```bash
php -d memory_limit=512M bin/client.php paper-import \
  --config=config.local.json --run=trial-01 --input=events.jsonl
php -d memory_limit=512M bin/client.php paper-export \
  --config=config.local.json --run=trial-01 --output=captured.jsonl
php -d memory_limit=512M bin/client.php paper-replay \
  --config=config.local.json --run=trial-01 --new-run=exact-replay
```

Exact replay checks the complete state hash. Order decisions and inference
provenance are included in the tape; replay makes no inference/network requests.
The immutable run configuration is also needed when importing into a new store.
`paper-replay` carries it automatically within the same store.

## Execution and sizing

* Policy defaults to Main plus same-side Backup. `main_only` is an alternate policy;
  the exchange simulator itself has no hard two-order restriction.
* Main uses the current ask for BUY / bid for SELL, rounded to the tick. Backup is
  `Main book price + signed close-to-close price speed × seconds to next evaluation`,
  rounded down for BUY / up for SELL. Omit a Backup on the wrong side or without funds.
* BUY uses `min(Q3bar, distributed free quote × R / fee-inclusive unit price)`.
  Shared quote capital is divided among configured markets using that quote.
  An evaluation may supply `q3bar` in base units. Without it, the explicit baseline
  predictor repeats the latest three completed bar volumes and multiplies their
  sum by `volume_participation`. This is a documented persistence forecast, not a
  recovered proprietary volume predictor. No candles means no inferred BUY size.
* Same-side averaging compares against the quantity-weighted campaign price.
  Backup accounts for Main's reservations and projected execution. SELL reserves
  separate eligible BUY-lot quantities and requires strictly positive net proceeds
  over acquisition cost plus configured minimum profit. BUY costs include fees.
* Exchange minimums and amount/tick steps apply. An exchange minimum never forces
  an order beyond funding, risk, liquidity or eligible-inventory limits.
* Each new book/trade supplies a shared quantity budget. Repeated identical books
  cannot replenish consumed depth. Resting fills subtract visible queue ahead;
  immediate/uncertain/OHLC fills pay taker fees. Fees are recorded per fill in base
  or quote. Negative rebates and third-asset fee discounts are not modeled.
* Main fully fills before Backup gets any fill. Unfilled amounts remain open.
  Reservations include worst-case fees during submission and cancellation delays.
  Cancellation-pending orders can still fill before acknowledgement.

## Events, recovery and FX

Each JSONL event has a stable `id`, integer `time_ms`, `type`, and (except `fx` or
`clock`) an exact `symbol`. Events are chronological; identical retries are ignored,
while an ID reused with different content is rejected atomically.

```json
{"id":"b1","type":"book","time_ms":1000,"symbol":"BTC/USD","bids":[["99","2"]],"asks":[["100","2"]]}
{"id":"s1","type":"evaluate","time_ms":1000,"symbol":"BTC/USD","action":"buy","q3bar":"1","expires_at_ms":21000}
{"id":"t1","type":"trade","time_ms":1500,"symbol":"BTC/USD","price":"99","quantity":"0.5","side":"sell"}
{"id":"fx1","type":"fx","time_ms":1600,"currency":"USD","rate":"1.35","observed_at_ms":1600}
```

Trade `side` is the aggressor side. `candle` carries `ohlcv` as
`[open_ms,open,high,low,close,volume]` and must be closed. `gap` includes `from_ms`
and `reason`; `resume` requires a new fresh book before execution restarts. `clock`
advances submission/cancellation timers. `evaluate` accepts BUY/SELL/HOLD (or hodl)
and optional intelligence provenance. Money and quantities are decimal strings.

The `fx` rate converts one unit of its currency to the run's reporting currency.
Original amounts and conversion rate/time remain attached to each fill. Stale or
missing FX is not silently filled; consolidated totals become null and coverage
shows the omission. Live mode refreshes direct or inverse public FX pairs on the execution/source
exchange when available. Otherwise supply FX events from a trusted
historical/current feed when using a different reporting currency. Starting funds in multiple currencies need
complete original cost-basis information for consolidated capital/return metrics.

Recover a provably complete interval into a separate run:

```bash
php -d memory_limit=512M bin/client.php paper-recover \
  --config=config.local.json --run=trial-01 --new-run=recovered-01 \
  --input=complete-capture.json
```

The JSON capture contains `symbol`, `from_ms`, `to_ms`, `complete: true`, a nonempty
`provenance`, integer `first_sequence`/`last_sequence`, and `events` with contiguous
`source_sequence` numbers. Coverage must exactly match a recorded gap. Only
book/trade/candle source events are accepted; recorded user/evaluation actions are
preserved. A capture's completeness claim must come from the source, not from
assuming OHLCV reconstructs missing trades. The original run remains unverified;
the rebuilt run retains capture hash and provenance.

## Comparisons and reports

Create a JSON file containing 1–32 named override sets, for example:

```json
{
  "fast": {"submission_delay_ms": 100},
  "slow": {"submission_delay_ms": 1000},
  "main_only": {"policy": "main_only"}
}
```

```bash
php -d memory_limit=512M bin/client.php paper-sweep \
  --config=config.local.json --run=trial-01 --sets=sets.json --prefix=compare
php -d memory_limit=512M bin/client.php paper-report \
  --config=config.local.json --run=trial-01 --output=report.json
```

Each set receives the same initial funds and input tape in a separate run. Sweeps
cannot change the source/execution exchange, account, reporting currency, balances
or opening inventory. These are execution-policy comparisons using recorded
intelligence; use separate backtests to compare model choices.

Reports include realized net earnings, original-currency totals, actual fees,
capital at cost, realized return/drawdown curve, order/fill/partial/cancellation
counts, time-to-fill, Main/Backup attribution, missing-data and valuation coverage.
No unrealized profit is included. Earnings samples are realized SELL fills; order
size samples are cumulative filled notional per order in reporting currency.
Histograms and descriptive fitted normal curves include count, mean, median and
sample standard deviation. Empty/constant samples have no misleading normal fit.

## API contract

All routes use the existing Bearer Client API authentication and subscription
ownership checks. Another user's subscription is returned as 404.

| Method | Route suffix under `/api/v1/client/markets/{subscription}` | Purpose |
| --- | --- | --- |
| GET | `/replay/history` | Bounded collected OHLCV and recorded-signal export |
| GET | `/replay/models` | Up to 100 production and 100 shadow model records |
| POST | `/replay/infer` | Actual inference on supplied closed OHLCV |

History parameters: `source_exchange`, `period`, `from_ms`, `to_ms`, optional
`limit` (1–500). Follow `next_from_ms`; a null cursor ends the result. Models:
`model_exchange`, `period`. Inference: `source_exchange`, `model_exchange`,
`period`, `model_id`, `family` (`production|shadow`), `intelligence`
(`historical|current`), `decision_at_ms`, `candles`. The last candle must close
exactly at the decision; timestamps must be contiguous and ascending at the declared interval.
The maximum input is 50000 candles / 16 MiB. Variable calendar-month/year periods
are excluded. Inference has an additional 30 requests/minute throttle. Missing
features return abstention; invalid input/model time returns 422; artifact
integrity failures fail the request. Client failures never fabricate a BUY/SELL.

## Validation supplied with this patch

* 53 focused Client checks: funding/reservation priority, Main/Backup sequencing,
  shared event volume, partial fills, base/quote fees, profitable lot matching,
  cancellation races/deadlines, gap handling/recovery, FX coverage, distributions,
  atomic idempotency, restart, exact replay, isolated comparisons and CLI routing.
* A short injected live-feed/inference fixture verifies the live driver and its
  replay without making external exchange requests. It is not the live soak.
* 50 Server tests / 1004 assertions across the new replay API and existing
  IntelligenceWorkflow / M2ClientSignalContract suites. Real KNN, tree, forest,
  neural-network and production-model paths are exercised.
* PHP syntax and Composer validation pass; Server PHP is formatted with Pint.
* The existing M1 Unix IPC daemon test cannot run in the build environment because
  native Unix sockets are prohibited there. This is an environment limitation;
  run `composer test:m1` locally before adoption.
* The real 24-hour paper acceptance and native TypePHP/AOT build remain to run on
  the target machine. M4's new dynamic CCXT/SQLite/CLI code is validated as PHP;
  this patch does not claim a new native compilation result.


## Validation limits

Automated fixtures exercise discovery, account reuse, multi-exchange workers,
subscription/model matching, resume behavior, public market rule normalization
and fee accounting. They make no external exchange requests. A real 24-hour
paper session, target-machine native IPC and native/AOT packaging remain separate
validation steps.
