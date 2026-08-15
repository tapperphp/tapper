<?php

declare(strict_types=1);

namespace Tapper\Console\Support;

use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Span;

/**
 * Clips a list of Spans to a fixed display width, replacing anything past the
 * limit with a single ellipsis span. The terminal does not wrap TUI panes on its
 * own initiative in a way the buffer accounts for, so any line built from
 * arbitrary-length input (log messages, JSON payloads, source code) has to be
 * clipped explicitly or it bleeds into the rows below it.
 */
final class SpanTruncator
{
    /**
     * @param  Span[]  $spans
     * @return Span[]
     */
    public static function truncate(array $spans, int $maxWidth, Style $ellipsisStyle): array
    {
        $totalWidth = array_sum(array_map(fn (Span $span): int => $span->width(), $spans));

        if ($totalWidth <= $maxWidth) {
            return $spans;
        }

        $budget = max(0, $maxWidth - 1);
        $truncated = [];

        foreach ($spans as $span) {
            if ($budget <= 0) {
                break;
            }

            if ($span->width() <= $budget) {
                $truncated[] = $span;
                $budget -= $span->width();

                continue;
            }

            $chars = array_slice(mb_str_split($span->content), 0, $budget);
            $truncated[] = Span::styled(implode('', $chars), $span->style);
            $budget = 0;
        }

        $truncated[] = Span::styled('…', $ellipsisStyle);

        return $truncated;
    }

    /**
     * Like truncate(), but starts the visible window `$offset` characters in — for
     * horizontal scrolling. Adds a leading `…` when characters are hidden to the
     * left, in addition to the trailing one when characters are hidden to the right.
     *
     * @param  Span[]  $spans
     * @return Span[]
     */
    public static function window(array $spans, int $offset, int $maxWidth, Style $ellipsisStyle): array
    {
        $chars = [];
        foreach ($spans as $span) {
            foreach (mb_str_split($span->content) as $char) {
                $chars[] = [$char, $span->style];
            }
        }

        $total = count($chars);
        $hasLeft = $offset > 0;
        $windowBudget = max(0, $maxWidth - ($hasLeft ? 1 : 0));
        $window = array_slice($chars, $offset, $windowBudget);
        $hasRight = ($offset + count($window)) < $total;

        if ($hasRight) {
            $window = array_slice($window, 0, max(0, count($window) - 1));
        }

        $result = [];

        if ($hasLeft) {
            $result[] = Span::styled('…', $ellipsisStyle);
        }

        $buffer = '';
        $bufferStyle = null;

        foreach ($window as [$char, $style]) {
            if ($bufferStyle !== null && $style !== $bufferStyle) {
                $result[] = Span::styled($buffer, $bufferStyle);
                $buffer = '';
            }

            $buffer .= $char;
            $bufferStyle = $style;
        }

        if ($buffer !== '') {
            $result[] = Span::styled($buffer, $bufferStyle);
        }

        if ($hasRight) {
            $result[] = Span::styled('…', $ellipsisStyle);
        }

        return $result;
    }
}
