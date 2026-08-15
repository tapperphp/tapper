<?php

declare(strict_types=1);

namespace Tapper\Console\Components;

use PhpTui\Term\KeyCode;
use PhpTui\Tui\Color\RgbColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\Buffer\BufferContext;
use PhpTui\Tui\Extension\Core\Widget\BufferWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Widget;
use Tapper\Console\CommandAttributes\KeyPressed;
use Tapper\Console\CommandAttributes\OnEvent;
use Tapper\Console\Component;
use Tapper\Console\Palette;

/**
 * The `/` filter input line. Only active while `AppState::typingMode` is on (toggled by
 * `LogList::startFilter()`, wired up in `Main::afterInit()`) — its key bindings are
 * intentionally non-global so they don't shadow the normal list/details bindings.
 */
class Filter extends Component
{
    #[OnEvent('input')]
    public function appendChar(array $data): void
    {
        $this->appState->filter .= $data['char'];
    }

    #[KeyPressed(KeyCode::Backspace)]
    public function backspace(): void
    {
        $this->appState->filter = mb_substr($this->appState->filter, 0, -1);
    }

    #[KeyPressed(KeyCode::Enter)]
    public function confirm(): void
    {
        $this->appState->typingMode = false;
    }

    #[KeyPressed(KeyCode::Esc)]
    public function cancel(): void
    {
        $this->appState->filter = '';
        $this->appState->typingMode = false;
    }

    protected function view(Area $area): Widget
    {
        $barArea = Area::fromScalars(
            $area->position->x,
            $area->position->y + $area->height - 1,
            $area->width,
            1,
        );

        $prefix = Span::styled(' /', Style::default()->fg(RgbColor::fromHex(Palette::ACCENT)));
        $text = Span::fromString($this->appState->filter);

        // ParagraphRenderer only writes cells under actual glyphs — it doesn't blank the
        // rest of its area — so without explicit padding, Navigation's text (rendered into
        // this same row a moment earlier) bleeds through past the end of the filter text.
        $padding = Span::fromString(str_repeat(' ', max(0, $barArea->width - $prefix->width() - $text->width())));

        return BufferWidget::new(function (BufferContext $context) use ($barArea, $prefix, $text, $padding): void {
            $context->draw(
                ParagraphWidget::fromSpans($prefix, $text, $padding),
                $barArea,
            );
        });
    }
}
