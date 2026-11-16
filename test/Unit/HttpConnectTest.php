<?php

declare(strict_types=1);

namespace Mercurius\Connectors\Test\Unit;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;
use Mercurius\Connectors\RouteFactory;
use Mercurius\Connectors\Test\Support\EchoServer;
use Mercurius\Connectors\Test\Support\FakeHttpProxy;
use Mercurius\Connectors\Test\Support\FakeSocks5Server;

final class HttpConnectTest extends AsyncTestCase {
    private const PASSWORD = 'pr0xy-pass';

    private EchoServer $echo;

    /** @var list<FakeHttpProxy|FakeSocks5Server> */
    private array $proxies = [];

    protected function setUp(): void {
        parent::setUp();
        $this->setTimeout(10);
        $this->echo = new EchoServer();
    }

    protected function tearDown(): void {
        $this->echo->close();

        foreach ($this->proxies as $proxy) {
            $proxy->close();
        }

        parent::tearDown();
    }

    public function testTunnelWithoutCredentials(): void {
        $proxy = $this->proxies[] = new FakeHttpProxy();
        $socket = RouteFactory::fromUri($proxy->uri())->connect('tcp://127.0.0.1:' . $this->echo->port());

        self::assertSame('through http', $this->roundTrip($socket, 'through http'));
        self::assertSame('127.0.0.1:' . $this->echo->port(), $proxy->requests[0]['target']);
        self::assertNull($proxy->requests[0]['authorization']);
    }

    public function testBasicProxyAuthorization(): void {
        $expected = 'Basic ' . \base64_encode('alice:' . self::PASSWORD);
        $proxy = $this->proxies[] = new FakeHttpProxy($expected);
        $socket = RouteFactory::fromUri($proxy->uri('alice:' . self::PASSWORD))->connect('127.0.0.1:' . $this->echo->port());

        self::assertSame('authed', $this->roundTrip($socket, 'authed'));
        self::assertSame($expected, $proxy->requests[0]['authorization']);
    }

    public function testProxyAuthenticationRequired(): void {
        $proxy = $this->proxies[] = new FakeHttpProxy('Basic ' . \base64_encode('alice:right'));

        try {
            RouteFactory::fromUri($proxy->uri('alice:' . self::PASSWORD))->connect('127.0.0.1:' . $this->echo->port());
            self::fail('ConnectException expected');
        } catch (ConnectException $exception) {
            self::assertStringContainsString('407', $exception->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $exception->getMessage());
        }
    }

    public function testHttpProxyReachedThroughSocks5(): void {
        $socks = $this->proxies[] = new FakeSocks5Server();
        $http = $this->proxies[] = new FakeHttpProxy();

        $socket = RouteFactory::chain($socks->uri(), $http->uri())->connect('127.0.0.1:' . $this->echo->port());

        self::assertSame('two hops', $this->roundTrip($socket, 'two hops'));
        self::assertCount(1, $socks->requests);
        self::assertCount(1, $http->requests);
    }

    private function roundTrip(Socket $socket, string $payload): string {
        $socket->write($payload);
        $received = '';

        while (\strlen($received) < \strlen($payload)) {
            $chunk = $socket->read();

            if ($chunk === null) {
                break;
            }

            $received .= $chunk;
        }

        $socket->close();

        return $received;
    }
}
