<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * The request never got a full answer: the server was unreachable, the
 * connection dropped, or a response ended early.
 *
 * Inside a transaction, the call may or may not have run on the server.
 * Roll back, open a new transaction, and run the whole command again.
 */
class TransportException extends \RuntimeException implements TamarackDBException {}
