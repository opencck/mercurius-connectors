# mercurius-connectors

**English** | [Русский](README.ru.md)

Outbound connection routes as an Amp `SocketConnector` factory: direct, HTTP CONNECT proxy, SOCKS5, VLESS (via [`rekryt/amphp-vless`](https://github.com/rekryt/amphp-vless)) and chains of them. Any AMPHP client — HTTP, database, SSH — can run over a route.

Part of [Mercurius](https://github.com/opencck/mercurius) — an autonomous personal agent. The package does not depend on the Mercurius application and can be used on its own.

**Status:** early development.

**Stack:** PHP 8.2+, AMPHP v3.

## Installation

```bash
composer require opencck/mercurius-connectors
```

Until the package is published on Packagist, add the GitHub repository first:

```bash
composer config repositories.mercurius-connectors vcs https://github.com/opencck/mercurius-connectors
composer require opencck/mercurius-connectors:dev-master
```

## Routes

| URI | Route |
|-----|-------|
| `null` or `''` | Direct: the default `Amp\Socket\socketConnector()` |
| `http://[user:pass@]host:port` | HTTP proxy with `CONNECT` (`amphp/http-tunnel`), `Proxy-Authorization: Basic` when credentials are given |
| `socks5://[user:pass@]host:port` | SOCKS5; the destination name is resolved locally, the proxy gets an IP address |
| `socks5h://[user:pass@]host:port` | SOCKS5; the destination name is sent to the proxy and resolved there |
| `vless://<id>@host:port?...` | VLESS share link (`amphp-vless`): `tcp`, `ws`, `httpupgrade`, `grpc`, `xhttp`, with or without TLS |

Percent-encode special characters in credentials (`p%40ss` for `p@ss`). Anything else — `https://` proxies, SOCKS4, unknown schemes — is rejected with `RouteException`.

## Usage

```php
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClientBuilder;
use Mercurius\Connectors\RouteFactory;

$connector = RouteFactory::fromUri(getenv('OUTBOUND_ROUTE') ?: null, $logger);

// One long-lived client per route: connections are pooled and reused.
$client = (new HttpClientBuilder())
    ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory($connector)))
    ->build();

// A raw TCP socket over the same route (e.g. for amphp/ssh: `connector: $connector`).
$socket = $connector->connect('tcp://example.org:22', null, new \Amp\TimeoutCancellation(20));
```

Chains: each next hop is reached through the previous one, the destination through the last.

```php
use Mercurius\Connectors\Chain;

$connector = RouteFactory::chain('socks5h://corp-proxy:1080', 'vless://…');
// or, with a logger and a printable description:
$chain = Chain::of('socks5h://corp-proxy:1080', 'vless://…')->withLogger($logger);
$logger->info('route: ' . $chain);   // socks5h://corp-proxy:1080 -> vless://***@vpn.example:443
$connector = $chain->connector();
```

Every route type can carry the next hop. A direct (empty) hop inside a chain is rejected.

## API

| Class | Purpose |
|-------|---------|
| `RouteFactory::fromUri(?string $uri, ?LoggerInterface $logger = null): SocketConnector` | Connector for one route |
| `RouteFactory::fromRoute(Route $route, ?LoggerInterface $logger = null, ?SocketConnector $via = null): SocketConnector` | Connector for a parsed route, optionally reaching the proxy through `$via` |
| `RouteFactory::chain(string ...$uris): SocketConnector` | Connector through a chain of routes |
| `RouteFactory::redact(string $uri): string` | Scheme, host and port only; user info (credentials, VLESS id) becomes `***`, path and query are dropped |
| `Route::parse(?string $uri): Route` | Parsed route: `type(): RouteType`, `host()`, `port()`, `hasCredentials()`, `__toString()` (redacted) |
| `RouteType` | Enum: `Direct`, `Http`, `Socks5`, `Socks5h`, `Vless` |
| `Chain` | Immutable chain builder: `of(string ...$uris)`, `then(string $uri)`, `withLogger()`, `hops()`, `connector()`, `__toString()` |
| `Socks5Connector` | SOCKS5 client (RFC 1928 CONNECT, RFC 1929 username/password), usable on its own |
| `RouteException extends \RuntimeException` | The route cannot be built; thrown before any network I/O |

Connection failures are the usual `Amp\Socket\ConnectException` (retry or fail over); cancellation by the caller is `Amp\CancelledException`. The SOCKS5 connector bounds the whole connect (DNS, proxy connection, handshake) by the `ConnectContext` connect timeout and the caller's `Cancellation`.

## Secrets

Exception messages, logs and `Route::__toString()` / `__debugInfo()` never contain passwords, VLESS ids or full URIs. `Route` refuses to be serialized. Keep route URIs themselves in secret storage.

## VLESS limitations

- **No REALITY and no XTLS Vision** (`flow=xtls-rprx-vision`): `amphp-vless` cannot implement them in PHP. Such links are rejected with a `RouteException` naming the feature — never silently downgraded to plain TLS. The server needs an inbound with `ws`, `grpc`, `httpupgrade`, `xhttp` or `tcp` and a real TLS certificate.
- **No multiplexing:** every `connect()` builds a new tunnel (TCP, TLS, transport handshake). Pool connections at the HTTP client level (as in the example above) instead of creating a client per request.
- VLESS has no "destination reached" reply: an unreachable destination shows up as silence or EOF, so keep your own read deadline.
- HTTPS inside VLESS is TLS inside TLS; it does not protect against statistical traffic analysis.

## Checks

```bash
composer install
composer test               # unit tests, no network needed
composer check              # tests + PHPStan (level max) + Psalm (level 1)
VLESS_URI='vless://…' composer test-integration   # fetches https://openrouter.ai/api/v1/models through VLESS
```

## License

[MIT](LICENSE)
