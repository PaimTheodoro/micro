<?php

namespace Psf\Enumerators;

enum HttpStatusCode: int
{
    case OK                       = 200;
    case CREATED                  = 201;
    case NO_CONTENT               = 204;
    case MOVED_PERMANENTLY        = 301;
    case PERMANENT_REDIRECT       = 308;
    case BAD_REQUEST              = 400;
    case UNAUTHORIZED             = 401;
    case FORBIDDEN                = 403;
    case NOT_FOUND                = 404;
    case METHOD_NOT_ALLOWED       = 405;
    case REQUEST_TIMEOUT          = 408;
    case INTERNAL_SERVER_ERROR    = 500;
    case NOT_IMPLEMENTED          = 501;
    case BAD_GATEWAY              = 502;
    case SERVICE_UNAVAILABLE      = 503;
    case GATEWAY_TIMEOUT          = 504;
    case HTTP_VERSION_NOT_SUPPORTED = 505;
}
