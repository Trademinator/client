<?php
declare(strict_types=1);

namespace Trademinator\Client\M2;

use RuntimeException;

/** Credentials are read only when configuring the runtime, never put in status. */
final class Credentials
{
    public static function read(string $environment, ?string $file): string
    {
        if ($file !== null) {
            if (is_link($file) || !is_file($file) || !is_readable($file)) {
                throw new RuntimeException('API key file must be a readable regular file.');
            }
            clearstatcache(true, $file);
            if (filesize($file) > 4096) { throw new RuntimeException('API key file is too large.'); }
            if (PHP_OS_FAMILY !== 'Windows') {
                $mode = fileperms($file);
                if ($mode === false || ($mode & 0077) !== 0) {
                    throw new RuntimeException('API key file must be private to its owner (chmod 600).');
                }
            }
            $bytes = file_get_contents($file);
            if ($bytes === false) { throw new RuntimeException('Cannot read API key file.'); }
            $token = trim($bytes);
        } else {
            $value = getenv($environment);
            $token = $value === false ? '' : trim($value);
        }
        if ($token === '' || strlen($token) > 4096 || preg_match('/[^\x21-\x7e]/', $token)) {
            throw new RuntimeException('Set the Server API key environment variable or a private server_api_key_file.');
        }
        return $token;
    }
}
