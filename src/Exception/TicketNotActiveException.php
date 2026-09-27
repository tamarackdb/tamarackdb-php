<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 410 TicketNotActive: The ticket's transaction has already ended.
 */
class TicketNotActiveException extends ServerException {}
