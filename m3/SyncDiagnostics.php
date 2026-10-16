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
    ];

    public static function describe(Throwable $error, string $exchange): array
    {
        if ($error instanceof LightningHashError) {
            return ['detail' => $error->getMessage()] + $error->diagnostic;
        }
        if ($error instanceof WithdrawalEvidenceError) {
            return ['reason' => 'withdrawal_fee_evidence_missing', 'detail' => $error->getMessage()] + $error->diagnostic;
        }
        $message = $error->getMessage();
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
