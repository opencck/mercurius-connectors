# mercurius-connectors

[English](README.md) | **Русский** | [中文](README.cn.md)

Маршруты исходящих соединений как фабрика `SocketConnector` из Amp: напрямую, через HTTP CONNECT-прокси, SOCKS5, VLESS (на [`rekryt/amphp-vless`](https://github.com/rekryt/amphp-vless)) и их цепочки. Через маршрут работает любой клиент AMPHP — HTTP, база данных, SSH.

Часть проекта [Меркурий (Mercurius)](https://github.com/opencck/mercurius) — автономного персонального агента. Пакет не зависит от приложения Меркурия и может использоваться отдельно.

**Статус:** ранняя разработка.

**Стек:** PHP 8.2+, AMPHP v3.

## Установка

```bash
composer require opencck/mercurius-connectors
```

Пока пакет не опубликован в Packagist, сначала подключите репозиторий GitHub:

```bash
composer config repositories.mercurius-connectors vcs https://github.com/opencck/mercurius-connectors
composer require opencck/mercurius-connectors:dev-master
```

## Маршруты

| URI | Маршрут |
|-----|---------|
| `null` или `''` | Напрямую: стандартный `Amp\Socket\socketConnector()` |
| `http://[user:pass@]host:port` | HTTP-прокси с методом `CONNECT` (`amphp/http-tunnel`), при наличии учётных данных — `Proxy-Authorization: Basic` |
| `socks5://[user:pass@]host:port` | SOCKS5; имя адресата резолвится локально, прокси получает IP-адрес |
| `socks5h://[user:pass@]host:port` | SOCKS5; имя адресата уходит прокси и резолвится там |
| `vless://<id>@host:port?...` | Ссылка VLESS (`amphp-vless`): `tcp`, `ws`, `httpupgrade`, `grpc`, `xhttp`, с TLS и без |

Спецсимволы в учётных данных кодируйте процентами (`p%40ss` вместо `p@ss`). Всё остальное — прокси `https://`, SOCKS4, неизвестные схемы — отклоняется с `RouteException`.

## Использование

```php
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClientBuilder;
use Mercurius\Connectors\RouteFactory;

$connector = RouteFactory::fromUri(getenv('OUTBOUND_ROUTE') ?: null, $logger);

// Один долгоживущий клиент на маршрут: соединения берутся из пула и переиспользуются.
$client = (new HttpClientBuilder())
    ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory($connector)))
    ->build();

// «Сырой» TCP-сокет через тот же маршрут (например, для amphp/ssh: `connector: $connector`).
$socket = $connector->connect('tcp://example.org:22', null, new \Amp\TimeoutCancellation(20));
```

Цепочки: каждый следующий узел достигается через предыдущий, адресат — через последний.

```php
use Mercurius\Connectors\Chain;

$connector = RouteFactory::chain('socks5h://corp-proxy:1080', 'vless://…');
// или с логгером и печатным описанием:
$chain = Chain::of('socks5h://corp-proxy:1080', 'vless://…')->withLogger($logger);
$logger->info('route: ' . $chain);   // socks5h://corp-proxy:1080 -> vless://***@vpn.example:443
$connector = $chain->connector();
```

Следующий узел может идти через маршрут любого типа. Прямой (пустой) узел внутри цепочки отклоняется.

## API

| Класс | Назначение |
|-------|------------|
| `RouteFactory::fromUri(?string $uri, ?LoggerInterface $logger = null): SocketConnector` | Коннектор для одного маршрута |
| `RouteFactory::fromRoute(Route $route, ?LoggerInterface $logger = null, ?SocketConnector $via = null): SocketConnector` | Коннектор для разобранного маршрута; до прокси можно идти через `$via` |
| `RouteFactory::chain(string ...$uris): SocketConnector` | Коннектор через цепочку маршрутов |
| `RouteFactory::redact(string $uri): string` | Только схема, хост и порт; user info (учётные данные, id VLESS) заменяется на `***`, путь и query отбрасываются |
| `Route::parse(?string $uri): Route` | Разобранный маршрут: `type(): RouteType`, `host()`, `port()`, `hasCredentials()`, `__toString()` (без секретов) |
| `RouteType` | Перечисление: `Direct`, `Http`, `Socks5`, `Socks5h`, `Vless` |
| `Chain` | Неизменяемый построитель цепочки: `of(string ...$uris)`, `then(string $uri)`, `withLogger()`, `hops()`, `connector()`, `__toString()` |
| `Socks5Connector` | Клиент SOCKS5 (RFC 1928 CONNECT, RFC 1929 логин/пароль), можно использовать отдельно |
| `RouteException extends \RuntimeException` | Маршрут нельзя построить; бросается до любого сетевого обмена |

Ошибки соединения — обычный `Amp\Socket\ConnectException` (повтор или переключение на запасной маршрут); отмена вызывающим — `Amp\CancelledException`. Коннектор SOCKS5 ограничивает всё подключение (DNS, соединение с прокси, рукопожатие) таймаутом из `ConnectContext` и `Cancellation` вызывающего.

## Секреты

Сообщения исключений, логи и `Route::__toString()` / `__debugInfo()` никогда не содержат паролей, id VLESS и полных URI. `Route` нельзя сериализовать. Сами URI маршрутов храните как секреты.

## Ограничения VLESS

- **Нет REALITY и XTLS Vision** (`flow=xtls-rprx-vision`): в PHP `amphp-vless` их реализовать не может. Такие ссылки отклоняются с `RouteException`, где названа причина, и никогда молча не превращаются в обычный TLS. На сервере нужен inbound `ws`, `grpc`, `httpupgrade`, `xhttp` или `tcp` с настоящим TLS-сертификатом.
- **Нет мультиплексирования:** каждый `connect()` строит новый туннель (TCP, TLS, рукопожатие транспорта). Держите пул соединений на уровне HTTP-клиента (как в примере выше), а не создавайте клиента на каждый запрос.
- У VLESS нет ответа «адресат достигнут»: недостижимый адресат выглядит как тишина или конец потока, поэтому нужен свой дедлайн на чтение.
- HTTPS внутри VLESS — это TLS внутри TLS; от статистического анализа трафика он не защищает.

## Проверки

```bash
composer install
composer test               # юнит-тесты, сеть не нужна
composer check              # тесты + PHPStan (уровень max) + Psalm (уровень 1)
VLESS_URI='vless://…' composer test-integration   # запрос https://openrouter.ai/api/v1/models через VLESS
```

## Лицензия

[MIT](LICENSE)
