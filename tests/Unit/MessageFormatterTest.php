<?php

declare(strict_types=1);

use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use Tapper\Console\MessageFormatter;
use Tapper\Console\Palette;

function inlineJoined(array $spans): string
{
    return implode('', array_map(fn (Span $s): string => $s->content, $spans));
}

function formattedJoined(array $lines): string
{
    return implode("\n", array_map(
        fn (Line $line): string => implode('', array_map(fn (Span $s): string => $s->content, $line->spans)),
        $lines,
    ));
}

describe('colorizeInlineJson', function () {
    it('renders a JSON string as quoted spans', function () {
        $spans = MessageFormatter::colorizeInlineJson('"hello"');

        expect(inlineJoined($spans))->toBe('"hello"');
    });

    it('renders a JSON number', function () {
        $spans = MessageFormatter::colorizeInlineJson('42');

        expect(inlineJoined($spans))->toBe('42');
    });

    it('renders JSON booleans as bare true/false', function () {
        expect(inlineJoined(MessageFormatter::colorizeInlineJson('true')))->toBe('true')
            ->and(inlineJoined(MessageFormatter::colorizeInlineJson('false')))->toBe('false');
    });

    it('renders JSON null as the bare word null', function () {
        $spans = MessageFormatter::colorizeInlineJson('null');

        expect(inlineJoined($spans))->toBe('null');
    });

    it('renders a JSON list with brackets and comma separators', function () {
        $spans = MessageFormatter::colorizeInlineJson('[1,2,3]');

        expect(inlineJoined($spans))->toBe('[1, 2, 3]');
    });

    it('renders a JSON object with braces and quoted keys', function () {
        $spans = MessageFormatter::colorizeInlineJson('{"a":1,"b":2}');

        expect(inlineJoined($spans))->toBe('{"a": 1, "b": 2}');
    });

    it('renders nested structures recursively', function () {
        $spans = MessageFormatter::colorizeInlineJson('{"list":[1,{"x":"y"}]}');

        expect(inlineJoined($spans))->toBe('{"list": [1, {"x": "y"}]}');
    });

    it('falls back to a single error-styled span for invalid JSON', function () {
        $spans = MessageFormatter::colorizeInlineJson('{not valid json');

        expect($spans)->toHaveCount(1)
            ->and($spans[0]->content)->toBe('{not valid json')
            ->and($spans[0]->style->fg->toHex())->toBe('#'.Palette::ERROR);
    });
});

describe('colorizeFormattedJson', function () {
    it('renders a scalar as a single line', function () {
        $lines = MessageFormatter::colorizeFormattedJson('"hi"');

        expect($lines)->toHaveCount(1)
            ->and(formattedJoined($lines))->toBe('"hi"');
    });

    it('renders an object across multiple indented lines with closing brace', function () {
        $lines = MessageFormatter::colorizeFormattedJson('{"a":1,"b":2}');

        expect(formattedJoined($lines))->toBe(
            "{\n"
            .'  "a": 1'."\n"
            .'  "b": 2'."\n"
            .'}'
        );
    });

    it('renders a list using square brackets without keys', function () {
        $lines = MessageFormatter::colorizeFormattedJson('[1,2]');

        expect(formattedJoined($lines))->toBe(
            "[\n"
            .'  1'."\n"
            .'  2'."\n"
            .']'
        );
    });

    it('indents nested objects one level deeper than their parent', function () {
        $lines = MessageFormatter::colorizeFormattedJson('{"a":{"b":1}}');

        expect(formattedJoined($lines))->toBe(
            "{\n"
            .'  "a": '."\n"
            .'  {'."\n"
            .'    "b": 1'."\n"
            .'  }'."\n"
            .'}'
        );
    });

    it('falls back to a single error-styled line for invalid JSON', function () {
        $lines = MessageFormatter::colorizeFormattedJson('{broken');

        expect($lines)->toHaveCount(1)
            ->and(formattedJoined($lines))->toBe('{broken')
            ->and($lines[0]->spans[0]->style->fg->toHex())->toBe('#'.Palette::ERROR);
    });
});
