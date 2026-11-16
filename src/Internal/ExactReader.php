<?php

declare(strict_types=1);

namespace Mercurius\Connectors\Internal;

use Amp\Cancellation;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;

/**
 * @internal Reads exact byte counts from a socket during a proxy handshake, keeping what arrives beyond them.
 */
final class ExactReader {
    private string $buffer = '';

    public function __construct(
        private readonly Socket $socket,
        private readonly Cancellation $cancellation,
        private readonly string $peer,
    ) {
    }

    /**
     * @throws ConnectException The peer closed the connection first.
     */
    public function read(int $length): string {
        while (\strlen($this->buffer) < $length) {
            // Ask only for what is missing, so bytes of the next protocol stay in the socket when it can help it.
            $chunk = $this->socket->read($this->cancellation, \max(1, $length - \strlen($this->buffer)));

            if ($chunk === null) {
                throw new ConnectException(\sprintf('%s closed the connection during the handshake', $this->peer));
            }

            $this->buffer .= $chunk;
        }

        $result = \substr($this->buffer, 0, $length);
        $this->buffer = \substr($this->buffer, $length);

        return $result;
    }

    /** Bytes received past the handshake; they belong to the tunnelled connection. */
    public function leftover(): string {
        return $this->buffer;
    }
}
