<?php

declare(strict_types=1);

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use Tapper\Console\EventBus;

describe('string/int events', function () {
    it('dispatches a registered string event to its listener', function () {
        $bus = new EventBus;
        $received = null;
        $bus->listen('custom', function ($data) use (&$received) {
            $received = $data;
        });

        $bus->emit('custom', ['foo' => 'bar']);

        expect($received)->toBe(['foo' => 'bar']);
    });

    it('does nothing when emitting an event with no listeners', function () {
        $bus = new EventBus;

        $bus->emit('nobody-listens');

        expect(true)->toBeTrue();
    });

    it('calls every listener registered for the same event, in order', function () {
        $bus = new EventBus;
        $calls = [];
        $bus->listen('tick', function () use (&$calls) {
            $calls[] = 'first';
        });
        $bus->listen('tick', function () use (&$calls) {
            $calls[] = 'second';
        });

        $bus->emit('tick');

        expect($calls)->toBe(['first', 'second']);
    });
});

describe('KeyCode/MouseEventKind registration', function () {
    it('registers a KeyCode listener under its enum name', function () {
        $bus = new EventBus;
        $fired = false;
        $bus->listen(KeyCode::Enter, function () use (&$fired) {
            $fired = true;
        });

        $bus->emit('Enter');

        expect($fired)->toBeTrue();
    });

    it('registers a MouseEventKind listener under a "Mouse"-prefixed name', function () {
        $bus = new EventBus;
        $fired = false;
        $bus->listen(MouseEventKind::Down, function () use (&$fired) {
            $fired = true;
        });

        $bus->emit('MouseDown');

        expect($fired)->toBeTrue();
    });
});

describe('CharKeyEvent dispatch', function () {
    it('dispatches keyed by the typed character and merges modifiers into the data', function () {
        $bus = new EventBus;
        $received = null;
        $bus->listen('a', function ($data) use (&$received) {
            $received = $data;
        });

        $bus->emit(CharKeyEvent::new('a', KeyModifiers::SHIFT));

        expect($received)->toBe(['modifiers' => KeyModifiers::SHIFT]);
    });

    it('lets extra data passed to emit override the default modifiers key', function () {
        $bus = new EventBus;
        $received = null;
        $bus->listen('b', function ($data) use (&$received) {
            $received = $data;
        });

        $bus->emit(CharKeyEvent::new('b'), ['modifiers' => 'overridden']);

        expect($received)->toBe(['modifiers' => 'overridden']);
    });
});

describe('CodedKeyEvent dispatch', function () {
    it('dispatches keyed by the KeyCode enum name', function () {
        $bus = new EventBus;
        $fired = false;
        $bus->listen(KeyCode::Esc->name, function () use (&$fired) {
            $fired = true;
        });

        $bus->emit(CodedKeyEvent::new(KeyCode::Esc));

        expect($fired)->toBeTrue();
    });
});

describe('FunctionKeyEvent dispatch', function () {
    it('dispatches keyed by "F{number}"', function () {
        $bus = new EventBus;
        $fired = false;
        $bus->listen('F5', function () use (&$fired) {
            $fired = true;
        });

        $bus->emit(FunctionKeyEvent::new(5));

        expect($fired)->toBeTrue();
    });
});

describe('MouseEvent dispatch', function () {
    it('dispatches keyed by "Mouse{kind}" and passes the event back in the data', function () {
        $bus = new EventBus;
        $received = null;
        $bus->listen('MouseScrollUp', function ($data) use (&$received) {
            $received = $data;
        });

        $event = MouseEvent::new(MouseEventKind::ScrollUp, MouseButton::None, column: 3, row: 4, modifiers: 0);
        $bus->emit($event);

        expect($received)->toBe(['event' => $event]);
    });
});
