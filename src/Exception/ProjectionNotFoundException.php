<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 404 ProjectionNotFound: No projection exists at that type and id. It
 * doesn't end the transaction.
 */
class ProjectionNotFoundException extends ServerException {}
