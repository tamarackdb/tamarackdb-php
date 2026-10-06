<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 409 TransactionBusy: another call still runs on the transaction, for
 * example one whose response timed out. The transaction goes on.
 */
class TransactionBusyException extends ServerException {}
