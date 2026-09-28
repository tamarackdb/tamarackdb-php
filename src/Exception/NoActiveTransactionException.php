<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * A call that needs a transaction while the Client has none: never begun,
 * committed, rolled back, or rolled back by the server after an error.
 */
class NoActiveTransactionException extends \LogicException implements TamarackDBException {}
