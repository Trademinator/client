<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

use InvalidArgumentException;

final class Config
{
    public string $mode = 'fixture';
    public string $dataDir;
    public string $exchange = 'kraken';
    public string $symbol = 'XRP/CAD';
    public string $serverUrl = '';
    public int $ipcPort = 17840;
    public int $intervalMs = 2000;
    public int $requestTimeout = 10;
    public int $wsRetrySeconds = 30;
    public int $serverPollSeconds = 60;
    public int $retention = 1000;

    public function __construct(array $values = [], string $baseDir = '.')
    {
        $allowed = ['mode', 'data_dir', 'exchange', 'symbol', 'server_url', 'ipc_port',
            'interval_ms', 'request_timeout_seconds', 'ws_retry_seconds', 'server_poll_seconds', 'retention'];
        if (array_diff(array_keys($values), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown configuration field. See config.m0.example.json.');
        }
        foreach (['mode' => 'mode', 'exchange' => 'exchange', 'symbol' => 'symbol', 'server_url' => 'serverUrl'] as $key => $property) {
            if (isset($values[$key])) {
                if (!is_string($values[$key])) {
                    throw new InvalidArgumentException($key.' must be a string.');
                }
                $this->$property = $values[$key];
            }
        }
        foreach (['ipc_port' => ['ipcPort', 0, 65535], 'interval_ms' => ['intervalMs', 10, 60000],
            'request_timeout_seconds' => ['requestTimeout', 1, 120], 'ws_retry_seconds' => ['wsRetrySeconds', 1, 3600],
            'server_poll_seconds' => ['serverPollSeconds', 30, 86400], 'retention' => ['retention', 10, 100000]] as $key => $rule) {
            if (array_key_exists($key, $values)) {
                if (!is_int($values[$key]) || $values[$key] < $rule[1] || $values[$key] > $rule[2]) {
                    throw new InvalidArgumentException($key.' is outside its supported integer range.');
                }
                $this->{$rule[0]} = $values[$key];
            }
        }
        if (!in_array($this->mode, ['fixture', 'live'], true)) {
            throw new InvalidArgumentException('mode must be fixture or live.');
        }
        if (!preg_match('/^[a-z][a-z0-9]{1,40}$/D', $this->exchange)
            || !preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._:-]+$/D', $this->symbol)) {
            throw new InvalidArgumentException('Invalid exchange ID or CCXT symbol.');
        }
        $path = $values['data_dir'] ?? 'var';
        if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('data_dir must be a nonempty local path.');
        }
        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || str_starts_with($path, '\\\\');
        $this->dataDir = $absolute ? $path : $baseDir.DIRECTORY_SEPARATOR.$path;
        $this->serverUrl = rtrim($this->serverUrl, '/');
        if ($this->serverUrl !== '') {
            self::validateServerUrl($this->serverUrl);
        }
    }

    public static function validateServerUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Server URL must have a host and no credentials, query, or fragment.');
        }
        $scheme = $parts['scheme'] ?? '';
        $loopback = in_array($parts['host'], ['127.0.0.1', '[::1]', 'localhost'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
            throw new InvalidArgumentException('Server URL requires HTTPS; HTTP is allowed only on loopback.');
        }
    }

    public static function load(?string $path): self
    {
        if ($path === null) {
            return new self([], getcwd());
        }
        if (!is_file($path)) {
            throw new InvalidArgumentException('Configuration file does not exist.');
        }
        $values = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($values) || array_is_list($values)) {
            throw new InvalidArgumentException('Configuration must be a JSON object.');
        }
        return new self($values, dirname((string) realpath($path)));
    }
}
