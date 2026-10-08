# Trademinator Client

Fresh standalone implementation, starting with **M0 — technology validation**.
This is a headless, read-only probe. It collects ticker observations and exercises
the intended dependencies. It does not place orders or run a trading strategy.

## Quick start

Use a 64-bit PHP CLI with BCMath, curl, GMP, JSON, mbstring, OpenSSL and PDO SQLite,
plus Composer 2. PHP 8.3 is the interpreted baseline; PHP 8.4 or 8.5 is recommended
and required by the TypePHP compiler evaluated for this milestone.

```bash
composer install
composer test
php bin/client.php doctor
php bin/client.php run --seconds=60
```

The default run uses deterministic fixtures and writes to `./var/`. Its first
WebSocket attempt deliberately fails, causing REST fallback; after 30 seconds,
WebSocket probing resumes. The output clearly says `mode: fixture`. No network
credentials are needed. `composer install` installs the committed lockfile,
including `trademinator/bcmath` and `trademinator/indicators` v0.1.0 and CCXT 4.5.57.

From another terminal in the same directory:

```bash
php bin/client.php status
php bin/client.php stop
```

`run` stays in the foreground. With no duration or sample limit, it runs until
`stop` or a supported termination signal. `--seconds=N` and `--samples=N` stop
when either limit is reached; zero means unlimited. An orderly shutdown exits
zero even if the network was unavailable: inspect `samples`, `errors`, `server`
and transport counters before calling a validation successful.

## Public exchange and Server API probe

Copy `config.example.json` to `config.local.json`. Set `mode` to `live`. The
initial exchange/symbol is Kraken `XRP/CAD`; change it to a market supported by
your installed CCXT version. No exchange API key is used.

To check your Server as well, set `server_url` to its origin, for example
`https://server.example.com` (without `/api/v1/client`). Supply a Client API key
from the Server's **Settings → Client API keys** through `TRADEMINATOR_API_KEY`.
For a Bash session you can enter it without putting it in shell history:

```bash
read -rsp 'Client API key: ' TRADEMINATOR_API_KEY
export TRADEMINATOR_API_KEY
php bin/client.php run --config=config.local.json --seconds=120
```

If `server_url` is empty, only the public exchange is probed. Configuration is
JSON; `.env` files are not loaded. Relative `data_dir` paths are resolved against
the configuration file's directory. Without a configuration file, `var` is
relative to the current working directory.

```bash
php bin/client.php status --config=config.local.json
php bin/client.php stop --config=config.local.json
```

The Server probe performs only these authenticated GETs, once per configured
poll period (60 seconds by default):

| Endpoint | Probe output |
| --- | --- |
| `/api/v1/client/markets` | Number of markets available to the key's user |
| `/api/v1/client/risk-factor` | Decimal R, algorithm version and source |

The Server remains the authority for R. A reported default must equal exactly
0.25. Timeouts, invalid JSON, unexpected API/algorithm versions and HTTP errors
produce `status: unavailable` and `risk_factor: null`; they never become an
invented default. R is not used to execute trades in M0. The small math fixture
checks the agreed `Qrisk = Qdistributed_capital × R` and
`Qbuy = min(Q3bar, Qrisk)` relationship without implementing the decision engine.

## What is implemented

- A typed PHP daemon with one in-flight market request, bounded request times,
  periodic state writes, finite-run options and shutdown cleanup.
- Real CCXT Pro ticker support where available, asynchronous CCXT REST fallback,
  reconnect cooldown, and recovery after failed market initialization.
- SQLite WAL persistence, restart-safe quote history and bounded retention
  (1,000 observations by default). Decimal values remain strings. Converting
  CCXT floats to strings cannot recover precision already lost inside CCXT.
- Authenticated local TCP `status`/`stop`, bound exclusively to `127.0.0.1`.
  Commands require a random token stored in the data directory. Port `0` requests
  an OS-assigned port. A lock prevents two daemons sharing one data directory.
- Shared math/indicator fixtures, a runtime doctor, fixture soak runner, and
  three distinct TypePHP build experiments.
- A GitHub Actions matrix for Linux, macOS and Windows on PHP 8.3–8.5. The matrix
  is supplied, but has not run for this new project yet.

Unix sockets, Windows named pipes, installed background services, private
exchange state, trading and Telegram belong to later milestones. Keep the data
directory private to the operating-system account running the client. Unix
files are created with restrictive modes; Windows access follows the directory's
ACL. Local control is not designed for access from another computer.

## Validation

```bash
composer validate --strict
composer lint
composer test
php tools/soak.php 60
```

`tools/soak.php` reports fixture throughput, fallback/recovery and PHP allocator
memory growth after a five-second warm-up. It accepts 10–604800 seconds and fails
if observations stop, errors occur, either transport is unexercised, or final
allocator growth exceeds 16 MiB. This threshold is a smoke-test budget, not proof
that native allocations or live CCXT are leak-free. Reports go to `build/soak-*`.

For TypePHP, use a toolchain with matching PHP 8.4/8.5 headers, runtime, required
extensions and PHPX, plus a C++17 compiler:

```bash
# TPC_BIN can point to a prepared compiler executable; default is tpc on PATH.
export TPC_BIN=/path/to/tpc
php tools/validate-native.php
```

See [the native-build experiments](docs/TYPEPHP.md) and
[the actual validation results](docs/VALIDATION.md). **M0 is not signed off yet:**
native builds, live authenticated Server/exchange reads, cross-platform runs and
extended memory testing must pass before the technology decision is closed.

## Layout

| Path | Purpose |
| --- | --- |
| `src/` | Standalone M0 runtime and adapters |
| `bin/client.php` | Interpreted PHP CLI |
| `native/`, `project*.yml` | TypePHP entry points and source boundaries |
| `tests/run.php` | Offline behavioral and integration checks |
| `tools/` | Lint, soak and native validation commands |
| `docs/` | Scope, build procedure and validation evidence |

No Laravel dependency is required. `vendor/`, state files, credentials and
generated binaries are excluded from the source distribution.
