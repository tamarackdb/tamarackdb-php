<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * beginTransaction() while the Client already has a transaction. End it
 * with commit() or rollback() first.
 */
class TransactionAlreadyActiveException extends \LogicException implements TamarackDBException {}
