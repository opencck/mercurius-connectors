<?php

declare(strict_types=1);

namespace Mercurius\Connectors\Test\Unit;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\InternetAddress;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Mercurius\Connectors\RouteException;
use Mercurius\Connectors\RouteFactory;
use Mercurius\Connectors\Socks5Connector;
use Mercurius\Connectors\Test\Support\EchoServer;
use Mercurius\Connectors\Test\Support\FakeSocks5Server;

use function Amp\delay;

final class Socks5ConnectorTest extends AsyncTestCase {
    private const PASSWORD = 'pa55-w0rd';

    private EchoServer $echo;

    /** @var list<FakeSocks5Server|EchoServer> */
    private array $servers = [];

    protected function setUp(): void {
        parent::setUp();
        $this->setTimeout(10);
        $this->echo = $this->track(new EchoServer());
    }

    protected function tearDown(): void {
        foreach ($this->servers as $server) {
            $server->close();
        }

        parent::tearDown();
    }

    public function testNoAuthDomainIsSentToProxyAndDataFlows(): void {
        $proxy = $this->track(new FakeSocks5Server());
        $socket = RouteFactory::fromUri($proxy->uri('socks5h'))->connect('tcp://localhost:' . $this->echo->port());

        self::assertSame('ping', $this->roundTrip($socket, 'ping'));
        self::assertSame("\x00", $proxy->requests[0]['methods']);
        self::assertSame(3, $proxy->requests[0]['atyp']);
        self::assertSame('localhost', $proxy->requests[0]['host']);
        self::assertSame($this->echo->port(), $proxy->requests[0]['port']);
    }

    public function testSocks5ResolvesLocallyAndSendsAnAddress(): void {
        $proxy = $this->track(new FakeSocks5Server());
        $context = (new ConnectContext())->withDnsTypeRestriction(\Amp\Dns\DnsRecord::A);
        $socket = RouteFactory::fromUri($proxy->uri('socks5'))->connect('localhost:' . $this->echo->port(), $context);

        self::assertSame('data', $this->roundTrip($socket, 'data'));
        self::assertSame(1, $proxy->requests[0]['atyp']);
        self::assertSame('127.0.0.1', $proxy->requests[0]['host']);
    }

    public function testIpv4LiteralAndInternetAddress(): void {
        $proxy = $this->track(new FakeSocks5Server());
        $socket = RouteFactory::fromUri($proxy->uri())->connect(new InternetAddress('127.0.0.1', $this->echo->port()));

        self::assertSame('x', $this->roundTrip($socket, 'x'));
        self::assertSame(1, $proxy->requests[0]['atyp']);
        self::assertSame('127.0.0.1', $proxy->requests[0]['host']);
    }

    public function testIpv6LiteralUsesAddressTypeFour(): void {
        $proxy = $this->track(new FakeSocks5Server(reply: 0x04));

        try {
            RouteFactory::fromUri($proxy->uri())->connect('tcp://[2001:db8::1]:443');
            self::fail('ConnectException expected');
        } catch (ConnectException $exception) {
            self::assertStringContainsString('host unreachable', $exception->getMessage());
            self::assertStringContainsString('[2001:db8::1]:443', $exception->getMessage());
        }

        self::assertSame(4, $proxy->requests[0]['atyp']);
        self::assertSame('2001:db8::1', $proxy->requests[0]['host']);
    }

    public function testUsernamePasswordAuthentication(): void {
        $proxy = $this->track(new FakeSocks5Server(credentials: ['alice', self::PASSWORD]));
        $socket = RouteFactory::fromUri($proxy->uri('socks5h', 'alice:' . self::PASSWORD))
            ->connect('127.0.0.1:' . $this->echo->port());

        self::assertSame('hello', $this->roundTrip($socket, 'hello'));
        self::assertSame("\x00\x02", $proxy->requests[0]['methods']);
        self::assertSame(['alice', self::PASSWORD], $proxy->requests[0]['auth']);
    }

    public function testWrongPasswordIsRejectedWithoutLeakingIt(): void {
        $proxy = $this->track(new FakeSocks5Server(credentials: ['alice', 'right']));

        try {
            RouteFactory::fromUri($proxy->uri('socks5h', 'alice:' . self::PASSWORD))->connect('127.0.0.1:1');
            self::fail('ConnectException expected');
        } catch (ConnectException $exception) {
            self::assertStringContainsString('rejected the user name or password', $exception->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $exception->getMessage());
        }
    }

    public function testProxyRequiringCredentialsWithoutThem(): void {
        $proxy = $this->track(new FakeSocks5Server(credentials: ['alice', 'right']));

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('requires credentials');
        RouteFactory::fromUri($proxy->uri())->connect('127.0.0.1:1');
    }

    public function testFailureReply(): void {
        $proxy = $this->track(new FakeSocks5Server(reply: 0x05));

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('connection refused (0x05)');
        RouteFactory::fromUri($proxy->uri())->connect('example.org:80');
    }

    public function testBytesAfterReplyAreNotLost(): void {
        $proxy = $this->track(new FakeSocks5Server(afterReply: "SSH-2.0-fake\r\n"));
        $socket = RouteFactory::fromUri($proxy->uri())->connect('127.0.0.1:' . $this->echo->port());

        $received = '';

        while (\strlen($received) < 14) {
            $received .= (string) $socket->read();
        }

        self::assertSame("SSH-2.0-fake\r\n", $received);
        self::assertSame('after', $this->roundTrip($socket, 'after'));
    }

    public function testServerThatSpeaksFirst(): void {
        $greeter = $this->track(new EchoServer("220 ready\r\n"));
        $proxy = $this->track(new FakeSocks5Server());
        $socket = RouteFactory::fromUri($proxy->uri())->connect('127.0.0.1:' . $greeter->port());

        $received = '';

        while (\strlen($received) < 11) {
            $received .= (string) $socket->read();
        }

        self::assertSame("220 ready\r\n", $received);
        $socket->close();
    }

    public function testCancellationStopsHangingHandshake(): void {
        $proxy = $this->track(new FakeSocks5Server(hang: true));
        $cancellation = new DeferredCancellation();
        \Revolt\EventLoop::delay(0.2, static fn () => $cancellation->cancel());

        $start = \microtime(true);

        try {
            RouteFactory::fromUri($proxy->uri())->connect('127.0.0.1:1', null, $cancellation->getCancellation());
            self::fail('CancelledException expected');
        } catch (CancelledException) {
            self::assertLessThan(2.0, \microtime(true) - $start);
        }
    }

    public function testConnectTimeoutFromContext(): void {
        $proxy = $this->track(new FakeSocks5Server(hang: true));
        $start = \microtime(true);

        try {
            RouteFactory::fromUri($proxy->uri())->connect('127.0.0.1:1', (new ConnectContext())->withConnectTimeout(0.3));
            self::fail('ConnectException expected');
        } catch (ConnectException $exception) {
            self::assertStringContainsString('timed out', $exception->getMessage());
            self::assertLessThan(2.0, \microtime(true) - $start);
        }
    }

    public function testNotASocksServer(): void {
        $notSocks = $this->track(new EchoServer("HTTP/1.1 400 Bad Request\r\n\r\n"));

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('does not speak SOCKS5');
        RouteFactory::fromUri('socks5://127.0.0.1:' . $notSocks->port())->connect('127.0.0.1:1');
    }

    public function testChainThroughTwoSocksServers(): void {
        $first = $this->track(new FakeSocks5Server());
        $second = $this->track(new FakeSocks5Server(credentials: ['bob', self::PASSWORD]));

        $socket = RouteFactory::chain($first->uri(), $second->uri('socks5h', 'bob:' . self::PASSWORD))
            ->connect('127.0.0.1:' . $this->echo->port());

        self::assertSame('chained', $this->roundTrip($socket, 'chained'));
        self::assertSame($second->port(), $first->requests[0]['port']);
        self::assertSame($this->echo->port(), $second->requests[0]['port']);
    }

    public function testUsesTheGivenUnderlyingConnector(): void {
        $proxy = $this->track(new FakeSocks5Server());
        $counting = new class implements SocketConnector {
            public int $calls = 0;

            public function connect(
                \Amp\Socket\SocketAddress|string $uri,
                ?ConnectContext $context = null,
                ?\Amp\Cancellation $cancellation = null,
            ): Socket {
                $this->calls++;

                return \Amp\Socket\connect($uri, $context, $cancellation);
            }
        };

        $connector = new Socks5Connector('127.0.0.1', $proxy->port(), connector: $counting);
        self::assertSame('via', $this->roundTrip($connector->connect('127.0.0.1:' . $this->echo->port()), 'via'));
        self::assertSame(1, $counting->calls);
    }

    public function testInvalidCredentialsInConstructor(): void {
        $this->expectException(RouteException::class);
        new Socks5Connector('127.0.0.1', 1080, 'user', null);
    }

    public function testInvalidDestination(): void {
        $this->expectException(ConnectException::class);
        (new Socks5Connector('127.0.0.1', 1080))->connect('udp://127.0.0.1:53');
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
        delay(0);

        return $received;
    }

    /**
     * @template T of FakeSocks5Server|EchoServer
     *
     * @param T $server
     *
     * @return T
     */
    private function track(FakeSocks5Server|EchoServer $server): FakeSocks5Server|EchoServer {
        $this->servers[] = $server;

        return $server;
    }
}
