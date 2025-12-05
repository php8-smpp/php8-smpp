<?php

declare(strict_types=1);

namespace Smpp\Utils\Network;

use Generator;
use Smpp\Exceptions\SmppInvalidArgumentException;

/**
 * DNS resolver that handles both IPv4 and IPv6 addresses with dual-stack support.
 * Produces optimized Entry objects containing both IP versions when available.
 */
class Resolver
{
    /**
     * @var callable Custom DNS resolver function (for testing)
     */
    private static $dnsResolver = 'dns_get_record';

    /**
     * Resolves all IP addresses for a host and generates Entry objects.
     *
     * Strategy:
     * 1. First yields entries with both IPv4 and IPv6 (dual-stack)
     * 2. Then yields remaining IPv4 addresses
     * 3. Then yields remaining IPv6 addresses
     * 4. Finally falls back to basic resolution if no records found
     *
     * @param string $host Domain name or IP address
     * @param int<1, 65535> $port Target port number
     *
     * @return Generator<int, Entry, mixed, void> Yields Entry objects
     * @throws SmppInvalidArgumentException
     */
    public static function getIPsByHost(string $host, int $port): Generator
    {
        $ipv4List = self::resolveIPs($host, DNS_A);
        $ipv6List = self::resolveIPs($host, DNS_AAAA);

        // Fallback if no DNS records found
        if (empty($ipv4List) && empty($ipv6List)) {
            $ip = gethostbyname($host);
            if ($ip !== $host) {
                yield self::createFallbackEntry($ip, $port, host: $host);
            }
            return;
        }

        // Dual-stack processing: pair available IPv4 and IPv6 addresses
        while ($ipv4List && $ipv6List) {
            yield new Entry(
                port: $port,
                ipv4: array_shift($ipv4List),
                ipv6: array_shift($ipv6List),
                host: $host,
            );
        }

        // Remaining IPv4 addresses
        foreach ($ipv4List as $ipv4) {
            yield new Entry(port: $port, ipv4: $ipv4, host: $host);
        }

        // Remaining IPv6 addresses
        foreach ($ipv6List as $ipv6) {
            yield new Entry(port: $port, ipv6: $ipv6, host: $host);
        }
    }

    /**
     * Creates an Entry for fallback IP address with automatic version detection.
     *
     * @param string $ip
     * @param int $port
     *
     * @return Entry
     * @throws SmppInvalidArgumentException
     */
    public static function createFallbackEntry(string $ip, int $port, ?string $host = null): Entry
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? new Entry(port: $port, ipv6: $ip, host: $host)
            : new Entry(port: $port, ipv4: $ip, host: $host);
    }

    /**
     * Resolves DNS records of specified type and extracts IP addresses.
     *
     * @param string $host Target hostname
     * @param int $type DNS record type (DNS_A/DNS_AAAA)
     *
     * @return array<string> List of IP addresses
     */
    public static function resolveIPs(string $host, int $type): array
    {
        $records = (self::$dnsResolver)($host, $type);
        return array_column($records, $type === DNS_A ? 'ip' : 'ipv6');
    }

    /**
     * Overrides default DNS resolver for testing purposes.
     *
     * @param callable $resolver Function that accepts (hostname, type) and returns records
     */
    public static function setDnsResolver(callable $resolver): void
    {
        self::$dnsResolver = $resolver;
    }

    /**
     * Restores default system DNS resolver.
     */
    public static function resetDnsResolver(): void
    {
        self::$dnsResolver = 'dns_get_record';
    }
}