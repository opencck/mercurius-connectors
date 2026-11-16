<?php

declare(strict_types=1);

namespace Mercurius\Connectors;

use Amp\Socket\SocketConnector;
use Psr\Log\LoggerInterface;

/**
 * A chain of routes: the first hop is reached directly, every next hop through the previous one,
 * and the destination through the last.
 *
 * ```php
 * $connector = Chain::of('socks5h://corp-proxy:1080', 'vless://…')->withLogger($logger)->connector();
 * ```
 *
 * Every route type can carry the next hop: HTTP CONNECT and SOCKS5 open their proxy connection through
 * the previous hop, VLESS builds its transport on top of it. A direct route is not a hop and is rejected.
 *
 * Each hop adds a round trip to every exchange; a VLESS hop opens a new tunnel per connection.
 */
final class Chain implements \Stringable {
    /**
     * @param list<Route> $hops
     */
    private function __construct(
        private readonly array $hops,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @throws RouteException A URI is invalid or is an empty (direct) hop.
     */
    public static function of(#[\SensitiveParameter] string ...$uris): self {
        $chain = new self([]);

        foreach ($uris as $uri) {
            $chain = $chain->then($uri);
        }

        return $chain;
    }

    /**
     * Returns a chain with one more hop at the end (closest to the destination).
     *
     * @throws RouteException
     */
    public function then(#[\SensitiveParameter] string $uri): self {
        return $this->thenRoute(Route::parse($uri));
    }

    /**
     * @throws RouteException
     */
    public function thenRoute(Route $route): self {
        if ($route->type() === RouteType::Direct) {
            throw new RouteException(
                \sprintf('Hop %d of the chain is empty: a direct connection cannot be a hop', \count($this->hops) + 1),
            );
        }

        return new self([...$this->hops, $route], $this->logger);
    }

    public function withLogger(LoggerInterface $logger): self {
        return new self($this->hops, $logger);
    }

    /**
     * @return list<Route>
     */
    public function hops(): array {
        return $this->hops;
    }

    /**
     * Builds the connector. With no hops this is the direct connector.
     *
     * @throws RouteException
     */
    public function connector(): SocketConnector {
        $connector = null;

        foreach ($this->hops as $hop) {
            $connector = RouteFactory::fromRoute($hop, $this->logger, $connector);
        }

        return $connector ?? RouteFactory::fromRoute(Route::parse(null), $this->logger);
    }

    /** Redacted form for logs: `socks5h://proxy:1080 -> vless://***@host:443`. */
    public function __toString(): string {
        return $this->hops === [] ? 'direct' : \implode(' -> ', \array_map(\strval(...), $this->hops));
    }
}
