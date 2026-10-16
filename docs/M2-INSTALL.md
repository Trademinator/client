# M2 installation and validation

For Client 2.1.0-rc1, also follow [console event installation](M2-CONSOLE.md).
The `run` command now requires `composer install` and produces structured
operational output. Earlier M2 validation counts below describe the API release.

M2 adds authenticated Server reads, explicit market selection, persistent market
state and candle-aligned scheduling. It does not place orders. Exchange
reconciliation is M3; private decision logic and sizing remain later milestones.

## Patch bases

- **Client:** the delivered `trademinator-client-M1-1.0.0-rc1-full.tar.gz`, SHA-256
  `8977c1d454b17c839f3fe7bca0a266ae30a7cfea9043f17a8343429811ffe35e`.
  This is the M1 release that passed your 10,000-order test. GitHub main still
  contained M0 at `b30777977dd548229f67188d414a28e04bd2e89a` when checked.
  **Do not apply the Client patch directly to that M0 checkout.**
- **Server:** the updated `Trademinator/server` commit
  `164a3298653b4c31ecf44fcfbfae63108c69d614` ("we dont need private information").
  A later revision must retain the same `market.signal` contract.

This is **M2 2.0.0-rc2**, replacing the first M2 patch. The Client reads the
existing authenticated `GET /api/v1/client/markets/{subscription}` response at
`market.signal`. It uses `is_fresh`, `is_current_model`, `expires_at_ms`, the
signal period and timestamps, plus the outer subscription identity and active
flag. It does not use the earlier proposed `market.intelligence` wrapper.

Only three body-free GET requests are used: the subscription list, market
signal detail, and risk factor R. Balances, positions, orders, trading history,
and exchange credentials remain local. No `/decision`, settings, reports, or
paper-trading request is sent. Signal validity is not permission to execute a
trade; M2 creates only RAM-resident candidates for later Client evaluation.

## Optional Server regression patch

The updated Server already contains the runtime API needed by M2. The rediffed
Server patch adds only `tests/Feature/M2ClientSignalContractTest.php`; applying
it is optional for operation. It adds no route, controller, response wrapper,
service, database migration, or Server-side sizing.

From that updated Server checkout, with downloads in `/home/dieu/tmp`:

```bash
git status --short
git apply --check /home/dieu/tmp/trademinator-server-M2.patch
git apply /home/dieu/tmp/trademinator-server-M2.patch
php -d memory_limit=512M artisan test --compact \
  tests/Feature/M2ClientSignalContractTest.php \
  tests/Feature/M5ClientApiTest.php \
  tests/Feature/M5SignalFreshnessTest.php \
  tests/Feature/M5ManagedBaseTest.php \
  tests/Feature/M5PaperExecutionModelTest.php \
  tests/Feature/M5PaperValuationTest.php \
  tests/Unit/PaperExecutionModelTest.php
```

No cache clear or production restart is needed for this test-only patch. If
`--check` fails, keep the error output and do not force or partially apply it.

## Replacing the first M2 delivery

These are complete replacement patches against the bases above, not incremental
patches on top of the first M2 delivery. Do not stack the old and new patches.

If the original Client M2 patch is already applied, stop the daemon, retain a
backup, prepare a fresh copy of the same M1 archive in a separate code directory,
and apply this replacement patch there. Deploy that code using the same config,
private key file and external data directory. The schema remains version 2, so
existing M2 state is retained; no database downgrade or restore is required.
Do not run the unpatched M1 code against an upgraded M2 database.

If the original Server M2 patch was applied, the clean updated Server source
above is the reference. Remove the obsolete `ClientIntelligence` addition and
its controller wiring through your source workflow; the corrected Client does
not consume that response wrapper. Keep unrelated Server changes.

## Apply the Client patch

Stop your existing Client service through its service manager. For a foreground
daemon, use `php bin/client.php stop` with the same config/data-dir options used
to start it. A managed service may restart a directly stopped daemon, so stop
the service itself before changing code or running offline commands.

From the **M1 Client** directory, using your real M1 state directory:

```bash
M2_DATA="$HOME/.local/share/trademinator/client"
php -d memory_limit=512M bin/client.php backup --data-dir="$M2_DATA"
git apply --check /home/dieu/tmp/trademinator-client-M2.patch
git apply /home/dieu/tmp/trademinator-client-M2.patch
php -d memory_limit=512M bin/client.php doctor
php -d memory_limit=512M tests/m2.php
php -d memory_limit=512M tests/m1.php
php -d memory_limit=512M bin/client.php check --data-dir="$M2_DATA"
```

`git apply` also works in an unpacked M1 release without a `.git` directory.
PHP must be 64-bit 8.3+ with PDO SQLite, BCMath, curl and OpenSSL. libcurl must
support asynchronous DNS; `doctor` reports `curl_async_dns: true`. No new Composer
packages are required. If you use Composer autoloading, run `composer dump-autoload`.

The first storage open upgrades SQLite to schema 2 after publishing a verified
pre-migration backup. It adds only `markets.state`, `subscription_id`, and
`checks_per_candle`. Financial history is preserved. Existing unselected markets
start STOPPED; those with open orders are recognized as STOPPING by the runtime.
Do not run M1 against the upgraded database. A code rollback requires restoring
the matching pre-upgrade state while the daemon is stopped; retain the backup.

## Configure authentication

```bash
cp config.m2.example.json config.m2.local.json
chmod 600 config.m2.local.json
read -rsp 'Client API key: ' TRADEMINATOR_API_KEY
printf '\n'
export TRADEMINATOR_API_KEY
php -d memory_limit=512M bin/client.php run \
  --config=config.m2.local.json --data-dir="$M2_DATA"
```

Use the Server-issued key from **Settings → Client API keys**. Several Client
installations may share the key. Set `server_url` to the HTTPS origin, without an
API path. The example uses `https://server.trademinator.com`. The token is never
sent to exchanges or included in status/logs.

For a background service, use a private key file so the service does not depend
on your interactive shell environment. On Linux:

```bash
install -d -m 700 "$HOME/.config/trademinator"
read -rsp 'Client API key: ' M2_KEY
printf '\n'
(umask 077; printf '%s\n' "$M2_KEY" > "$HOME/.config/trademinator/server-api.key")
unset M2_KEY
```

Add `"server_api_key_file": "/home/dieu/.config/trademinator/server-api.key"`
to `config.m2.local.json`, adjusting the path to the account running the service.
The file must have no group/other permissions on Unix; symlinks are rejected.
On Windows, use an ordinary private file restricted to the service account and
set its path in JSON with escaped backslashes. A configured file takes precedence
over the environment variable. A key-file change is picked up by `reload`.

Use your existing M1 service installer with the M2 config and the same state
directory, then restart the service. Its local control and service mechanisms
are preserved. An existing compiled executable does not change when source is
patched: use PHP for this validation, or rebuild and separately validate the
updated `project-m1.yml` target, which now embeds M2 and requires curl/OpenSSL.

For a Windows foreground validation, use PowerShell from the patched Client:

```powershell
$M2Data = Join-Path $env:LOCALAPPDATA 'Trademinator\Client'
Copy-Item config.m2.example.json config.m2.local.json
$env:TRADEMINATOR_API_KEY = [System.Net.NetworkCredential]::new('', (Read-Host 'Client API key' -AsSecureString)).Password
php -d memory_limit=512M tests/m2.php
php -d memory_limit=512M tests/m1.php
php -d memory_limit=512M bin/client.php run --config=config.m2.local.json "--data-dir=$M2Data"
```

In another PowerShell window, recreate `$M2Data` and use the same commands below
with `"--data-dir=$M2Data"`. The Server key is needed by the daemon, not by local
control commands. For an installed Windows service, use the private file option
and stop/start the existing service through Service Control Manager.

## Select operational markets without restarting

Run these from another terminal. Every command must use the same config and
data directory as the daemon:

```bash
php bin/client.php subscriptions --config=config.m2.local.json --data-dir="$M2_DATA"
php bin/client.php accounts --config=config.m2.local.json --data-dir="$M2_DATA"
```

Wait for a successful catalog fetch before activating a market. The subscriptions
list includes inactive subscriptions for visibility, but they cannot be activated.
Reuse the intended local account ID. If no local account exists, register its
identity (this creates no exchange credentials, connection or balances):

```bash
php bin/client.php account-add --exchange=bitso --label=paper \
  --config=config.m2.local.json --data-dir="$M2_DATA"
```

Use the returned account ID and the subscribed market's subscription ID:

```bash
php bin/client.php market-activate --account=ACCOUNT_UUID --subscription=SUBSCRIPTION_UUID \
  --config=config.m2.local.json --data-dir="$M2_DATA"
php bin/client.php markets --config=config.m2.local.json --data-dir="$M2_DATA"
php bin/client.php status --config=config.m2.local.json --data-dir="$M2_DATA"
```

Copy the local market `id` from the response for later controls:

```bash
php bin/client.php market-checks --market=MARKET_UUID --checks=4 \
  --config=config.m2.local.json --data-dir="$M2_DATA"
php bin/client.php market-stop --market=MARKET_UUID \
  --config=config.m2.local.json --data-dir="$M2_DATA"
```

`--checks=default` restores the global default. `--checks=3` may also be supplied
at activation. Counts 1–59 are supported; 60 would duplicate the final soft-close
slot. `subscriptions` and `markets` support `--offset=0 --limit=50`. Subscription
pages allow 100 entries; market pages cap at 50 to fit local IPC responses.
`server-refresh` queues a catalog/risk refresh. `reload` reloads the config/key.
None of these actions requires restarting the daemon.

## Expected behavior

- All subscriptions are visible; only user-selected ACTIVE markets poll
  intelligence. Account/exchange/symbol identify the market; period is runtime
  scheduling data, not market identity.
- With duration `P` and configurable count `N`, ordinary targets are `iP/N` for
  `i=1..N-1`. The final target is `P-P/60`, preferably before candle close and
  acceptable through `P+P/60`. For 1m/3 checks: 20s, 40s, 59s; the final window
  extends through 61s. Missed windows are discarded. Calendar periods use their
  actual UTC candle duration.
- Requests share a conservative default 100/minute budget and honor Server
  throttling. Capacity warnings do not silently change a market's period or
  number of checks. Adaptive check counts are deferred.
- STOPPING pulls no further intelligence and ignores late responses. It remains
  subscription-active until the local ledger has no open/partially-filled order.
  Filled, cancelled, rejected or expired terminal states allow STOPPED. Elapsed
  time alone never declares an order cancelled; M3 will supply reconciliation.
- Subscription removal/deactivation requests the same stop transition. An outage
  or invalid key keeps the market ACTIVE with a warning, clears opportunities,
  and retries. It does not declare orders complete.
- HOLD causes no trading action. Valid BUY/SELL opportunities remain available
  for repeated local evaluation until they expire. M2 never records ACT, sizes a
  trade or submits an order. Stale, period-mismatched, superseded or malformed
  signals cannot become candidates. The Client checks both Server freshness
  flags and the absolute expiry against its own clock. No fixed 30-second TTL
  or guessed candle-age limit is substituted for the Server expiry. The
  original Server revision lacking these fields fails closed; the optional
  test-only Server patch does not affect compatibility. Once expiry is observed,
  cached evidence cannot be revived by moving the local clock backward. During
  a detected backward clock jump the market remains ACTIVE with a warning and
  no candidates. Previously cached evidence is discarded; a new authenticated
  detail response is required after the clock catches up and freshness checks pass.
- Only market selection/state/check overrides are durable. Subscription catalogs,
  polling deadlines, cached signals and warnings stay in RAM and rebuild after
  restart. Existing crash status excludes those runtime contents.

## Local acceptance

1. Run both test suites above, including native IPC on Linux/Windows.
2. Start M2 with a valid key, list subscriptions, activate one market and confirm
   the same daemon `run_id` remains in status. Unselected markets must not poll.
3. Observe the schedule and fresh intelligence status. A valid HOLD may be shown
   as `server_hold`; `trading_enabled` and `live_order_execution` remain false.
4. Interrupt Server connectivity. The selected market must stay ACTIVE with a
   warning and no eligible cached opportunity. Restore connectivity and observe
   recovery. Do not alter exchange connectivity for this test.
5. Stop the market and confirm no further intelligence polls. With no open ledger
   orders it stops immediately. The offline tests exercise filled/cancelled/
   expired order drainage; M2 cannot yet monitor a real exchange order.
6. Restart and confirm saved selection/state is retained while operational data
   starts empty and is fetched again. Windows/macOS and rebuilt native binaries
   require their own acceptance runs.

## Validation performed for this revision

On Linux with PHP 8.4.26:

- M2: **309 checks/assertions passed** across storage (33),
  HTTP/API (88), scheduler (74), runtime (87), and configuration/reload (27).
- Server: **51 tests, 417 assertions passed** across the new M2 contract tests,
  existing M5 API/freshness tests and paper-trading regression tests. Pint passed.
- Interoperability: **10 actual Laravel HTTP response scenarios** were accepted
  or rejected correctly by the revised Client validator: BUY, SELL, HOLD,
  Action-only BUY/SELL/HOLD, null signal, inactive subscription, stale signal,
  and superseded model. The responses used synthetic fixtures, no live account.
- The curl integration test verified GET, an empty request body, no query string,
  bearer authentication, redirect refusal, response limits and request timeout.
- PHP syntax, Composer validation, whitespace checks and clean patch application
  passed. The complete M2 suite also passed from a freshly patched M1 baseline.

The earlier M1 core validation remains applicable: ledger 31 assertions,
Hard T points 44 assertions, the simulation suite, and the 10,000-order /
20,000-observation run passed all 46 scenario checks. Financial/storage code
is unchanged by this API revision; that long simulation was not repeated for
this rediff. It submitted no network requests or exchange orders.

Native Unix IPC could not be exercised in the original validation because the
environment prohibited Unix sockets. Run the complete M1 suite on your machine.
Live Server credentials, Windows/macOS services and rebuilt native binaries
have not been tested here. M2 has no live order execution to enable.
