# M1 acceptance tests

Start with disposable test directories. M1 does not submit exchange orders, and these tests require no exchange keys or Server credentials. The simulator advances a synthetic clock so daily backups and large workloads can be tested without waiting for market activity.

The test commands below use the PHP source runtime. Windows/macOS service and named-pipe checks must still be run on their respective OS. Passing PHP tests does not establish that an M1 native/AOT executable builds or works.

## 1. Runtime and automated checks

From the extracted source directory:

```bash
php bin/client.php doctor
php -d memory_limit=512M tests/m1.php
```

Both commands must exit with code 0. The final test summary must report `status: passed` for the ledger, hardpoints, daemon, and simulation suites. Keep complete output if any check fails; do not proceed to unattended operation on a failing platform.

The suites exercise financial invariants and replay/idempotency; shared quote balances and isolation; backup and hard-point safety; daemon control, exclusive locking and restart; and deterministic fixtures. A targeted rerun is supported, for example `php tests/m1.php --suite=daemon`.

## 2. Deterministic 10,000-order run

Linux/macOS:

```bash
M1_TEST="$HOME/m1-test-$(date -u +%Y%m%dT%H%M%SZ)"
php -d memory_limit=512M tools/m1-simulate.php "--data-dir=$M1_TEST" --orders=10000 --scenario=all
```

Windows PowerShell:

```powershell
$M1Test = Join-Path $env:LOCALAPPDATA ('Trademinator\M1-test-' + (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ'))
php -d memory_limit=512M tools/m1-simulate.php "--data-dir=$M1Test" --orders=10000 --scenario=all
if ($LASTEXITCODE -ne 0) { throw 'Simulation failed. Keep its report and data for diagnosis.' }
```

Do **not** create the destination first. The simulator refuses an existing directory. Reuse neither your production data directory nor a previous simulation directory.

Read `simulation-report.json` in the destination. Expected primary workload:

| Item | Expected result |
|---|---:|
| Status | `passed` |
| Real network requests / orders submitted | 0 / 0 |
| Completed synthetic orders | 10,000 |
| BUY/SELL round trips | 5,000 |
| Partial/final cumulative order observations | 20,000 |
| Allocation records | 10,000 |
| Markets sharing USD | 3 |
| Final synthetic USD | 1,098,900 |
| Virtual days | 10 |
| Retained daily backups | 7 |
| Retained every-1,000-trades backups | 7 |

Every eligible financial event may produce a checkpoint. There is no arbitrary cap or daily suppression of checkpoints. An unchanged synchronization must not produce another checkpoint. Dust or open orders must prevent one. The simulator's separate dust fixture demonstrates how allocated residual quantities stop being dust; this is an accounting test, not an autonomous dedusting trader.

The `load/` subdirectory is left available for manual inspection and CLI tests. Other scenarios use separate subdirectories so their state cannot contaminate the load result.

## 3. Foreground daemon and private control

Choose another new test directory; `init` creates it. In terminal A on Linux/macOS:

```bash
M1_RUN="$HOME/m1-daemon-test"
M1_RUN_CONFIG="$HOME/m1-daemon-config.json"
cp config.m1.example.json "$M1_RUN_CONFIG"
php bin/client.php init "--config=$M1_RUN_CONFIG" "--data-dir=$M1_RUN"
php bin/client.php run "--config=$M1_RUN_CONFIG" "--data-dir=$M1_RUN" --seconds=180
```

In terminal B, as the same user, repeat the `M1_RUN` and `M1_RUN_CONFIG` assignments and run:

```bash
php bin/client.php status "--data-dir=$M1_RUN"
php bin/client.php reload "--config=$M1_RUN_CONFIG" "--data-dir=$M1_RUN"
php bin/client.php run "--data-dir=$M1_RUN" --seconds=5
php bin/client.php backup "--data-dir=$M1_RUN"
php bin/client.php stop "--data-dir=$M1_RUN"
```

Expected: `status` succeeds, `reload` acknowledges success, the second `run` is rejected, offline `backup` is rejected while the daemon owns the directory, and `stop` causes the original process to exit cleanly. The two deliberate refusals must have a nonzero exit status. After it exits:

```bash
php bin/client.php check "--data-dir=$M1_RUN"
php bin/client.php backup "--data-dir=$M1_RUN"
php bin/client.php run "--data-dir=$M1_RUN" --seconds=10
```

The database check and backup must now succeed, and restarting must not require deleting any lock/runtime file.

Reload requires starting with an explicit configuration file; a run using defaults alone correctly rejects reload.

On Windows use the same PHP commands with `$M1Run = Join-Path $env:LOCALAPPDATA 'Trademinator\M1-daemon-test'` and arguments such as `"--data-dir=$M1Run"`. Copy the example configuration to `$M1RunConfig` outside the source directory and pass `"--config=$M1RunConfig"` when starting and reloading. Control travels over a local named pipe protected for the user, rather than a TCP port. Confirm it works from a second PowerShell session under the same account. Do not test with an elevated session running as a different user.

To test configuration reload, use a copy of `config.m1.example.json` outside the source directory and pass `--config=...` consistently. Change `log_level` or `tick_ms` to another valid value, issue `reload`, and inspect the response/log. Invalid JSON or invalid settings must be rejected while the current daemon keeps running. Changing the data directory requires a stopped daemon and a deliberate restart with the new path.

## 4. Hard T1: review, approval, stale-candidate rejection

The automated hardpoints suite tests rejected promotion without approval, stale candidates, archive integrity and recovery. Run it independently if needed:

```bash
php tests/m1.php --suite=hardpoints
```

For a manual promotion exercise, keep the daemon stopped and use only the disposable `load/` data from step 2. Read `workload.account_id` from its report, then substitute that value:

```bash
M1_ACCOUNT='REPLACE_WITH_WORKLOAD_ACCOUNT_ID'
php bin/client.php check "--data-dir=$M1_TEST/load"
php bin/client.php hard-t1-propose "--data-dir=$M1_TEST/load" "--account=$M1_ACCOUNT"
```

Inspect the candidate details, eligibility, latest checkpoints, balances and token. A proposal alone must not archive active history or replace Hard T0. Omitting approval must fail:

```bash
php bin/client.php hard-t1-promote "--data-dir=$M1_TEST/load" "--account=$M1_ACCOUNT"
```

Only after reviewing this **synthetic** candidate, copy its exact token into the explicit approval command:

```bash
php bin/client.php hard-t1-promote "--data-dir=$M1_TEST/load" "--account=$M1_ACCOUNT" --approve=REPLACE_WITH_REVIEWED_TOKEN
php bin/client.php check "--data-dir=$M1_TEST/load"
```

Expected: the promoted point becomes the new reconstruction baseline; full archived state and its manifest remain available; the active state remains financially consistent. Reusing an old token or promoting after relevant state changes must fail. Do not manually edit SQLite to manufacture that condition: the hardpoints suite supplies controlled stale-state fixtures.

`hard-restore --account=UUID --point=UNIX_MS --approve=yes` is an explicit account-state replacement from a hard-point archive. Exercise restoration only with a disposable fixture and a point reported by its own archive manifest. `recover` resumes an interrupted hard-point operation; it is not a generic command to import arbitrary backup files.

## 5. Crash/restart and backups

Use the disposable daemon directory from step 3, never a production instance. Start it, obtain its PID from the successful `status` response, and terminate **that exact PHP process** with `kill -KILL PID` on Unix or `Stop-Process -Id PID -Force` on Windows. Do not kill all PHP processes by name. Then restart with the same directory and run `status`; stop it normally and run `check`.

Expected: restart succeeds without manually deleting `daemon.lock`, SQLite integrity passes, and the event log shows the new lifecycle. The hardpoints tests separately exercise interruption during archive/promotion and recovery; an idle-daemon crash alone does not validate financial-transaction recovery.

Backups are distinct from hard points: daily snapshots and each additional 1,000 completed trades retain seven snapshots independently. Check the two backup groups in `load/backups/` from the simulation. The test's virtual clock validates day changes, 1,000-trade boundaries, retention and no duplicate idle backups. A manual `backup` is available only while the daemon is stopped. Hard-point history is under `hard-points/`. Do not restore by copying a database over an active process.

For recovery from a corrupt active database, stop the daemon and use `php bin/client.php recover-backup --data-dir=TEST_DIRECTORY --approve=yes` **only on a disposable copy during testing**. This explicitly replaces the active database with the newest verified usable backup, skipping corrupt candidates and quarantining the replaced database. Restored account reconciliation is invalidated; recovery must not imply that old balances are current exchange truth. Preserve the reported quarantine and recovery output for inspection.

## 6. OS service acceptance

Install using [M1-INSTALL.md](M1-INSTALL.md) only after the foreground tests pass. Verify all of the following on each target OS:

1. The OS service/LaunchAgent/login task runs under the intended **same** user and uses the intended external data directory.
2. CLI `status` works from that user's second shell; no public TCP listener is used for local control.
3. Stop and start using the OS service manager work; configuration reload is acknowledged.
4. Deliberately terminate the managed PHP process in a disposable service test. Confirm the manager restarts it and the database passes `check` after a normal stop.
5. Restart the computer, or log out/in according to the chosen mode. Linux boot operation requires lingering; macOS LaunchAgents and Windows login tasks require user login. A true Windows service must start through SCM.
6. Uninstall registration, confirm the persistent data remains, and reinstall using the same paths.

The Linux installer can be syntax-checked with `bash -n tools/install-m1.sh tools/uninstall-m1.sh`. This is not a substitute for testing an actual user service. The Windows host must compile on Windows before service acceptance; PowerShell and C# source review alone do not establish a passed Windows result.

## Evidence to retain

Keep the complete `doctor` and automated-test output, `simulation-report.json`, OS/PHP version, daemon/service logs, and any error output. For a failure, preserve the entire affected **test** data directory, including SQLite companions and hard-point archives. Report which OS, runtime mode (PHP/true service/login task), command and exact test step failed. Avoid sending real account credentials or unrelated local configuration.
