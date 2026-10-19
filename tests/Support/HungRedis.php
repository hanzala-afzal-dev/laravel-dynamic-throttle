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
    /** @var resource */
    private $server;

    public readonly int $port;

    private function __construct()
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);

        if ($server === false) {
            throw new RuntimeException("Cannot listen: {$message}");
        }

        $this->server = $server;
        $address = (string) stream_socket_get_name($server, false);
        $this->port = (int) substr($address, (int) strrpos($address, ':') + 1);
    }

    public static function listen(): self
    {
        return new self;
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
        fclose($this->server);
    }
}
