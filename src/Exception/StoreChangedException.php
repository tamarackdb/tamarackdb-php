<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * A read got another store ID than the one its position came from: the
 * store was reset. Sequence Positions from the old store no longer mean
 * anything. Start over from the beginning.
 */
class StoreChangedException extends \RuntimeException implements TamarackDBException {}
