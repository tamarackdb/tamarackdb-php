<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 503 WriteQueueFull: too many requests already wait for their turn in the
 * server's write queue. Nothing was written.
 */
class WriteQueueFullException extends ServerException {}
