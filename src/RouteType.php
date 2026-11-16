<?php

declare(strict_types=1);

namespace Mercurius\Connectors;

/**
 * Kind of an outbound route.
 */
enum RouteType: string {
    /** Connect straight to the destination. */
    case Direct = 'direct';

    /** HTTP proxy with the CONNECT method. */
    case Http = 'http';

    /** SOCKS5 proxy; the destination name is resolved locally and sent as an IP address. */
    case Socks5 = 'socks5';

    /** SOCKS5 proxy; the destination name is sent to the proxy and resolved there. */
    case Socks5h = 'socks5h';

    /** VLESS tunnel (rekryt/amphp-vless). */
    case Vless = 'vless';
}
