<?php

declare(strict_types=1);

namespace Mercurius\Connectors;

use Amp\Vless\Exception\UnsupportedFeatureException;
use Amp\Vless\Exception\VlessException;
use Amp\Vless\VlessConfig;

/**
 * A parsed outbound route.
 *
 * Holds credentials (and, for VLESS, the parsed configuration with the user id), but never shows
 * them: {@see __toString()} and {@see __debugInfo()} return the redacted form, which is safe for logs.
 */
final class Route implements \Stringable {
    private function __construct(
        private readonly RouteType $type,
        private readonly ?string $host = null,
        private readonly ?int $port = null,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly ?VlessConfig $vless = null,
    ) {
    }

    /**
     * Parses a route URI.
     *
     * - null or empty string: direct;
     * - `http://[user:pass@]host:port`: HTTP CONNECT proxy;
     * - `socks5://[user:pass@]host:port`, `socks5h://...`: SOCKS5 proxy;
     * - `vless://<id>@host:port?...`: VLESS share link.
     *
     * @throws RouteException
     */
    public static function parse(#[\SensitiveParameter] ?string $uri): self {
        $uri = \trim($uri ?? '');

        if ($uri === '') {
            return new self(RouteType::Direct);
        }

        $parts = \parse_url($uri);

        if ($parts === false) {
            throw new RouteException(\sprintf('Malformed route URI: %s', RouteFactory::redact($uri)));
        }

        $scheme = \strtolower($parts['scheme'] ?? '');

        if ($scheme === '' || !\str_contains($uri, '://')) {
            throw new RouteException(\sprintf('Malformed route URI: %s', RouteFactory::redact($uri)));
        }

        return match ($scheme) {
            'http' => self::parseProxy(RouteType::Http, $uri, $parts),
            'socks5' => self::parseProxy(RouteType::Socks5, $uri, $parts),
            'socks5h' => self::parseProxy(RouteType::Socks5h, $uri, $parts),
            'vless' => self::parseVless($uri, $parts),
            'https' => throw new RouteException(
                \sprintf(
                    'Route %s: proxies reached over TLS (https://) are not supported, use http:// for an HTTP CONNECT proxy',
                    RouteFactory::redact($uri),
                ),
            ),
            'socks', 'socks4', 'socks4a' => throw new RouteException(
                \sprintf('Route %s: only SOCKS5 is supported, use socks5:// or socks5h://', RouteFactory::redact($uri)),
            ),
            default => throw new RouteException(
                \sprintf(
                    'Unsupported route scheme "%s" (%s); supported: http, socks5, socks5h, vless, or empty for direct',
                    RouteFactory::safeScheme($scheme),
                    RouteFactory::redact($uri),
                ),
            ),
        };
    }

    public function type(): RouteType {
        return $this->type;
    }

    /** Proxy or VLESS server host (IPv6 without brackets); null for a direct route. */
    public function host(): ?string {
        return $this->host;
    }

    /** Proxy or VLESS server port; null for a direct route. */
    public function port(): ?int {
        return $this->port;
    }

    /** True when the route carries a secret: proxy credentials or a VLESS id. */
    public function hasCredentials(): bool {
        return $this->username !== null || $this->vless !== null;
    }

    /** Proxy user name (null when the proxy needs no authentication). */
    public function username(): ?string {
        return $this->username;
    }

    /** Proxy password (null when the proxy needs no authentication). */
    public function password(): ?string {
        return $this->password;
    }

    /** Parsed VLESS configuration; null for other route types. */
    public function vlessConfig(): ?VlessConfig {
        return $this->vless;
    }

    /** `host:port` of the proxy or VLESS server, IPv6 in brackets; null for a direct route. */
    public function authority(): ?string {
        if ($this->host === null || $this->port === null) {
            return null;
        }

        $host = \str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;

        return $host . ':' . $this->port;
    }

    /** Redacted form for logs: `socks5h://***@proxy:1080`, `vless://***@host:443`, `direct`. */
    public function __toString(): string {
        if ($this->type === RouteType::Direct) {
            return 'direct';
        }

        return $this->type->value . '://' . ($this->hasCredentials() ? '***@' : '') . (string) $this->authority();
    }

    /**
     * @return array{route: string, type: string, host: ?string, port: ?int, hasCredentials: bool}
     */
    public function __debugInfo(): array {
        return [
            'route' => (string) $this,
            'type' => $this->type->value,
            'host' => $this->host,
            'port' => $this->port,
            'hasCredentials' => $this->hasCredentials(),
        ];
    }

    /** A route holds secrets; serializing it would write them out. */
    public function __serialize(): array {
        throw new \LogicException('A route holds secrets and cannot be serialized');
    }

    /**
     * @param array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string} $parts
     */
    private static function parseProxy(RouteType $type, #[\SensitiveParameter] string $uri, #[\SensitiveParameter] array $parts): self {
        $redacted = RouteFactory::redact($uri);
        [$host, $port] = self::hostAndPort($parts, $redacted);

        if ((isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RouteException(\sprintf('Route %s: a proxy URI must not contain a path, query or fragment', $redacted));
        }

        $username = isset($parts['user']) ? \rawurldecode($parts['user']) : null;
        $password = isset($parts['pass']) ? \rawurldecode($parts['pass']) : null;

        if ($username === '' && ($password === null || $password === '')) {
            $username = $password = null;
        }

        if ($username !== null || $password !== null) {
            $username ??= '';
            $password ??= '';

            if ($username === '') {
                throw new RouteException(\sprintf('Route %s: the proxy user name is empty', $redacted));
            }

            if ($type === RouteType::Http && \str_contains($username, ':')) {
                throw new RouteException(\sprintf('Route %s: an HTTP proxy user name must not contain ":"', $redacted));
            }

            if ($type !== RouteType::Http && (\strlen($username) > 255 || \strlen($password) > 255 || $password === '')) {
                throw new RouteException(
                    \sprintf('Route %s: SOCKS5 user name and password must be 1 to 255 bytes long (RFC 1929)', $redacted),
                );
            }

            if (\preg_match('/[\x00-\x1f\x7f]/', $username . $password) === 1) {
                throw new RouteException(\sprintf('Route %s: proxy credentials contain control characters', $redacted));
            }
        }

        return new self($type, $host, $port, $username, $password);
    }

    /**
     * @param array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string} $parts
     */
    private static function parseVless(#[\SensitiveParameter] string $uri, #[\SensitiveParameter] array $parts): self {
        $redacted = RouteFactory::redact($uri);
        $id = isset($parts['user']) ? \rawurldecode($parts['user']) : '';

        try {
            $config = VlessConfig::fromUri($uri);
        } catch (UnsupportedFeatureException $exception) {
            throw new RouteException(
                \sprintf(
                    'VLESS route %s uses a feature that amphp-vless cannot provide (no REALITY, no XTLS Vision, '
                        . 'no mkcp/quic/h2/h3 transports), it will not be downgraded silently: %s',
                    $redacted,
                    self::scrub($exception->getMessage(), $id),
                ),
            );
        } catch (VlessException|\InvalidArgumentException $exception) {
            throw new RouteException(
                \sprintf('Invalid VLESS route %s: %s', $redacted, self::scrub($exception->getMessage(), $id)),
            );
        }

        return new self(RouteType::Vless, $config->server, $config->port, vless: $config);
    }

    /**
     * @param array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string} $parts
     *
     * @return array{string, int}
     */
    private static function hostAndPort(array $parts, string $redacted): array {
        $host = $parts['host'] ?? '';

        if (\str_starts_with($host, '[') && \str_ends_with($host, ']')) {
            $host = \substr($host, 1, -1);
        }

        if ($host === '' || \preg_match('/[\x00-\x20\x7f\/@?#]/', $host) === 1) {
            throw new RouteException(\sprintf('Route %s: the host is missing or invalid', $redacted));
        }

        $port = $parts['port'] ?? null;

        if ($port === null || $port < 1 || $port > 65535) {
            throw new RouteException(\sprintf('Route %s: a port from 1 to 65535 is required', $redacted));
        }

        return [$host, $port];
    }

    /** Removes the VLESS id from a library message, in case one ever ends up there. */
    private static function scrub(string $message, #[\SensitiveParameter] string $secret): string {
        return $secret === '' ? $message : \str_replace($secret, '***', $message);
    }
}
