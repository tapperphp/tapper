<?php

declare(strict_types=1);

namespace Tapper\Rpc;

use Tapper\SocketPath;

class JsonRpcClient
{
    public function __construct(
        protected string $host = '127.0.0.1',
        protected ?int $port = null,
        protected float $timeout = 0.5,
        protected float $pauseTimeout = 3600,
    ) {}

    public function call(JsonRpc $jsonRpc): ?array
    {
        $payload = $jsonRpc->payload();

        $target = $this->port !== null
            ? "tcp://{$this->host}:{$this->port}"
            : 'unix://'.SocketPath::resolve();

        $socket = @stream_socket_client($target, $errno, $errstr, $this->timeout);

        if (! $socket) {
            return null;
        }

        fwrite($socket, json_encode($payload)."\n");

        stream_set_timeout($socket, (int) $this->pauseTimeout);
        $response = fgets($socket);

        fclose($socket);

        if (! $response) {
            return null;
        }

        $decoded = json_decode($response, true);
        if (! is_array($decoded) || ! isset($decoded['result'])) {
            return null;
        }

        return $decoded;
    }
}
