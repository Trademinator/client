<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

/** Safe financial context constructed from normalized ledger evidence, never raw exchange replies. */
final class InventoryEvidenceError extends \RuntimeException
{
    public readonly array $diagnostic;

    public function __construct(array $event, string $required, string $shortfall, array $pool, array $completed, bool $ambiguous = false)
    {
        $currency = Normalize::currency($event['currency']);
        $at = Numbers::iso(Numbers::ms($event['at']));
        $required = Numbers::decimal($required);
        $shortfall = Numbers::decimal($shortfall);
        $available = Numbers::sub($required, $shortfall);
        $kind = in_array($event['kind'], ['sell','quote_spend','withdrawal','fee'], true) ? $event['kind'] : 'outflow';
        $symbol = isset($event['symbol']) ? Normalize::symbol($event['symbol']) : null;
        $row = $event['row'];
        $identity = [$row['account_id'], $row['exchange_order_id'] ?? $row['exchange_operation_id'], $event['sequence'], $kind, $currency];
        $later = '0';
        $pending = '0';
        $next = null;
        foreach ($pool as $lot) {
            if ($lot['currency'] !== $currency || bccomp($lot['quantity'], '0', 24) === 0) { continue; }
            $when = $lot['available_at'] ?? $lot['acquired_at'];
            if ($when > $event['at']) {
                $later = Numbers::add($later, $lot['quantity']);
                $next = $next === null || $when < $next ? $when : $next;
            } elseif (isset($lot['execution']) && !isset($completed[$lot['execution']])) {
                $pending = Numbers::add($pending, $lot['quantity']);
            }
        }
        $this->diagnostic = ['reason' => $ambiguous ? 'ambiguous_same_timestamp_funding' : 'inventory_shortfall',
            'outflow_ref' => substr(hash('sha256', Numbers::json($identity)), 0, 16),
            'outflow_at' => $at, 'outflow_kind' => $kind, 'outflow_currency' => $currency, 'symbol' => $symbol,
            'outflow_required' => $required, 'inventory_available' => $available, 'inventory_shortfall' => $shortfall,
            'later_acquisition_quantity' => $later, 'next_acquisition_at' => $next,
            'pending_execution_acquisition_quantity' => $pending];
        $label = match ($kind) { 'quote_spend' => 'BUY quote payment', 'sell' => 'SELL debit', 'withdrawal' => 'Withdrawal debit', 'fee' => 'Fee debit', default => 'Outflow' };
        parent::__construct($label.' at '.$at.($symbol === null ? '' : ' ('.$symbol.')')
            .' requires '.$required.' '.$currency.'; only '.$available.' '.$currency
            .' is attributable at that time (shortfall '.$shortfall.' '.$currency.').'
            .($ambiguous ? ' Same-timestamp funding dependencies remain unresolved.' : ' Check earlier acquisition history and the opening inventory.'));
    }
}
