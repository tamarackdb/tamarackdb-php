<?php

declare(strict_types=1);

namespace TamarackDB\Exception;

use TamarackDB\Http\Response;

/**
 * The server answered with an error status.
 *
 * $errorCode is the stable `error` code of the response body, such as
 * "InvalidRequest", or null when the server answered in plain text (an
 * unknown path or a wrong method).
 */
class ServerException extends \RuntimeException implements TamarackDBException
{
    private const array CLASSES = [
        'InvalidRequest' => InvalidRequestException::class,
        'Unauthorized' => UnauthorizedException::class,
        'ProjectionNotFound' => ProjectionNotFoundException::class,
        'ConcurrencyException' => ConcurrencyException::class,
        'PayloadTooLarge' => PayloadTooLargeException::class,
        'InternalError' => InternalErrorException::class,
        'WriteQueueFull' => WriteQueueFullException::class,
        'ShuttingDown' => ShuttingDownException::class,
        'Unavailable' => UnavailableException::class,
    ];

    final public function __construct(
        public readonly int $statusCode,
        public readonly ?string $errorCode,
        public readonly ?string $detail,
    ) {
        $message = \sprintf('TamarackDB responded %d', $statusCode);
        if ($errorCode !== null) {
            $message .= ' ' . $errorCode;
        }
        if ($detail !== null && $detail !== '') {
            $message .= ': ' . $detail;
        }
        parent::__construct($message, $statusCode);
    }

    public static function fromResponse(Response $response): self
    {
        $data = json_decode($response->body, true);
        if (\is_array($data) && isset($data['error']) && \is_string($data['error'])) {
            $detail = isset($data['message']) && \is_string($data['message']) ? $data['message'] : null;
            $class = self::CLASSES[$data['error']] ?? self::class;

            return new $class($response->statusCode, $data['error'], $detail);
        }

        $text = trim($response->body);

        return new self($response->statusCode, null, $text === '' ? null : $text);
    }
}
