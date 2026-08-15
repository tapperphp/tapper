<?php

declare(strict_types=1);

namespace Tapper\Console;

/**
 * Shared hex color slots for the TUI chrome. Widgets should reference these
 * by name instead of writing raw hex literals, so the theme lives in one place.
 */
final class Palette
{
    public const string ACCENT = '7aa2f7';

    public const string TEXT_DEFAULT = 'c0caf5';

    public const string SELECTION_BG = '2a2e42';

    public const string ERROR = 'f7768e';
}
