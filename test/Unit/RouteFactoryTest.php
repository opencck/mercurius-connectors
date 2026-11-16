<?php

declare(strict_types=1);

namespace Mercurius\Connectors\Test\Unit;

use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\Vless\VlessConnector;
use Mercurius\Connectors\Chain;
use Mercurius\Connectors\RouteException;
use Mercurius\Connectors\RouteFactory;
use Mercurius\Connectors\Socks5Connector;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

use function Amp\Socket\socketConnector;

final class RouteFactoryTest extends TestCase {
    private const UUID = 'b831381d-6324-4d53-ad4f-8cda48b30811';

    public function testDirectIsTheDefaultConnector(): void {
        self::assertSame(socketConnector(), RouteFactory::fromUri(null));
        self::assertSame(socketConnector(), RouteFactory::fromUri(''));
    }

    public function testConnectorTypes(): void {
        self::assertInstanceOf(Http1TunnelConnector::class, RouteFactory::fromUri('http://u:p@proxy:3128'));
        self::assertInstanceOf(Socks5Connector::class, RouteFactory::fromUri('socks5://proxy:1080'));
        self::assertInstanceOf(Socks5Connector::class, RouteFactory::fromUri('socks5h://u:p@proxy:1080'));
        self::assertInstanceOf(
            VlessConnector::class,
            RouteFactory::fromUri('vless://' . self::UUID . '@vpn.example:443?security=tls&type=ws&path=%2Fws'),
        );
    }

    public function testUnsupportedSchemeThrows(): void {
        $this->expectException(RouteException::class);
        RouteFactory::fromUri('ssh://user@host:22');
    }

    public function testLogsRedactedRouteAndWarnsAboutInsecureVless(): void {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void {
                $text = (string) $message;

                if (isset($context['route']) && \is_string($context['route'])) {
                    $text = \str_replace('{route}', $context['route'], $text);
                }

                $this->records[] = [$level, $text];
            }
        };

        RouteFactory::fromUri('vless://' . self::UUID . '@vpn.example:443?security=tls&allowInsecure=1', $logger);

        $levels = \array_column($logger->records, 0);
        self::assertContains('warning', $levels);

        foreach ($logger->records as [, $text]) {
            self::assertStringNotContainsString(self::UUID, $text);
            self::assertStringContainsString('vless://***@vpn.example:443', $text);
        }
    }

    public function testChainOfNothingIsDirect(): void {
        self::assertSame(socketConnector(), RouteFactory::chain());
    }

    public function testChainRejectsDirectHop(): void {
        $this->expectException(RouteException::class);
        $this->expectExceptionMessage('Hop 2');
        RouteFactory::chain('socks5://proxy:1080', '');
    }

    public function testChainBuildsEveryRouteType(): void {
        $chain = Chain::of(
            'socks5h://u:p@first:1080',
            'http://second:3128',
            'vless://' . self::UUID . '@vpn.example:443?security=tls&type=grpc&serviceName=x',
        );

        self::assertCount(3, $chain->hops());
        self::assertSame('socks5h://***@first:1080 -> http://second:3128 -> vless://***@vpn.example:443', (string) $chain);
        self::assertInstanceOf(VlessConnector::class, $chain->connector());
    }

    public function testChainRejectsVisionHopWithoutSecrets(): void {
        try {
            RouteFactory::chain('socks5://proxy:1080', 'vless://' . self::UUID . '@h:443?security=tls&flow=xtls-rprx-vision');
            self::fail('RouteException expected');
        } catch (RouteException $exception) {
            self::assertStringContainsString('xtls-rprx-vision', $exception->getMessage());
            self::assertStringNotContainsString(self::UUID, $exception->getMessage());
        }
    }
}
