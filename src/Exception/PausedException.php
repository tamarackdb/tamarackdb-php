<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 503 Paused: beginTransaction() while a pause is requested or in place.
 * No transaction began.
 */
class PausedException extends ServerException {}
