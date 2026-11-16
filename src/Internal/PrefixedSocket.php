<?php

declare(strict_types=1);

namespace Mercurius\Connectors\Internal;

use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Socket\TlsState;

/**
 * @internal A socket that first returns bytes already read past the end of a proxy handshake
 *     (a server that speaks first, like SSH, may arrive in the same packet as the proxy reply).
 *
 * @implements \IteratorAggregate<int, string>
 */
final class PrefixedSocket implements Socket, \IteratorAggregate {
    use ForbidCloning;
    use ForbidSerialization;
    use ReadableStreamIteratorAggregate;

    public function __construct(
        private readonly Socket $socket,
        private string $prefix,
    ) {
    }

    public function read(?Cancellation $cancellation = null, ?int $limit = null): ?string {
        if ($this->prefix !== '') {
            $chunk = \substr($this->prefix, 0, $limit ?? \strlen($this->prefix));
            $this->prefix = \substr($this->prefix, \strlen($chunk));

            return $chunk;
        }

        return $this->socket->read($cancellation, $limit);
    }

    public function isReadable(): bool {
        return $this->prefix !== '' || $this->socket->isReadable();
    }

    public function write(string $bytes): void {
        $this->socket->write($bytes);
    }

    public function end(): void {
        $this->socket->end();
    }

    public function isWritable(): bool {
        return $this->socket->isWritable();
    }

    public function close(): void {
        $this->prefix = '';
        $this->socket->close();
    }

    public function isClosed(): bool {
        return $this->socket->isClosed();
    }

    public function onClose(\Closure $onClose): void {
        $this->socket->onClose($onClose);
    }

    public function getLocalAddress(): SocketAddress {
        return $this->socket->getLocalAddress();
    }

    public function getRemoteAddress(): SocketAddress {
        return $this->socket->getRemoteAddress();
    }

    public function setupTls(?Cancellation $cancellation = null): void {
        $this->socket->setupTls($cancellation);
    }

    public function shutdownTls(?Cancellation $cancellation = null): void {
        $this->socket->shutdownTls($cancellation);
    }

    public function isTlsConfigurationAvailable(): bool {
        return $this->socket->isTlsConfigurationAvailable();
    }

    public function getTlsState(): TlsState {
        return $this->socket->getTlsState();
    }

    public function getTlsInfo(): ?TlsInfo {
        return $this->socket->getTlsInfo();
    }
}
