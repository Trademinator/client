<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use InvalidArgumentException;
use Trademinator\Client\M1\Paths;
use Trademinator\Client\M1\EventRecord;
use Trademinator\Client\M2\Credentials;

final class Settings
{
    public static function validate(array $input, ?string $baseDir = null): array
    {
        $defaults = ['accounts' => [], 'sync_interval_ms' => 60000, 'timeout_ms' => 15000,
            'retry_base_ms' => 1000, 'retry_max_ms' => 60000, 'ws_retry_ms' => 30000,
            'history_limit' => 100, 'history_max_pages' => 1000, 'overlap_ms' => 300000,
            'transfer_window_ms' => 172800000, 'transfer_amount_tolerance' => '0.02',
            'transfer_high_score' => 90, 'transfer_medium_score' => 45];
        if (array_diff(array_keys($input), array_keys($defaults))) {
            throw new InvalidArgumentException('Unknown exchange configuration field.');
        }
        $s = array_replace($defaults, $input);
        foreach (['sync_interval_ms' => [1000,3600000], 'timeout_ms' => [100,60000], 'retry_base_ms' => [100,60000],
            'retry_max_ms' => [1000,3600000], 'ws_retry_ms' => [1000,3600000], 'history_limit' => [1,1000],
            'history_max_pages' => [1,10000], 'overlap_ms' => [1000,86400000], 'transfer_window_ms' => [1000,2592000000],
            'transfer_high_score' => [1,100], 'transfer_medium_score' => [1,100]] as $key => [$min,$max]) {
            if (!is_int($s[$key]) || $s[$key] < $min || $s[$key] > $max) {
                throw new InvalidArgumentException('Invalid exchange '.$key.'.');
            }
        }
        $s['transfer_amount_tolerance'] = Numbers::decimal($s['transfer_amount_tolerance']);
        if (bccomp($s['transfer_amount_tolerance'], '1', 24) > 0 || $s['transfer_medium_score'] >= $s['transfer_high_score'] || $s['retry_base_ms'] > $s['retry_max_ms']) {
            throw new InvalidArgumentException('Invalid exchange thresholds.');
        }
        if (!is_array($s['accounts']) || !array_is_list($s['accounts'])) {
            throw new InvalidArgumentException('exchange.accounts must be a list.');
        }
        $ids = [];
        foreach ($s['accounts'] as &$a) {
            if (!is_array($a) || array_diff(array_keys($a), ['exchange','account_id','api_key_env','secret_env','password_env','uid_env','api_key_file','secret_file','password_file','uid_file','history_since_ms','valuation_quote','currency_markets','public_price_exchanges','coingecko_ids','coingecko_api_key_env','history_params','bitso_lightning_payment_hashes'])) {
                throw new InvalidArgumentException('Invalid exchange account configuration.');
            }
            if (!isset($a['account_id'])) {
                if (!is_string($a['exchange'] ?? null) || !preg_match('/^[a-z][a-z0-9]{1,40}$/D', $a['exchange'])) {
                    throw new InvalidArgumentException('An exchange name is required for automatic account registration.');
                }
                $a['history_since_ms'] ??= 0;
            } elseif (!is_string($a['account_id']) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $a['account_id']) || isset($ids[$a['account_id']])) {
                throw new InvalidArgumentException('Unique local account_id required.');
            }
            if (isset($a['account_id'])) { $ids[$a['account_id']] = true; }
            // Compatibility for the superseded workaround. It has no effect on
            // reconciliation and is not forwarded to an exchange adapter.
            unset($a['bitso_lightning_payment_hashes']);
            // CoinGecko identities now come exclusively from the Server.
            unset($a['coingecko_ids']);
            foreach (['api_key','secret','password','uid'] as $key) {
                foreach (['env','file'] as $kind) {
                    $field = $key.'_'.$kind;
                    if (!isset($a[$field])) {
                        continue;
                    }
                    if (!is_string($a[$field]) || $a[$field] === '') {
                        throw new InvalidArgumentException('Invalid credential reference.');
                    }
                    if ($kind === 'env' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D', $a[$field])) {
                        throw new InvalidArgumentException('Invalid credential environment variable.');
                    }
                    if ($kind === 'file') {
                        $a[$field] = Paths::absolute($a[$field], $baseDir);
                    }
                }
            }
            foreach (['api_key','secret'] as $key) {
                if (!isset($a[$key.'_env']) && !isset($a[$key.'_file'])) {
                    throw new InvalidArgumentException('Exchange API key and secret references required.');
                }
            }
            if (!is_int($a['history_since_ms'] ?? null) || $a['history_since_ms'] < 0) {
                throw new InvalidArgumentException('Explicit history_since_ms required; no guessed opening balance.');
            }
            $a['valuation_quote'] ??= 'USD';
            if (!is_string($a['valuation_quote']) || !preg_match('/^[A-Z0-9]{2,12}$/D', $a['valuation_quote'])) {
                throw new InvalidArgumentException('Invalid valuation_quote.');
            }
            foreach (['currency_markets','history_params'] as $key) {
                $a[$key] ??= [];
                if (!is_array($a[$key])) {
                    throw new InvalidArgumentException('Invalid '.$key.'.');
                }
            }
            foreach ($a['history_params'] as $method => $policy) {
                if (!in_array($method, ['fetch_my_trades','fetch_open_orders','fetch_order_trades','fetch_ledger','fetch_deposits','fetch_withdrawals'], true)
                    || !is_array($policy) || array_diff(array_keys($policy), ['mode','cursor_param','params'])
                    || !in_array($policy['mode'] ?? 'time', ['time','backward_id','forward_id','all'], true)
                    || !is_array($policy['params'] ?? [])) {
                    throw new InvalidArgumentException('Invalid history pagination policy.');
                }
            }
            $a['public_price_exchanges'] ??= [];
            if (!is_array($a['public_price_exchanges']) || !array_is_list($a['public_price_exchanges'])) {
                throw new InvalidArgumentException('Invalid public_price_exchanges.');
            }
            foreach ($a['public_price_exchanges'] as $exchange) {
                if (!is_string($exchange) || !preg_match('/^[a-z][a-z0-9]{1,40}$/D', $exchange)) {
                    throw new InvalidArgumentException('Invalid public price exchange.');
                }
            }
        }
        unset($a);
        return $s;
    }
    public static function credentials(array $account): array
    {
        $result = [];
        foreach (['api_key' => 'apiKey','secret' => 'secret','password' => 'password','uid' => 'uid'] as $field => $ccxt) {
            if (!isset($account[$field.'_env']) && !isset($account[$field.'_file'])) {
                continue;
            }
            try {
                $result[$ccxt] = Credentials::read($account[$field.'_env'] ?? '', $account[$field.'_file'] ?? null);
            } catch (\RuntimeException $error) {
                $file = isset($account[$field.'_file']);
                $reason = match ($error->getMessage()) {
                    'API key file must be a readable regular file.' => 'credential_file_unreadable',
                    'API key file is too large.' => 'credential_file_too_large',
                    'API key file must be private to its owner (chmod 600).' => 'credential_file_permissions',
                    'Cannot read API key file.' => 'credential_file_read_failed',
                    default => 'credential_empty_or_invalid',
                };
                $detail = match ($reason) {
                    'credential_file_unreadable' => 'Credential file is missing, unreadable, not a regular file, or a symbolic link.',
                    'credential_file_too_large' => 'Credential file exceeds 4096 bytes.',
                    'credential_file_permissions' => 'Credential file allows group or other access. Set mode 0600.',
                    'credential_file_read_failed' => 'Credential file could not be read by the Client process.',
                    default => $file ? 'Credential file is empty or contains invalid characters; use only the credential value.' : 'Credential environment variable is unset, empty, or contains invalid characters in the Client process.',
                };
                throw new CredentialError(['reason' => $reason, 'credential_field' => $field,
                    'config_field' => $field.($file ? '_file' : '_env'),
                    'source_type' => $file ? 'file' : 'environment', 'detail' => $detail]);
            }
            EventRecord::hideSecret($result[$ccxt]);
        }
        return $result;
    }
}
