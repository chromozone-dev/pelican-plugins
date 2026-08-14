<?php

namespace Chromozone\ReverseProxy\Enums;

/**
 * How a proxy manager expects the session to be presented.
 *
 * Upstream Nginx Proxy Manager returns the JWT in the POST /api/tokens body and
 * reads it from an Authorization header. NPMplus never puts the token in the
 * body - it sets a signed, Secure-only `__Host-Http-token` cookie and its auth
 * middleware reads nothing else.
 */
enum AuthMode: string
{
    case Bearer = 'bearer';
    case Cookie = 'cookie';
}
