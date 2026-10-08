# Validation record — 2026-10-04

This source is the initial M0 implementation. **The technology-validation
milestone remains open.** It is not a production trading client.

| Check | Actual result |
| --- | --- |
| Composer dependency resolution/lock | Passed; real published packages installed |
| `composer validate --strict` | Passed |
| PHP syntax checks | Passed |
| Behavioral/integration suite | 14 checks passed on Linux x86-64, PHP 8.3.6 |
| Shared BCMath and indicator arithmetic | Passed with native BCMath; tiny decimal EMA/SMA and sizing fixture |
| CCXT async/Pro adapter construction | Passed for Kraken using locked CCXT v4.5.57 |
| WebSocket failure/timeout, REST fallback, cooldown and recovery | Passed using injected offline responses |
| Local TCP control | Passed across processes, including authentication, fragmented input, status, stop and singleton lock |
| SQLite restart/retention/decimal preservation | Passed |
| Server HTTP contracts | Passed against a local HTTP fixture, including bearer header, redirects, auth errors and malformed JSON |
| 60-second fixture smoke soak | 5,827 samples, 0 errors; 98 REST + 5,729 WebSocket samples; 8 MiB PHP allocation, 0 bytes growth after warm-up |
| Live Kraken feed | Attempted; unavailable because outbound DNS was unreachable in the execution environment; 0 real samples |
| Real authenticated Server | Not run; no Server URL/API key supplied |
| TypePHP v0.9.4 Linux executable | Release SHA-256 verified; launch failed due to missing `libphpx.so` |
| Native compile and executable behavior | Blocked before compilation; not validated |
| PHP 8.4/8.5 and Windows/macOS | CI matrix supplied; not run here |
| 24-hour live/native memory stability | Not run |

The fixture soak is a short check of the PHP loop, SQLite, retention and simulated
transport changes. It cannot establish CCXT/WebSocket connection stability,
process RSS stability, native memory usage or cross-platform portability.

The live run remained responsive and shut down at the requested duration after
the network failures. That demonstrates continuity under this failure, not
successful exchange connectivity. Its zero exit code indicates an orderly
shutdown; successful live validation requires nonzero observations.

Current Server request/response shapes were checked against
`Trademinator/trader-server` commit
`944c081a4a109fe259bcab05b590542031dcd01c`, including the risk-factor endpoint.
No Server files were modified. No repository has been published or pushed for
this new Client implementation.

The source distribution includes sanitized machine-readable results under
`docs/evidence/`. Run the supplied checks again on the intended build and runtime
machines and append their results before signing off M0.
