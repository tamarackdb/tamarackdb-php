<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * The client stopped waiting for a response. For POST /begin and
 * POST /pause, this means the request waited in the server's queue for
 * longer than the client's queue timeout.
 */
class TimeoutException extends TransportException {}
