<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 503 Unavailable: GET /health only: the server's storage is unreachable.
 */
class UnavailableException extends ServerException {}
