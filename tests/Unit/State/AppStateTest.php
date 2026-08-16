<?php

declare(strict_types=1);

use Tapper\Console\State\AppState;
use Tapper\Console\State\LogItem;

function makeLogItem(string $message = 'hello', string $kind = 'log', string $caller = 'foo.php:1'): LogItem
{
    return new LogItem(
        id: 1,
        timestamp: 0.0,
        message: $message,
        caller: $caller,
        trace: [],
        rootDir: '/tmp',
        code: [],
        kind: $kind,
    );
}

describe('magic accessors', function () {
    it('reads constructor-promoted properties through __get', function () {
        $state = new AppState(version: '1.2.3', port: 4321);

        expect($state->version)->toBe('1.2.3')
            ->and($state->port)->toBe(4321);
    });

    it('writes properties through __set and notifies the change callback', function () {
        $state = new AppState;
        $calls = 0;
        $state->setOnChange(function () use (&$calls) {
            $calls++;
        });

        $state->cursor = 5;

        expect($state->cursor)->toBe(5)
            ->and($calls)->toBe(1);
    });
});

describe('observe', function () {
    it('invokes the observer with the new value when the field changes', function () {
        $state = new AppState;
        $seen = null;
        $state->observe('cursor', function ($value) use (&$seen) {
            $seen = $value;
        });

        $state->cursor = 3;

        expect($seen)->toBe(3);
    });

    it('supports multiple observers on the same field', function () {
        $state = new AppState;
        $calls = [];
        $state->observe('offset', function ($v) use (&$calls) {
            $calls[] = "a:{$v}";
        });
        $state->observe('offset', function ($v) use (&$calls) {
            $calls[] = "b:{$v}";
        });

        $state->offset = 2;

        expect($calls)->toBe(['a:2', 'b:2']);
    });

    it('throws when observing a field that does not exist', function () {
        $state = new AppState;

        $state->observe('doesNotExist', fn () => null);
    })->throws(RuntimeException::class, 'doesNotExist is not defined.');
});

describe('appendLog', function () {
    it('appends a new log entry and returns true', function () {
        $state = new AppState;

        $result = $state->appendLog(makeLogItem('first'));

        expect($result)->toBeTrue()
            ->and($state->logs())->toHaveCount(1)
            ->and($state->logs()[0]->message)->toBe('first');
    });

    it('merges consecutive identical entries and bumps the repeat counter instead of appending', function () {
        $state = new AppState;
        $state->appendLog(makeLogItem('same', 'log', 'a.php:1'));

        $result = $state->appendLog(makeLogItem('same', 'log', 'a.php:1'));

        expect($result)->toBeFalse()
            ->and($state->logs())->toHaveCount(1)
            ->and($state->logs()[0]->repeatCount)->toBe(2);
    });

    it('does not merge entries that differ in kind, message, or caller', function () {
        $state = new AppState;
        $state->appendLog(makeLogItem('same', 'log', 'a.php:1'));
        $state->appendLog(makeLogItem('same', 'error', 'a.php:1'));
        $state->appendLog(makeLogItem('different', 'log', 'a.php:1'));
        $state->appendLog(makeLogItem('same', 'log', 'b.php:2'));

        expect($state->logs())->toHaveCount(4);
    });

    it('only compares against the immediately preceding entry, not the whole history', function () {
        $state = new AppState;
        $state->appendLog(makeLogItem('a'));
        $state->appendLog(makeLogItem('b'));
        $result = $state->appendLog(makeLogItem('a'));

        expect($result)->toBeTrue()
            ->and($state->logs())->toHaveCount(3);
    });

    it('notifies the "logs" observer when a log is appended', function () {
        $state = new AppState;
        $notified = false;
        $state->observe('logs', function () use (&$notified) {
            $notified = true;
        });

        $state->appendLog(makeLogItem());

        expect($notified)->toBeTrue();
    });
});

describe('filteredLogs', function () {
    it('returns all logs unfiltered when filter is empty', function () {
        $state = new AppState;
        $state->appendLog(makeLogItem('alpha'));
        $state->appendLog(makeLogItem('beta'));

        expect($state->filteredLogs())->toHaveCount(2);
    });

    it('filters logs by case-insensitive substring match on the message', function () {
        $state = new AppState;
        $state->appendLog(makeLogItem('Alpha Version'));
        $state->appendLog(makeLogItem('beta version'));
        $state->filter = 'ALPHA';

        $result = $state->filteredLogs();

        expect($result)->toHaveCount(1)
            ->and($result[0]->message)->toBe('Alpha Version');
    });

    it('returns an empty array when nothing matches', function () {
        $state = new AppState;
        $state->appendLog(makeLogItem('alpha'));
        $state->filter = 'zzz';

        expect($state->filteredLogs())->toBe([]);
    });
});

describe('deffer/commit batching', function () {
    it('suppresses the change callback and observers while batching', function () {
        $state = new AppState;
        $changeCalls = 0;
        $observerCalls = 0;
        $state->setOnChange(function () use (&$changeCalls) {
            $changeCalls++;
        });
        $state->observe('cursor', function () use (&$observerCalls) {
            $observerCalls++;
        });

        $state->deffer();
        $state->cursor = 1;
        $state->cursor = 2;
        $state->cursor = 3;

        expect($changeCalls)->toBe(0)
            ->and($observerCalls)->toBe(0)
            ->and($state->cursor)->toBe(3);
    });

    it('replays each changed field observer exactly once on commit, regardless of write count', function () {
        $state = new AppState;
        $observerCalls = 0;
        $state->observe('cursor', function () use (&$observerCalls) {
            $observerCalls++;
        });

        $state->deffer();
        $state->cursor = 1;
        $state->cursor = 2;
        $state->cursor = 3;
        $state->commit();

        expect($observerCalls)->toBe(1);
    });

    it('fires the change callback exactly once on commit', function () {
        $state = new AppState;
        $changeCalls = 0;
        $state->setOnChange(function () use (&$changeCalls) {
            $changeCalls++;
        });

        $state->deffer();
        $state->cursor = 1;
        $state->offset = 1;
        $state->commit();

        expect($changeCalls)->toBe(1);
    });

    it('does not replay observers for fields that were not touched while batching', function () {
        $state = new AppState;
        $offsetCalls = 0;
        $state->observe('offset', function () use (&$offsetCalls) {
            $offsetCalls++;
        });

        $state->deffer();
        $state->cursor = 1;
        $state->commit();

        expect($offsetCalls)->toBe(0);
    });

    it('batches appendLog under the "logs" key like any other field', function () {
        $state = new AppState;
        $logsCalls = 0;
        $state->observe('logs', function () use (&$logsCalls) {
            $logsCalls++;
        });

        $state->deffer();
        $state->appendLog(makeLogItem('a'));
        $state->appendLog(makeLogItem('b'));
        $state->commit();

        expect($logsCalls)->toBe(1)
            ->and($state->logs())->toHaveCount(2);
    });

    it('resumes immediate notification after commit', function () {
        $state = new AppState;
        $observerCalls = 0;
        $state->observe('cursor', function () use (&$observerCalls) {
            $observerCalls++;
        });

        $state->deffer();
        $state->cursor = 1;
        $state->commit();
        $state->cursor = 2;

        expect($observerCalls)->toBe(2);
    });
});
