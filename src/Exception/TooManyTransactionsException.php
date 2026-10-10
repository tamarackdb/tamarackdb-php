<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 503 TooManyTransactions: beginTransaction() while the server already has
 * as many open transactions as it allows. No transaction began.
 */
class TooManyTransactionsException extends ServerException {}
