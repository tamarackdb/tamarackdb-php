<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * A call on a Transaction that has already ended: committed, rolled back,
 * or rolled back by the server after an error.
 */
class TransactionEndedException extends \LogicException implements TamarackDBException {}
