<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 409 ConcurrencyException: An Append Condition failed, or a projection
 * write doesn't match the stored projection. The transaction is rolled
 * back: read again, decide again, and retry in a new transaction.
 */
class ConcurrencyException extends ServerException {}
