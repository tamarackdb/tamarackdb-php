<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 413 PayloadTooLarge: An event, a projection, or the whole request is over
 * the configured size limit.
 */
class PayloadTooLargeException extends ServerException {}
