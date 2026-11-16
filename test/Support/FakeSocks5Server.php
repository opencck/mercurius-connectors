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
 * Minimal in-process SOCKS5 server (CONNECT only) for tests.
 *
 * On success it connects to the requested destination and relays bytes, so data flow through the tunnel
 * and chains through several servers can be checked end to end.
 */
final class FakeSocks5Server {
    /** @var list<array{methods: string, auth: ?array{string, string}, atyp: int, host: string, port: int}> */
    public array $requests = [];

    private readonly ResourceServerSocket $server;

    /**
     * @param array{string, string}|null $credentials Required user name and password.
     * @param int $reply Reply code for CONNECT; non-zero means failure.
     * @param bool $hang Accept the connection and never answer.
     * @param string $afterReply Extra bytes sent in the same write as the success reply.
     */
    public function __construct(
        private readonly ?array $credentials = null,
        private readonly int $reply = 0,
        private readonly bool $hang = false,
        private readonly string $afterReply = '',
    ) {
        $this->server = listen('127.0.0.1:0');

        async(function (): void {
            while ($client = $this->server->accept()) {
                async($this->serve(...), $client)->ignore();
            }
        })->ignore();
    }

    public function uri(string $scheme = 'socks5h', ?string $userInfo = null): string {
        return $scheme . '://' . ($userInfo !== null ? $userInfo . '@' : '') . '127.0.0.1:' . $this->port();
    }

    /** @return int<0, 65535> */
    public function port(): int {
        $address = $this->server->getAddress();
        \assert($address instanceof InternetAddress);

        return $address->getPort();
    }

    public function close(): void {
        $this->server->close();
    }

    private function serve(Socket $client): void {
        $buffer = '';
        $read = static function (int $length) use ($client, &$buffer): string {
            while (\strlen($buffer) < $length) {
                $chunk = $client->read();

                if ($chunk === null) {
                    throw new \RuntimeException('client went away');
                }

                $buffer .= $chunk;
            }

            $result = \substr($buffer, 0, $length);
            $buffer = \substr($buffer, $length);

            return $result;
        };

        try {
            if ($this->hang) {
                while ($client->read() !== null) {
                    // swallow everything, answer nothing
                }

                return;
            }

            $head = $read(2);
            \assert($head[0] === "\x05");
            $methods = $read(\ord($head[1]));
            $auth = null;

            if ($this->credentials !== null) {
                if (!\str_contains($methods, "\x02")) {
                    $client->write("\x05\xFF");

                    return;
                }

                $client->write("\x05\x02");
                $read(1);
                $user = $read(\ord($read(1)));
                $pass = $read(\ord($read(1)));
                $auth = [$user, $pass];

                if ([$user, $pass] !== $this->credentials) {
                    $client->write("\x01\x01");

                    return;
                }

                $client->write("\x01\x00");
            } else {
                $client->write("\x05\x00");
            }

            $request = $read(4);
            $atyp = \ord($request[3]);
            $host = match ($atyp) {
                1 => (string) \inet_ntop($read(4)),
                4 => (string) \inet_ntop($read(16)),
                3 => $read(\ord($read(1))),
                default => throw new \RuntimeException('bad atyp'),
            };
            $unpacked = \unpack('n', $read(2));
            \assert(\is_array($unpacked) && \is_int($unpacked[1]));
            $port = $unpacked[1];

            $this->requests[] = ['methods' => $methods, 'auth' => $auth, 'atyp' => $atyp, 'host' => $host, 'port' => $port];

            if ($this->reply !== 0) {
                $client->write("\x05" . \chr($this->reply) . "\x00\x01\x00\x00\x00\x00\x00\x00");

                return;
            }

            $upstream = connect((\str_contains($host, ':') ? "[$host]" : $host) . ':' . $port);
            $client->write("\x05\x00\x00\x01\x7f\x00\x00\x01\x00\x00" . $this->afterReply);

            if ($buffer !== '') {
                $upstream->write($buffer);
            }

            Relay::run($client, $upstream);
        } catch (\Throwable) {
        } finally {
            $client->close();
        }
    }
}
