<?php
declare(strict_types=1);

namespace Trademinator\Client\M2;

use InvalidArgumentException;
use RuntimeException;

/** Bounded, nonblocking GET transport. Credentials never enter returned records. */
final class Transport
{
    private \CurlMultiHandle $multi;
    private array $requests = [];
    private bool $closed = false;

    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $token,
        private readonly int $timeoutMs = 10000,
        private readonly int $maxBytes = 1048576,
        private readonly bool $allowLoopbackHttp = false,
    ) {
        self::validateOrigin($baseUrl, $allowLoopbackHttp);
        if ($token === '' || strlen($token) > 4096 || preg_match('/[^\x21-\x7E]/', $token)) {
            throw new InvalidArgumentException('Invalid Server API credential.');
        }
        if ($timeoutMs < 100 || $timeoutMs > 60000 || $maxBytes < 1024 || $maxBytes > 4194304) {
            throw new InvalidArgumentException('Invalid Server transport limits.');
        }
        if (!extension_loaded('curl')) { throw new RuntimeException('M2 requires the PHP curl extension.'); }
        if ((curl_version()['features'] & CURL_VERSION_ASYNCHDNS) === 0) {
            throw new RuntimeException('M2 requires libcurl with asynchronous DNS.');
        }
        $this->multi = curl_multi_init();
        curl_multi_setopt($this->multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 2);
        curl_multi_setopt($this->multi, CURLMOPT_MAX_HOST_CONNECTIONS, 2);
    }

    public static function validateOrigin(string $url, bool $allowLoopbackHttp = false): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')
            || preg_match('/[\x00-\x20\x7F\\\\]/', $url)
            || !in_array($parts['scheme'], ['https', 'http'], true)) {
            throw new InvalidArgumentException('Server URL must be an HTTPS origin without a path or credentials.');
        }
        $host = strtolower($parts['host']);
        $loopback = in_array($host, ['127.0.0.1', '[::1]', 'localhost'], true);
        if ($parts['scheme'] !== 'https' && !($allowLoopbackHttp && $loopback)) {
            throw new InvalidArgumentException('Server URL must use HTTPS.');
        }
    }

    public function start(string $requestId, string $path): void
    {
        if ($this->closed || count($this->requests) >= 8 || $requestId === '' || strlen($requestId) > 128) {
            throw new RuntimeException('Server transport is unavailable or at capacity.');
        }
        foreach ($this->requests as $request) {
            if ($request['request_id'] === $requestId) { throw new RuntimeException('Duplicate Server request identifier.'); }
        }
        if (!preg_match('~^/api/v1/client/(?:markets(?:/[A-Za-z0-9_-]{1,128})?|risk-factor)$~D', $path)) {
            throw new InvalidArgumentException('Invalid Server request path.');
        }
        $handle = curl_init();
        $key = spl_object_id($handle);
        $this->requests[$key] = ['handle' => $handle, 'request_id' => $requestId,
            'headers' => [], 'header_bytes' => 0, 'body' => '', 'error' => null];
        curl_setopt_array($handle, [
            CURLOPT_URL => $this->baseUrl.$path,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$this->token, 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => $this->allowLoopbackHttp ? CURLPROTO_HTTPS | CURLPROTO_HTTP : CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, $this->timeoutMs),
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Trademinator-Client-M2/1',
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use ($key): int {
                $length = strlen($line);
                $this->requests[$key]['header_bytes'] += $length;
                if ($this->requests[$key]['header_bytes'] > 16384) {
                    $this->requests[$key]['error'] = 'response_headers_too_large';
                    return 0;
                }
                if (str_starts_with($line, 'HTTP/')) { $this->requests[$key]['headers'] = []; }
                $pair = explode(':', $line, 2);
                $name = strtolower(trim($pair[0]));
                if (count($pair) === 2 && in_array($name, ['retry-after', 'content-type'], true)) {
                    $this->requests[$key]['headers'][$name] = trim($pair[1]);
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $bytes) use ($key): int {
                if (strlen($this->requests[$key]['body']) + strlen($bytes) > $this->maxBytes) {
                    $this->requests[$key]['error'] = 'response_too_large';
                    return 0;
                }
                $this->requests[$key]['body'] .= $bytes;
                return strlen($bytes);
            },
        ]);
        if (curl_multi_add_handle($this->multi, $handle) !== CURLM_OK) {
            unset($this->requests[$key]);
            curl_close($handle);
            throw new RuntimeException('Cannot queue Server request.');
        }
    }

    /** Call once each daemon tick; never wait/select/sleep for network activity. */
    public function poll(): array
    {
        if ($this->closed || $this->requests === []) { return []; }
        $running = 0;
        do { $result = curl_multi_exec($this->multi, $running); } while ($result === CURLM_CALL_MULTI_PERFORM);
        if ($result !== CURLM_OK) {
            $failed = array_map(static fn(array $r): array => ['request_id' => $r['request_id'],
                'status' => 0, 'headers' => [], 'body' => '', 'error' => 'transport_error'], array_values($this->requests));
            $this->cancelAll();
            return $failed;
        }
        $completed = [];
        while (($info = curl_multi_info_read($this->multi)) !== false) {
            $handle = $info['handle'];
            $key = spl_object_id($handle);
            if (!isset($this->requests[$key])) { continue; }
            $request = $this->requests[$key];
            $error = $request['error'] ?? ($info['result'] === CURLE_OK ? null : 'transport_error');
            $completed[] = ['request_id' => $request['request_id'],
                'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'headers' => $request['headers'], 'body' => $error === null ? $request['body'] : '', 'error' => $error];
            curl_multi_remove_handle($this->multi, $handle);
            curl_close($handle);
            unset($this->requests[$key]);
        }
        return $completed;
    }

    public function pending(): int { return count($this->requests); }

    public function cancelAll(): void
    {
        foreach ($this->requests as $request) {
            curl_multi_remove_handle($this->multi, $request['handle']);
            curl_close($request['handle']);
        }
        $this->requests = [];
    }

    public function __debugInfo(): array { return ['pending' => $this->pending()]; }
    public function __serialize(): array { throw new RuntimeException('Server transport cannot be serialized.'); }

    public function __destruct()
    {
        if (isset($this->multi) && !$this->closed) {
            $this->cancelAll();
            curl_multi_close($this->multi);
            $this->closed = true;
        }
    }
}
