<?php

declare(strict_types=1);

use Tapper\Rpc\JsonRpcRequest;

describe('payload', function () {
    it('builds a JSON-RPC 2.0 envelope with the given method and params', function () {
        $request = new JsonRpcRequest('log', ['message' => 'hi']);

        $payload = $request->payload();

        expect($payload['jsonrpc'])->toBe('2.0')
            ->and($payload['method'])->toBe('log')
            ->and($payload['params'])->toBe(['message' => 'hi']);
    });

    it('generates a unique id when none is given', function () {
        $a = (new JsonRpcRequest('log', []))->payload();
        $b = (new JsonRpcRequest('log', []))->payload();

        expect($a['id'])->toBeString()
            ->and($a['id'])->not->toBe('')
            ->and($a['id'])->not->toBe($b['id']);
    });

    it('uses the given id instead of generating one', function () {
        $payload = (new JsonRpcRequest('wait', [], id: 'fixed-id'))->payload();

        expect($payload['id'])->toBe('fixed-id');
    });
});
