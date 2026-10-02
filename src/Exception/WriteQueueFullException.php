<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 503 WriteQueueFull: Too many writes are already waiting in the server's
 * write queue (maxQueuedWrites). Nothing was written.
 */
class WriteQueueFullException extends ServerException {}
