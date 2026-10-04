<?php

declare(strict_types=1);

namespace TamarackDB\Http;

use TamarackDB\Exception\ServerException;
use TamarackDB\Exception\TransportException;

/**
 * Sends HTTP requests to one TamarackDB server. The client adds the
 * TamarackDB headers (content type); a transport adds whatever its
 * connection needs, such as the Bearer token.
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
     * Sends a request and yields the lines of a 200 response body as they
     * arrive, without their line endings. Empty lines are skipped.
     *
     * When the consumer stops early, the connection is closed.
     *
     * $onHeaders gets the response headers of a 200 response, keyed by
     * lowercase name, once, before the first line.
     *
     * @param array<string, string> $headers
     * @param (\Closure(array<string, string>): void)|null $onHeaders
     *
     * @return \Generator<int, string>
     *
     * @throws ServerException on a status other than 200
     * @throws TransportException when the response didn't arrive in full
     */
    public function stream(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
        ?\Closure $onHeaders = null,
    ): \Generator;
}
