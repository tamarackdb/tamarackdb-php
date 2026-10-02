<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * appendEvents() got an Append Condition built from a read, and an event
 * appended in the same transaction after that read matches it. The decision
 * was made on a read that this transaction made stale, which the server
 * can't detect at commit.
 *
 * This is a bug in the application, not a conflict with another
 * transaction: retrying doesn't fix it. Make the read, the decision and
 * the append of each decision happen in turn. The transaction is closed.
 */
class StaleConditionException extends \LogicException implements TamarackDBException {}
