<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * A call that needs a transaction while the Client has none: never begun,
 * committed, rolled back, or closed after a bug in the application (see
 * StaleConditionException, UnreadEventsException and
 * ProjectionNotReadException).
 */
class NoActiveTransactionException extends \LogicException implements TamarackDBException {}
