<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * A call on a Transaction that is over (committed, rolled back, or ended
 * by the server after an error), or Client::getTransaction() without an
 * active transaction.
 */
class NoActiveTransactionException extends \LogicException implements TamarackDBException {}
