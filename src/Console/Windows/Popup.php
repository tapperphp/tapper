<?php

declare(strict_types=1);

namespace Tapper\Console\Windows;

use PhpTui\Term\KeyCode;
use PhpTui\Tui\Color\RgbColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\Buffer\BufferContext;
use PhpTui\Tui\Extension\Core\Widget\BufferWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;
use Tapper\Console\CommandAttributes\KeyPressed;
use Tapper\Console\Component;
use Tapper\Console\Palette;

class Popup extends Component
{
    #[KeyPressed('?', global: true)]
    public function toggle(): void
    {
        $this->appState->popupOpen = ! $this->appState->popupOpen;
    }

    #[KeyPressed(KeyCode::Esc, global: true)]
    public function close(): void
    {
        if ($this->appState->popupOpen) {
            $this->appState->popupOpen = false;
        }
    }

    protected function view(Area $area): Widget
    {
        $keyStyle = Style::default()->fg(RgbColor::fromHex(Palette::ACCENT));
        $descStyle = Style::default()->fg(RgbColor::fromHex(Palette::TEXT_DEFAULT));

        $shortcuts = [
            ['↑ / k', '↓ / j', 'Move cursor'],
            ['g', 'G', 'Jump to top / bottom'],
            ['Ctrl+u', 'Ctrl+d', 'Page up / down'],
            ['space', 'enter', 'Open details'],
            ['h / ←', 'l / →', 'Scroll details horizontally'],
            ['enter', '', 'Continue a paused wait'],
            ['backspace', 'esc', 'Close details / back to live'],
            ['ctrl+l', '', 'Clear all logs'],
            ['/', '', 'Filter logs'],
            ['enter', 'esc', 'Confirm / cancel filter'],
            ['?', '', 'Toggle this help'],
            ['q', '', 'Quit'],
        ];

        $lines = array_map(
            fn (array $row): Line => Line::fromSpans(
                Span::styled(sprintf('%-10s', $row[0]), $keyStyle),
                Span::styled(sprintf('%-10s', $row[1]), $keyStyle),
                Span::styled($row[2], $descStyle),
            ),
            $shortcuts,
        );

        $width = min($area->width, 46);
        $height = min($area->height, count($lines) + 2);

        $popupArea = Area::fromScalars(
            $area->position->x + intdiv(max(0, $area->width - $width), 2),
            $area->position->y + intdiv(max(0, $area->height - $height), 2),
            $width,
            $height,
        );

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' Shortcuts ')->horizontalAlignment(HorizontalAlignment::Center))
            ->widget(ParagraphWidget::fromLines(...$lines));

        return BufferWidget::new(function (BufferContext $context) use ($block, $popupArea): void {
            $context->draw($block, $popupArea);
        });
    }
}
