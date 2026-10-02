<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * appendEvents() got an Append Condition with an afterSequence that matches
 * no read made since the last append of events in this transaction. A read
 * can back an append only until the next append of events: after that, the
 * decision may rest on stale data, and the server can't tell at commit.
 *
 * This is a bug in the application, not a conflict with another
 * transaction: retrying doesn't fix it. Read again before deciding. The
 * transaction is closed.
 */
class TransactionSequenceException extends \LogicException implements TamarackDBException {}
