<?php

namespace Psf\Http;

/**
 * @deprecated Use \Psf\Enumerators\HttpStatusCode enum instead.
 */
class StatusCode{
	public const OK = 200;
	public const CREATED = 201;
	public const NO_CONTENT = 204;
	public const MOVED_PERMANENTLY = 301;
	public const PERMANENT_REDIRECT = 308;
	public const BAD_REQUEST = 400;
	public const UNAUTHORIZED = 401;
	public const FORBIDDEN = 403;
	public const NOT_FOUND = 404;
	public const METHOD_NOT_ALLOWED = 405;
	public const REQUEST_TIMEOUT = 408;
	public const INTERNAL_SERVER_ERROR = 500;
	public const NOT_IMPLEMENTED = 501;
	public const BAD_GATEWAY = 502;
	public const SERVICER_UNAVAILABLE = 503;
	public const GATEWAY_TIMEOUT = 504;
	public const HTTP_VERSION_NOT_SUPPORTED = 505;
}