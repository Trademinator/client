<?php

declare(strict_types=1);

namespace Trademinator\Client\M1;

use InvalidArgumentException;

/** Fully validated immutable candidate; reload swaps it only after validation. */
final class Config
{
    public readonly string $dataDir;
    public readonly ?string $sourceFile;
    public readonly int $tickMs;
    public readonly int $logMaxBytes;
    public readonly int $logKeep;
    public readonly string $logLevel;
    public readonly string $serverUrl;
    public readonly string $serverApiKeyEnv;
    public readonly ?string $serverApiKeyFile;
    public readonly int $checksPerCandle;
    public readonly int $serverTimeoutMs;
    public readonly int $serverCatalogIntervalMs;
    public readonly int $serverRiskIntervalMs;
    public readonly int $serverMinRequestIntervalMs;
    private readonly ?string $dataDirOverride;

    public function __construct(array $values = [], ?string $baseDir = null, ?string $sourceFile = null, ?string $dataDirOverride = null)
    {
        $allowed = ['data_dir', 'tick_ms', 'log_max_bytes', 'log_keep', 'log_level',
            'server_url', 'server_api_key_env', 'server_api_key_file', 'checks_per_candle',
            'server_timeout_ms', 'server_catalog_interval_ms', 'server_risk_interval_ms', 'server_min_request_interval_ms'];
        $unknown = array_diff(array_keys($values), $allowed);
        if ($unknown !== []) { throw new InvalidArgumentException('Unknown configuration field(s): '.implode(', ', $unknown)); }
        $path = $dataDirOverride ?? (array_key_exists('data_dir', $values) ? $values['data_dir'] : Paths::defaultDataDir());
        if (!is_string($path)) { throw new InvalidArgumentException('data_dir must be a local path string.'); }
        $this->dataDir = Paths::absolute($path, $baseDir);
        $this->sourceFile = $sourceFile;
        $this->dataDirOverride = $dataDirOverride;
        foreach ([
            'tick_ms' => ['tickMs', 250, 50, 10000],
            'log_max_bytes' => ['logMaxBytes', 5242880, 4096, 1073741824],
            'log_keep' => ['logKeep', 5, 1, 100],
            'checks_per_candle' => ['checksPerCandle', 3, 1, 59],
            'server_timeout_ms' => ['serverTimeoutMs', 10000, 100, 60000],
            'server_catalog_interval_ms' => ['serverCatalogIntervalMs', 60000, 1000, 3600000],
            'server_risk_interval_ms' => ['serverRiskIntervalMs', 60000, 1000, 3600000],
            'server_min_request_interval_ms' => ['serverMinRequestIntervalMs', 600, 600, 60000],
        ] as $field => [$property, $default, $min, $max]) {
            $value = array_key_exists($field, $values) ? $values[$field] : $default;
            if (!is_int($value) || $value < $min || $value > $max) { throw new InvalidArgumentException($field.' must be an integer from '.$min.' through '.$max.'.'); }
            $this->$property = $value;
        }
        $level = array_key_exists('log_level', $values) ? $values['log_level'] : 'info';
        if (!is_string($level) || !in_array($level, ['debug', 'info', 'warning', 'error'], true)) { throw new InvalidArgumentException('Invalid log_level.'); }
        $this->logLevel = $level;
        $url = $values['server_url'] ?? '';
        if (!is_string($url) || strlen($url) > 2048) { throw new InvalidArgumentException('server_url must be an HTTPS origin.'); }
        $parts = parse_url($url);
        if ($url !== '' && ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)
            || preg_match('/[\x00-\x20\x7f]/', $url))) {
            throw new InvalidArgumentException('server_url must be an HTTPS origin without credentials, path, query or fragment.');
        }
        $this->serverUrl = rtrim($url, '/');
        $environment = $values['server_api_key_env'] ?? 'TRADEMINATOR_API_KEY';
        if (!is_string($environment) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D', $environment)) {
            throw new InvalidArgumentException('server_api_key_env must name an environment variable.');
        }
        $this->serverApiKeyEnv = $environment;
        $file = $values['server_api_key_file'] ?? null;
        if ($file !== null && (!is_string($file) || $file === '')) { throw new InvalidArgumentException('server_api_key_file must be a local path.'); }
        $this->serverApiKeyFile = $file === null ? null : Paths::absolute($file, $baseDir);
    }

    public static function load(?string $path = null, ?string $dataDirOverride = null): self
    {
        if ($path === null) { return new self([], (string) getcwd(), null, $dataDirOverride); }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) { throw new InvalidArgumentException('Configuration file is missing or unreadable.'); }
        if (filesize($resolved) > 1048576) { throw new InvalidArgumentException('Configuration exceeds 1 MiB.'); }
        $bytes = file_get_contents($resolved);
        if ($bytes === false) { throw new InvalidArgumentException('Cannot read configuration file.'); }
        $object = json_decode($bytes, false, 32, JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) { throw new InvalidArgumentException('Configuration must be a JSON object.'); }
        $values = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        return new self($values, dirname($resolved), $resolved, $dataDirOverride);
    }

    public function reload(): self
    {
        if ($this->sourceFile === null) { throw new InvalidArgumentException('Reload requires a --config file.'); }
        $candidate = self::load($this->sourceFile, $this->dataDirOverride);
        $currentPath = PHP_OS_FAMILY === 'Windows' ? strtolower($this->dataDir) : $this->dataDir;
        $candidatePath = PHP_OS_FAMILY === 'Windows' ? strtolower($candidate->dataDir) : $candidate->dataDir;
        if ($candidatePath !== $currentPath) { throw new InvalidArgumentException('data_dir cannot change during reload; stop and restart explicitly.'); }
        return $candidate;
    }

    public function toArray(): array
    {
        return ['data_dir' => $this->dataDir, 'tick_ms' => $this->tickMs,
            'log_max_bytes' => $this->logMaxBytes, 'log_keep' => $this->logKeep, 'log_level' => $this->logLevel,
            'server_url' => $this->serverUrl, 'server_api_key_env' => $this->serverApiKeyEnv,
            'server_api_key_file' => $this->serverApiKeyFile, 'checks_per_candle' => $this->checksPerCandle,
            'server_timeout_ms' => $this->serverTimeoutMs, 'server_catalog_interval_ms' => $this->serverCatalogIntervalMs,
            'server_risk_interval_ms' => $this->serverRiskIntervalMs,
            'server_min_request_interval_ms' => $this->serverMinRequestIntervalMs];
    }

    /** This includes the secret: pass only to Runtime, never to logging or status. */
    public function serverSettings(): array
    {
        return ['enabled' => $this->serverUrl !== '', 'url' => $this->serverUrl,
            'token' => $this->serverUrl === '' ? '' : \Trademinator\Client\M2\Credentials::read($this->serverApiKeyEnv, $this->serverApiKeyFile),
            'timeout_ms' => $this->serverTimeoutMs, 'checks_per_candle' => $this->checksPerCandle,
            'catalog_interval_ms' => $this->serverCatalogIntervalMs, 'risk_interval_ms' => $this->serverRiskIntervalMs,
            'min_request_interval_ms' => $this->serverMinRequestIntervalMs,
            'retry_base_ms' => 1000, 'retry_max_ms' => 60000];
    }
}
