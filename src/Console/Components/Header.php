<?php

declare(strict_types=1);

namespace Tapper\Console\Components;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Widget;
use Tapper\Console\Component;

class Header extends Component
{
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
                        Span::fromString(' | '),
                        Span::fromString(sprintf('port: %s', $this->appState->port)),
                    ),
                );
    }
}
