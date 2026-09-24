<?php

declare(strict_types=1);

/*
| A TCP proxy for outage tests (SPEC-003 AC-16): stopping it is an outage of
| the service behind it, and starting it again on the same port ends it.
|
| php tcp-proxy.php <listen-port> <target-host> <target-port>
|
| Port 0 picks a free port. Prints the port once it listens, then forwards
| bytes both ways until it is killed.
*/

[, $listenPort, $targetHost, $targetPort] = $argv;

$server = stream_socket_server("tcp://127.0.0.1:{$listenPort}", $errorCode, $error);

if ($server === false) {
    fwrite(STDERR, "Cannot listen on port {$listenPort}: {$error}\n");
    exit(1);
}

echo parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT), "\n";

/** @var array<int, resource> $sockets */
$sockets = [];
/** @var array<int, resource> $peers the other end of each connection, by socket id */
$peers = [];

while (true) {
    $read = [$server, ...array_values($sockets)];
    $write = $except = null;

    if (stream_select($read, $write, $except, null) === false) {
        exit(1);
    }

    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = stream_socket_accept($server);
            $upstream = stream_socket_client("tcp://{$targetHost}:{$targetPort}", $errorCode, $error, 1.0);

            if ($client === false || $upstream === false) {
                if (is_resource($client)) {
                    fclose($client);
                }

                continue;
            }

            $sockets[(int) $client] = $client;
            $sockets[(int) $upstream] = $upstream;
            $peers[(int) $client] = $upstream;
            $peers[(int) $upstream] = $client;

            continue;
        }

        // Closed earlier in this round, together with its peer.
        if (! isset($peers[(int) $socket])) {
            continue;
        }

        $data = fread($socket, 65536);
        $peer = $peers[(int) $socket];

        if ($data === false || $data === '') {
            foreach ([$socket, $peer] as $end) {
                unset($sockets[(int) $end], $peers[(int) $end]);
                fclose($end);
            }

            continue;
        }

        fwrite($peer, $data);
    }
}
