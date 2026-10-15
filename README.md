# Trademinator Client — M1

M1 supplies the per-user local daemon, SQLite accounting storage, native local
control, backups, reconstruction points and deterministic simulation. It does
not connect to a private exchange account or execute live orders. Those belong
to later milestones. This is the first M1 release candidate, for acceptance
on your Linux and Windows machines (and macOS before deployment there).

## Start and test

M1 needs **64-bit PHP 8.3+**, **PDO SQLite** and **BCMath**. Composer is not needed
for the M1 core. This full distribution also includes M0’s locked PHP libraries. On Windows, local named-pipe control also needs PowerShell.

```bash
php bin/client.php doctor
php tests/m1.php
php tools/m1-simulate.php --data-dir=/tmp/trademinator-m1-fixture --orders=10000 --scenario=all
php bin/client.php run --config=config.m1.example.json --seconds=60
```

The simulation directory must be new. It uses synthetic accounts, exact decimal
amounts and a virtual clock. It never submits exchange orders. The ordinary
`run` command uses the current operating-system user's persistent data directory.
Use `--data-dir=PATH` for an isolated acceptance run. In another terminal, using
the same configuration/data directory:

```bash
php bin/client.php status --config=config.m1.example.json
php bin/client.php reload --config=config.m1.example.json
php bin/client.php stop --config=config.m1.example.json
```

Read [installation and replacement](docs/M1-INSTALL.md) and the complete
[acceptance procedure](docs/M1-TESTING.md). Actual results produced while building
this archive are in [M1 validation](docs/M1-VALIDATION.md).

## Local state and recovery

Executable files are separate from persistent state:

| Platform | Default state directory |
| --- | --- |
| Linux | `~/.local/share/trademinator/client/` |
| Windows | `%LOCALAPPDATA%\Trademinator\Client\` |
| macOS | `~/Library/Application Support/Trademinator/` |

Each user has an independent database, daemon lock and IPC endpoint. Local
control uses a Unix domain socket on Linux/macOS and a named pipe on Windows;
there is no local TCP port to allocate. Linux user services, a macOS LaunchAgent
and Windows installation tooling are included under `services/` and `tools/`.

SQLite uses WAL, foreign keys, transactional writes and eight application tables:
`exchange_accounts`, `markets`, `schema_migrations`, `orders`, `buys_vs_sells`,
`operations`, `checkpoints`, and `signal_decisions`. Quantities and prices are
stored as decimal strings. Accepted cumulative partial/final order observations
are preserved for audit. `operations` contains deposits and withdrawals;
`buys_vs_sells` records quantities allocated between acquisitions and sales.

Backups run daily and after each 1,000 completed trades, with independent rolling
retention. Hard T points are permanent and are not part of rolling retention.
All qualifying market checkpoints are retained until an explicitly approved
Hard T1 archive. Repeated synchronization without financial activity does not
create another checkpoint.

Hard T0 captures an account's chosen starting state with no open orders. A Hard
T1 candidate requires current checkpoints for every account market and no open
orders. Promotion requires its specific approval token and rechecks eligibility.
Previous full states, accounting histories and manifests remain archived under
`hard-points/<account-uuid>/<unix-ms>/`. The active baseline changes only as part
of the recoverable promotion procedure.

Use `php bin/client.php help` for offline backup and hard-point commands. Stop
the daemon before those mutations. Synthetic evidence is not a claim that an
exchange has been reconciled; future private-exchange integration must provide
the authoritative state.

## What is preserved from M0

All tracked M0 code, tests, diagnostics, lockfile and TypePHP build experiments
are included. Its entry point is now `bin/m0.php`; its original documentation is
[here](docs/M0-README.md). To use those probes:

```bash
composer install
php bin/m0.php doctor
composer test:m0
php tools/soak.php 60
```

M0's `native/main.php` and `project*.yml` experiments still target M0. The separate
`project-m1.yml` is an M1 embedded-runtime build candidate and must be validated
on each target toolchain; an M0 executable does not run M1.

The archive contains source, not your private configuration, local state,
credentials, existing local test evidence or old locally compiled executables.
The locked Composer vendor directory and upstream licences are included. Preserve those separately before replacing the checkout.
Do not copy an M0 SQLite database over the M1 database: M0 quote-history tables
are not an M1 accounting ledger.
