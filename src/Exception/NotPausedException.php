<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 409 NotPaused: A projection rebuild call made while the server isn't
 * paused.
 */
class NotPausedException extends ServerException {}
