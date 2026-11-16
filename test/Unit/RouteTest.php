<?php

declare(strict_types=1);

namespace Mercurius\Connectors\Test\Unit;

use Mercurius\Connectors\Route;
use Mercurius\Connectors\RouteException;
use Mercurius\Connectors\RouteFactory;
use Mercurius\Connectors\RouteType;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase {
    private const UUID = 'b831381d-6324-4d53-ad4f-8cda48b30811';
    private const PASSWORD = 's3cr3t-Pa55';

    public function testNullAndEmptyAreDirect(): void {
        foreach ([null, '', '   '] as $uri) {
            $route = Route::parse($uri);
            self::assertSame(RouteType::Direct, $route->type());
            self::assertNull($route->host());
            self::assertNull($route->port());
            self::assertFalse($route->hasCredentials());
            self::assertSame('direct', (string) $route);
        }
    }

    public function testHttpWithCredentials(): void {
        $route = Route::parse('http://user:' . self::PASSWORD . '@proxy.example:3128');

        self::assertSame(RouteType::Http, $route->type());
        self::assertSame('proxy.example', $route->host());
        self::assertSame(3128, $route->port());
        self::assertTrue($route->hasCredentials());
        self::assertSame('user', $route->username());
        self::assertSame(self::PASSWORD, $route->password());
        self::assertSame('http://***@proxy.example:3128', (string) $route);
    }

    public function testPercentEncodedCredentialsAreDecoded(): void {
        $route = Route::parse('socks5://us%40er:p%3Ass@10.0.0.1:1080/');

        self::assertSame('us@er', $route->username());
        self::assertSame('p:ss', $route->password());
    }

    public function testSocksSchemes(): void {
        $plain = Route::parse('socks5://127.0.0.1:1080');
        self::assertSame(RouteType::Socks5, $plain->type());
        self::assertFalse($plain->hasCredentials());
        self::assertSame('socks5://127.0.0.1:1080', (string) $plain);

        $remote = Route::parse('SOCKS5H://proxy:1080');
        self::assertSame(RouteType::Socks5h, $remote->type());
    }

    public function testIpv6Host(): void {
        $route = Route::parse('socks5h://[2001:db8::1]:1080');

        self::assertSame('2001:db8::1', $route->host());
        self::assertSame('[2001:db8::1]:1080', $route->authority());
        self::assertSame('socks5h://[2001:db8::1]:1080', (string) $route);
    }

    public function testVless(): void {
        $route = Route::parse('vless://' . self::UUID . '@vpn.example:443?security=tls&type=ws&path=%2Fws#label');

        self::assertSame(RouteType::Vless, $route->type());
        self::assertSame('vpn.example', $route->host());
        self::assertSame(443, $route->port());
        self::assertTrue($route->hasCredentials());
        self::assertNotNull($route->vlessConfig());
        self::assertSame('vless://***@vpn.example:443', (string) $route);
    }

    public function testDebugOutputHasNoSecrets(): void {
        foreach ([
            'vless://' . self::UUID . '@vpn.example:443?security=tls',
            'socks5h://user:' . self::PASSWORD . '@proxy:1080',
            'http://user:' . self::PASSWORD . '@proxy:3128',
        ] as $uri) {
            $route = Route::parse($uri);
            $dumps = [\print_r($route, true), \var_export($route->__debugInfo(), true), (string) $route];

            foreach ($dumps as $dump) {
                self::assertStringNotContainsString(self::UUID, $dump);
                self::assertStringNotContainsString(self::PASSWORD, $dump);
            }
        }
    }

    public function testRouteCannotBeSerialized(): void {
        $this->expectException(\LogicException::class);
        \serialize(Route::parse('socks5://user:' . self::PASSWORD . '@proxy:1080'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidUris(): iterable {
        yield 'unknown scheme' => ['ftp://user:' . self::PASSWORD . '@host:21', 'Unsupported route scheme "ftp"'];
        yield 'https proxy' => ['https://user:' . self::PASSWORD . '@proxy:443', 'not supported'];
        yield 'socks4' => ['socks4://user:' . self::PASSWORD . '@proxy:1080', 'only SOCKS5'];
        yield 'no scheme' => ['user:' . self::PASSWORD . '@proxy', 'Malformed'];
        yield 'missing port' => ['socks5://user:' . self::PASSWORD . '@proxy', 'port'];
        yield 'missing host' => ['http://:3128', 'Malformed'];
        yield 'path in proxy uri' => ['http://user:' . self::PASSWORD . '@proxy:3128/x?y=' . self::PASSWORD, 'path'];
        yield 'empty socks password' => ['socks5://user@proxy:1080', '1 to 255'];
        yield 'vless without port' => ['vless://' . self::UUID . '@vpn.example?security=tls', 'Invalid VLESS route'];
        yield 'vless unknown transport' => ['vless://' . self::UUID . '@vpn.example:443?type=bogus', 'Invalid VLESS route'];
    }

    /**
     * @dataProvider invalidUris
     */
    public function testInvalidUrisAreRejectedWithoutSecrets(string $uri, string $expected): void {
        try {
            Route::parse($uri);
            self::fail('RouteException expected');
        } catch (RouteException $exception) {
            self::assertStringContainsString($expected, $exception->getMessage());
            self::assertSecretFree($exception, $uri);
        }
    }

    public function testRealityIsRejectedClearly(): void {
        $uri = 'vless://' . self::UUID . '@vpn.example:443?security=reality&pbk=PUBLICKEY&sid=ab12&fp=chrome&type=tcp';

        try {
            Route::parse($uri);
            self::fail('RouteException expected');
        } catch (RouteException $exception) {
            self::assertStringContainsString('REALITY', $exception->getMessage());
            self::assertStringContainsString('vless://***@vpn.example:443', $exception->getMessage());
            self::assertStringNotContainsString('PUBLICKEY', $exception->getMessage());
            self::assertSecretFree($exception, $uri);
        }
    }

    public function testVisionIsRejectedClearly(): void {
        $uri = 'vless://' . self::UUID . '@vpn.example:443?security=tls&flow=xtls-rprx-vision&type=tcp';

        try {
            Route::parse($uri);
            self::fail('RouteException expected');
        } catch (RouteException $exception) {
            self::assertStringContainsString('xtls-rprx-vision', $exception->getMessage());
            self::assertStringContainsString('Vision', $exception->getMessage());
            self::assertSecretFree($exception, $uri);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function redactions(): iterable {
        yield 'vless' => ['vless://' . self::UUID . '@vpn.example:443?security=tls&path=%2Fws#My%20node', 'vless://***@vpn.example:443'];
        yield 'socks with credentials' => ['socks5h://user:' . self::PASSWORD . '@proxy:1080', 'socks5h://***@proxy:1080'];
        yield 'http without credentials' => ['http://proxy:3128', 'http://proxy:3128'];
        yield 'ipv6' => ['socks5://u:p@[::1]:1080', 'socks5://***@[::1]:1080'];
        yield 'garbage' => ['not a uri ' . self::PASSWORD, '[unparseable URI]'];
        yield 'empty' => ['', 'direct'];
        yield 'user only' => ['vless://' . self::UUID . '@h:1', 'vless://***@h:1'];
        yield 'unencoded hash in password' => ['http://u:12#' . self::PASSWORD . '@proxy:3128', 'http://***@proxy:3128'];
        yield 'unencoded slash in password' => ['socks5://u:9/' . self::PASSWORD . '@proxy:1080', 'socks5://***@proxy:1080'];
    }

    /**
     * @dataProvider redactions
     */
    public function testRedact(string $uri, string $expected): void {
        self::assertSame($expected, RouteFactory::redact($uri));
    }

    private static function assertSecretFree(\Throwable $exception, string $uri): void {
        do {
            $message = $exception->getMessage();
            self::assertStringNotContainsString(self::UUID, $message);
            self::assertStringNotContainsString(self::PASSWORD, $message);
            self::assertStringNotContainsString($uri, $message);
            $exception = $exception->getPrevious();
        } while ($exception !== null);
    }
}
