<?php

declare(strict_types=1);

namespace Mercurius\Connectors;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use Amp\TimeoutCancellation;
use Mercurius\Connectors\Internal\Destination;
use Mercurius\Connectors\Internal\ExactReader;
use Mercurius\Connectors\Internal\PrefixedSocket;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\Dns\dnsResolver;
use function Amp\Socket\socketConnector;

/**
 * SOCKS5 client connector (RFC 1928, CONNECT command only).
 *
 * Authentication: none, or username/password (RFC 1929). With `$remoteDns` (the `socks5h://` scheme) a
 * destination name is sent to the proxy and resolved there; without it (`socks5://`) the name is resolved
 * locally and the proxy gets an IP address.
 *
 * The whole connect (resolving, reaching the proxy, the handshake) is bounded by the connect timeout of the
 * {@see ConnectContext} and by the caller's {@see Cancellation}. TLS to the destination works as with a direct
 * socket: the caller's TLS context goes to the proxy connection and `setupTls()` runs after the handshake.
 */
final class Socks5Connector implements SocketConnector {
    use ForbidCloning;
    use ForbidSerialization;

    private const VERSION = "\x05";

    private const METHOD_NONE = 0x00;
    private const METHOD_PASSWORD = 0x02;
    private const METHOD_UNACCEPTABLE = 0xFF;

    private const ATYP_IPV4 = 0x01;
    private const ATYP_DOMAIN = 0x03;
    private const ATYP_IPV6 = 0x04;

    private const REPLIES = [
        0x01 => 'general SOCKS server failure',
        0x02 => 'connection not allowed by ruleset',
        0x03 => 'network unreachable',
        0x04 => 'host unreachable',
        0x05 => 'connection refused',
        0x06 => 'TTL expired',
        0x07 => 'command not supported',
        0x08 => 'address type not supported',
    ];

    private readonly SocketConnector $connector;

    private readonly LoggerInterface $logger;

    private readonly string $proxy;

    /**
     * @param SocketConnector|null $connector Reaches the proxy. Captured now when omitted, so the connector
     *     can later be installed process-wide without dialling through itself.
     *
     * @throws RouteException Invalid credentials.
     */
    public function __construct(
        string $proxyHost,
        int $proxyPort,
        #[\SensitiveParameter] private readonly ?string $username = null,
        #[\SensitiveParameter] private readonly ?string $password = null,
        private readonly bool $remoteDns = true,
        ?SocketConnector $connector = null,
        ?LoggerInterface $logger = null,
    ) {
        if (($username === null) !== ($password === null)) {
            throw new RouteException('SOCKS5 user name and password must be given together');
        }

        if ($username !== null && $password !== null) {
            $userLength = \strlen($username);
            $passwordLength = \strlen($password);

            if ($userLength < 1 || $userLength > 255 || $passwordLength < 1 || $passwordLength > 255) {
                throw new RouteException('SOCKS5 user name and password must be 1 to 255 bytes long (RFC 1929)');
            }
        }

        if ($proxyPort < 1 || $proxyPort > 65535) {
            throw new RouteException('SOCKS5 proxy port must be from 1 to 65535');
        }

        $this->proxy = Destination::format($proxyHost, $proxyPort);
        $this->connector = $connector ?? socketConnector();
        $this->logger = $logger ?? new NullLogger();
    }

    public function connect(
        SocketAddress|string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null,
    ): Socket {
        [$host, $port] = Destination::parse($uri);
        $destination = Destination::format($host, $port);

        $context ??= new ConnectContext();
        $timeout = $context->getConnectTimeout();
        $deadline = new TimeoutCancellation($timeout, 'SOCKS5 connect timed out');
        $bounded = $cancellation !== null ? new CompositeCancellation($cancellation, $deadline) : $deadline;

        try {
            if (!$this->remoteDns && !self::isIp($host)) {
                $host = $this->resolve($host, $context, $bounded);
            }

            $socket = $this->connector->connect($this->proxy, $context, $bounded);
        } catch (CancelledException $exception) {
            $cancellation?->throwIfRequested();

            throw $this->timedOut($destination, $timeout, $exception);
        } catch (ConnectException $exception) {
            throw $this->failed($destination, $exception);
        }

        try {
            $reader = new ExactReader($socket, $bounded, 'SOCKS5 proxy ' . $this->proxy);
            $this->handshake($socket, $reader, $host, $port);
        } catch (CancelledException $exception) {
            $socket->close();
            $cancellation?->throwIfRequested();

            throw $this->timedOut($destination, $timeout, $exception);
        } catch (ConnectException $exception) {
            $socket->close();

            throw $this->failed($destination, $exception);
        } catch (StreamException $exception) {
            $socket->close();

            throw $this->failed($destination, new ConnectException(
                \sprintf('SOCKS5 proxy %s broke the connection during the handshake: %s', $this->proxy, $exception->getMessage()),
                0,
                $exception,
            ));
        } catch (\Throwable $exception) {
            $socket->close();

            throw $exception;
        }

        $leftover = $reader->leftover();

        return $leftover === '' ? $socket : new PrefixedSocket($socket, $leftover);
    }

    private function timedOut(string $destination, float $timeout, CancelledException $previous): ConnectException {
        $this->logger->debug('SOCKS5 connect to {destination} via {proxy} timed out', [
            'destination' => $destination,
            'proxy' => $this->proxy,
        ]);

        return new ConnectException(
            \sprintf('Connecting to %s through SOCKS5 proxy %s timed out after %.1f s', $destination, $this->proxy, $timeout),
            0,
            $previous,
        );
    }

    private function failed(string $destination, ConnectException $exception): ConnectException {
        $this->logger->debug('SOCKS5 connect to {destination} via {proxy} failed: {reason}', [
            'destination' => $destination,
            'proxy' => $this->proxy,
            'reason' => $exception->getMessage(),
        ]);

        return $exception;
    }

    /**
     * @throws ConnectException
     * @throws StreamException
     * @throws CancelledException
     */
    private function handshake(Socket $socket, ExactReader $reader, string $host, int $port): void {
        $withPassword = $this->username !== null && $this->password !== null;
        $methods = $withPassword ? \chr(self::METHOD_NONE) . \chr(self::METHOD_PASSWORD) : \chr(self::METHOD_NONE);
        $socket->write(self::VERSION . \chr(\strlen($methods)) . $methods);

        $choice = $reader->read(2);
        $this->assertVersion($choice[0]);
        $method = \ord($choice[1]);

        if ($method === self::METHOD_PASSWORD && $withPassword) {
            $this->authenticate($socket, $reader);
        } elseif ($method === self::METHOD_UNACCEPTABLE) {
            throw new ConnectException(
                \sprintf(
                    'SOCKS5 proxy %s accepted none of the offered authentication methods (%s)',
                    $this->proxy,
                    $withPassword ? 'none, username/password' : 'none; the proxy probably requires credentials',
                ),
            );
        } elseif ($method !== self::METHOD_NONE) {
            throw new ConnectException(
                \sprintf('SOCKS5 proxy %s chose authentication method 0x%02x that was not offered', $this->proxy, $method),
            );
        }

        $socket->write(self::VERSION . "\x01\x00" . self::encodeAddress($host) . \pack('n', $port));

        $reply = $reader->read(4);
        $this->assertVersion($reply[0]);
        $code = \ord($reply[1]);

        if ($code !== 0x00) {
            throw new ConnectException(
                \sprintf(
                    'SOCKS5 proxy %s could not connect to %s: %s (0x%02x)',
                    $this->proxy,
                    Destination::format($host, $port),
                    self::REPLIES[$code] ?? 'unknown error',
                    $code,
                ),
            );
        }

        $boundLength = match (\ord($reply[3])) {
            self::ATYP_IPV4 => 4,
            self::ATYP_IPV6 => 16,
            self::ATYP_DOMAIN => \ord($reader->read(1)),
            default => throw new ConnectException(
                \sprintf('SOCKS5 proxy %s sent an unknown address type 0x%02x', $this->proxy, \ord($reply[3])),
            ),
        };

        // Bound address and port: not needed for CONNECT.
        $reader->read($boundLength + 2);
    }

    private function authenticate(Socket $socket, ExactReader $reader): void {
        $username = (string) $this->username;
        $password = (string) $this->password;

        $socket->write("\x01" . \chr(\strlen($username)) . $username . \chr(\strlen($password)) . $password);

        $status = $reader->read(2);

        if ($status[1] !== "\x00") {
            throw new ConnectException(\sprintf('SOCKS5 proxy %s rejected the user name or password', $this->proxy));
        }
    }

    private function assertVersion(string $byte): void {
        if ($byte !== self::VERSION) {
            throw new ConnectException(
                \sprintf('%s does not speak SOCKS5 (version byte 0x%02x)', $this->proxy, \ord($byte)),
            );
        }
    }

    /**
     * @throws ConnectException
     */
    private static function encodeAddress(string $host): string {
        $packed = \filter_var($host, \FILTER_VALIDATE_IP) !== false ? \inet_pton($host) : false;

        if ($packed !== false) {
            return \chr(\strlen($packed) === 4 ? self::ATYP_IPV4 : self::ATYP_IPV6) . $packed;
        }

        $length = \strlen($host);

        if ($length > 255) {
            throw new ConnectException('Destination host name is longer than 255 bytes and cannot be sent over SOCKS5');
        }

        return \chr(self::ATYP_DOMAIN) . \chr($length) . $host;
    }

    private static function isIp(string $host): bool {
        return \filter_var($host, \FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @throws ConnectException
     */
    private function resolve(string $host, ConnectContext $context, Cancellation $cancellation): string {
        try {
            $records = dnsResolver()->resolve($host, $context->getDnsTypeRestriction(), $cancellation);
        } catch (DnsException $exception) {
            throw new ConnectException(
                \sprintf('Could not resolve %s locally for SOCKS5 proxy %s: %s', $host, $this->proxy, $exception->getMessage()),
                0,
                $exception,
            );
        }

        foreach ($records as $record) {
            if ($record->getType() === DnsRecord::A || $record->getType() === DnsRecord::AAAA) {
                return $record->getValue();
            }
        }

        throw new ConnectException(\sprintf('Could not resolve %s locally for SOCKS5 proxy %s', $host, $this->proxy));
    }
}
