<?php

declare(strict_types=1);

namespace Tapper\Console;

use PhpTui\Tui\Color\RgbColor;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;

class MessageFormatter
{
    private const string STRING_COLOR = '9ece6a';

    private const string KEY_COLOR = '73daca';

    private const string NUMBER_COLOR = 'fd9d63';

    private const string BOOL_COLOR = 'fd9d63';

    private const string NULL_COLOR = '2bc3de';

    private const string BRACKETS_COLOR = Palette::TEXT_DEFAULT;

    private const string PUNCTUATION_COLOR = '89ddff';

    private const string ERROR_COLOR = Palette::ERROR;

    public static function colorizeInlineJson(string $json): array
    {
        $data = json_decode($json, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return [Span::styled($json, Style::default()->fg(RgbColor::fromHex(self::ERROR_COLOR)))];
        }

        return self::encodeInlineSpans($data);
    }

    public static function colorizeFormattedJson(string $json): array
    {
        $data = json_decode($json, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return [Line::fromSpan(Span::styled($json, Style::default()->fg(RgbColor::fromHex(self::ERROR_COLOR))))];
        }

        $lines = [];
        self::encodeFormattedLines($data, 0, $lines);

        return $lines;
    }

    private static function quoteForKey(): Span
    {
        return Span::styled('"', Style::default()->fg(RgbColor::fromHex(self::KEY_COLOR)));
    }

    private static function quoteForString(): Span
    {
        return Span::styled('"', Style::default()->fg(RgbColor::fromHex(self::STRING_COLOR)));
    }

    private static function styledSymbol(string $char): Span
    {
        return Span::styled($char, Style::default()->fg(RgbColor::fromHex(self::PUNCTUATION_COLOR)));
    }

    private static function styledBracket(string $bracket): Span
    {
        return Span::styled($bracket, Style::default()->fg(RgbColor::fromHex(self::BRACKETS_COLOR)));
    }

    private static function encodeInlineSpans(mixed $data): array
    {
        $spans = [];

        if (is_array($data)) {
            if (array_is_list($data)) {
                $spans[] = self::styledBracket('[');
                foreach ($data as $i => $value) {
                    if ($i > 0) {
                        $spans[] = self::styledSymbol(', ');
                    }
                    $spans = array_merge($spans, self::encodeInlineSpans($value));
                }
                $spans[] = self::styledBracket(']');
            } else {
                $spans[] = self::styledBracket('{');
                $i = 0;
                foreach ($data as $key => $value) {
                    if ($i++ > 0) {
                        $spans[] = self::styledSymbol(', ');
                    }

                    $spans[] = self::quoteForKey();
                    $spans[] = Span::styled($key, Style::default()->fg(RgbColor::fromHex(self::KEY_COLOR)));
                    $spans[] = self::quoteForKey();
                    $spans[] = self::styledSymbol(': ');
                    $spans = array_merge($spans, self::encodeInlineSpans($value));
                }
                $spans[] = self::styledBracket('}');
            }
        } elseif (is_string($data)) {
            $spans[] = self::quoteForString();
            $spans[] = Span::styled($data, Style::default()->fg(RgbColor::fromHex(self::STRING_COLOR)));
            $spans[] = self::quoteForString();
        } elseif (is_bool($data)) {
            $spans[] = Span::styled($data ? 'true' : 'false', Style::default()->fg(RgbColor::fromHex(self::BOOL_COLOR)));
        } elseif (is_numeric($data)) {
            $spans[] = Span::styled((string) $data, Style::default()->fg(RgbColor::fromHex(self::NUMBER_COLOR)));
        } elseif ($data === null) {
            $spans[] = Span::styled('null', Style::default()->fg(RgbColor::fromHex(self::NULL_COLOR)));
        }

        return $spans;
    }

    private static function encodeFormattedLines(mixed $data, int $indent, array &$lines): void
    {
        $pad = str_repeat('  ', $indent);

        if (is_array($data)) {
            $isList = array_is_list($data);
            $lines[] = Line::fromSpan(self::styledBracket($pad.($isList ? '[' : '{')));

            $i = 0;
            foreach ($data as $key => $value) {
                $lineSpans = [Span::fromString($pad.'  ')];

                if (! $isList) {
                    $lineSpans[] = self::quoteForKey();
                    $lineSpans[] = Span::styled($key, Style::default()->fg(RgbColor::fromHex(self::KEY_COLOR)));
                    $lineSpans[] = self::quoteForKey();
                    $lineSpans[] = self::styledSymbol(': ');
                }

                if (is_array($value)) {
                    $lines[] = new Line($lineSpans);
                    self::encodeFormattedLines($value, $indent + 1, $lines);
                } else {
                    $lineSpans = array_merge($lineSpans, self::encodeInlineSpans($value));
                    $lines[] = new Line($lineSpans);
                }

                $i++;
            }

            $lines[] = Line::fromSpan(self::styledBracket($pad.($isList ? ']' : '}')));
        } else {
            $lines[] = new Line(self::encodeInlineSpans($data));
        }
    }
}
