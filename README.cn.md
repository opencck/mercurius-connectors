# mercurius-connectors

[English](README.md) | [Русский](README.ru.md) | **中文**

> 本文由模型翻译，尚未经人工审校；如有出入，以英文版为准。

作为 Amp `SocketConnector` 工厂的出站连接路由：直连、HTTP CONNECT 代理、SOCKS5、VLESS（通过 [`rekryt/amphp-vless`](https://github.com/rekryt/amphp-vless)）及它们的链。任何 AMPHP 客户端——HTTP、数据库、SSH——都可以在某条路由上运行。

[Mercurius](https://github.com/opencck/mercurius) 的一部分——一个自主的个人智能体。该包不依赖 Mercurius 应用，可以单独使用。

**状态：** 早期开发。

**技术栈：** PHP 8.2+、AMPHP v3。

## 安装

```bash
composer require opencck/mercurius-connectors
```

在该包发布到 Packagist 之前，请先添加 GitHub 仓库：

```bash
composer config repositories.mercurius-connectors vcs https://github.com/opencck/mercurius-connectors
composer require opencck/mercurius-connectors:dev-master
```

## 路由

| URI | 路由 |
|-----|-------|
| `null` 或 `''` | 直连：默认的 `Amp\Socket\socketConnector()` |
| `http://[user:pass@]host:port` | 使用 `CONNECT` 的 HTTP 代理（`amphp/http-tunnel`），提供凭据时带 `Proxy-Authorization: Basic` |
| `socks5://[user:pass@]host:port` | SOCKS5；目标名称在本地解析，代理得到的是 IP 地址 |
| `socks5h://[user:pass@]host:port` | SOCKS5；目标名称发送给代理，由代理解析 |
| `vless://<id>@host:port?...` | VLESS 分享链接（`amphp-vless`）：`tcp`、`ws`、`httpupgrade`、`grpc`、`xhttp`，带或不带 TLS |

凭据中的特殊字符需要百分号编码（`p@ss` 写作 `p%40ss`）。其他任何形式——`https://` 代理、SOCKS4、未知的协议方案——都会被拒绝并抛出 `RouteException`。

## 用法

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

链：下一跳通过上一跳到达，目标则通过最后一跳到达。

```php
use Mercurius\Connectors\Chain;

$connector = RouteFactory::chain('socks5h://corp-proxy:1080', 'vless://…');
// or, with a logger and a printable description:
$chain = Chain::of('socks5h://corp-proxy:1080', 'vless://…')->withLogger($logger);
$logger->info('route: ' . $chain);   // socks5h://corp-proxy:1080 -> vless://***@vpn.example:443
$connector = $chain->connector();
```

每种路由类型都可以携带下一跳。链中的直连（空）跳会被拒绝。

## API

| 类 | 用途 |
|-------|---------|
| `RouteFactory::fromUri(?string $uri, ?LoggerInterface $logger = null): SocketConnector` | 单条路由的连接器 |
| `RouteFactory::fromRoute(Route $route, ?LoggerInterface $logger = null, ?SocketConnector $via = null): SocketConnector` | 已解析路由的连接器，可选择通过 `$via` 到达代理 |
| `RouteFactory::chain(string ...$uris): SocketConnector` | 经由一条路由链的连接器 |
| `RouteFactory::redact(string $uri): string` | 仅保留协议方案、主机和端口；用户信息（凭据、VLESS id）变为 `***`，路径和查询串被去掉 |
| `Route::parse(?string $uri): Route` | 已解析的路由：`type(): RouteType`、`host()`、`port()`、`hasCredentials()`、`__toString()`（已脱敏） |
| `RouteType` | 枚举：`Direct`、`Http`、`Socks5`、`Socks5h`、`Vless` |
| `Chain` | 不可变的链构建器：`of(string ...$uris)`、`then(string $uri)`、`withLogger()`、`hops()`、`connector()`、`__toString()` |
| `Socks5Connector` | SOCKS5 客户端（RFC 1928 CONNECT、RFC 1929 用户名/密码），可单独使用 |
| `RouteException extends \RuntimeException` | 无法构建该路由；在任何网络 I/O 之前抛出 |

连接失败是通常的 `Amp\Socket\ConnectException`（重试或切换到备用）；调用方的取消是 `Amp\CancelledException`。SOCKS5 连接器会用 `ConnectContext` 的连接超时和调用方的 `Cancellation` 限制整个连接过程（DNS、代理连接、握手）。

## 机密

异常消息、日志以及 `Route::__toString()` / `__debugInfo()` 绝不包含密码、VLESS id 或完整的 URI。`Route` 拒绝被序列化。请把路由 URI 本身存放在机密存储中。

## VLESS 的限制

- **不支持 REALITY，也不支持 XTLS Vision**（`flow=xtls-rprx-vision`）：`amphp-vless` 无法用 PHP 实现它们。这类链接会被拒绝，并抛出点明该特性的 `RouteException`——绝不会悄悄降级为普通 TLS。服务器需要一个使用 `ws`、`grpc`、`httpupgrade`、`xhttp` 或 `tcp` 的入站，以及真实的 TLS 证书。
- **不支持多路复用：** 每次 `connect()` 都会建立一条新隧道（TCP、TLS、传输握手）。请在 HTTP 客户端层面做连接池（如上例），不要为每个请求创建一个客户端。
- VLESS 没有“已到达目标”的应答：无法到达的目标表现为沉默或 EOF，因此请自行设置读取截止时间。
- VLESS 内的 HTTPS 是 TLS 套 TLS；它不能防御流量的统计分析。

## 检查

```bash
composer install
composer test               # unit tests, no network needed
composer check              # tests + PHPStan (level max) + Psalm (level 1)
VLESS_URI='vless://…' composer test-integration   # fetches https://openrouter.ai/api/v1/models through VLESS
```

## 许可证

[MIT](LICENSE)
