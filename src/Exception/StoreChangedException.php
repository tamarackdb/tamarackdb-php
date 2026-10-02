<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * readEvents() found a store ID other than the one of the given
 * StorePosition, or the store ID changed between two pages: a reset
 * emptied the store. Read again from the start, with no position, and
 * rebuild what depends on the old one. The transaction stays open.
 */
class StoreChangedException extends \RuntimeException implements TamarackDBException {}
