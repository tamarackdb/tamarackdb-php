<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 404 TransactionNotFound: the transaction is unknown, expired, or already
 * over, often because an earlier error ended it. Run the whole command
 * again, in a new transaction.
 */
class TransactionNotFoundException extends ServerException {}
