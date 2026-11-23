<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

/** Fail-closed lease shared by the paper supervisor and its child process. */
final class WorkerLease
{
    // Allow short scheduling / I/O delays without stopping every paper worker.
    // A genuinely missing supervisor still stops a worker before it can run indefinitely.
    public const TIMEOUT_SECONDS = 60;
    private ?array $stopped = null;

    public function __construct(private readonly string $file) {}

    public function active(): bool
    {
        if ($this->stopped !== null) { return false; }
        $state = self::inspect($this->file);
        if (!$state['valid']) { $this->stopped = $state; }
        return $state['valid'];
    }

    /** @return array{valid: bool, reason: string, age_seconds?: int} */
    public function state(): array { return $this->stopped ?? self::inspect($this->file); }

    /** @return array{valid: bool, reason: string, age_seconds?: int} */
    public static function inspect(string $file): array
    {
        clearstatcache(true, $file);
        if (!is_file($file)) { return ['valid' => false, 'reason' => 'supervisor_lease_removed']; }
        $mtime = @filemtime($file);
        if ($mtime === false) { return ['valid' => false, 'reason' => 'supervisor_lease_unreadable']; }
        $age = max(0, time() - $mtime);
        if ($age >= self::TIMEOUT_SECONDS) {
            return ['valid' => false, 'reason' => 'supervisor_heartbeat_expired', 'age_seconds' => $age];
        }
        return ['valid' => true, 'reason' => 'active', 'age_seconds' => $age];
    }
}
