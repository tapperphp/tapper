<?php

declare(strict_types=1);

namespace Tapper\Rpc;

final readonly class JsonRpcRequest implements JsonRpc
{
    public function __construct(
        private string $method,
        private array $params,
        private ?string $id = null,
    ) {}

    public function payload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => $this->method,
            'params' => $this->params,
            'id' => $this->id ?? uniqid('rpc_', true),
        ];
    }
}
