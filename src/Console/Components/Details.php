<?php

declare(strict_types=1);

namespace Tapper\Console\Components;

use DateTime;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\CompositeWidget;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Extension\Core\Widget\Scrollbar\ScrollbarOrientation;
use PhpTui\Tui\Extension\Core\Widget\Scrollbar\ScrollbarState;
use PhpTui\Tui\Extension\Core\Widget\Scrollbar\ScrollbarSymbols;
use PhpTui\Tui\Extension\Core\Widget\ScrollbarWidget;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Widget\Widget;
use Tapper\Console\CommandAttributes\KeyPressed;
use Tapper\Console\CommandAttributes\Mouse;
use Tapper\Console\Component;
use Tapper\Console\MessageFormatter;

class Details extends Component
{
    private int $count = 0;

    #[KeyPressed(KeyCode::Backspace)]
    #[KeyPressed(KeyCode::Esc)]
    public function close(): void
    {
        $this->appState->previewLog = null;
    }

    #[KeyPressed(KeyCode::Up)]
    #[KeyPressed('k')]
    #[Mouse(MouseEventKind::ScrollUp)]
    public function up(): void
    {
        if ($this->appState->detailsOffset > 0) {
            $this->appState->detailsOffset--;
        }
    }

    #[KeyPressed(KeyCode::Down)]
    #[KeyPressed('j')]
    #[Mouse(MouseEventKind::ScrollDown)]
    public function down(): void
    {
        if ($this->appState->detailsOffset < $this->count - $this->area->height) {
            $this->appState->detailsOffset++;
        }
    }

    #[KeyPressed('u', KeyModifiers::CONTROL)]
    public function pageUp(): void
    {
        $halfPage = (int) floor($this->area->height / 2);

        $this->appState->detailsOffset = max(0, $this->appState->detailsOffset - $halfPage);
    }

    #[KeyPressed('d', KeyModifiers::CONTROL)]
    public function pageDown(): void
    {
        $halfPage = (int) floor($this->area->height / 2);

        $this->appState->detailsOffset = min(
            $this->count - $this->area->height,
            $this->appState->detailsOffset + $halfPage,
        );
    }

    protected function view(Area $area): Widget
    {
        $log = $this->appState->previewLog;
        $datetime = DateTime::createFromFormat('U.u', sprintf('%.6f', $log->timestamp));
        $formatted = $datetime->format('Y-m-d H:i:s.u');

        $info = [
            Line::fromString(sprintf('Log #%s | %s', $log->id, $log->caller)),
            Line::fromString($formatted),
            Line::fromString(''),
            Line::fromString('──────────────────── Payload ────────────────────'),
            Line::fromString(''),
        ];
        $infoListItems = array_map(fn ($line) => ListItem::new(Text::fromLine($line)), $info);

        $formattedMessage = MessageFormatter::colorizeFormattedJson($log->message);
        $formattedListItems = array_map(fn ($line) => ListItem::new(Text::fromLine($line)), $formattedMessage);

        $allItems = [
            ...$infoListItems,
            ...$formattedListItems,
        ];

        $this->count = count($allItems);

        return CompositeWidget::fromWidgets(
            ListWidget::default()
                ->items(...$allItems)
                ->offset($this->appState->detailsOffset),
            ScrollbarWidget::default()
                ->state(new ScrollbarState(max(0, $this->count - $this->area->height), $this->appState->detailsOffset, 1))
                ->orientation(ScrollbarOrientation::VerticalRight)
                ->symbols(new ScrollbarSymbols('│', '█', '', ''))
                ->endSymbol(null)
                ->beginSymbol(null),
        );
    }
}
