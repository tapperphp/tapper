<?php

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
        $server = new SocketServer('127.0.0.1:2138');

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
                        ));

                        self::$id++;

                        $this->eventBus->listen(KeyCode::Enter, fn () => ($encoder->write([
                            'jsonrpc' => '2.0',
                            'result' => 'continue',
                            'id' => $id,
                        ])
                        ));

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
