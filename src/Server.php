<?php

declare(strict_types=1);

namespace Tapper;

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use PhpTui\Term\KeyCode;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Tapper\Console\EventBus;
use Tapper\Console\State\AppState;
use Tapper\Console\State\LogItem;

class Server
{
    private int $id = 0;

    /** @var list<callable> FIFO queue of pending wait() resolvers, one per outstanding tp()->wait() call */
    private array $waitResolvers = [];

    private bool $waitListenerRegistered = false;

    public function __construct(
        private readonly AppState $appState,
        private readonly EventBus $eventBus
    ) {}

    public function run(?int $port = null): void
    {
        $server = $this->createSocketServer($port);

        $server->on('connection', function (ConnectionInterface $conn) {
            $this->handleConnection($conn);
        });
    }

    private function createSocketServer(?int $port): SocketServer
    {
        if ($port !== null) {
            return new SocketServer("127.0.0.1:{$port}");
        }

        $socketPath = SocketPath::resolve();
        @unlink($socketPath);

        return new SocketServer('unix://'.$socketPath);
    }

    private function handleConnection(ConnectionInterface $conn): void
    {
        $decoder = new Decoder($conn, true);
        $encoder = new Encoder($conn, true);

        $decoder->on('data', function ($message) use ($encoder) {
            $this->handleMessage($message, $encoder);
        });
    }

    private function handleMessage(array $message, Encoder $encoder): void
    {
        if (($message['jsonrpc'] ?? '') !== '2.0') {
            $this->writeError($encoder, -32600, 'Invalid Request', $message['id'] ?? null);

            return;
        }

        $method = $message['method'] ?? '';
        $params = $message['params'] ?? [];
        $id = $message['id'] ?? null;

        match ($method) {
            'log' => $this->handleLog($params, $id, $encoder),
            'wait' => $this->handleWait($params, $id, $encoder),
            default => $this->writeError($encoder, -32601, "Method '{$method}' not found", $id),
        };
    }

    private function handleLog(array $params, mixed $id, Encoder $encoder): void
    {
        $kind = $params['kind'] ?? 'log';

        $isAppended = $this->appState->appendLog(new LogItem(
            $this->id,
            $params['microtime'],
            $kind === 'error' ? $params['message'] : json_encode($params['message'], JSON_UNESCAPED_UNICODE),
            $params['caller'],
            $params['trace'],
            $params['rootDir'],
            $params['code'],
            kind: $kind,
        ));

        $this->writeResult($encoder, 'ok', $id);

        if ($isAppended) {
            $this->id++;
        }
    }

    private function handleWait(array $params, mixed $id, Encoder $encoder): void
    {
        $isAppended = $this->appState->appendLog(new LogItem(
            $this->id,
            $params['microtime'],
            "⏸ {$params['message']} — press ENTER to continue",
            $params['caller'],
            $params['trace'],
            $params['rootDir'],
            $params['code'],
            kind: 'wait',
        ));

        if ($isAppended) {
            $this->id++;
        }

        $this->appState->pendingWaits++;

        $this->waitResolvers[] = function () use ($encoder, $id) {
            $this->writeResult($encoder, 'continue', $id);
            $this->appState->pendingWaits = max(0, $this->appState->pendingWaits - 1);
        };

        $this->registerWaitListener();
    }

    private function writeResult(Encoder $encoder, mixed $result, mixed $id): void
    {
        $encoder->write([
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id,
        ]);
    }

    private function writeError(Encoder $encoder, int $code, string $message, mixed $id): void
    {
        $encoder->write([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'id' => $id,
        ]);
    }

    /**
     * Registers a single, permanent Enter listener the first time it's needed, instead of
     * one per wait() call. Each keypress resolves the oldest pending wait in FIFO order, so
     * overlapping wait() calls no longer all resolve on the same keypress.
     */
    private function registerWaitListener(): void
    {
        if ($this->waitListenerRegistered) {
            return;
        }

        $this->waitListenerRegistered = true;

        $this->eventBus->listen(KeyCode::Enter, function () {
            $resolver = array_shift($this->waitResolvers);

            if ($resolver) {
                $resolver();
            }
        });
    }
}
