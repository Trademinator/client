# M3 patch validation

Baseline: `Trademinator/client` GitHub `main`,
`f37294bfd53c490f6d0f9f35550995cff38e5e10`.
GitHub HEAD was checked again before patch generation and was unchanged.

Environment: Linux, PHP 8.3.6, installed dependencies from the repository's
existing lockfile, `memory_limit=512M`. No exchange credentials were used.

| Check | Result |
| --- | --- |
| PHP syntax checks, including M3 and soak tooling | Passed |
| Composer manifest/lock validation | Passed |
| M0 regression | 15 tests passed |
| M1 ledger, hard points and simulation suites | Passed |
| M1 daemon suite | 14 checks passed; suite exits 1 because native Unix-socket startup is blocked by this environment (`Operation not permitted`) |
| M2 storage, API, scheduler, runtime, configuration, console, events and CLI suites | Passed; native daemon lifecycle check reported its existing environment skip |
| M3 accounting scenarios | 38 checks passed |
| M3 runtime/recovery scenarios | 16 checks passed |
| M3 transport, pagination and pinned CCXT parser scenarios | 8 checks passed |
| 10,000 mixed BUY/SELL events, followed by duplicate replay | Passed; 9.480 s, 81,788,928 peak allocated bytes for the accounting suite |
| Exchange orders submitted / withdrawals initiated by tests | 0 / 0 |
| Whitespace and clean-baseline patch application | Verified before delivery |

The M3 checks cover exact decimals, free/used/total consistency, manual trades,
fill fragments, duplicate replay, partial fills, base/quote/third-currency fees,
LIFO withdrawals, estimated deposits, unique and ambiguous transfer matches,
multi-lot transfer provenance, wallet discrepancies, persisted uncertain outcomes,
explicit client-order resolution, immutable candidate capture, fresh H1 replay,
exact approval tokens, post-snapshot activity, archived late matches and unchanged
Hard H0 hashes. Transport tests also exercise non-progressing cursor refusal,
wallet changes during capture, REST-only readiness, WebSocket invalidation,
REST failure, retry recovery, authentication blocking and error sanitization.

## Remaining acceptance gates

* A 24-hour **read-only** Bitso ATOM/USD run with real private history and balances.
* Real network interruption, reconnect, and process-restart evidence.
* Linux and Windows TypePHP/native build and runtime validation.
* Native IPC lifecycle on the target machines.
* Confirmation that no unexplained discrepancy remains in the user's actual data.

`tools/m3-soak.php` and `M3-INSTALL.md` provide the local acceptance procedure.
Synthetic success does not complete these gates. The current account inventory
model still fails closed for crypto used as another market's quote; that boundary
is documented in the installation guide and requires a separate inventory-model
extension. This patch does not claim all exchange/account combinations are ready
for live trading.
