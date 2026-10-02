<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * condition() on a read result whose rest still held events matching the
 * query: the application decided without seeing them. Read to the end, or
 * narrow the query. The transaction is closed.
 */
class UnreadEventsException extends \LogicException implements TamarackDBException {}
