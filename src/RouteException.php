<?php

declare(strict_types=1);

namespace Mercurius\Connectors;

/**
 * A route cannot be built: malformed or unsupported URI, a feature the transport cannot provide,
 * or a chain that cannot be assembled.
 *
 * Thrown before any network I/O. Messages never contain passwords, VLESS ids or full URIs:
 * routes are named in their redacted form (see {@see RouteFactory::redact()}).
 */
final class RouteException extends \RuntimeException {
}
