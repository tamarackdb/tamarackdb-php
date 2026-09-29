<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * The client stopped waiting for a response. For a request that waits
 * for its turn in the server's queue, the time spent waiting counts.
 */
class TimeoutException extends TransportException {}
