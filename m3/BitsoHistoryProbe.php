<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

use function React\Async\await;

/** Diagnostic only. Never used by reconciliation and never opens a local database. */
class BitsoHistoryProbe extends BitsoReadClient
{
    private const SOURCES = [
        'user_trades' => ['path' => 'user_trades', 'id' => 'tid', 'kind' => 'trade'],
        'fundings' => ['path' => 'fundings', 'id' => 'fid', 'kind' => 'deposit'],
        'withdrawals' => ['path' => 'withdrawals/', 'id' => 'wid', 'kind' => 'withdrawal'],
        'rfq_conversions' => ['path' => 'conversions', 'id' => 'id', 'kind' => 'conversion'],
    ];
    private const ERRORS = [
        'invalid_window' => 'Supply valid ISO 8601 timestamps with explicit UTC offsets; from must precede to.',
        'invalid_page' => 'The endpoint did not return a valid bounded history page.',
        'invalid_record' => 'A history record lacks a valid identity, timestamp or financial field.',
        'conflicting_identity' => 'The same history identity returned conflicting financial details.',
        'non_chronological_page' => 'Backward history returned a newer unseen record; window coverage is unconfirmed.',
        'non_progressing_cursor' => 'History pagination repeated a cursor or made no progress.',
        'page_budget_exhausted' => 'The page budget ended before the requested window was covered.',
        'event_limit_reached' => 'The window contains more than 1000 events in this source; request a narrower window.',
        'unexpected_filter_result' => 'RFQ history returned a record outside the requested creation-time window.',
    ];

    /** Strict inputs avoid interpreting a local time in the machine's timezone. */
    public static function time(string $value): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})$/D', $value)) {
            throw new RuntimeException('invalid_window');
        }
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            $ms = Numbers::ms($date->format('Y-m-d\TH:i:s.uP'));
        } catch (Throwable) { throw new RuntimeException('invalid_window'); }
        if (($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $ms < 0) {
            throw new RuntimeException('invalid_window');
        }
        return $ms;
    }

    public static function diagnostic(Throwable $error): array
    {
        $reason = $error->getMessage();
        return isset(self::ERRORS[$reason])
            ? ['reason' => $reason, 'detail' => self::ERRORS[$reason]]
            : SyncDiagnostics::describe($error, 'bitso');
    }

    /** All credentials, HTTP, throttling and error handling remain in CCXT. */
    public function sign($path, $api = 'public', $method = 'GET', $params = [], $headers = null, $body = null)
    {
        if ($method !== 'GET' || $body !== null) { throw new RuntimeException('History probe permits GET requests only.'); }
        if ($api !== 'diagnostic_rfq') { return parent::sign($path, $api, $method, $params, $headers, $body); }
        // This documented read endpoint uses a different host/path from v3.
        // Never prepend /api/v3, or reuse a signature for another request path.
        // https://docs.bitso.com/bitso-api/docs/get-rfq-conversion-history
        if ($path !== 'conversions' || array_diff(array_keys($params), ['page_size', 'page_token', 'created_at_gte', 'created_at_lt'])) {
            throw new RuntimeException('History probe RFQ request is invalid.');
        }
        $this->check_required_credentials();
        $endpoint = '/rfq/v1/conversions'.($params === [] ? '' : '?'.$this->urlencode($params));
        $nonce = (string)$this->nonce();
        $signature = $this->hmac($this->encode($nonce.'GET'.$endpoint), $this->encode($this->secret), 'sha256');
        return ['url' => 'https://api.bitso.com'.$endpoint, 'method' => 'GET', 'body' => null,
            'headers' => ['Authorization' => 'Bitso '.$this->apiKey.':'.$nonce.':'.$signature]];
    }

    /** A checked API window is not proof that Bitso exposes every historical app conversion. */
    public function inspect(int $from, int $to, int $configuredSince, int $maxPages = 1000, int $limit = 100, ?callable $progress = null): array
    {
        if ($from < 0 || $from >= $to || $configuredSince < 0) { throw new RuntimeException('invalid_window'); }
        if ($maxPages < 1 || $maxPages > 10000 || $limit < 1 || $limit > 100) { throw new RuntimeException('invalid_page'); }
        $report = ['tool' => 'm3-bitso-history-check', 'read_only' => true, 'status' => 'window_checked',
            'window' => ['from_inclusive' => Numbers::iso($from), 'to_exclusive' => Numbers::iso($to)],
            'configured_history_since_ms' => $configuredSince,
            'window_starts_before_configured_history' => $from < $configuredSince,
            'local_checkpoint_checked' => false, 'page_limit' => $limit, 'max_pages_per_source' => $maxPages,
            'sources' => [], 'timeline' => [],
            'notes' => [
                'Window coverage describes the API responses only; an empty source does not prove an event never occurred.',
                'RFQ history may not include legacy app conversions. Conversion records and underlying trades may overlap; do not add them together.',
                'Currency codes are reported separately. No USD/USDC/USDT alias, fee inference or balance adjustment is applied.',
                'This tool does not verify order lifecycle, resolve transfer fees, read Hard H0 or replay the ledger.',
            ]];
        foreach (self::SOURCES as $source => $definition) {
            $summary = ['coverage' => 'partial', 'pages_read' => 0, 'records_scanned' => 0, 'duplicates' => 0,
                'events_in_window' => 0, 'earliest_seen_at' => null, 'latest_seen_at' => null];
            try {
                $this->scan($source, $definition, $from, $to, $configuredSince, $maxPages, $limit, $summary, $report['timeline'], $progress);
            } catch (Throwable $error) {
                $summary['error'] = self::diagnostic($error);
                $report['status'] = 'partial';
            }
            $report['sources'][$source] = $summary;
        }
        usort($report['timeline'], static fn(array $a, array $b): int =>
            [$a['at'], $a['source'], $a['ref']] <=> [$b['at'], $b['source'], $b['ref']]);
        return $report;
    }

    private function scan(string $source, array $definition, int $from, int $to, int $configuredSince, int $maxPages,
        int $limit, array &$summary, array &$timeline, ?callable $progress): void
    {
        $rfq = $source === 'rfq_conversions';
        $params = $rfq ? ['page_size' => $limit, 'created_at_gte' => Numbers::iso($from), 'created_at_lt' => Numbers::iso($to)]
            : ['limit' => $limit] + ($source === 'user_trades' ? ['sort' => 'desc'] : []);
        $cursor = null; $cursors = []; $seen = []; $oldest = PHP_INT_MAX;
        for ($page = 1; $page <= $maxPages; ++$page) {
            if ($progress !== null) { $progress($source, $page, $summary['records_scanned']); }
            $response = await($this->request($definition['path'], $rfq ? 'diagnostic_rfq' : 'private', 'GET', $params));
            ++$summary['pages_read'];
            $rows = is_array($response) ? ($response[$rfq ? 'conversions' : 'payload'] ?? null) : null;
            if ((!$rfq && ($response['success'] ?? null) !== true) || !is_array($rows) || !array_is_list($rows) || count($rows) > $limit) {
                throw new RuntimeException('invalid_page');
            }
            $new = 0; $pageTime = PHP_INT_MAX; $edge = null;
            foreach ($rows as $raw) {
                if (!is_array($raw)) { throw new RuntimeException('invalid_record'); }
                try {
                    $id = Normalize::id($raw[$definition['id']] ?? null);
                    $at = self::time($raw['created_at'] ?? '');
                    $event = $this->event($source, $definition['kind'], $id, $at, $raw);
                } catch (Throwable) { throw new RuntimeException('invalid_record'); }
                ++$summary['records_scanned'];
                $summary['earliest_seen_at'] = min($summary['earliest_seen_at'] ?? $event['at'], $event['at']);
                $summary['latest_seen_at'] = max($summary['latest_seen_at'] ?? $event['at'], $event['at']);
                if (!$rfq && ($at > $pageTime || (!isset($seen[$id]) && $at > $oldest))) {
                    throw new RuntimeException('non_chronological_page');
                }
                $pageTime = $at; $edge = $id;
                $fingerprint = hash('sha256', Numbers::json($event));
                if (isset($seen[$id])) {
                    if ($seen[$id] !== $fingerprint) { throw new RuntimeException('conflicting_identity'); }
                    ++$summary['duplicates'];
                    continue;
                }
                $seen[$id] = $fingerprint; ++$new;
                if ($rfq && ($at < $from || $at >= $to)) { throw new RuntimeException('unexpected_filter_result'); }
                if ($at < $from || $at >= $to) { continue; }
                if ($summary['events_in_window'] >= 1000) { throw new RuntimeException('event_limit_reached'); }
                $event['before_configured_history'] = $at < $configuredSince;
                $timeline[] = $event; ++$summary['events_in_window'];
            }
            $oldest = min($oldest, $pageTime);
            if ($rfq) {
                $next = $response['next_page_token'] ?? null;
                if ($next !== null && (!is_string($next) || strlen($next) > 8192)) { throw new RuntimeException('invalid_page'); }
                $done = $next === null || $next === '';
                $completion = 'endpoint_exhausted';
            } else {
                // Read past an equal-time boundary, including a full page of ties.
                $done = count($rows) < $limit || $oldest < $from;
                $completion = $oldest < $from ? 'lower_bound_crossed' : 'endpoint_exhausted';
                $next = $edge;
            }
            if ($done) {
                $summary['coverage'] = 'window_covered'; $summary['completion'] = $completion;
                return;
            }
            // RFQ tokens can advance across empty filtered pages. Distinct
            // tokens and the page budget bound that scan without assuming sort order.
            if ((!$rfq && $new === 0) || $next === $cursor || isset($cursors[$next])) { throw new RuntimeException('non_progressing_cursor'); }
            $cursors[$next] = true; $cursor = $next;
            $params[$rfq ? 'page_token' : 'marker'] = $next;
        }
        throw new RuntimeException('page_budget_exhausted');
    }

    /** Allowlist only money, times, currency/book codes and opaque hashed references. */
    private function event(string $source, string $kind, string $id, int $at, array $raw): array
    {
        $event = ['source' => $source, 'kind' => $kind, 'ref' => substr(hash('sha256', $id), 0, 16), 'at' => Numbers::iso($at)];
        if ($kind === 'trade') {
            $event['raw'] = [
                'book' => $this->code($raw['book'] ?? null), 'major_currency' => $this->code($raw['major_currency'] ?? null),
                'minor_currency' => $this->code($raw['minor_currency'] ?? null), 'side' => $this->choice($raw['side'] ?? null, ['buy', 'sell']),
                'major' => $this->money($raw['major'] ?? null, true), 'minor' => $this->money($raw['minor'] ?? null, true),
                'price' => $this->money($raw['price'] ?? null), 'fees_amount' => $this->money($raw['fees_amount'] ?? null),
                'fees_currency' => $this->code($raw['fees_currency'] ?? null), 'margin' => !empty($raw['margin_order_type']),
            ];
            $event['order_ref'] = substr(hash('sha256', Normalize::id($raw['oid'] ?? null)), 0, 16);
            try {
                // Same pinned parser and boundary normalizer used by M3. Do not
                // fetch current markets: they can omit the historical book.
                $trade = Normalize::trade(parent::parse_trade($raw));
                $event['ccxt_at'] = Numbers::iso($trade['timestamp']);
                $event['timestamp_matches_ccxt'] = $trade['timestamp'] === $at;
                unset($trade['id'], $trade['order'], $trade['timestamp']);
                $event['normalized_trade'] = $this->clean($trade);
            } catch (Throwable $error) { $event['normalization_error'] = self::diagnostic($error); }
        } elseif ($kind === 'conversion') {
            $event['raw'] = ['source' => $this->code($raw['source'] ?? null), 'target' => $this->code($raw['target'] ?? null),
                'source_amount' => $this->money($raw['source_amount'] ?? null), 'target_amount' => $this->money($raw['target_amount'] ?? null),
                'status' => $this->choice($raw['status'] ?? null, ['PENDING', 'FAILED', 'COMPLETED']),
                'updated_at' => isset($raw['updated_at']) ? Numbers::iso(self::time($raw['updated_at'])) : null];
            $event['imported_by_current_m3'] = false;
        } else {
            $details = is_array($raw['details'] ?? null) ? $raw['details'] : [];
            $event['raw'] = ['currency' => $this->code($raw['currency'] ?? null), 'asset' => $this->code($raw['asset'] ?? null),
                'amount' => $this->money($raw['amount'] ?? null),
                'status' => $this->choice($raw['status'] ?? null, ['pending', 'processing', 'in_progress', 'complete', 'failed']),
                'listed_fee' => $this->money($details['fee'] ?? null), 'listed_net_amount' => $this->money($details['amount_to_send'] ?? null)];
            $transaction = parent::parse_transaction($raw);
            $event['ccxt_currency'] = $this->code($transaction['currency']);
            $event['ccxt_at'] = Numbers::iso(Normalize::timestamp($transaction));
            $event['timestamp_matches_ccxt'] = $transaction['timestamp'] === $at;
            $event['fee_resolution'] = 'not_performed';
        }
        return $event;
    }

    private function choice(mixed $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : 'unrecognized';
    }
    private function code(mixed $value): ?string
    {
        if ($value === null) { return null; }
        if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9_.\/-]{0,63}$/iD', $value)
            || preg_match('/^[a-f0-9]{16,}$/iD', $value) || preg_match('/^ln(?:bc|tb|bcrt)/i', $value)) { return '[redacted]'; }
        return $this->clean($value);
    }
    private function money(mixed $value, bool $signed = false): ?string
    {
        if ($value === null) { return null; }
        $negative = $signed && is_string($value) && str_starts_with($value, '-');
        return $this->clean(($negative ? '-' : '').Numbers::decimal($negative ? substr($value, 1) : $value));
    }
    private function clean(mixed $value): mixed
    {
        if (is_array($value)) { return array_map(fn($item) => $this->clean($item), $value); }
        if (is_string($value)) {
            foreach ([$this->apiKey, $this->secret, $this->password, $this->uid] as $credential) {
                if (is_string($credential) && $credential !== '' && stripos($value, $credential) !== false) { return '[redacted]'; }
            }
        }
        return $value;
    }
}
