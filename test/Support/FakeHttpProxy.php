<?php

declare(strict_types=1);

namespace Mercurius\Connectors\Test\Support;

use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\Socket;

use function Amp\async;
use function Amp\Socket\connect;
use function Amp\Socket\listen;

/**
 * Minimal in-process HTTP CONNECT proxy for tests: answers 407 without the expected
 * Proxy-Authorization, otherwise 200 and relays to the destination.
 */
final class FakeHttpProxy {
    /** @var list<array{target: string, authorization: ?string}> */
    public array $requests = [];

    private readonly ResourceServerSocket $server;

    public function __construct(private readonly ?string $expectedAuthorization = null) {
        $this->server = listen('127.0.0.1:0');

        async(function (): void {
            while ($client = $this->server->accept()) {
                async($this->serve(...), $client)->ignore();
            }
        })->ignore();
    }

    public function uri(?string $userInfo = null): string {
        $address = $this->server->getAddress();
        \assert($address instanceof InternetAddress);

        return 'http://' . ($userInfo !== null ? $userInfo . '@' : '') . '127.0.0.1:' . $address->getPort();
    }

    public function close(): void {
        $this->server->close();
    }

    private function serve(Socket $client): void {
        try {
            $buffer = '';

            while (!\str_contains($buffer, "\r\n\r\n")) {
                $chunk = $client->read();

                if ($chunk === null) {
                    return;
                }

                $buffer .= $chunk;
            }

            $end = (int) \strpos($buffer, "\r\n\r\n");
            $head = \substr($buffer, 0, $end);
            $rest = \substr($buffer, $end + 4);
            $lines = \explode("\r\n", $head);
            [$method, $target] = \explode(' ', $lines[0]);
            $authorization = null;

            foreach (\array_slice($lines, 1) as $line) {
                [$name, $value] = \array_map(\trim(...), \explode(':', $line, 2)) + [1 => ''];

                if (\strtolower($name) === 'proxy-authorization') {
                    $authorization = $value;
                }
            }

            $this->requests[] = ['target' => $target, 'authorization' => $authorization];

            if ($method !== 'CONNECT') {
                $client->write("HTTP/1.1 405 Method Not Allowed\r\nContent-Length: 0\r\n\r\n");

                return;
            }

            if ($this->expectedAuthorization !== null && $authorization !== $this->expectedAuthorization) {
                $client->write(
                    "HTTP/1.1 407 Proxy Authentication Required\r\nProxy-Authenticate: Basic realm=\"test\"\r\n"
                        . "Content-Length: 0\r\nConnection: close\r\n\r\n",
                );

                return;
            }

            $upstream = connect($target);
            $client->write("HTTP/1.1 200 Connection established\r\n\r\n");

            if ($rest !== '') {
                $upstream->write($rest);
            }

            Relay::run($client, $upstream);
        } catch (\Throwable) {
        } finally {
            $client->close();
        }
    }
}
