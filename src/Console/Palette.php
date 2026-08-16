<?php

declare(strict_types=1);

namespace Tapper\Console;

/**
 * Shared hex color slots for the TUI chrome. Widgets should reference these
 * by name instead of writing raw hex literals, so the theme lives in one place.
 */
final class Palette
{
    public const ACCENT = '7aa2f7';

    public const TEXT_DEFAULT = 'c0caf5';

    public const SELECTION_BG = '2a2e42';

    public const ERROR = 'f7768e';
}
