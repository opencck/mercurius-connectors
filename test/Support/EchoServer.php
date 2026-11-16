<?php

declare(strict_types=1);

namespace Mercurius\Connectors\Test\Support;

use Amp\Socket\ResourceServerSocket;
use Amp\Socket\Socket;

use function Amp\async;
use function Amp\Socket\listen;

/**
 * Echo server on 127.0.0.1 that optionally greets first (like SSH).
 */
final class EchoServer {
    private readonly ResourceServerSocket $server;

    public function __construct(private readonly string $greeting = '') {
        $this->server = listen('127.0.0.1:0');

        async(function (): void {
            while ($client = $this->server->accept()) {
                async($this->serve(...), $client)->ignore();
            }
        })->ignore();
    }

    public function address(): string {
        return $this->server->getAddress()->toString();
    }

    /** @return int<0, 65535> */
    public function port(): int {
        $address = $this->server->getAddress();
        \assert($address instanceof \Amp\Socket\InternetAddress);

        return $address->getPort();
    }

    public function close(): void {
        $this->server->close();
    }

    private function serve(Socket $client): void {
        try {
            if ($this->greeting !== '') {
                $client->write($this->greeting);
            }

            while (null !== $chunk = $client->read()) {
                $client->write($chunk);
            }
        } catch (\Throwable) {
        } finally {
            $client->close();
        }
    }
}
