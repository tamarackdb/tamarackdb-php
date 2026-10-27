<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

/**
 * 507 StorageFull: The server's disk is full. Nothing was written, and a
 * transaction is over. Writes go through again once the operator frees
 * space: don't retry on your own.
 */
class StorageFullException extends ServerException {}
