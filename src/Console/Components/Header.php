<?php

declare(strict_types=1);

namespace Tapper\Console\Components;

use PhpTui\Tui\Color\RgbColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Widget;
use Tapper\Console\CommandAttributes\Periodic;
use Tapper\Console\Component;
use Tapper\Console\Palette;

class Header extends Component
{
    #[Periodic(1.0)]
    public function clearExpiredErrorNotice(): void
    {
        if ($this->appState->errorNotice !== null && microtime(true) >= $this->appState->errorNoticeExpiresAt) {
            $this->appState->errorNotice = null;
        }
    }

    protected function view(Area $area): Widget
    {
        $waiting = $this->appState->pendingWaits > 0;
        $live = $this->appState->live;

        $unread = $this->appState->unread > 0;

        [$dot, $label] = match (true) {
            $waiting => [Span::fromString('⏳')->yellow(), 'WAIT'],
            $live => [Span::fromString('●')->red(), 'LIVE'],
            default => [Span::fromString('⏸')->blue(), 'PAUSED'],
        };

        return
            BlockWidget::default()
                ->borders(Borders::BOTTOM)
                ->borderType(BorderType::Plain)
                ->widget(
                    ParagraphWidget::fromSpans(
                        Span::fromString(' '),
                        $dot,
                        Span::fromString(' '),
                        Span::fromString($label),
                        $unread ? Span::fromString(sprintf(' (↓%s)', $this->appState->unread))->yellow() : Span::fromString(''),
                        $this->appState->port !== null
                            ? Span::fromString(sprintf(' | port: %s', $this->appState->port))
                            : Span::fromString(''),
                        $this->appState->filter !== '' && ! $this->appState->typingMode
                            ? Span::styled(sprintf(' | filter: %s', $this->appState->filter), Style::default()->fg(RgbColor::fromHex(Palette::ACCENT)))
                            : Span::fromString(''),
                        $this->appState->errorNotice !== null
                            ? Span::styled(' | '.$this->appState->errorNotice, Style::default()->fg(RgbColor::fromHex(Palette::ERROR)))
                            : Span::fromString(''),
                    ),
                );
    }
}
