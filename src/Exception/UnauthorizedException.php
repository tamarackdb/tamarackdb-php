<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 401 Unauthorized: Missing or invalid Bearer token.
 */
class UnauthorizedException extends ServerException {}
