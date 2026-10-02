<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * readEvents() found that a reset emptied the store, during the read or
 * since the first read of the transaction. Sequence Positions read before
 * the reset mean nothing now: read again from the start, and rebuild what
 * depends on them. The transaction stays open.
 */
class StoreChangedException extends \RuntimeException implements TamarackDBException {}
