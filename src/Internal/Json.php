<?php

declare(strict_types=1);

namespace TamarackDB\Internal;

use TamarackDB\Exception\ProtocolException;

/**
 * @internal
 */
final class Json
{
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decodes a JSON object from the server.
     *
     * @return array<string, mixed>
     */
    public static function decodeObject(string $json): array
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProtocolException('invalid JSON from the server: ' . $e->getMessage(), 0, $e);
        }
        if (!\is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ProtocolException('expected a JSON object from the server');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
