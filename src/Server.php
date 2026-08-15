<?php

declare(strict_types=1);

namespace Tapper;

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use PhpTui\Term\KeyCode;
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

    public function run(): void
    {
        $socketPath = SocketPath::resolve();
        @unlink($socketPath);
        $server = new SocketServer('unix://'.$socketPath);

        $server->on('connection', function (\React\Socket\ConnectionInterface $conn) {
            $decoder = new Decoder($conn, true);
            $encoder = new Encoder($conn, true);

            $decoder->on('data', function ($message) use ($encoder) {

                if (($message['jsonrpc'] ?? '') !== '2.0') {
                    $encoder->write([
                        'jsonrpc' => '2.0',
                        'error' => [
                            'code' => -32600,
                            'message' => 'Invalid Request',
                        ],
                        'id' => $message['id'] ?? null,
                    ]);

                    return;
                }

                $method = $message['method'] ?? '';
                $params = $message['params'] ?? [];
                $id = $message['id'] ?? null;

                switch ($method) {
                    case 'log':
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

                        $encoder->write([
                            'jsonrpc' => '2.0',
                            'result' => 'ok',
                            'id' => $id,
                        ]);

                        if ($isAppended) {
                            $this->id++;
                        }
                        break;

                    case 'wait':
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
                            $encoder->write([
                                'jsonrpc' => '2.0',
                                'result' => 'continue',
                                'id' => $id,
                            ]);

                            $this->appState->pendingWaits = max(0, $this->appState->pendingWaits - 1);
                        };

                        $this->registerWaitListener();

                        break;

                    default:
                        $encoder->write([
                            'jsonrpc' => '2.0',
                            'error' => [
                                'code' => -32601,
                                'message' => "Method '{$method}' not found",
                            ],
                            'id' => $id,
                        ]);
                }
            });
        });
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
