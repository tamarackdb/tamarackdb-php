<?php

declare(strict_types=1);

namespace TamarackDB\Http;

use TamarackDB\Exception\ServerException;
use TamarackDB\Exception\TransportException;

/**
 * Sends HTTP requests to one TamarackDB server. The client adds the
 * headers of the request itself (content type); a transport adds whatever
 * its connection needs, such as the Bearer token.
 */
interface Transport
{
    /**
     * Sends a request and returns its response, whatever its status.
     *
     * @param array<string, string> $headers
     *
     * @throws TransportException when no full response arrived
     */
    public function send(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
    ): Response;

    /**
     * Sends a request and returns once the headers of a 200 response have
     * arrived. The body lines are read as the consumer asks for them. When
     * the consumer stops early, the connection is closed.
     *
     * @param array<string, string> $headers
     *
     * @throws ServerException on a status other than 200
     * @throws TransportException when the headers didn't arrive, or when
     *                            the body lines don't arrive in full
     */
    public function stream(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
    ): StreamedResponse;
}
