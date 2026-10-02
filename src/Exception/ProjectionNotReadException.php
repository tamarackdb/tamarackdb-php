<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * deleteProjection() on a projection the transaction neither read nor
 * saved, so its version is unknown. Read it with getProjection() first.
 * The transaction is closed.
 */
class ProjectionNotReadException extends \LogicException implements TamarackDBException {}
