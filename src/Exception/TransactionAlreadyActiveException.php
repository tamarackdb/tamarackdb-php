<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * A call that can't run while the Client has a transaction:
 * beginTransaction(), or writeProjections(). End the transaction with
 * commit() or rollback() first.
 */
class TransactionAlreadyActiveException extends \LogicException implements TamarackDBException {}
