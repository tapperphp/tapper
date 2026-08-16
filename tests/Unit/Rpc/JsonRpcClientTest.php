<?php

declare(strict_types=1);

use Tapper\Rpc\JsonRpcClient;
use Tapper\Rpc\JsonRpcRequest;

/**
 * Spawns a throwaway PHP subprocess that binds an ephemeral TCP port, accepts exactly
 * one connection, and replies with $response. A subprocess (not an in-process accept())
 * is required because JsonRpcClient::call() is fully synchronous end-to-end (connect,
 * write, blocking read, close) with no point where a single-threaded test could interleave
 * server-side accept()/read()/write() calls of its own.
 *
 * @return array{0: int, 1: callable(): void} [bound port, teardown callback]
 */
function startFakeTcpServer(string $response): array
{
    $script = <<<'PHP'
        <?php
        $response = $argv[1];
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        fwrite(STDOUT, stream_socket_get_name($server, false)."\n");
        fflush(STDOUT);
        $conn = @stream_socket_accept($server, 10);
        if ($conn) {
            fgets($conn);
            fwrite($conn, $response);
            fclose($conn);
        }
        fclose($server);
        PHP;

    $scriptPath = tempnam(sys_get_temp_dir(), 'tapper_fake_tcp_').'.php';
    file_put_contents($scriptPath, $script);

    $process = proc_open(
        [PHP_BINARY, $scriptPath, $response],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    $address = trim(fgets($pipes[1]));
    $port = (int) substr($address, strrpos($address, ':') + 1);

    $stop = function () use ($process, $pipes, $scriptPath) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            proc_close($process);
        }
        @unlink($scriptPath);
    };

    return [$port, $stop];
}

describe('TCP transport (port configured)', function () {
    it('connects over TCP and returns the decoded response', function () {
        [$port, $stop] = startFakeTcpServer(json_encode(['jsonrpc' => '2.0', 'result' => 'ok', 'id' => 'x'])."\n");

        $client = new JsonRpcClient(port: $port, timeout: 2.0);
        $response = $client->call(new JsonRpcRequest('log', [], id: 'x'));

        $stop();

        expect($response)->toBe(['jsonrpc' => '2.0', 'result' => 'ok', 'id' => 'x']);
    });

    it('returns null when the response has no result key', function () {
        [$port, $stop] = startFakeTcpServer(json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32601, 'message' => 'nope']])."\n");

        $client = new JsonRpcClient(port: $port, timeout: 2.0);
        $response = $client->call(new JsonRpcRequest('log', []));

        $stop();

        expect($response)->toBeNull();
    });

    it('returns null when nothing is listening on the configured port', function () {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $address = stream_socket_get_name($server, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($server);

        $client = new JsonRpcClient(port: $port, timeout: 0.5);
        $response = $client->call(new JsonRpcRequest('log', []));

        expect($response)->toBeNull();
    });
});
