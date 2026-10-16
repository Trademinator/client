# M2 console events

Client 2.1.0-rc1 adds visible operational events to `bin/client.php run`.
The formatter is Symfony Console 7.4 LTS, installed from `composer.lock`.
The milestone remains M2.

## Install the follow-up patch

Apply this incremental patch to M2 2.0.0-rc2 **with the recovery-warning fix**.
It is not the original cumulative M1-to-M2 patch.

From the Client repository, with the downloaded patch in `~/tmp`:

```bash
PATCH="$HOME/tmp/trademinator-client-M2-console-events.patch"
git apply --check "$PATCH"
php -d memory_limit=512M bin/client.php stop \
  --config=config.m2.local.json \
  --data-dir="$HOME/.local/share/trademinator/client"
```

Wait for the original daemon terminal to return to its shell prompt. Then:

```bash
git apply "$PATCH"
COMPOSER_MEMORY_LIMIT=512M composer install --no-interaction --prefer-dist
php -d memory_limit=512M bin/client.php doctor
php -d memory_limit=512M tests/m2.php
```

Use `install` to install the supplied lockfile. The patch adds seven packages
without changing versions of existing packages. Run needs the Composer project
dependencies, including GMP and mbstring, in addition to PDO SQLite, BCMath,
curl, JSON and OpenSSL on 64-bit PHP 8.3+. Composer resolves against PHP 8.3.0
to preserve that minimum.

Composer can still report the pre-existing abandoned `pear/console_table`
dependency required by the installed CCXT version. The new colour formatter
uses Symfony Console; it does not use PEAR Console_Color2 or Console_Table.

Restart in the original terminal that still has `TRADEMINATOR_API_KEY` exported:

```bash
php -d memory_limit=512M bin/client.php run \
  --config=config.m2.local.json \
  --data-dir="$HOME/.local/share/trademinator/client"
```

Existing accounts, market selections and database schema are retained. An
already-running daemon must restart to load the new PHP code.

## Human output

The default is two physical lines per operational event:

```text
2026-10-16T03:20:00.573Z INFO  [MARKET_SIGNAL_RECEIVED] Bitso ATOM/USD: HOLD.
        {"exchange":"bitso","symbol":"ATOM/USD","action":"hold","reason":"degraded_action_only"}
```

This example is abbreviated; actual payloads also carry market identity and
the relevant event fields. The first line starts with the UTC timestamp and
severity in gray, followed by the coloured event name and its description.
The second line is indented, compact JSON. Green denotes ordinary activity,
cyan requests, yellow warnings, red errors, and gray debug messages/timestamps.

Normal output includes daemon startup/shutdown, storage readiness, new backups
and Hard T1 availability, local account creation, market state/schedule changes,
scheduled checks, signal results, catalog/risk refreshes, and communication
failures/retries/recovery. Signals include their reason, including HOLD and
abstention. There is no event for an idle 100 ms loop tick.

```bash
# Include low-level request events.
php -d memory_limit=512M bin/client.php run --config=config.m2.local.json --verbose

# Force colour, or turn it off explicitly.
php -d memory_limit=512M bin/client.php run --config=config.m2.local.json --color=always
php -d memory_limit=512M bin/client.php run --config=config.m2.local.json --color=never
```

`--color=auto` is the default. It disables ANSI for redirected output, a dumb
terminal, or a nonempty `NO_COLOR` variable. `--color=always` explicitly overrides
automatic detection. `--verbose` and `-v` are equivalent. These flags and
`--output` apply to `run`; local control commands retain their JSON responses.

## Structured events and the future TUI

```bash
php -d memory_limit=512M bin/client.php run \
  --config=config.m2.local.json --output=json
```

JSON mode writes one full event per line and never adds ANSI, even when colour
is explicitly requested. Each record has:

| Field | Meaning |
|---|---|
| `schema_version` | Event envelope version; initially `1`. |
| `at` | UTC timestamp with milliseconds. |
| `level` | `debug`, `info`, `warning`, or `error`. |
| `event` | Stable lowercase name, such as `market_signal_received`. |
| `message` | Human-readable description; consumers should not parse it. |
| `run_id` | Daemon run identity; empty for failures before a daemon exists. |
| `context` | Bounded structured event details. |

The same event record feeds the existing rotated `logs/client.jsonl` and the
console. Console visibility is independent of the configured disk `log_level`;
debug output requires `--verbose`, and debug file logging requires
`log_level: "debug"`. A future TUI or syslog sink can consume the structured
record without parsing colour sequences or human sentences. Syslog transport
and an interactive TUI are not implemented in this patch.

All run events use stdout, including error events. Errors before console setup
(for example missing dependencies) retain the existing JSON stderr fallback.
When launched by a service manager or in the background, output goes wherever
that launcher directs stdout. Use JSON mode for machine consumers. This patch
does not add a new self-detach option.

Credential keys and registered API-key values are redacted. Payloads use
selected operational fields, not raw API headers, responses, scoring arrays,
or local financial state. Control characters and formatter markup cannot
inject terminal commands. Records are bounded to prevent oversized payloads.

Output pipes/sockets are best effort and bounded: a blocked consumer can cause
console events to be dropped rather than stall the daemon. A shutdown while
output is backlogged may leave a final stdout line incomplete. The independent
rotated file log remains available. Operational events are not the accounting
ledger and stdout is not a lossless delivery channel.

## Validation and platform scope

The automated suites cover plain/ANSI/JSON output, environment colour rules,
redaction and terminal injection, bounded blocked-pipe behavior, lifecycle and
runtime events, connection recovery, and unchanged trading/state behavior.
Use `php -d memory_limit=512M tests/m2.php --suite=console,runtime-events,console-cli`
for the new focused suites. Run the full M1/M2 suites when validating the build.

Validation on PHP 8.4: 484 M2 assertions passed. The CLI lifecycle probe and
M1 daemon IPC tests cannot fully run in the hosted test environment because it
denies Unix socket creation; rerun them on the target Linux machine.
Composer validation, platform dependency checks, and PHP syntax checks passed.

`MANIFEST.sha256` covers source and installed package files. Composer-generated
autoload metadata is excluded because Composer regenerates it per installation;
dependency versions are pinned by `composer.lock`.

The native build recipe now embeds Composer dependencies and their bootstrap
files. Compiled binaries and Windows/macOS terminal/service behavior still
require native platform validation; Linux PHP output is tested separately.
