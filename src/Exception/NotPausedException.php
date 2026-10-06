<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 409 NotPaused: reset() while no pause is in place. Nothing was deleted.
 */
class NotPausedException extends ServerException {}
