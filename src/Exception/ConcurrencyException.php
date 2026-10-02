<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 409 ConcurrencyException at commit(): An Append Condition failed, or a
 * projection write doesn't match the stored projection. Another
 * transaction wrote first, and nothing was written. Read again, decide
 * again, and retry in a new transaction.
 */
class ConcurrencyException extends ServerException {}
