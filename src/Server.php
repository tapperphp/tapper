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
    private static $id = 0;

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
                        $this->appState->appendLog(new LogItem(
                            self::$id,
                            $params['microtime'],
                            json_encode($params['message'], JSON_UNESCAPED_UNICODE),
                            $params['caller'],
                            $params['trace'],
                            $params['rootDir'],
                            $params['code'],
                        ));

                        $encoder->write([
                            'jsonrpc' => '2.0',
                            'result' => 'ok',
                            'id' => $id,
                        ]);

                        self::$id++;
                        break;

                    case 'wait':
                        $this->appState->appendLog(new LogItem(
                            self::$id,
                            $params['microtime'],
                            "⏸ {$params['message']} — press ENTER to continue",
                            $params['caller'],
                            $params['trace'],
                            $params['rootDir'],
                            $params['code'],
                        ));

                        self::$id++;
                        $this->appState->pendingWaits++;

                        $this->eventBus->listen(KeyCode::Enter, function () use ($encoder, $id) {
                            $encoder->write([
                                'jsonrpc' => '2.0',
                                'result' => 'continue',
                                'id' => $id,
                            ]);

                            $this->appState->pendingWaits = max(0, $this->appState->pendingWaits - 1);
                        });

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
}
