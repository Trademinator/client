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
    private readonly ?string $dataDirOverride;

    public function __construct(array $values = [], ?string $baseDir = null, ?string $sourceFile = null, ?string $dataDirOverride = null)
    {
        $allowed = ['data_dir', 'tick_ms', 'log_max_bytes', 'log_keep', 'log_level'];
        $unknown = array_diff(array_keys($values), $allowed);
        if ($unknown !== []) { throw new InvalidArgumentException('Unknown M1 configuration field(s): '.implode(', ', $unknown)); }
        $path = $dataDirOverride ?? (array_key_exists('data_dir', $values) ? $values['data_dir'] : Paths::defaultDataDir());
        if (!is_string($path)) { throw new InvalidArgumentException('data_dir must be a local path string.'); }
        $this->dataDir = Paths::absolute($path, $baseDir);
        $this->sourceFile = $sourceFile;
        $this->dataDirOverride = $dataDirOverride;
        foreach ([
            'tick_ms' => ['tickMs', 250, 50, 10000],
            'log_max_bytes' => ['logMaxBytes', 5242880, 4096, 1073741824],
            'log_keep' => ['logKeep', 5, 1, 100],
        ] as $field => [$property, $default, $min, $max]) {
            $value = array_key_exists($field, $values) ? $values[$field] : $default;
            if (!is_int($value) || $value < $min || $value > $max) { throw new InvalidArgumentException($field.' must be an integer from '.$min.' through '.$max.'.'); }
            $this->$property = $value;
        }
        $level = array_key_exists('log_level', $values) ? $values['log_level'] : 'info';
        if (!is_string($level) || !in_array($level, ['debug', 'info', 'warning', 'error'], true)) { throw new InvalidArgumentException('Invalid log_level.'); }
        $this->logLevel = $level;

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
            'log_max_bytes' => $this->logMaxBytes, 'log_keep' => $this->logKeep, 'log_level' => $this->logLevel];
    }
}
