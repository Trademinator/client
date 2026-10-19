# M4 — paper execution and replay

Client base: `f1d0c3ac57c9ecc52740da69d588942350bd4f25`.
Server base: `ce5668d1902d7a1d3dc1758f22f22888fb891fb3`.

Apply the corresponding patch from each repository root:

```bash
git apply --check /path/to/trademinator-client-m4.patch
git apply /path/to/trademinator-client-m4.patch
composer dump-autoload
php -d memory_limit=512M tests/m4.php
php -d memory_limit=512M bin/client.php paper-help
```

On the Server:

```bash
git apply --check /path/to/trademinator-server-m4.patch
git apply /path/to/trademinator-server-m4.patch
php -d memory_limit=512M artisan optimize:clear
php -d memory_limit=512M artisan test --compact tests/Feature/M4ReplayTest.php
```

No new Server migration or dependency is required. The existing tournament/shadow
migrations must already have run. Use the existing Client API key and enable the
Client API as usual. Also run the complete Server suite before deploying.

## Create, resume and reset

Copy `config.m4.example.json` to a local paper configuration. Its fee rates,
precision, minimums and balances are examples, not a claim about current exchange
rules. Set these to the rules of the **simulated execution exchange**. Public live
feeds check that the exact spot pair exists on both exchanges. No exchange private
keys are read by M4 and no exchange order or withdrawal API is called.

```bash
php -d memory_limit=512M bin/client.php paper-create \
  --config=config.m3.local.json --paper-config=config.m4.local.json --run=trial-01
php -d memory_limit=512M bin/client.php paper-report \
  --config=config.m3.local.json --run=trial-01
```

An account may share its balances across multiple configured markets. The run
stores its starting funds, opening lots and all simulation parameters. Starting
base inventory needs explicit opening lots with fee-inclusive unit costs before
it can be sold. Unattributed inventory remains in the balance but is ineligible
for profitable lot matching. Real M1/M3 accounting and Hard T/H points remain
separate. Storage is `<data_dir>/paper/paper.sqlite`, using SQLite WAL and FULL
synchronization; each event and its resulting state commit atomically.

Restart `paper-live` with the same run to resume. `paper-create` refuses an existing
ID. `paper-reset --run=trial-01 --new-run=trial-02` creates a new run with the same
initial configuration; it preserves the original run.

## Models and current intelligence

```bash
php -d memory_limit=512M bin/client.php paper-models \
  --config=config.m3.local.json --subscription=SUBSCRIPTION_UUID \
  --model-exchange=bitso --period=15m
```

Select and pin a `model_id`. `family=production` executes the existing production
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

Add this `intelligence` object inside each market in the paper configuration,
using a model returned by `paper-models`, **before creating the run**:

```json
{
  "subscription": "SUBSCRIPTION_UUID",
  "period": "15m",
  "family": "production",
  "model_id": "MODEL_UUID",
  "model_exchange": "bitso",
  "source": "current",
  "warmup": 500
}
```

```bash
php -d memory_limit=512M bin/client.php paper-live \
  --config=config.m3.local.json --run=trial-01 --seconds=86400
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
  --config=config.m3.local.json --subscription=SUBSCRIPTION_UUID \
  --source-exchange=binance --period=15m \
  --from-ms=START_MS --to-ms=END_MS --output=history.json
```

The Server supplies existing collected/archive history; it does not start an
exchange backfill through this endpoint. `source_exchange` in the paper config
must match the history export. Set `execution_exchange` independently, e.g. Bitso.

```bash
php -d memory_limit=512M bin/client.php paper-backtest \
  --config=config.m3.local.json --run=trial-01 --input=history.json \
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
  --config=config.m3.local.json --run=trial-01 --input=events.jsonl
php -d memory_limit=512M bin/client.php paper-export \
  --config=config.m3.local.json --run=trial-01 --output=captured.jsonl
php -d memory_limit=512M bin/client.php paper-replay \
  --config=config.m3.local.json --run=trial-01 --new-run=exact-replay
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
  --config=config.m3.local.json --run=trial-01 --new-run=recovered-01 \
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
  --config=config.m3.local.json --run=trial-01 --sets=sets.json --prefix=compare
php -d memory_limit=512M bin/client.php paper-report \
  --config=config.m3.local.json --run=trial-01 --output=report.json
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
