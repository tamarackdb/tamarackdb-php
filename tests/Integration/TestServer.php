<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TamarackDB\Client;
use TamarackDB\Exception\ServerException;
use TamarackDB\Exception\TransportException;

/**
 * A tamarackdb-server process for the integration tests, started from the
 * binary named by TAMARACKDB_SERVER_BIN, with devMode on and a new database
 * created by the tamarackdb-init binary next to it. Tests are skipped when
 * the variable isn't set.
 *
 * Servers are started once per configuration and stopped when PHP exits.
 */
final class TestServer
{
    /** @var array<string, self> */
    private static array $servers = [];

    /** @var resource */
    private $process;

    private function __construct(
        public readonly string $url,
        public readonly ?string $socket,
        private readonly string $dir,
    ) {}

    /**
     * @param array<string, string> $env extra TAMARACKDB_* settings
     */
    public static function get(TestCase $test, array $env = [], bool $unixSocket = false): self
    {
        $key = serialize([$env, $unixSocket]);
        if (!isset(self::$servers[$key])) {
            $bin = getenv('TAMARACKDB_SERVER_BIN');
            if (!\is_string($bin) || $bin === '') {
                $test->markTestSkipped('set TAMARACKDB_SERVER_BIN to a tamarackdb-server binary to run the integration tests');
            }
            if (!is_executable($bin)) {
                throw new \RuntimeException(\sprintf('TAMARACKDB_SERVER_BIN "%s" is not executable', $bin));
            }
            self::$servers[$key] = self::start($bin, $env, $unixSocket);
        }

        return self::$servers[$key];
    }

    public function client(?string $token = null, float $timeout = 60.0): Client
    {
        return $this->socket === null
            ? Client::http($this->url, $token, $timeout)
            : Client::unixSocket($this->socket, $token, $timeout);
    }

    /**
     * @param array<string, string> $env
     */
    private static function start(string $bin, array $env, bool $unixSocket): self
    {
        // A short path: a unix socket path is limited to 107 bytes.
        $dir = sys_get_temp_dir() . '/tdb-' . bin2hex(random_bytes(4));
        mkdir($dir);

        self::initDatabase($bin, $dir);

        $env += ['TAMARACKDB_DATA_DIR' => $dir . '/data', 'TAMARACKDB_DEV_MODE' => 'true'];
        if ($unixSocket) {
            $socket = $dir . '/s.sock';
            $url = 'http://localhost';
            $env['TAMARACKDB_SOCKET_PATH'] = $socket;
        } else {
            $socket = null;
            $port = self::freePort();
            $url = 'http://127.0.0.1:' . $port;
            $env['TAMARACKDB_BIND_ADDRESS'] = '127.0.0.1';
            $env['TAMARACKDB_PORT'] = (string) $port;
        }

        $process = proc_open(
            [$bin, '-config', $dir . '/none.toml'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . '/server.log', 'w'], 2 => ['file', $dir . '/server.log', 'a']],
            $pipes,
            $dir,
            $env + getenv(),
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('could not start ' . $bin);
        }

        $server = new self($url, $socket, $dir);
        $server->process = $process;
        register_shutdown_function($server->stop(...));
        $server->waitUntilReady();

        return $server;
    }

    /**
     * The server refuses to start without a database: tamarackdb-init,
     * built next to it, creates one in $dir/data.
     */
    private static function initDatabase(string $serverBin, string $dir): void
    {
        $init = \dirname($serverBin) . '/tamarackdb-init';
        if (!is_executable($init)) {
            throw new \RuntimeException(\sprintf('"%s" is missing or not executable: build it next to tamarackdb-server', $init));
        }
        exec(escapeshellarg($init) . ' -data-dir ' . escapeshellarg($dir . '/data') . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException("tamarackdb-init failed:\n" . implode("\n", $output));
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('could not find a free port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function waitUntilReady(): void
    {
        $client = $this->client();
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            if (!proc_get_status($this->process)['running']) {
                throw new \RuntimeException("tamarackdb-server exited:\n" . file_get_contents($this->dir . '/server.log'));
            }
            try {
                $client->health();

                return;
            } catch (ServerException) {
                // It answered, if only with 401 when auth is on.
                return;
            } catch (TransportException) {
                usleep(50_000);
            }
        }
        throw new \RuntimeException("tamarackdb-server didn't start in time:\n" . file_get_contents($this->dir . '/server.log'));
    }

    private function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }
}
