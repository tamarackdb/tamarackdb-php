<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * The server answered with a body this library can't make sense of.
 */
class ProtocolException extends \RuntimeException implements TamarackDBException {}
