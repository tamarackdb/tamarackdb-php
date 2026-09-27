<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * A value built on the client side breaks a rule of the TamarackDB API.
 */
class InvalidArgumentException extends \InvalidArgumentException implements TamarackDBException {}
