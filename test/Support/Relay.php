<?php

declare(strict_types=1);

namespace Mercurius\Connectors\Test\Support;

use Amp\Socket\Socket;

use function Amp\async;
use function Amp\Future\await;

/**
 * Pipes two sockets into each other until either side ends.
 */
final class Relay {
    public static function run(Socket $a, Socket $b): void {
        $pump = static function (Socket $from, Socket $to): void {
            try {
                while (null !== $chunk = $from->read()) {
                    $to->write($chunk);
                }
            } catch (\Throwable) {
            } finally {
                $from->close();
                $to->close();
            }
        };

        await([async($pump, $a, $b), async($pump, $b, $a)]);
    }
}
