<?php

declare(strict_types=1);

namespace Trademinator\Client\M1;

use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;

/** Presentation only; JSON events remain independent of ANSI and future TUI views. */
final class ConsoleEvents
{
    private StreamOutput $output;
    private bool $unavailable = false;
    private string $pending = '';

    public function __construct(private readonly string $format = 'human', string $color = 'auto', private readonly bool $verbose = false, $stream = null)
    {
        if (!in_array($format, ['human', 'json'], true)) { throw new RuntimeException('Output must be human or json.'); }
        if (!in_array($color, ['auto', 'always', 'never'], true)) { throw new RuntimeException('Color must be auto, always, or never.'); }
        $stream ??= STDOUT;
        $decorated = match ($color) { 'always' => true, 'never' => false, default => null };
        // An explicit option may override environment preferences. Automatic mode
        // never emits escape sequences to redirected output, even with FORCE_COLOR.
        if ($format === 'json' || ($color === 'auto' && (
            !is_resource($stream) || !@stream_isatty($stream) || getenv('TERM') === 'dumb'
            || (getenv('NO_COLOR') !== false && getenv('NO_COLOR') !== '')
            || (isset($_SERVER['NO_COLOR']) && $_SERVER['NO_COLOR'] !== '')
        ))) { $decorated = false; }
        $this->output = new StreamOutput($stream, OutputInterface::VERBOSITY_NORMAL, $decorated);
        $stat = @fstat($stream);
        $type = is_array($stat) ? ($stat['mode'] & 0170000) : null;
        if (in_array($type, [0010000, 0140000], true) && !@stream_set_blocking($stream, false)) {
            // A non-draining downstream process must not freeze the daemon.
            $this->unavailable = true;
        }
    }

    public function write(array $record): void
    {
        if ($this->unavailable || (!$this->verbose && ($record['level'] ?? '') === 'debug')) { return; }
        try {
            if ($this->format === 'json') {
                $text = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
            } else {
                $level = (string) ($record['level'] ?? 'info');
                $event = (string) ($record['event'] ?? 'event');
                $color = match ($level) {
                    'error' => 'red', 'warning' => 'yellow', 'debug' => 'gray',
                    default => str_contains($event, 'request') ? 'cyan' : 'green',
                };
                $escape = static fn(string $text): string => OutputFormatter::escape(EventRecord::safeText($text));
                $header = '<fg=gray>'.$escape((string) ($record['at'] ?? '')).' '.$escape(strtoupper($level)).'</>  '
                    .'<fg='.$color.';options=bold>['.$escape(strtoupper($event)).']</> '
                    .$escape((string) ($record['message'] ?? ''));
                $context = json_encode($record['context'] ?? [], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                // Format only the heading. The second line is literal compact JSON,
                // so markup-looking values and backslashes retain their exact meaning.
                $text = $this->output->getFormatter()->format($header)."\n        ".$context."\n";
            }
            $this->flushPending();
            if (strlen($this->pending) + strlen($text) > 65536) { return; }
            $this->pending .= $text;
            $this->flushPending();
        } catch (Throwable) {
            // A closed pipe, terminal or stream must never stop market processing.
            $this->unavailable = true;
        }
    }

    private function flushPending(): void
    {
        if ($this->pending === '') { return; }
        $written = @fwrite($this->output->getStream(), $this->pending);
        if ($written === false) { $this->unavailable = true; $this->pending = ''; return; }
        if ($written > 0) { $this->pending = substr($this->pending, $written); }
    }
}
