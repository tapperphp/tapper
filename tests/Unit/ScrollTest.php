<?php

declare(strict_types=1);

use Tapper\Console\State\AppState;
use Tapper\Console\Support\Scroll;

beforeEach(function () {
    $this->appState = new AppState;
    $this->scroll = new Scroll($this->appState);
});

describe('cursor movement', function () {
    it('cursor down', function () {
        $this->appState->cursor = 0;
        $this->appState->offset = 0;
        $this->scroll->cursorDown(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(1);
        expect($this->appState->offset)->toBe(0);
    });

    it('stops moving cursor at the end', function () {
        $this->appState->cursor = 9;
        $this->appState->offset = 5;
        $this->scroll->cursorDown(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(9);
        expect($this->appState->offset)->toBe(5);
    });

    it('move offset on cursor down', function () {
        $this->appState->cursor = 4;
        $this->appState->offset = 0;
        $this->scroll->cursorDown(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(5);
        expect($this->appState->offset)->toBe(1);
    });

    it('do not move cursor up if on top', function () {
        $this->appState->cursor = 0;
        $this->appState->offset = 0;
        $this->scroll->cursorUp(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(0);
        expect($this->appState->offset)->toBe(0);
    });

    it('move cursor up', function () {
        $this->appState->cursor = 1;
        $this->appState->offset = 0;
        $this->scroll->cursorUp(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(0);
        expect($this->appState->offset)->toBe(0);
    });

    it('do not move offset when cursor and offset is on bottom', function () {
        $this->appState->cursor = 9;
        $this->appState->offset = 5;
        $this->scroll->cursorUp(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(8);
        expect($this->appState->offset)->toBe(5);
    });

    it('move offset together with scroll', function () {
        $this->appState->cursor = 5;
        $this->appState->offset = 5;
        $this->scroll->cursorUp(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(4);
        expect($this->appState->offset)->toBe(4);
    });

    it('moves everything to bottom', function () {
        $this->appState->cursor = 5;
        $this->appState->offset = 2;
        $this->scroll->scrollToBottom(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(9);
        expect($this->appState->offset)->toBe(5);
    });
});

describe('scroll movement', function () {
    it('scrolls down with cursor when on top', function () {
        $this->appState->cursor = 0;
        $this->appState->offset = 0;
        $this->scroll->scrollDown(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(1);
        expect($this->appState->offset)->toBe(1);
    });

    it('scrolls down but not move cursor if not on top', function () {
        $this->appState->cursor = 2;
        $this->appState->offset = 0;
        $this->scroll->scrollDown(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(2);
        expect($this->appState->offset)->toBe(1);
    });

    it('do not scroll if at the bottom', function () {
        $this->appState->cursor = 8;
        $this->appState->offset = 5;
        $this->scroll->scrollDown(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(8);
        expect($this->appState->offset)->toBe(5);
    });

    it('do not scroll if at the top', function () {
        $this->appState->cursor = 2;
        $this->appState->offset = 0;
        $this->scroll->scrollUp(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(2);
        expect($this->appState->offset)->toBe(0);
    });

    it('scrolls if in the middle', function () {
        $this->appState->cursor = 4;
        $this->appState->offset = 2;
        $this->scroll->scrollUp(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(4);
        expect($this->appState->offset)->toBe(1);
    });

    it('scrolls with cursor if on bottom', function () {
        $this->appState->cursor = 9;
        $this->appState->offset = 5;
        $this->scroll->scrollUp(count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(8);
        expect($this->appState->offset)->toBe(4);
    });
});

describe('scroll jumping', function () {
    it('jump to specific item', function () {
        $this->appState->cursor = 0;
        $this->appState->offset = 0;
        $this->scroll->jump(position: 2, count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(2);
        expect($this->appState->offset)->toBe(0);
    });

    it('moving offset up on jump', function () {
        $this->appState->cursor = 9;
        $this->appState->offset = 5;
        $this->scroll->jump(position: 2, count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(2);
        expect($this->appState->offset)->toBe(2);
    });

    it('moving offset down on jump', function () {
        $this->appState->cursor = 0;
        $this->appState->offset = 0;
        $this->scroll->jump(position: 7, count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(7);
        expect($this->appState->offset)->toBe(3);
    });

    it('jump to top if less than 0', function () {
        $this->appState->cursor = 4;
        $this->appState->offset = 2;
        $this->scroll->jump(position: -2, count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(0);
        expect($this->appState->offset)->toBe(0);
    });

    it('jump to the end if more than count', function () {
        $this->appState->cursor = 0;
        $this->appState->offset = 0;
        $this->scroll->jump(position: 12, count: 10, visible: 5);

        expect($this->appState->cursor)->toBe(9);
        expect($this->appState->offset)->toBe(5);
    });
});

describe('scrollbar state', function () {
    it('maps count/visible/offset onto content length, viewport length and position', function () {
        $state = Scroll::scrollbarState(count: 100, visible: 20, offset: 35);

        expect($state->contentLength)->toBe(80)
            ->and($state->viewportContentLength)->toBe(20)
            ->and($state->position)->toBe(35);
    });

    it('never lets content length go negative when everything fits on screen', function () {
        $state = Scroll::scrollbarState(count: 5, visible: 20, offset: 0);

        expect($state->contentLength)->toBe(0);
    });

    it('uses the max offset (count - visible) as content length so the thumb can reach the bottom', function () {
        $count = 100;
        $visible = 20;
        $maxOffset = $count - $visible;

        $state = Scroll::scrollbarState($count, $visible, $maxOffset);

        expect($state->position)->toBe($state->contentLength);
    });
});

describe('proportional thumb', function () {
    it('returns null when everything already fits on screen', function () {
        expect(Scroll::proportionalThumb(count: 5, visible: 20, offset: 0, trackHeight: 20))->toBeNull();
    });

    it('sizes the thumb against the true visible/count ratio, not scrollbarState\'s count-visible one', function () {
        // Regression: with count=13, visible=8, scrollbarState()'s contentLength is only
        // 5 (count - visible), so feeding that into ScrollbarRenderer's size formula
        // (viewport / contentLength) gives 8/5 > 1 — a thumb that fills the whole
        // 8-row track even though barely more than half the content is visible.
        [$start, $end] = Scroll::proportionalThumb(count: 13, visible: 8, offset: 0, trackHeight: 8);

        expect($end - $start)->toBe((int) round((8 / 13) * 8))
            ->and($end - $start)->toBeLessThan(8);
    });

    it('starts the thumb at the top when offset is 0', function () {
        [$start] = Scroll::proportionalThumb(count: 13, visible: 8, offset: 0, trackHeight: 8);

        expect($start)->toBe(0);
    });

    it('reaches the very bottom of the track at the max offset', function () {
        $count = 13;
        $visible = 8;
        $trackHeight = 8;
        $maxOffset = $count - $visible;

        [, $end] = Scroll::proportionalThumb($count, $visible, $maxOffset, $trackHeight);

        expect($end)->toBe($trackHeight);
    });
});
