<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

use React\Dns\Resolver\ResolverInterface;
use RuntimeException;
use Throwable;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Small adapter for platforms where ReactPHP cannot discover the OS DNS servers.
 *
 * Windows 11 no longer ships WMIC by default, while react/dns 1.x still uses it
 * for system resolver discovery. PHP's native resolver already follows the
 * Windows DNS configuration (including split DNS), so use it for Server API
 * connections instead of silently falling back to a public resolver.
 */
final class SystemResolver implements ResolverInterface
{
    // React\Dns\Model\Message::TYPE_A. Keep the native class independent from
    // React's model classes; TypePHP only needs the ResolverInterface contract.
    private const DNS_TYPE_A = 1;

    public function resolve($domain)
    {
        try {
            return resolve($this->ipv4($domain));
        } catch (Throwable $error) {
            return reject($error);
        }
    }

    public function resolveAll($domain, $type)
    {
        if ($type !== self::DNS_TYPE_A) {
            return reject(new RuntimeException('System resolver only supports IPv4 A records.'));
        }
        try {
            return resolve([$this->ipv4($domain)]);
        } catch (Throwable $error) {
            return reject($error);
        }
    }

    private function ipv4(mixed $domain): string
    {
        if (!is_string($domain) || $domain === '' || str_contains($domain, "\0")) {
            throw new RuntimeException('Invalid DNS hostname.');
        }
        if (@inet_pton($domain) !== false && !str_contains($domain, ':')) {
            return $domain;
        }
        $address = @gethostbyname($domain);
        if (!is_string($address) || $address === $domain || @inet_pton($address) === false || str_contains($address, ':')) {
            throw new RuntimeException('Server hostname could not be resolved.');
        }
        return $address;
    }
}
