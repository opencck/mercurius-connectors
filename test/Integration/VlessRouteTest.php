<?php

declare(strict_types=1);

namespace Mercurius\Connectors\Test\Integration;

use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\ConnectContext;
use Amp\TimeoutCancellation;
use Mercurius\Connectors\RouteFactory;

/**
 * Needs a real VLESS server: set VLESS_URI. Run with `composer test-integration`.
 *
 * @group integration
 */
final class VlessRouteTest extends AsyncTestCase {
    public function testFetchesOpenRouterModelsThroughVless(): void {
        $uri = \getenv('VLESS_URI');

        if (!\is_string($uri) || $uri === '') {
            self::markTestSkipped('VLESS_URI is not set');
        }

        $this->setTimeout(60);

        $connector = RouteFactory::fromUri($uri);
        $client = (new HttpClientBuilder())
            ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory($connector, new ConnectContext())))
            ->build();

        $request = new Request('https://openrouter.ai/api/v1/models');
        $request->setTcpConnectTimeout(15);
        $request->setTlsHandshakeTimeout(20);
        $request->setTransferTimeout(40);

        $response = $client->request($request, new TimeoutCancellation(50));
        $body = $response->getBody()->buffer();

        self::assertSame(200, $response->getStatus());
        $data = \json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayHasKey('data', $data);
    }
}
