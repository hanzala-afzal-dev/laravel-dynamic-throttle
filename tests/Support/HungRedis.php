<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use RuntimeException;

/**
 * A Redis that hangs, as an overloaded or failing-over one can (issue #99): a
 * port that accepts connections and never answers. The kernel completes each
 * connection, and nothing ever reads it. Each test gets a free port of its own,
 * so it is safe under --parallel.
 */
final class HungRedis
{
    /**
     * @param  resource|null  $server
     * @param  resource|null  $process
     */
    private function __construct(private $server, private $process, public readonly int $port) {}

    public static function listen(): self
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);

        if ($server === false) {
            throw new RuntimeException("Cannot listen: {$message}");
        }

        $address = (string) stream_socket_get_name($server, false);

        return new self($server, null, (int) substr($address, (int) strrpos($address, ':') + 1));
    }

    /**
     * One that answers the first command of the first connection, such as the SELECT
     * a client sends as it connects, then hangs: a connection that was open before
     * Redis stopped answering. It runs in a process of its own, since this one waits
     * on it.
     */
    public static function afterFirstReply(): self
    {
        $code = <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:0');
            $address = stream_socket_get_name($server, false);
            fwrite(STDOUT, substr($address, strrpos($address, ':') + 1)."\n");
            $client = stream_socket_accept($server, 60);
            fread($client, 65536);
            fwrite($client, "+OK\r\n");
            fgets(STDIN);
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start the fake Redis.');
        }

        $port = (int) fgets($pipes[1]);

        return new self(null, $process, $port);
    }

    /**
     * A connection to it, with the timeouts a dedicated connection should have:
     * "read_timeout" for phpredis, "read_write_timeout" for Predis.
     *
     * @return array<string, mixed>
     */
    public function connection(float $timeout): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => $this->port,
            'timeout' => $timeout,
            'read_timeout' => $timeout,
            'read_write_timeout' => $timeout,
            'max_retries' => 0,
        ];
    }

    public function close(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
