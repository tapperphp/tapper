<?php

declare(strict_types=1);

use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Span;
use Tapper\Console\Support\SpanTruncator;

function joined(array $spans): string
{
    return implode('', array_map(fn (Span $s): string => $s->content, $spans));
}

describe('window', function () {
    it('returns the spans untouched when everything already fits', function () {
        $spans = [Span::fromString('hello')];

        $result = SpanTruncator::window($spans, offset: 0, maxWidth: 20, ellipsisStyle: Style::default());

        expect(joined($result))->toBe('hello');
    });

    it('adds a trailing ellipsis when content overflows to the right', function () {
        $spans = [Span::fromString('hello world')];

        $result = SpanTruncator::window($spans, offset: 0, maxWidth: 6, ellipsisStyle: Style::default());

        expect(joined($result))->toBe('hello…');
    });

    it('adds a leading ellipsis when scrolled past the start', function () {
        $spans = [Span::fromString('hello world')];

        $result = SpanTruncator::window($spans, offset: 6, maxWidth: 20, ellipsisStyle: Style::default());

        expect(joined($result))->toBe('…world');
    });

    it('adds both ellipses when scrolled into the middle', function () {
        $spans = [Span::fromString('hello world foo bar')];

        $result = SpanTruncator::window($spans, offset: 4, maxWidth: 6, ellipsisStyle: Style::default());

        expect(joined($result))->toBe('…o wo…');
    });

    it('shows just a leading ellipsis once scrolled past this line\'s own length', function () {
        $spans = [Span::fromString('short')];

        $result = SpanTruncator::window($spans, offset: 20, maxWidth: 10, ellipsisStyle: Style::default());

        expect(joined($result))->toBe('…');
    });

    it('preserves per-span styles across the window', function () {
        $red = Style::default()->red();
        $blue = Style::default()->blue();
        $spans = [Span::styled('foo', $red), Span::styled('bar', $blue)];

        $result = SpanTruncator::window($spans, offset: 0, maxWidth: 20, ellipsisStyle: Style::default());

        expect($result)->toHaveCount(2)
            ->and($result[0]->content)->toBe('foo')
            ->and($result[0]->style)->toBe($red)
            ->and($result[1]->content)->toBe('bar')
            ->and($result[1]->style)->toBe($blue);
    });
});
