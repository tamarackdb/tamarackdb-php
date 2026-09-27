<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 503 TransactionQueueFull: Too many requests are already waiting for a
 * transaction.
 */
class TransactionQueueFullException extends ServerException {}
