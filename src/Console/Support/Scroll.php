<?php

declare(strict_types=1);

namespace Tapper\Console\Support;

use Tapper\Console\State\AppState;

class Scroll
{
    public function __construct(private readonly AppState $appState) {}

    /**
     * php-tui's ScrollbarState only has one `contentLength` field, which
     * ScrollbarRenderer uses as the denominator for *both* the thumb's position
     * ratio (correct: it has to be `count - visible`, the range `offset` moves
     * over, so the thumb reaches the very end at max offset) and its size ratio
     * (wrong for that same reason: sizing needs `count`, not `count - visible`,
     * or a short list barely taller than the viewport renders a thumb that fills
     * the whole track). The two ratios need different denominators, which the
     * built-in widget can't express — so this computes thumb bounds directly
     * against the true `visible / count` proportion instead of going through
     * ScrollbarState/ScrollbarRenderer.
     *
     * @return array{0: int, 1: int}|null null when everything already fits (no thumb needed)
     */
    public static function proportionalThumb(int $count, int $visible, int $offset, int $trackHeight): ?array
    {
        if ($count <= $visible || $trackHeight <= 0) {
            return null;
        }

        $thumbSize = min($trackHeight, max(1, (int) round(($visible / $count) * $trackHeight)));
        $maxThumbStart = $trackHeight - $thumbSize;
        $maxOffset = max(1, $count - $visible);
        $ratio = min(1.0, $offset / $maxOffset);
        $thumbStart = (int) round($ratio * $maxThumbStart);

        return [$thumbStart, $thumbStart + $thumbSize];
    }

    public function cursorDown(int $count, int $visible): void
    {
        if ($this->appState->cursor < $count - 1) {
            $this->appState->cursor++;
        }

        if ($this->appState->cursor > $this->appState->offset + $visible - 1) {
            $this->appState->offset++;
        }
    }

    public function cursorUp(int $count, int $visible): void
    {
        if ($this->appState->cursor > 0) {
            $this->appState->cursor--;
        }

        if ($this->appState->cursor < $this->appState->offset) {
            $this->appState->offset--;
        }
    }

    public function scrollDown(int $count, int $visible): void
    {
        if ($this->appState->offset + $visible < $count) {
            $this->appState->offset++;
        }

        if ($this->appState->offset > $this->appState->cursor) {
            $this->appState->cursor++;
        }
    }

    public function scrollUp(int $count, int $visible): void
    {
        if ($this->appState->offset > 0) {
            $this->appState->offset--;
        }

        if ($this->appState->offset + $visible <= $this->appState->cursor) {
            $this->appState->cursor--;
        }
    }

    public function scrollToBottom(int $count, int $visible): void
    {
        $this->appState->cursor = $count - 1;
        $this->appState->offset = $count - $visible;
    }

    public function jump(int $position, int $count, int $visible): void
    {
        if ($position < 0) {
            $position = 0;
        }

        if ($position > $count - 1) {
            $position = $count - 1;
        }

        if ($this->appState->cursor === $position) {
            return;
        }

        $this->appState->cursor = $position;

        if ($this->appState->cursor < $this->appState->offset) {
            $this->appState->offset = $this->appState->cursor;
        }

        if ($this->appState->cursor > $this->appState->offset + $visible - 1) {
            $this->appState->offset = max(0, $this->appState->cursor - $visible + 1);
        }
    }
}
