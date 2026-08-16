<?php

declare(strict_types=1);

use Tapper\Console\PhpHighlighter;

function spanFor(array $spans, string $content): ?PhpTui\Tui\Text\Span
{
    foreach ($spans as $span) {
        if ($span->content === $content) {
            return $span;
        }
    }

    return null;
}

describe('highlightLine', function () {
    it('reconstructs the original line when spans are joined back together', function () {
        $line = 'function foo($bar) { return $bar; }';

        $spans = PhpHighlighter::highlightLine($line);

        expect(implode('', array_map(fn ($s) => $s->content, $spans)))->toBe($line);
    });

    it('colors a language keyword', function () {
        $spans = PhpHighlighter::highlightLine('return $x;');

        $span = spanFor($spans, 'return');

        expect($span)->not->toBeNull()
            ->and($span->style->fg->toHex())->toBe('#bb9af7');
    });

    it('colors a variable', function () {
        $spans = PhpHighlighter::highlightLine('$foo = 1;');

        $span = spanFor($spans, '$foo');

        expect($span)->not->toBeNull()
            ->and($span->style->fg->toHex())->toBe('#7dcfff');
    });

    it('colors a string literal', function () {
        $spans = PhpHighlighter::highlightLine("\$x = 'hello';");

        $span = spanFor($spans, "'hello'");

        expect($span)->not->toBeNull()
            ->and($span->style->fg->toHex())->toBe('#9ece6a');
    });

    it('colors an integer literal', function () {
        $spans = PhpHighlighter::highlightLine('$x = 42;');

        $span = spanFor($spans, '42');

        expect($span)->not->toBeNull()
            ->and($span->style->fg->toHex())->toBe('#ff9e64');
    });

    it('colors a comment', function () {
        $spans = PhpHighlighter::highlightLine('$x = 1; // note');

        $span = spanFor($spans, '// note');

        expect($span)->not->toBeNull()
            ->and($span->style->fg->toHex())->toBe('#565f89');
    });

    it('colors bare keywords like true/false/null as keywords, not plain identifiers', function () {
        $spans = PhpHighlighter::highlightLine('$x = true;');

        $span = spanFor($spans, 'true');

        expect($span)->not->toBeNull()
            ->and($span->style->fg->toHex())->toBe('#bb9af7');
    });

    it('colors a function call name differently from a bare identifier', function () {
        $spans = PhpHighlighter::highlightLine('strlen($x);');

        $span = spanFor($spans, 'strlen');

        expect($span)->not->toBeNull()
            ->and($span->style->fg->toHex())->toBe('#7aa2f7');
    });

    it('falls back to the default text color for a plain identifier that is not a call', function () {
        $spans = PhpHighlighter::highlightLine('$x = SOME_CONST;');

        $span = spanFor($spans, 'SOME_CONST');

        expect($span)->not->toBeNull()
            ->and($span->style->fg->toHex())->toBe('#c0caf5');
    });
});
