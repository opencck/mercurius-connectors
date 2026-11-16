<?php

declare(strict_types=1);

namespace Mercurius\Connectors;

use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\Socket\SocketConnector;
use Amp\Vless\Exception\VlessException;
use Amp\Vless\VlessConnector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\Socket\socketConnector;

/**
 * Builds an {@see SocketConnector} for an outbound route: direct, HTTP CONNECT, SOCKS5 or VLESS.
 *
 * Every AMPHP client that accepts a connector (amphp/http-client, database drivers, amphp/ssh)
 * then goes through the route without knowing about it.
 */
final class RouteFactory {
    private function __construct() {
    }

    /**
     * @param string|null $uri Route URI; null or empty string means a direct connection.
     *
     * @throws RouteException The URI is malformed or asks for something that cannot be provided.
     */
    public static function fromUri(#[\SensitiveParameter] ?string $uri, ?LoggerInterface $logger = null): SocketConnector {
        return self::fromRoute(Route::parse($uri), $logger);
    }

    /**
     * Builds the connector for a parsed route.
     *
     * @param SocketConnector|null $via Reaches the proxy or VLESS server itself (for chains). When null, the
     *     process-wide connector is captured now, so installing the result as the global connector later cannot
     *     make it dial through itself.
     *
     * @throws RouteException
     */
    public static function fromRoute(Route $route, ?LoggerInterface $logger = null, ?SocketConnector $via = null): SocketConnector {
        $logger ??= new NullLogger();

        if ($route->type() === RouteType::Direct) {
            return $via ?? socketConnector();
        }

        $via ??= socketConnector();
        $authority = (string) $route->authority();

        $connector = match ($route->type()) {
            RouteType::Http => new Http1TunnelConnector($authority, self::proxyAuthorization($route), $via),
            RouteType::Socks5 => self::socks5($route, false, $via, $logger),
            RouteType::Socks5h => self::socks5($route, true, $via, $logger),
            RouteType::Vless => self::vless($route, $via, $logger),
        };

        $logger->debug('Outbound route ready: {route}', ['route' => (string) $route]);

        return $connector;
    }

    /**
     * Builds a connector that goes through every route in turn: the first is reached directly, each next one
     * through the previous, and the destination through the last.
     *
     * `RouteFactory::chain('socks5://proxy:1080', 'vless://…')` reaches the VLESS server through the SOCKS5 proxy.
     * No URIs means a direct connection.
     *
     * @throws RouteException
     */
    public static function chain(#[\SensitiveParameter] string ...$uris): SocketConnector {
        return Chain::of(...$uris)->connector();
    }

    /**
     * Hides everything secret in a route URI: keeps only the scheme, host and port, replaces user info
     * (proxy credentials, the VLESS id) with `***`, drops the path, query and fragment.
     *
     * `vless://<id>@example.org:443?security=tls#name` becomes `vless://***@example.org:443`.
     */
    public static function redact(#[\SensitiveParameter] string $uri): string {
        $uri = \trim($uri);

        if ($uri === '') {
            return 'direct';
        }

        if (\preg_match('~^([A-Za-z][A-Za-z0-9+.\-]*)://(.*)$~s', $uri, $match) !== 1) {
            return '[unparseable URI]';
        }

        // User info is cut off by hand, at the last "@", before parse_url() sees it: a password with an
        // unencoded "#", "/" or "?" would otherwise be split into a "host" and "port" and printed.
        $rest = $match[2];
        $at = \strrpos($rest, '@');
        $hasUserInfo = $at !== false;

        if ($at !== false) {
            $rest = \substr($rest, $at + 1);
        }

        $parts = \parse_url('scheme://' . $rest);

        if ($parts === false) {
            return '[unparseable URI]';
        }

        $result = self::safeScheme(\strtolower($match[1])) . '://' . ($hasUserInfo ? '***@' : '');

        if (isset($parts['host'])) {
            $result .= (string) \preg_replace('/[^A-Za-z0-9.\-_:\[\]%]/', '?', $parts['host']);
        }

        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }

        return $result;
    }

    /** @internal Keeps an unknown scheme printable and short in messages. */
    public static function safeScheme(string $scheme): string {
        return \substr((string) \preg_replace('/[^a-z0-9+.\-]/', '?', $scheme), 0, 16);
    }

    private static function socks5(Route $route, bool $remoteDns, SocketConnector $via, LoggerInterface $logger): Socks5Connector {
        return new Socks5Connector(
            (string) $route->host(),
            (int) $route->port(),
            $route->username(),
            $route->password(),
            remoteDns: $remoteDns,
            connector: $via,
            logger: $logger,
        );
    }

    /**
     * @return array<non-empty-string, string>
     */
    private static function proxyAuthorization(Route $route): array {
        $username = $route->username();

        if ($username === null) {
            return [];
        }

        return ['Proxy-Authorization' => 'Basic ' . \base64_encode($username . ':' . (string) $route->password())];
    }

    private static function vless(Route $route, SocketConnector $via, LoggerInterface $logger): VlessConnector {
        $config = $route->vlessConfig();
        \assert($config !== null);

        if ($config->allowInsecure) {
            $logger->warning('VLESS route {route}: certificate verification of the VLESS server is OFF (allowInsecure)', [
                'route' => (string) $route,
            ]);
        }

        try {
            return new VlessConnector($config, VlessConnector::transportFor($config, $via));
        } catch (VlessException|\InvalidArgumentException $exception) {
            throw new RouteException(
                \sprintf('VLESS route %s cannot be built: %s', (string) $route, \str_replace($config->uuid, '***', $exception->getMessage())),
            );
        }
    }
}
