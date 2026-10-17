<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use React\Promise\PromiseInterface;
use RuntimeException;

use function React\Async\async;
use function React\Async\await;

/** Fill gaps in the pinned CCXT read adapter; signing, HTTP and throttling stay in CCXT. */
class BitsoReadClient extends \ccxt\async\bitso
{
    private ?\WeakMap $withdrawalFailures = null;

    public function describe(): mixed
    {
        return $this->deep_extend(parent::describe(), ['has' => ['fetchWithdrawals' => true]]);
    }

    public static function is_json_encoded_object($input): bool
    {
        // CCXT 4.5.57 indexes input[0] even for an empty HTTP error body.
        return is_string($input) && $input !== '' && parent::is_json_encoded_object($input);
    }

    public function fetch_my_trades(?string $symbol = null, ?int $since = null, ?int $limit = 25, $params = []): PromiseInterface
    {
        return async(function () use ($symbol, $since, $limit, $params): array {
            // Bitso's book filter is optional. Scanning today's available_books
            // loses executions in removed markets, including their quote receipts.
            // https://docs.bitso.com/bitso-api/docs/user-trades
            if ($symbol !== null || $since !== null || array_diff(array_keys($params), ['marker', 'sort'])
                || (isset($params['sort']) && $params['sort'] !== 'desc') || ($limit !== null && $limit < 1)) {
                throw new RuntimeException('Bitso trade history requires unfiltered backward marker pagination.');
            }
            $request = ['limit' => min(100, $limit ?? 100), 'sort' => 'desc'];
            if (array_key_exists('marker', $params)) {
                // Trade IDs are strings; intval can corrupt IDs above PHP_INT_MAX.
                $request['marker'] = Normalize::id($params['marker']);
            }
            await($this->load_markets());
            $rows = $this->privateTrades($this->historyPayload(await($this->privateGetUserTrades($request)), $request['limit']));
            $previous = PHP_INT_MAX;
            foreach ($rows as $row) {
                if ($row['timestamp'] > $previous) {
                    throw new RuntimeException('Bitso trade history is not in backward chronological order.');
                }
                $previous = $row['timestamp'];
            }
            // Preserve server order for the marker, including equal timestamps.
            // CCXT parse_trades sorts locally and is unsuitable for this boundary.
            return $rows;
        })();
    }

    public function fetch_order_trades(string $id, ?string $symbol = null, ?int $since = null, ?int $limit = null, $params = []): PromiseInterface
    {
        return async(function () use ($id, $symbol, $since, $limit, $params): array {
            if ($since !== null || $limit !== null || $params !== []) {
                throw new RuntimeException('Bitso order trade verification requires the complete unfiltered order history.');
            }
            await($this->load_markets());
            // This endpoint accepts an order ID, not a current market. Parse the
            // book recorded on each fill so delisted pairs remain verifiable.
            $rows = $this->historyPayload(await($this->privateGetOrderTradesOid(['oid' => Normalize::id($id)])));
            return $this->privateTrades($rows, $id, $symbol);
        })();
    }

    public function fetch_open_orders(?string $symbol = null, ?int $since = null, ?int $limit = null, $params = []): PromiseInterface
    {
        return async(function () use ($symbol, $since, $limit, $params): array {
            if ($symbol !== null || $since !== null || $params !== [] || ($limit !== null && $limit < 1)) {
                throw new RuntimeException('Bitso open orders require an unfiltered account-wide list.');
            }
            await($this->load_markets());
            // The documented endpoint has a limit (default 500), no marker.
            // Never call a capped list complete or trim it using CCXT's parser.
            // https://docs.bitso.com/bitso-api/docs/list-open-orders
            $limit = min(500, $limit ?? 500);
            $rows = $this->historyPayload(await($this->privateGetOpenOrders(['limit' => $limit])), $limit);
            if (count($rows) === $limit) {
                throw new RuntimeException('Bitso open-order list reached its limit; completeness cannot be confirmed.');
            }
            $out = [];
            $seen = [];
            foreach ($rows as $raw) {
                if (!is_array($raw)) { throw new RuntimeException('Bitso private history response is invalid.'); }
                $expected = $this->spotSymbol($raw);
                $row = parent::parse_order($raw);
                $order = Normalize::order($row);
                if ($order['symbol'] !== $expected || !in_array($order['status'], ['open', 'partially_filled'], true)
                    || isset($seen[$order['id']])) {
                    throw new RuntimeException('Bitso open-order identity, market or status is invalid.');
                }
                $seen[$order['id']] = true;
                $out[] = $row;
            }
            return $out;
        })();
    }

    private function historyPayload(mixed $response, ?int $limit = null): array
    {
        if (!is_array($response) || ($response['success'] ?? null) !== true
            || !is_array($response['payload'] ?? null) || !array_is_list($response['payload'])
            || ($limit !== null && count($response['payload']) > $limit)) {
            throw new RuntimeException('Bitso private history response is invalid.');
        }
        return $response['payload'];
    }

    private function privateTrades(array $rows, ?string $orderId = null, ?string $symbol = null): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $raw) {
            if (!is_array($raw) || !isset($raw['major'], $raw['minor'], $raw['fees_amount'], $raw['fees_currency'])
                || !in_array($raw['side'] ?? null, ['buy', 'sell'], true)) {
                throw new RuntimeException('Bitso private trade evidence is incomplete.');
            }
            $expected = $this->spotSymbol($raw);
            $row = parent::parse_trade($raw);
            $trade = Normalize::trade($row);
            if ($trade['symbol'] !== $expected || ($symbol !== null && $trade['symbol'] !== $symbol)
                || ($orderId !== null && $trade['order'] !== $orderId)) {
                throw new RuntimeException('Historical order trade identity, market or side differs.');
            }
            if (isset($seen[$trade['id']]) && $seen[$trade['id']] !== $trade) {
                throw new RuntimeException('Conflicting exchange trade identity.');
            }
            $seen[$trade['id']] = $trade;
            $out[] = $row;
        }
        return $out;
    }

    private function spotSymbol(array $raw): string
    {
        if (isset($raw['margin_order_type']) && $raw['margin_order_type'] !== '') {
            throw new RuntimeException('Bitso margin activity is unsupported by spot accounting.');
        }
        $book = $raw['book'] ?? null;
        if (!is_string($book) || count($parts = explode('_', $book)) !== 2 || in_array('', $parts, true)) {
            throw new RuntimeException('Bitso private history market is invalid.');
        }
        $base = $this->safe_currency_code($parts[0]);
        $quote = $this->safe_currency_code($parts[1]);
        if ((isset($raw['major_currency']) && (!is_string($raw['major_currency']) || $this->safe_currency_code($raw['major_currency']) !== $base))
            || (isset($raw['minor_currency']) && (!is_string($raw['minor_currency']) || $this->safe_currency_code($raw['minor_currency']) !== $quote))) {
            throw new RuntimeException('Bitso private history currencies conflict with its order book.');
        }
        return Normalize::symbol($base.'/'.$quote);
    }

    public function fetch_deposits(?string $code = null, ?int $since = null, ?int $limit = null, $params = []): PromiseInterface
    {
        return $this->transferPage('deposit', $code, $since, $limit, $params);
    }

    public function fetch_withdrawals(?string $code = null, ?int $since = null, ?int $limit = null, $params = []): PromiseInterface
    {
        return $this->transferPage('withdrawal', $code, $since, $limit, $params);
    }

    private function transferPage(string $kind, ?string $code, ?int $since, ?int $limit, array $params): PromiseInterface
    {
        return async(function () use ($kind, $code, $since, $limit, $params): array {
            // Full account history only. Identifier/status/method filters can hide
            // financial activity; Bitso's identifier filters even override limit.
            if ($code !== null || $since !== null || array_diff(array_keys($params), ['marker', 'sort'])
                || (isset($params['sort']) && $params['sort'] !== 'desc')
                || ($limit !== null && $limit < 1)) {
                throw new RuntimeException('Bitso transfer history requires unfiltered backward marker pagination.');
            }
            $request = ['limit' => min(100, $limit ?? 100)];
            if (array_key_exists('marker', $params)) {
                $request['marker'] = Normalize::id($params['marker']);
            }
            await($this->load_markets());
            // Both GET methods already exist in the pinned CCXT implicit API.
            // https://docs.bitso.com/bitso-payouts-funding/docs/fundings
            // https://docs.bitso.com/bitso-payouts-funding/docs/list-your-withdrawals
            $response = await($kind === 'deposit' ? $this->privateGetFundings($request) : $this->privateGetWithdrawals($request));
            if (!is_array($response) || ($response['success'] ?? null) !== true
                || !is_array($response['payload'] ?? null) || !array_is_list($response['payload'])
                || count($response['payload']) > $request['limit']) {
                throw new RuntimeException('Bitso transfer history response is not a valid bounded payload list.');
            }
            $out = [];
            $previousTimestamp = PHP_INT_MAX;
            foreach ($response['payload'] as $raw) {
                if (!is_array($raw) || !isset($raw[$kind === 'deposit' ? 'fid' : 'wid'])
                    || isset($raw[$kind === 'deposit' ? 'wid' : 'fid'])
                    || !in_array($raw['status'] ?? null, ['pending', 'processing', 'in_progress', 'complete', 'failed'], true)) {
                    throw new RuntimeException('Bitso transfer identity, direction or status is invalid.');
                }
                $row = parent::parse_transaction($raw);
                Normalize::id($row['id']);
                $timestamp = Normalize::timestamp($row);
                if ($timestamp > $previousTimestamp) {
                    throw new RuntimeException('Bitso transfer history is not in backward chronological order.');
                }
                $previousTimestamp = $timestamp;
                if (in_array($raw['status'], ['processing', 'in_progress'], true)) {
                    $row['status'] = 'pending';
                }
                if ($kind === 'withdrawal' && $row['status'] === 'ok') {
                    // Bitso amount is the TOTAL debit. M3 stores the net transfer
                    // plus its confirmed expense, so they must sum to that debit.
                    $gross = Numbers::decimal($raw['amount'] ?? null);
                    $details = is_array($raw['details'] ?? null) ? $raw['details'] : [];
                    if (!isset($details['fee']) && !isset($details['amount_to_send'])) {
                        $details = $this->withdrawalDetails($row, $gross, $raw);
                    }
                    $fee = isset($details['fee']) ? Numbers::decimal($details['fee']) : null;
                    $net = isset($details['amount_to_send']) ? Numbers::decimal($details['amount_to_send']) : null;
                    if (($fee !== null && bccomp($fee, $gross, 24) > 0)
                        || ($net !== null && bccomp($net, $gross, 24) > 0)) {
                        throw new RuntimeException('Bitso withdrawal total differs from net amount plus fee.');
                    }
                    $fee ??= Numbers::sub($gross, $net);
                    $net ??= Numbers::sub($gross, $fee);
                    if (bccomp($net, '0', 24) <= 0 || bccomp(Numbers::add($net, $fee), $gross, 24) !== 0) {
                        throw new RuntimeException('Bitso withdrawal total differs from net amount plus fee.');
                    }
                    $row['amount'] = $net;
                    $row['fee'] = ['currency' => Normalize::currency($row['currency']), 'cost' => $fee];
                }
                // Classify while native routing and external evidence are still present.
                // Carry only the safe descriptor; raw personal details are removed below.
                $row['m3_transfer_label'] = TransferLabels::suggest('bitso', $row, $kind);
                // Preserve server order for the marker, including equal timestamps.
                // Pending/failed entries remain in the page for correct pagination.
                // An invoice contains a payment secret, not a reusable wallet address.
                foreach (['address', 'addressTo', 'addressFrom'] as $field) {
                    if (is_string($row[$field] ?? null) && preg_match('/^(?:lightning:)?ln(?:bc|tb)/i', $row[$field])) {
                        $row[$field] = null;
                    }
                }
                unset($row['info']);
                $out[] = $row;
            }
            return $out;
        })();
    }

    /** One bounded GET may supply fields omitted from the list; never guess a fee. */
    private function withdrawalDetails(array &$listed, string $gross, array $raw): array
    {
        $credentials = [$this->apiKey, $this->secret, $this->password, $this->uid];
        $context = WithdrawalEvidenceError::withdrawalContext($listed, $gross)
            + WithdrawalEvidenceError::taxonomyContext($raw, credentials: $credentials)
            + ['phase' => 'withdrawal_invoice_validation', 'withdrawal_lookup_attempted' => false];
        try {
            $invoiceAmounts = $this->invoiceAmounts($raw, $listed, $gross);
            if ($invoiceAmounts !== null) {
                return $invoiceAmounts;
            }
            $context['phase'] = 'withdrawal_detail_lookup';
            $context['withdrawal_lookup_attempted'] = true;
            $response = await($this->privateGetWithdrawalsWid(['wid' => rawurlencode(Normalize::id($listed['id']))]));
            if (!is_array($response) || ($response['success'] ?? null) !== true
                || !is_array($response['payload'] ?? null) || !array_is_list($response['payload'])
                || count($response['payload']) !== 1 || !is_array($response['payload'][0])) {
                throw new RuntimeException('Bitso individual withdrawal response must contain exactly one record.');
            }
            $lookup = $response['payload'][0];
            $context = array_replace($context, WithdrawalEvidenceError::taxonomyContext($raw, $lookup, $credentials));
            if (!isset($lookup['wid']) || isset($lookup['fid']) || ($lookup['status'] ?? null) !== 'complete') {
                throw new RuntimeException('Bitso individual withdrawal differs from the completed list record; repeat the account snapshot.');
            }
            $row = parent::parse_transaction($lookup);
            if (Normalize::id($row['id']) !== Normalize::id($listed['id'])
                || Normalize::currency($row['currency']) !== Normalize::currency($listed['currency'])
                || Normalize::timestamp($row) !== Normalize::timestamp($listed)
                || bccomp(Numbers::decimal($lookup['amount'] ?? null), $gross, 24) !== 0) {
                throw new RuntimeException('Bitso individual withdrawal differs from the completed list record; repeat the account snapshot.');
            }
            $details = is_array($lookup['details'] ?? null) ? $lookup['details'] : [];
            if (!isset($details['fee']) && !isset($details['amount_to_send'])) {
                // Keep both records: the list may contain the invoice while the
                // individual lookup supplies its hash, or vice versa.
                $invoiceAmounts = $this->invoiceAmounts($raw, $listed, $gross, $lookup);
                if ($invoiceAmounts !== null) {
                    return $invoiceAmounts;
                }
                if (isset($listed['txid'], $row['txid']) && strtolower($listed['txid']) !== strtolower($row['txid'])) {
                    throw new RuntimeException('Bitso individual withdrawal differs from the completed list record; repeat the account snapshot.');
                }
                $listed['txid'] ??= $row['txid'];
                $evidence = BitsoSpei::zeroFeeEvidence($raw, $lookup, $gross)
                    ?? BitsoInternalTransfer::zeroFeeEvidence($raw, $lookup, $listed, $gross);
                if ($evidence !== null) {
                    $listed['network'] = $evidence['network'];
                    $listed['m3_fee_evidence'] = $evidence;
                    return ['fee' => '0', 'amount_to_send' => $gross];
                }
                throw new WithdrawalEvidenceError($listed, $gross, $raw, $lookup, $credentials);
            }
            return $details;
        } catch (\Throwable $error) {
            $this->withdrawalFailures ??= new \WeakMap();
            $this->withdrawalFailures[$error] = $context;
            // Authentication and network exceptions retain their exact class.
            throw $error;
        }
    }

    private function invoiceAmounts(array $raw, array &$row, string $gross, ?array $lookup = null): ?array
    {
        $evidence = LightningInvoice::zeroFeeEvidence($raw, $row, $gross, $lookup);
        if ($evidence === null) {
            return null;
        }
        $row['m3_fee_evidence'] = $evidence;
        $row['network'] = 'LIGHTNING';
        $row['txid'] = $evidence['payment_hash'];
        return ['fee' => '0', 'amount_to_send' => $gross];
    }

    /** Validated amounts/dates and a hashed reference only; no raw response or ID. */
    public function failureContext(\Throwable $error): array
    {
        return $this->withdrawalFailures[$error] ?? [];
    }
}
