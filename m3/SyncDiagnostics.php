<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use Throwable;

/** Translate trusted error categories/codes, never publish response bodies or request arguments. */
final class SyncDiagnostics
{
    // Bitso's documented read/auth/pagination errors. Keep messages local: API responses
    // may echo credentials, signatures, personal details, or full signed request URLs.
    // https://docs.bitso.com/bitso-api/docs/authentication-error-02-http-401
    // https://docs.bitso.com/bitso-api/docs/validation-errors-03-http-400
    // https://docs.bitso.com/bitso-api/docs/throttling-errors-08-http-420
    private const BITSO = [
        '0201' => 'Authentication rejected; verify the key and secret.',
        '0202' => 'This API key lacks permission for this operation; check its read permissions.',
        '0203' => 'Login token expired or invalid.',
        '0204' => 'PIN rejected.',
        '0205' => 'Login attempt limit reached.',
        '0206' => 'Nonce has an unsupported type.',
        '0207' => 'Nonce rejected; check the system clock and concurrent use of this key.',
        '0208' => 'This operation requires authentication.',
        '0209' => 'Two-factor verification is required.',
        '0210' => 'Device verification is required.',
        '0211' => 'Device verification failed; check IP restrictions and token validity.',
        '0212' => 'Bitso denied access.',
        '0213' => 'The current IP address is not permitted.',
        '0214' => 'reCAPTCHA verification failed.',
        '0215' => 'Request signature rejected; verify the key/secret pair.',
        '0216' => 'Bitso restricted this account; contact Bitso.',
        '0301' => 'Bitso does not recognize this order book.',
        '0302' => 'Time-frame parameter rejected.',
        '0303' => 'A required request field is absent.',
        '0304' => 'A required request field has an invalid value.',
        '0310' => 'Withdrawal ID is unknown or belongs to another account.',
        '0311' => 'Funding ID is unknown or belongs to another account.',
        '0312' => 'Order details are unavailable: the ID may be unknown, owned by another account, or outside Bitso history retention.',
        '0313' => 'Currency parameter rejected.',
        '0320' => 'Pagination parameters rejected; check the history cursor, sort and limit.',
        '0321' => 'Trade ID is unknown.',
        '0326' => 'The API key is inactive.',
        '0327' => 'Bitso does not offer this service at the requesting location.',
        '0328' => 'Bitso does not offer this service in the requesting country.',
        '0346' => 'Accept the updated terms in your Bitso account.',
        '0372' => 'A request field has the wrong value type.',
        '0373' => 'Request expired; check the system clock.',
        '0377' => 'Order matched but trade records are still processing; retry reconciliation.',
        '0378' => 'Order has no match yet.',
        '0801' => 'Request-rate limit reached; synchronization will retry with backoff.',
        '0802' => 'Operation-attempt limit reached; synchronization will retry with backoff.',
    ];

    private const LOCAL = [
        'Bitso trade history requires unfiltered backward marker pagination.',
        'Bitso trade history is not in backward chronological order.',
        'Bitso order trade verification requires the complete unfiltered order history.',
        'Bitso open orders require an unfiltered account-wide list.',
        'Bitso open-order list reached its limit; completeness cannot be confirmed.',
        'Bitso open-order identity, market or status is invalid.',
        'Bitso private history response is invalid.',
        'Bitso private trade evidence is incomplete.',
        'Bitso margin activity is unsupported by spot accounting.',
        'Bitso private history market is invalid.',
        'Bitso private history currencies conflict with its order book.',
        'Bitso transfer history requires unfiltered backward marker pagination.',
        'Bitso transfer history response is not a valid bounded payload list.',
        'Bitso transfer identity, direction or status is invalid.',
        'Bitso transfer history is not in backward chronological order.',
        'Bitso completed withdrawal lacks confirmed fee and net amount; accounting cannot infer a zero fee.',
        'Bitso individual withdrawal response must contain exactly one record.',
        'Bitso individual withdrawal differs from the completed list record; repeat the account snapshot.',
        'Bitso individual withdrawal payment method differs from the list record.',
        'Invalid Bitso SPEI fee accounting evidence.',
        'Invalid Bitso Transfer fee accounting evidence.',
        'Bitso withdrawal total differs from net amount plus fee.',
        'Bitso withdrawal contains conflicting Lightning invoices.',
        'Lightning invoice amount differs from the Bitso total debit; fee evidence is still required.',
        'Invalid Lightning invoice evidence.',
        'Lightning evidence requires a Bitcoin mainnet invoice with an explicit valid amount.',
        'Lightning invoice checksum is invalid.',
        'Lightning invoice evidence needs an explicit payee public key for signature verification.',
        'Lightning invoice signature could not be verified.',
        'Invalid Lightning fee accounting evidence.',
        'Bitso Lightning operator confirmation belongs to another account.',
        'Bitso transfer changed during pagination; repeat the account snapshot.',
        'Historical order requires matching account and order trade evidence.',
        'Historical order trade identity, market or side differs.',
        'Order trade history does not confirm all observed account trades.',
        'Missing order details with recent fills require another authoritative snapshot.',
        'Historical order appeared in the open-order confirmation; retry synchronization.',
        'Historical trade evidence cannot resolve a locally open or uncertain order.',
        'Historical executions require verified fill evidence and unknown original order details.',

        'Invalid history page.',
        'History pagination made no progress.',
        'History timestamp boundary is full; configure cursor pagination.',
        'History page budget exhausted; history is incomplete.',
        'Unsupported history pagination mode.',
        'Wallet changed during reconciliation; repeat the account snapshot.',
        'CCXT lacks complete transfer history for this exchange.',
        'Ledger transfer direction is unknown.',
        'Exchange fee amount is missing.',
        'Exchange currency is missing or invalid.',
        'Stable exchange identity is required.',
        'Exchange timestamp is required.',
        'Unknown exchange trade side.',
        'Trade amount, price and cost must be positive.',
        'M3 requires a unified spot BASE/QUOTE symbol.',
        'Unknown exchange order status.',
        'Invalid cumulative order quantity.',
        'Closed order is not fully filled; exchange status is ambiguous.',
        'Missing order side.',
        'Transfer amount must be positive.',
        'Complete account totals are missing.',
        'Unknown wallet total.',
        'Wallet free + used differs from total.',
        'Invalid CCXT amount.',
        'CCXT amount exceeds decimal range.',
        'Invalid exchange timestamp.',
        'Incomplete exchange snapshot.',
        'currency_markets must select the same base currency.',
        'Ambiguous inventory attribution: configure currency_markets.',
        'Legacy order needs full exchange fill history.',
        'Conflicting exchange trade identity.',
        'Trade requires authoritative order details.',
        'Trade/order identity mismatch.',
        'Order fills are incomplete; reconciliation required.',
        'Order cumulative cost differs from its fills.',
        'Unpriced open market order: wait for exchange confirmation.',
        'Conflicting transfer financial evidence.',
        'Unattributed outflow: history or acquisition inventory is incomplete.',

        // M3 reuses M1's ledger and immutable archives. These fixed messages
        // are safe to publish; exceptions containing paths or values are not.
        'Unknown exchange account.',
        'Unknown market.',
        'Market belongs to another exchange account.',
        'Amounts must be nonnegative decimal strings with at most 24 fractional places; floats are forbidden.',
        'Amount must be positive.',
        'Invalid currency identifier.',
        'Market symbol must match BASE/QUOTE.',
        'opening_lots must be an array.',
        'Exchange order ID required.',
        'Filled plus remaining must equal order quantity.',
        'Invalid order side or status.',
        'Order status disagrees with its cumulative fill quantities.',
        'A fee currency is required for nonzero fees.',
        'Base fee exceeds purchased quantity.',
        'Order cumulative cost must be zero exactly when its filled quantity is zero.',
        'An exchange order identity cannot change.',
        'Cumulative order fills cannot decrease.',
        'Closed order cannot reopen.',
        'Out-of-order order update rejected.',
        'Operation must be an identified deposit or withdrawal.',
        'Market operations must reference its base currency; quote transfers are account operations.',
        'Base-currency transfers need an explicit market for lot accounting.',
        'Nonzero operation fee needs a currency.',
        'Deposit fee must be smaller than its amount.',
        'Conflicting replay of an immutable financial operation.',
        'Withdrawal exceeds attributable market inventory.',
        'Wallet reconciliation failed: supplied balances do not equal opening balances plus recorded financial activity.',
        'Wallet reconciliation failed: market inventory does not match attributable base balances.',
        'An allocation references missing acquisition inventory.',
        'Acquisition inventory is overallocated.',
        'SELL is overallocated.',
        'Checkpoint requires a reconciled account wallet.',
        'Checkpoint requires consistent market inventory attribution.',
        'Checkpoint requires no open market orders, no dust, and fully matched SELL fills.',
        'Snapshot contains another account.',
        'Restored snapshot failed database integrity checks.',
        'Invalid account ID.',
        'Cannot lock hard point.',
        'Cannot publish immutable candidate.',
        'Hard-point identity does not match its directory.',
        'Hard-point status SHA-256 mismatch.',
        'Hard-point status identity mismatch.',
        'Cyclic hard-point ancestry.',
        'Cost correction changed archived wallet arithmetic.',
        'Late transfer correction changed live wallet amounts.',
        'Transfer cost provenance contains an unresolved dependency cycle.',
        'Transfer source lot has ambiguous inventory attribution.',
            'Cash inventory baseline is not part of the verified hard-point ancestry.',
            'Incomplete M3 currency-inventory schema.',
            'Allocation inventory currency differs from the SELL base currency.',
        'Acquisition lot has ambiguous inventory ownership.',
        'Quote inventory market is missing or has a different currency.',
        'SQLite snapshot SHA-256 mismatch.',
        'SQLite snapshot has no completed client schema.',
        'SQLite snapshot failed foreign_key_check.',
        'Cannot make a SQLite snapshot inside a transaction.',
        'Refusing to overwrite an existing SQLite snapshot.',
        'Cannot create temporary metadata file.',
        'Incomplete metadata write.',
        'Cannot flush metadata file.',
        'Cannot atomically publish metadata file.',
        'Invalid metadata object.',
        'Cannot reopen snapshot for synchronization.',
        'Cannot synchronize snapshot.',
        'Cannot open directory for durable publication.',
        'Cannot synchronize directory metadata.',
    ];

    public static function describe(Throwable $error, string $exchange): array
    {
        return self::classify($error, $exchange) + self::source($error);
    }

    /** Only fixed source labels; no installation path, stack frames or arguments. */
    private static function source(Throwable $error): array
    {
        $root = dirname(__DIR__);
        foreach ([
            'm1/Backup.php', 'm1/Database.php', 'm1/HardPoints.php', 'm1/Ledger.php',
            'm3/Accounting.php', 'm3/BitsoInternalTransfer.php', 'm3/BitsoReadClient.php',
            'm3/BitsoSpei.php', 'm3/CcxtAccount.php', 'm3/HardPoints.php', 'm3/History.php',
            'm3/HistoricalOrder.php', 'm3/LightningInvoice.php', 'm3/Lots.php',
            'm3/Normalize.php', 'm3/Numbers.php', 'm3/Prices.php', 'm3/Reconciler.php',
            'm3/Runtime.php', 'm3/Transfers.php', 'm3/InventoryEvidenceError.php',
        ] as $relative) {
            if ($error->getFile() === realpath($root.'/'.$relative) && $error->getLine() > 0) {
                return ['source_file' => $relative, 'source_line' => $error->getLine()];
            }
        }
        return [];
    }

    private static function classify(Throwable $error, string $exchange): array
    {
        if ($error instanceof InventoryEvidenceError) {
            return ['detail' => $error->getMessage()] + $error->diagnostic;
        }
        if ($error instanceof LightningHashError) {
            return ['detail' => $error->getMessage()] + $error->diagnostic;
        }
        if ($error instanceof WithdrawalEvidenceError) {
            return ['reason' => 'withdrawal_fee_evidence_missing', 'detail' => $error->getMessage()] + $error->diagnostic;
        }
        $message = $error->getMessage();
        if ($message === 'M1 does not yet support spending one market inventory as another market quote currency.') {
            return ['reason' => 'inventory_currency_role_conflict',
                'detail' => 'History uses a currency both as market inventory and as another market\'s quote currency. Current inventory accounting does not support that combination.'];
        }
        if ($message === 'Same-timestamp history has unresolved funding dependencies; finer exchange timing or acquisition evidence is required.') {
            return ['reason' => 'ambiguous_same_timestamp_funding', 'detail' => $message];
        }
        if (in_array($message, self::LOCAL, true)) {
            return ['reason' => 'reconciliation_validation', 'detail' => $message];
        }
        if ($exchange === 'bitso' && is_a($error, 'ccxt\\BaseError')) {
            if (strlen($message) <= 65536 && str_starts_with($message, 'bitso ')) {
                $body = json_decode(substr($message, 6), true, 16);
                $code = is_array($body) && ($body['success'] ?? null) === false && is_array($body['error'] ?? null)
                    ? ($body['error']['code'] ?? null) : null;
                if ((is_string($code) || is_int($code)) && preg_match('/^[0-9]{4}$/D', (string)$code)) {
                    $code = (string)$code;
                    return ['reason' => 'exchange_response', 'exchange_code' => $code,
                        'detail' => self::BITSO[$code] ?? 'Bitso returned an unmapped error code; consult its error documentation. Response text withheld.'];
                }
                // A CCXT argument check, raised before any HTTP request. Do not quote it:
                // its suffix is untrusted, just like the exchange error.message field.
                if (preg_match('/^bitso fetch(?:MyTrades|OpenOrders)\(\) does not support fetching (?:trades|\$?orders) starting from a timestamp/', $message)) {
                    return ['reason' => 'timestamp_pagination_unsupported',
                        'detail' => 'Bitso requires an ID cursor for this history method. Configure history_params with mode=backward_id, cursor_param=marker and params.sort=desc.'];
                }
            }
        }
        // Pinned CCXT's handle_http_status_code() formats errors as
        // "id id URL METHOD STATUS BODY". Extract only the numeric status from
        // that anchored prefix. URLs, headers and response text remain private.
        if (is_a($error, 'ccxt\\NetworkError') && preg_match('/^[a-z][a-z0-9]{1,40}$/D', $exchange)) {
            $id = preg_quote($exchange, '~');
            if (preg_match('~\A'.$id.' '.$id.' https?://[^\x00-\x20\x7f]{1,8192} (?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS) ([45][0-9]{2})(?: |$)~', $message, $match)) {
                $status = (int)$match[1];
                [$reason, $detail] = match ($status) {
                    400 => ['http_bad_request', 'HTTP request rejected; check the adapter and history parameters.'],
                    401 => ['http_unauthorized', 'HTTP authentication rejected; check API credentials and the requested endpoint.'],
                    403 => ['http_forbidden', 'HTTP access denied; check endpoint permissions, IP restrictions and gateway policy.'],
                    404 => ['http_not_found', 'HTTP endpoint was not found; check exchange endpoint availability and the installed CCXT adapter.'],
                    405 => ['http_method_not_allowed', 'HTTP method was rejected; check the installed CCXT adapter and endpoint.'],
                    408, 504 => ['http_timeout', 'Exchange or gateway timed out; synchronization will retry.'],
                    410 => ['http_gone', 'HTTP endpoint is no longer available; verify the supported exchange endpoint.'],
                    418, 420, 429 => ['http_rate_limit', 'HTTP request limit or access restriction; synchronization will retry with backoff.'],
                    451 => ['http_restricted', 'HTTP access is restricted for legal or regional reasons; check exchange availability for this account.'],
                    default => $status >= 500
                        ? ['http_server_error', 'Exchange or gateway returned a server error; synchronization will retry.']
                        : ['http_rejected', 'HTTP request was rejected; inspect the status and exchange endpoint requirements.'],
                };
                return ['reason' => $reason, 'http_status' => $status, 'detail' => $detail];
            }
        }
        foreach ([
            'ccxt\\RequestTimeout' => ['request_timeout', 'Exchange request timed out; check connectivity and exchange.timeout_ms.'],
            'ccxt\\RateLimitExceeded' => ['rate_limit', 'Exchange rate limit reached; synchronization will retry with backoff.'],
            'ccxt\\ExchangeNotAvailable' => ['exchange_unavailable', 'Exchange request failed without a recognized HTTP status; synchronization will retry.'],
            'ccxt\\NetworkError' => ['network_error', 'Network connection failed; check DNS, TLS, proxy and connectivity.'],
            'ccxt\\NotSupported' => ['unsupported_operation', 'Installed CCXT adapter does not support this operation.'],
            'ccxt\\BadSymbol' => ['invalid_symbol', 'Exchange does not recognize the requested market.'],
            'ccxt\\BadRequest' => ['invalid_request', 'Exchange rejected the request parameters.'],
            'ccxt\\ExchangeError' => ['exchange_error', 'Exchange or CCXT rejected this operation without a recognized safe error code. Response text withheld.'],
        ] as $type => [$reason, $detail]) {
            if (is_a($error, $type)) {
                return ['reason' => $reason, 'detail' => $detail];
            }
        }
        return ['reason' => 'synchronization_error', 'detail' => 'Synchronization failed an unrecognized local check. Raw error text withheld.'];
    }
}
