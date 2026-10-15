# Deterministic M1 fixtures

`tools/m1-simulate.php` generates synthetic ledger events directly. It never connects
to an exchange, reads credentials, or submits orders. The market symbols are labels;
the prices are deliberately artificial.

The workload uses three markets sharing one USD wallet: XRP/USD, ATOM/USD, and
BTC/USD. Every round trip is BUY 10 units at USD 10, then SELL 10 units at USD 12.
Each order arrives first as a partial fill of 4 and then as a cumulative final fill
of 10. Quote-currency fees are 0.1% of the filled quote amount. SELL allocations
consume the BUY in two parts, 4 and 6. One round trip therefore increases USD by
19.78 and leaves no base asset. The independent expected wallet starts at USD
1,000,000. With 10,000 completed orders, the final trading wallet is USD 1,098,900.

10,000 means completed orders (5,000 round trips), not the 20,000 partial/final order
observations. The virtual clock spans ten days beginning 2026-01-01 UTC. This
exercises the daily backup schedule without waiting for real days to pass.

Every 500 completed orders, except at the end, this synthetic workload explicitly
approves a fixture Hard T1 promotion. All checkpoints and source records remain in
the permanent archives while the live ledger starts from the new baseline. This
tests repeated archival boundaries and bounds the active replay workload. It is
not an automatic approval policy for real accounts; those still require the user
to approve each specific candidate.

Additional isolated scenarios exercise dust, stale Hard T1 approvals, interrupted
backup/archive publication, corruption recovery, and data-directory isolation.
These fixture directories and their archives are disposable. The simulator refuses
an existing data directory and requires an explicit destination.

Run a full workload:

```sh
php tools/m1-simulate.php --data-dir=/absolute/new/path/m1-fixture --orders=10000 --scenario=all
```

Use `--scenario=load` to run only trading, reconciliation, and backup scheduling.
The JSON report records checks and counts. A nonzero exit status means a check failed.
