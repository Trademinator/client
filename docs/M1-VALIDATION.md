# M1 release-candidate validation

Release: `1.0.0-rc1`, 2026-10-15. Source baseline:
`Trademinator/client@b30777977dd548229f67188d414a28e04bd2e89a`.

This is a complete PHP source distribution with its locked Composer libraries,
not a precompiled Windows/Linux/macOS executable. No GitHub write was performed.
The supplied M1 native-build configuration is a separate unvalidated candidate.

## Executed here

Environment: Linux, 64-bit PHP 8.3.6, PDO SQLite, BCMath; memory limit 512 MiB.

| Check | Result |
| --- | --- |
| PHP syntax across Client source/tests/tools | Passed |
| Composer strict manifest/lock validation | Passed |
| Preserved M0 behavioural/integration tests | 15 passed |
| Preserved M0 diagnostic tests | 10 passed |
| Preserved M0 callback-arity regression tests | 8 passed |
| M1 ledger/accounting tests | 31 assertions passed |
| M1 backup/hard-point/recovery tests | 44 assertions passed |
| M1 small simulation integration | 13 assertions plus 46 scenario checks passed |
| CLI reviewed Hard T1 proposal/promotion | Passed; missing/reused token rejected |
| M1 10,000-order simulation | 46 checks passed; 10,000 checkpoints; 19 synthetic Hard T1 promotions |
| Final backup directories, independently counted | 7 daily and 7 trade-count snapshots |
| M1 daemon non-IPC checks | 14 passed |
| Native Unix IPC lifecycle | Blocked by execution environment; not certified |
| Linux/macOS installer shell syntax | Passed |

The daemon test defaults to strict native IPC validation. Here it was also run
with the explicit test-only `--allow-unavailable-ipc` option, reporting one
skipped integration group because creation of Unix-domain sockets returns
`Operation not permitted`. This is an environment limitation, not a passed
native test. Do **not** use that option for target-platform acceptance.

The final large run completed in **146.278 seconds**, with a PHP allocator peak
of **26 MiB**. These are measurements for this synthetic workload, not live
exchange throughput or a long-term memory guarantee.

## Acceptance still required on your machines

Run `php tests/m1.php` with no skip flag, then the 10,000-order simulator and
foreground/service procedures in `M1-TESTING.md`. Native Unix status/stop/reload,
Windows named pipes and SCM host, macOS LaunchAgent, boot/logon behaviour and M1
AOT execution were not run here. Windows power-loss durability also needs target
validation; PHP cannot perform the POSIX directory-fsync sequence on Windows.

Fault-injection tests cover interrupted backup/archive publication, stale
approvals, commit recovery, corruption fallback and reconstruction. They do not
claim to reproduce every filesystem or hardware power-loss failure.

## Boundaries of this milestone

- M1 has no private-exchange adapter, live strategy or order execution. Ordinary
  daemon startup creates an empty local core; the simulator creates synthetic
  accounts/markets. M0 public probes remain at `bin/m0.php`.
- Accounting currently rejects third-currency order fees and markets that spend
  another market's base inventory as quote currency. Those cases need explicit
  inventory attribution before exchange integration; they do not silently pass.
- Initial holdings with unknown acquisition costs retain an unknown basis.
  Hard T0 still allows that state if there are no open orders; this does not
  authorize a later sale without a known basis.
- Order audit history stores accepted cumulative exchange observations, including
  partial/final quantities, costs, fees, timestamps and metadata. These are not
  invented individual exchange-fill identifiers. Exact duplicate replay adds no
  observation and does not generate a checkpoint.
- Each qualifying checkpoint is preserved. In the large fixture, explicit
  synthetic approvals periodically archive complete histories; the real daemon
  only proposes Hard T1 and never approves it itself.
- Backup recovery and hard-point restoration invalidate reconciliation. A historic
  snapshot must not be treated as current exchange truth.

The managed `/workspace` storage used during development sometimes restored
backup directories after successful deletion. The identical retention tests
passed on local `/tmp` storage, with an independent directory count. Full-load
acceptance therefore uses local temporary storage; the deletion anomaly was not
hidden by weakening retention checks.

See `docs/evidence/m1/` for the recorded results and full fixture report.
