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

## Bitso historical-order update

Incremental base: GitHub M3 `6b6f93b5593fbfd12cdad922b3e8773bb34637e0`
plus the delivered sync-diagnostics patch (authentication diagnostics may also be
present). The update adds schema 4 and verified historical execution imports.

Validation on Linux/PHP 8.3.6, with no exchange credentials or network requests:

* 29 historical-order checks: full order trades, correct IDs/markets/sides,
  unknown original terms, actual fee/cost preservation, duplicate replay,
  120 fills for one order, H1 evidence, later authoritative details, uncertain
  submissions, and rejection of incomplete/conflicting/open/recent evidence.
* 12 schema checks: exact v3 rows/IDs/allocations preserved, verified pre-migration
  backup, foreign-key validation/enforcement, atomic rollback under injected
  failure, and no repeat migration on reopen.
* Existing M3 accounting (38 checks, 10,000 events), runtime (26 checks including
  authentication diagnostics), transport (8), and sync diagnostics (20) passed.
* M1 ledger, hard points and simulation plus all M2 suites passed. M1 daemon
  reports 14 passing checks and one explicit native-IPC environment skip.
* PHP syntax, whitespace and incremental patch application verified.

This fixes the historical-lookup assumption; it does not establish acceptance
against a real Bitso account. Restart the local read-only run after applying.

## Bitso transfer endpoints and empty-response update

Incremental base: GitHub M3 `6b6f93b5593fbfd12cdad922b3e8773bb34637e0` plus
the delivered authentication, synchronization diagnostics, historical-order and
HTTP/progress patches. GitHub main was checked again before this update.

The live ledger request returned HTTP 404. The Bitso read extension now uses the
documented funding and withdrawal GET endpoints through CCXT. Vendor code and
the dependency lock are unchanged. An empty-response guard fixes the reported
CCXT string-offset warnings without hiding the HTTP failure.

Validation on Linux/PHP 8.3.6:

* 43 new checks exercise the real CCXT signing/implicit-GET/HTTP-parser path with
  a fully replaced HTTP browser and synthetic credentials: 205 deposits over
  three pages, actual page limits, equal timestamps, overlap, duplicate conflicts,
  page-budget and repeated-page rejection, status handling, exact gross/net/fee
  accounting, financial replay, transfer history independent of the trade cursor,
  financial boundaries, forbidden filters, malformed responses and empty 404s.
* All 213 M3 checks pass, including 10,000 accounting events (9.232 seconds,
  81,788,928-byte peak memory in this run), runtime, transport, diagnostics,
  historical orders and schema migration suites.
* PHP lint and whitespace checks pass. The incremental patch applies to the
  preceding delivered patches and reproduces the tested files exactly.

These are offline validations. A successful reconciliation of the user's live
Bitso account and the remaining read-only acceptance gates are still required.

## Bitso individual withdrawal evidence and diagnostics

Incremental base: GitHub M3 `6b6f93b5593fbfd12cdad922b3e8773bb34637e0` plus
the previously delivered patches through `m3-bitso-transfers`. GitHub main was
checked before this update. No vendor, dependency lock or database schema change
is required.

Completed list records without fee/net evidence now get one individual GET.
The returned withdrawal must match the list's identity and financial fields.
If both fields remain absent, the normal warning and status include its UTC
creation time, currency and total debit for lookup in Bitso's console. A separate
read-only tool checks the newest 100 withdrawals without scanning market trades
or opening the local database. Missing fee evidence is never treated as zero.

Validation on Linux/PHP 8.3.6, using synthetic credentials and offline transports:

* All 243 M3 checks pass, including 10,000 accounting events (9.813 seconds,
  81,788,928-byte peak memory).
* The Bitso transfer suite now has 73 checks. Its 30 additional checks cover
  individual reads, explicit zero fees, exact total/fee/net arithmetic, mismatched
  identities and financial fields, malformed payloads, retained authentication
  and HTTP error types, private-data exclusion, normal warning/status diagnostics,
  and blocked runtime authorization while evidence is missing.
* CLI help, required arguments and sanitized configuration failures pass without
  network access. PHP lint, whitespace and incremental patch application pass.

The individual endpoint may return the same incomplete fields as the list.
These tests do not establish the fee of the user's live withdrawal; its console
details are still needed if the new lookup cannot supply that evidence.

## Bitso Lightning invoice evidence

Incremental base: GitHub M3 `6b6f93b5593fbfd12cdad922b3e8773bb34637e0` plus
all previous delivered patches through `m3-withdrawal-details`. GitHub main was
checked again before this change. No dependency or schema migration is added.

The reported console entry says no fee. Its BOLT11 invoice was verified locally
with Python cryptography and with the new PHP parser: checksum and signature
valid, payment hash matching the supplied Bitso transaction, and invoice amount
exactly equal to the total debit. That establishes zero additional expense
charged to this balance; no network expense paid by another party is invented.

The adapter recognizes this evidence when an authenticated completed withdrawal
contains the invoice. It records zero fee only for an exact amount/hash match,
keeps the invoice digest for audit and removes invoice values from addresses.
The check tool exposes recognized zero-fee records without printing invoices.

Offline validation on Linux/PHP 8.3.6:

* All 278 M3 checks pass, including 10,000 accounting events (9.538 seconds,
  81,788,928-byte peak memory).
* The 108-check Bitso suite includes 35 new checks for independent signed
  synthetic fixtures, exact amount units, checksum/signature/identity validation,
  malformed and unsupported invoice rejection, list/detail reads, no fee inferred
  from network labels, correct wallet debit, replay and durable audit evidence.
* PHP lint, whitespace and application of the incremental patch pass.
* The user's actual invoice is absent from source, fixtures and the patch.

The fallback requires a mainnet BOLT11 amount and an explicit payee key. It does
not recover omitted payee keys, infer fees from current schedules, or access the
Bitso console. If the API omits the invoice as well as fee/net fields, it still
needs additional evidence; live reconciliation has not been verified here.

## Bitso Lightning hash lookup and diagnostics

Incremental base: the preceding `m3-bitso-lightning` patch, on GitHub M3
`6b6f93b5593fbfd12cdad922b3e8773bb34637e0` and the earlier delivered fixes.
The old message treated missing, malformed and conflicting hashes alike and
stopped before an individual lookup when an invoice was already present.

Missing hashes now permit one lookup by withdrawal ID. Verified list/detail
identity permits combining the list's invoice with the lookup's hash (or the
reverse). Explicit root/details `tx_hash` and `payment_hash` values must all
agree with the signed invoice. Invalid formats, conflicting hashes and
conflicting invoices remain blocked. Diagnostics identify fixed field paths,
states and hashed references without exposing private response values.

Offline validation on Linux/PHP 8.3.6:

* All 299 M3 checks pass, including 10,000 financial events (9.996 seconds,
  81,788,928-byte peak memory).
* The 129-check Bitso suite adds 21 checks covering missing/empty list hashes,
  split list/detail evidence, explicit hash fields, precise missing/invalid/
  mismatch diagnostics, privacy, conflicting fields/invoices, identity checks,
  retained HTTP errors, blocked authorization and unchanged financial state.
* Wallet reconciliation, replay and retained invoice audit evidence also pass
  using the new missing-hash lookup path.
* PHP lint, whitespace and incremental patch application pass.

No schema or dependency changes and no live exchange requests were made.
The supplied log does not establish which hash condition the live API returned;
the read-only checker now distinguishes those conditions. A genuine conflict
or evidence still missing after lookup continues to block reconciliation.

## Bitso console-confirmed Lightning payment hash

Incremental base: the preceding `m3-bitso-lightning-hash` patch. GitHub main
was checked again and remains M3 `6b6f93b5593fbfd12cdad922b3e8773bb34637e0`.

The read-only diagnostic confirms that both list and individual API responses
contain the invoice but omit the payment hash. Its invoice hash reference agrees
with the hash supplied from Bitso's console. An optional account-level
`bitso_lightning_payment_hashes` map now records that operator confirmation for
one withdrawal reference. No user-specific confirmation is hard-coded in source.

The confirmed hash must match the signed invoice. Zero fee still requires an
authenticated completed BTC withdrawal, a matching individual lookup and exact
equality of invoice amount and balance debit. It cannot override malformed or
conflicting API evidence. Normalized accounting records distinguish operator
confirmation from exchange-supplied hashes and enforce the account binding.

Offline validation on Linux/PHP 8.3.6:

* All seven M3 suites pass: 336 checks in total. The final Bitso suite has 166
  checks, including 37 new checks for configuration validation, operator
  provenance, lookup requirements, privacy, account and withdrawal scoping,
  rejected configuration replacement, conflicting evidence, exact amounts,
  invoice signatures, pending transfers, authoritative API fees and audit data.
* The financial suite processes 10,000 events in 9.647 seconds with an
  81,788,928-byte peak. Operator-confirmed withdrawals reconcile and replay
  without duplicating debits; cross-account ingestion rolls back.
* Later API enrichment preserves the original operator audit record. PHP lint,
  whitespace and incremental patch application pass.

There are no schema or dependency changes, live exchange requests or submitted
orders. The operator must add the account-specific config entry and restart the
daemon. The checker reports accepted records as `operator_bitso_lightning_invoice`;
checking the first page alone does not establish full account reconciliation.

## Generic Bitso withdrawal evidence

Incremental base: the preceding `m3-bitso-console-hash` patch. GitHub main was
checked again and remains M3 `6b6f93b5593fbfd12cdad922b3e8773bb34637e0`.
This change supersedes the account configuration workaround described above.

An authenticated completed withdrawal already associates its ID with its attached
invoice. If Bitso omits a separate payment hash, one matching individual lookup
confirms the record, and its verified signed invoice supplies the hash. Exact
amount equality and rejection of supplied conflicting hashes remain mandatory.
No per-withdrawal configuration is needed. The obsolete config key is discarded;
existing operator audit metadata remains historical evidence.

The reported MXN failure revealed another response format: Bitso's documented
SPEI service omits fee/net fields and defines its three protocols as fee-free.
The adapter recognizes only the documented MXN/praxis/SPEI classification,
checks matching individual withdrawal data and records its method-rule basis
explicitly. Explicit API fee/net amounts take precedence. This is a documented
method interpretation, including historical records, not verification of an
individual historical bank receipt. Unrecognized methods remain blocked and
now expose safe taxonomy and receipt-presence diagnostics.

Offline validation on Linux/PHP 8.3.6:

* All seven M3 suites pass: 353 checks, including 183 Bitso transfer checks.
* The financial suite processes 10,000 events in 9.602 seconds with an
  81,788,928-byte peak. Network requests and submitted orders remain zero.
* Generic invoice coverage includes unrelated accounts, IDs and amounts,
  invoices present only in one response, omitted hashes, supplied hash conflicts,
  exact amounts, signatures, obsolete-config removal and audit preservation.
* SPEI coverage includes all three documented protocols, explicit nonzero fees,
  missing/unknown/conflicting classifications, lookup identity mismatches,
  pending transactions, privacy, exact balance reconciliation and duplicate
  replay. Audit metadata distinguishes the method rule from an API-reported fee.
* PHP lint, whitespace validation and incremental patch application pass.

No schema or dependency changes were made. Live Bitso credentials were not used.
The user's MXN log does not include its taxonomy, so live applicability to that
particular withdrawal is not yet established. Re-run the read-only checker and
full reconciliation after restarting the daemon; passing the first page does
not establish completion of M3's remaining acceptance gates.

## Bitso withdrawal taxonomy diagnostics

Incremental base: `m3-bitso-generic-withdrawals`. GitHub main was checked again
and remains `6b6f93b5593fbfd12cdad922b3e8773bb34637e0`.

The live MXN failure reported four `unrecognized` values from both endpoints.
Those labels were produced by the diagnostic allowlist; they were not the
actual codes returned by Bitso. The output therefore cannot identify the method
or establish a fee. This patch displays bounded machine codes for the four
taxonomy fields, independently of the accounting adapter's supported methods.
It preserves credential redaction, excludes personal details and distinguishes
missing, nonstring and redacted values. No fee or reconciliation rules change.

Offline validation on Linux/PHP 8.3.6:

* All 274 targeted checks pass: 191 Bitso transfer checks, 57 synchronization
  diagnostic checks and 26 runtime checks.
* Eight new checks cover unknown codes in both list and lookup diagnostics,
  sanitized transport context, malformed values, URLs, identifiers, invoices,
  all four configured credentials and individual-lookup network failures.
* Unknown methods still block reconciliation. Existing invoice and SPEI
  accounting tests pass without changing the accepted fee evidence.
* PHP lint for changed PHP files, whitespace validation and incremental patch
  application pass. No live exchange requests or orders were made.

The actual method of the user's MXN withdrawal remains unknown. Re-running the
read-only checker with this patch supplies the missing classification evidence;
the earlier redacted output does not justify a new zero-fee assumption.

## Bitso internal transfers

Incremental base: `m3-bitso-taxonomy-diagnostics`. GitHub main was checked again
and remains `6b6f93b5593fbfd12cdad922b3e8773bb34637e0`.

The updated live diagnostic exposes `bt` for method, network, protocol and
integration in both completed withdrawal responses. The adapter now interprets
that exact tuple as internal Bitso Transfer, whose public service documentation
describes transfers between Bitso users as fee-free for any supported currency:
<https://bitso.com/products/transfer>. The API-code mapping is an interpretation
of the observed format; the public page documents the service fee, not the enum.

The rule requires a matching individual lookup, including withdrawal ID,
currency, timestamp, completed status and exact total debit. Explicit fee or net
amount fields take precedence. Missing, mixed or contradictory classification
data still blocks reconciliation. The entire debit becomes principal and its
zero-fee evidence records `bitso_transfer_bt_v1`, `exchange_documentation`, the
currency, the four classification codes and the documentation URL. This records
the method-rule basis, including for historical records; it does not represent
an independently verified historical receipt. No customer IDs, withdrawal
references, amounts, dates or hashes are hardcoded into the rule.

Offline validation on Linux/PHP 8.3.6:

* All seven M3 suites pass: 407 checks, including 237 Bitso transfer checks.
* The 46 new checks cover unrelated accounts and currencies, exact amounts,
  missing/mixed classifications, inconsistent list/lookup identity, pending and
  failed transfers, explicit fee precedence, rejected audit evidence, privacy,
  financial reconciliation, duplicate replay and later fee-evidence changes.
* A synthetic internal withdrawal debits its principal once, without a fee
  expense. Later explicit zero-fee evidence preserves the original audit trail;
  a contradictory later fee rolls back without changing balances or revision.
* The financial suite processes 10,000 events in 9.493 seconds with an
  81,788,928-byte peak. Network requests and submitted orders remain zero.
* PHP lint, whitespace validation and incremental patch application pass.

No schema or dependency changes were made. Live credentials were not used. The
read-only checker reports accepted records in
`documented_bitso_transfer_zero_fees`. Live full-history reconciliation remains
to be run after applying the patch and restarting the daemon; a successful
first-page check does not complete M3's remaining acceptance gates.

## Local reconciliation failure diagnostics

Base: GitHub `Trademinator/client` commit
`1d6bb4cdd50241e5be7baf924d09be1ffe56c05d` (bitso compatibility work).

The reported `RuntimeException` was hidden by the safe diagnostic allowlist.
The former `reconcile_account` fallback also covered failures in preparation,
deposit pricing, transfer matching and hard-point work, so the log alone did not
identify the failing stage or accounting invariant.

This patch adds fixed ledger/archive messages and an explicit explanation for
the existing inventory/quote currency-role restriction. It retains the actual
stage across async cleanup and reports an allowlisted project-relative source
filename plus line. Unknown messages, absolute paths, stack arguments, signed
URLs and exchange response bodies remain private. Financial checks, accounting,
retry backoff, database schema and dependencies are unchanged.

Offline validation on Linux/PHP 8.3.6:

* All seven M3 suites pass: 424 checks, including 74 diagnostic checks.
* The 17 added checks exercise real currency-role and cumulative-fill failures,
  rejected-write rollback, private dynamic storage errors, source-path spoofing,
  an async deposit-pricing failure, status/console parity, blocked readiness,
  unchanged exponential backoff and successful-retry cleanup.
* The financial suite processes 10,000 events in 11.063 seconds with an
  81,788,928-byte peak. No exchange requests or orders are made.
* All Client PHP files pass syntax checks. Whitespace validation and patch
  application against the exact GitHub base pass.

The synthetic currency-role failure reproduces the old generic diagnostic, but
does not establish the cause on the user's account. Restart the daemon and use
the resulting stage, reason and source location to identify that cause. Live
reconciliation remains unverified; this patch improves diagnosis without
bypassing an unresolved financial check.


## Currency inventory across market roles (schema 5)

Incremental base: GitHub `Trademinator/client`
`1d6bb4cdd50241e5be7baf924d09be1ffe56c05d`, with the previously delivered
reconciliation-diagnostics patch applied. GitHub main was rechecked at that same
commit before delivery. The user's explicit `inventory_currency_role_conflict`
now identifies the legacy M1 restriction as the failing check.

M3 now attributes currency outflows across the account's acquisition lots. Real
SELL allocations retain both the selling market and the inventory-owning market.
BUY quote spending and third-currency fees consume inventory separately. A real
SELL's net quote receipt is an acquisition fragment with its original fill
provenance. Unknown valuation conversions remain unknown; no balancing financial
rows or zero-cost prices are fabricated. The legacy M1 opt-in boundary remains.

Earlier cash operations are attributed when their currency first becomes an
inventory base, without changing financial identity, amounts or timestamps.
Transfers reconstruct all consumed source lots and convert each known basis in
its own original quote currency. A receiver can retrieve a missing historical
conversion after another account reveals the source denomination, without
replacing an existing deposit basis or rate.

Same-timestamp executions release credit only after all their tracked debits
are funded, including third-currency fees. Opaque trade IDs are only deterministic
tie breakers, not chronology evidence. Unfunded cycles and unresolved same-time
funding schedules roll back; the latter report
`ambiguous_same_timestamp_funding`. The algorithm does not search every possible
ordering of an ambiguous history group.

Schema 5 adds the inventory owner to allocations, preserves account and SELL
foreign keys, and migrates existing allocations with their original owner. The
verified pre-migration backup and transactional rollback are tested. Old archive
snapshots remain readable without rewriting their files. New inventory roles
after H0 reconstruct the original opening cash exactly once, including zero-cash
boundaries, subsequent promotions and late cost corrections.

Offline validation on Linux/PHP 8.3.6:

* All eleven M3 suites pass: 549 checks. These include 58 currency-role checks,
  28 archive-transition checks, 27 schema-5 checks, 11 missing-rate recovery checks,
  75 diagnostic checks and the existing transport, runtime and Bitso coverage.
* The financial suite processes and replays 10,000 events in 12.997 seconds with
  a 98,566,144-byte peak. No exchange requests or orders are made.
* Tests cover global LIFO, principal and fee conservation, source ownership,
  mixed valuation quotes, chronological rollback, same-time trade and fee
  dependencies, restart/idempotence, checkpoint invalidation, H/H1 promotion,
  post-capture activity, immutable archive hashes and late transfer matching.
* All Client PHP files pass syntax checks. Whitespace validation and incremental
  patch application against the declared base pass.

No dependencies, private credentials or account-specific mappings are added.
Unknown remaining acquisition cost still blocks market readiness and candidate
H eligibility. Live Bitso history and native platform acceptance remain to be
verified on the user's machines; these offline tests do not establish them.


## Outflow evidence and deposit-pricing progress

Incremental base: `trademinator-client-m3-currency-inventory.patch` already
applied over the reconciliation-diagnostics patch and GitHub commit
`1d6bb4cdd50241e5be7baf924d09be1ffe56c05d`. GitHub main was checked again and
remains at that commit.

The reported status has two failed attempts and an ongoing attempt whose total
elapsed time is 2,037,218 ms (33 minutes 57 seconds), currently at deposit pricing.
Its previous generic outflow error does not identify which currency, transaction
or acquisition is missing. It cannot establish a fee, opening balance or
accounting adjustment. This patch supplies that evidence without weakening the
quantity invariant.

An isolated inventory preflight now runs before public historical-price work.
Failures report the normalized outflow date, kind, symbol, required/available
amounts and exact shortfall. Later acquisitions are reported separately and never
backdated. Hashed references remain stable across preview/live reconstruction;
private exchange identifiers and response data stay out of events and status.

Pricing now displays per-stage call counts, current provider/candle request and
deposit progress. Providers and catalogs are reused; bounded caches retain both
successful and unavailable historical results within an attempt. Duplicate
candle fetches and unnecessary second bridge legs are eliminated. The receiving
account's own market quotes no longer trigger transfer FX lookups. Missing FX
from another account still recovers on a later sync. Async public methods time
out using the existing exchange timeout; cancellation clears timers even when
an underlying adapter does not settle its cancelled promise.

Offline validation on Linux/PHP 8.3.6:

* All thirteen M3 suites pass: 592 checks, including 16 new inventory diagnostic
  checks and 27 new public pricing/progress checks.
* Coverage includes exact 24-place shortfalls, BUY/SELL/withdrawal/third-currency
  failures, chronological exclusion of later inventory, repeatable references,
  preview/live diagnostic parity, unchanged live rows after successful and failed
  previews, rejected-write rollback, and unknown-cost readiness protection.
* Pricing checks cover provider reuse, candle/inverse caching, missing-result
  retry on a later snapshot, rejection of future candles, timeout/cancellation,
  owned-client cleanup, visible pending requests, redacted progress, normal info
  events, no self-account transfer FX, and shutdown without late financial writes.
* The 10,000-event financial suite passes in 14.187 seconds with a 98,566,144-byte
  peak. Network requests and submitted orders remain zero.
* All Client PHP files pass syntax checks; whitespace and incremental patch
  application checks pass. Delivered files match the tested source exactly.

Schema 5 and dependencies are unchanged. No fee or balance adjustment is made.
The precise missing acquisition on the live account still requires the new
outflow diagnostic; these tests do not establish that history's completeness.

## Bitso account-wide trade history

Incremental base: `trademinator-client-m3-outflow-pricing.patch` applied after
the currency-inventory and reconciliation-diagnostics patches, over GitHub main
`1d6bb4cdd50241e5be7baf924d09be1ffe56c05d`. Main was checked again before this work.

The new diagnostic identifies an earlier USD withdrawal with no attributable
inventory. Reviewing that path found a confirmed coverage defect: the Client
enumerated today's available spot markets and asked the pinned CCXT adapter
for each book's trades. Executions from removed books were never requested.
The adapter also required a current market for independent order-trade lookup.

Bitso documents an optional `book` on
[`GET /user_trades`](https://docs.bitso.com/bitso-api/docs/user-trades).
Its [change history](https://docs.bitso.com/bitso-api/docs/history-of-changes)
records that correction on 2021-07-19. The read extension now requests unfiltered
account-wide trade pages, preserves the server's marker order and exact string
identities, and verifies historical fills by order ID without requiring an
available market. Currency identities and the configured/Hard H0 boundary remain
intact. Existing open/uncertain orders cannot be resolved from missing details.

The [open-order endpoint](https://docs.bitso.com/bitso-api/docs/list-open-orders)
documents an account-wide list and limit, but no marker. Initial and confirming
reads therefore request that list with a 500-row cap; reaching the cap blocks
completeness. Old example configurations remain accepted. Partial filters,
conflicting evidence, unsupported margin activity and invalid payloads fail with
explicit local diagnostics.

Offline validation on Linux/PHP 8.3.6:

* All fourteen M3 suites pass: 642 checks, including 50 new account-history checks.
* A regression reproduces a USD withdrawal shortfall when a removed market's
  earlier sale is omitted. The full independently verified history reconciles,
  replays without duplicates, and creates no balancing operation.
* Real pinned CCXT signing, implicit GET methods and parsing run against an
  intercepted HTTP fixture. Tests cover 205 trades over three pages, overlaps,
  equal timestamps, markers above PHP integer range, history boundaries,
  saturation, non-progress, page budgets and malformed/contradictory evidence.
* Available terminal details keep their actual status; missing details keep
  unknown original terms. Current-market catalogs are not augmented with
  fabricated trading definitions. USD and USDC stay distinct.
* The 10,000-event financial suite passes in 12.607 seconds with a 98,566,144-byte
  peak. Network requests and submitted orders remain zero.
* PHP syntax, whitespace and incremental patch application checks pass.

This fixes a demonstrated import gap, not a verified explanation of the live
account's particular withdrawal. Restart and reconcile to determine whether the
additional records supply that acquisition. If the shortfall remains, inspect
the earlier exchange deposit/trade/conversion evidence before deciding on any
opening inventory. No schema, dependency or account-specific mapping is added.

## Bitso conversion-history diagnostic

Base: GitHub `Trademinator/client` commit
`b1974113f91a6b57d759b56e2970919dbad53ae2` (`more bitso work`). The repository's
152 source files matched the preceding delivered patches before this update.

The new `tools/m3-bitso-history-check.php` is a read-only diagnostic. It collects
a requested time window from trades, fundings, withdrawals and RFQ conversions,
retaining raw currency/asset distinctions and showing CCXT timestamps beside
source timestamps. It does not change daemon reconciliation or create financial
records. API retention and the coverage of legacy app conversions remain to be
verified using the live report.

Validation on Linux/PHP 8.3.6 with the pinned dependency lockfile:

* All 15 M3 suites pass: 699 checks, including 57 new diagnostic/replay checks.
* The real CCXT request/signing/parser path runs through an intercepted HTTP
  transport. No exchange credentials, network requests or submitted orders.
* Tests verify exact decimal amounts, raw USD/USDC distinctions, timezone
  normalization, omitted private fields, source-specific errors, API cutoffs,
  overlapping pages, equal-time boundaries and markers above PHP integer range.
* RFQ tests verify its exact signed path/query, persistent date filters, short
  and empty pages with continuation tokens, arbitrary response ordering, cycles,
  unavailable endpoints and pending statuses without inventory creation.
* Both USD and USDC deposit/trade/withdrawal fixtures reconcile in all six input
  permutations. Future proceeds, missing conversions and currency mismatches
  still reject the withdrawal. Successful preflight leaves the live data intact.
* CLI checks validate timezone/page-budget errors, safe missing-credential
  reporting and no creation of the configured local data directory.
* The 10,000-event financial suite passes in 13.775 seconds with a 98,566,144-byte
  peak. PHP lint, Composer validation, whitespace and clean-base patch application
  pass.

This diagnostic establishes what each API returns, not whether the complete
account can reconcile. An empty RFQ result does not disprove a historical app
conversion; the tool never treats absence as a zero balance or an adjustment.
