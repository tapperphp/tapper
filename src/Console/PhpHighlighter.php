<?php

declare(strict_types=1);

namespace Tapper\Console;

use PhpTui\Tui\Color\RgbColor;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Span;

/**
 * Syntax-highlights a single line of PHP source using the native `token_get_all()`
 * lexer, so the code excerpt shown in the Details view gets real PHP highlighting
 * instead of a flat color per line. Each excerpt line is tokenized independently
 * (prefixed with a synthetic `<?php ` tag that is stripped from the output), which
 * is a best-effort approximation: constructs spanning multiple lines (e.g. a heredoc
 * that starts above the excerpt window) may tokenize imperfectly, but `token_get_all`
 * never throws for that — it just produces slightly-off tokens for that one line.
 */
final class PhpHighlighter
{
    private const string KEYWORD_COLOR = 'bb9af7';

    private const string VARIABLE_COLOR = '7dcfff';

    private const string STRING_COLOR = '9ece6a';

    private const string NUMBER_COLOR = 'ff9e64';

    private const string COMMENT_COLOR = '565f89';

    private const string FUNCTION_COLOR = '7aa2f7';

    private const array KEYWORD_TOKENS = [
        T_IF, T_ELSE, T_ELSEIF, T_ENDIF, T_FOR, T_FOREACH, T_ENDFOR, T_ENDFOREACH,
        T_WHILE, T_ENDWHILE, T_DO, T_SWITCH, T_ENDSWITCH, T_CASE, T_DEFAULT, T_BREAK,
        T_CONTINUE, T_FUNCTION, T_FN, T_RETURN, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM,
        T_EXTENDS, T_IMPLEMENTS, T_NEW, T_CLONE, T_INSTANCEOF, T_STATIC, T_PUBLIC,
        T_PROTECTED, T_PRIVATE, T_ABSTRACT, T_FINAL, T_READONLY, T_CONST, T_USE,
        T_NAMESPACE, T_TRY, T_CATCH, T_FINALLY, T_THROW, T_MATCH, T_ECHO, T_PRINT,
        T_GLOBAL, T_ARRAY, T_LIST, T_ISSET, T_UNSET, T_EMPTY, T_YIELD, T_AS,
        T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_REQUIRE, T_REQUIRE_ONCE,
        T_INCLUDE, T_INCLUDE_ONCE, T_GOTO, T_DECLARE, T_ENDDECLARE, T_VAR, T_CALLABLE,
        T_INSTEADOF,
    ];

    private const array STRING_TOKENS = [
        T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC,
        T_END_HEREDOC,
    ];

    private const array COMMENT_TOKENS = [T_COMMENT, T_DOC_COMMENT];

    private const array BARE_KEYWORDS = ['true', 'false', 'null', 'self', 'parent'];

    /**
     * @return Span[]
     */
    public static function highlightLine(string $line): array
    {
        $tokens = token_get_all('<?php '.$line);

        // Drop the synthetic opening tag we injected above so it never appears in output.
        array_shift($tokens);

        $spans = [];

        foreach ($tokens as $i => $token) {
            if (is_string($token)) {
                $spans[] = Span::fromString($token);

                continue;
            }

            [$id, $text] = $token;
            $spans[] = Span::styled($text, self::styleFor($id, $text, $tokens, $i));
        }

        return $spans;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function styleFor(int $id, string $text, array $tokens, int $index): Style
    {
        if (in_array($id, self::COMMENT_TOKENS, true)) {
            return Style::default()->fg(RgbColor::fromHex(self::COMMENT_COLOR));
        }

        if (in_array($id, self::KEYWORD_TOKENS, true)) {
            return Style::default()->fg(RgbColor::fromHex(self::KEYWORD_COLOR));
        }

        if ($id === T_VARIABLE) {
            return Style::default()->fg(RgbColor::fromHex(self::VARIABLE_COLOR));
        }

        if (in_array($id, self::STRING_TOKENS, true)) {
            return Style::default()->fg(RgbColor::fromHex(self::STRING_COLOR));
        }

        if ($id === T_LNUMBER || $id === T_DNUMBER) {
            return Style::default()->fg(RgbColor::fromHex(self::NUMBER_COLOR));
        }

        if ($id === T_STRING) {
            if (in_array(strtolower($text), self::BARE_KEYWORDS, true)) {
                return Style::default()->fg(RgbColor::fromHex(self::KEYWORD_COLOR));
            }

            if (self::nextSignificant($tokens, $index) === '(') {
                return Style::default()->fg(RgbColor::fromHex(self::FUNCTION_COLOR));
            }
        }

        return Style::default()->fg(RgbColor::fromHex(Palette::TEXT_DEFAULT));
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nextSignificant(array $tokens, int $index): ?string
    {
        for ($i = $index + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                return $token;
            }

            if ($token[0] === T_WHITESPACE) {
                continue;
            }

            return $token[1];
        }

        return null;
    }
}
