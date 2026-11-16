<?php

declare(strict_types=1);

namespace Mercurius\Connectors\Internal;

use Amp\Socket\ConnectException;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;

/**
 * @internal Splits what AMPHP hands a connector into host and port.
 */
final class Destination {
    private function __construct() {
    }

    /**
     * Accepts `tcp://host:port`, `host:port`, `[::1]:443` and {@see InternetAddress}.
     *
     * @return array{string, int} Host (IPv6 without brackets) and port.
     *
     * @throws ConnectException
     */
    public static function parse(SocketAddress|string $uri): array {
        if ($uri instanceof InternetAddress) {
            return [$uri->getAddress(), $uri->getPort()];
        }

        if ($uri instanceof SocketAddress) {
            throw new ConnectException('Only TCP destinations can be reached through a proxy, got ' . $uri->toString());
        }

        $rest = $uri;

        if (\stripos($rest, 'tcp://') === 0) {
            $rest = \substr($rest, 6);
        } elseif (\str_contains($rest, '://')) {
            throw new ConnectException('Only TCP destinations can be reached through a proxy');
        }

        $parts = \parse_url('tcp://' . $rest);
        $host = \is_array($parts) ? ($parts['host'] ?? '') : '';
        $port = \is_array($parts) ? ($parts['port'] ?? 0) : 0;

        if (\str_starts_with($host, '[') && \str_ends_with($host, ']')) {
            $host = \substr($host, 1, -1);
        }

        if ($host === '' || $port < 1) {
            throw new ConnectException(\sprintf('Invalid destination "%s": expected host:port', self::printable($rest)));
        }

        return [$host, $port];
    }

    public static function format(string $host, int $port): string {
        return (\str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
    }

    private static function printable(string $value): string {
        return \substr((string) \preg_replace('/[^\x21-\x7e]/', '?', $value), 0, 100);
    }
}
