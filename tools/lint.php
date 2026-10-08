<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = false;
foreach (['src', 'bin', 'native', 'tests', 'tools'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $process = proc_open([PHP_BINARY, '-l', $file->getPathname()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) { fwrite(STDERR, $out); $failed = true; }
        }
    }
}
echo $failed ? "Lint failed.\n" : "All M0 PHP files passed syntax checks.\n";
exit($failed ? 1 : 0);
