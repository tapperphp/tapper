<?php

declare(strict_types=1);

namespace Tapper\Console\Components;

use DateTime;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Color\RgbColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\Buffer\BufferContext;
use PhpTui\Tui\Extension\Core\Widget\BufferWidget;
use PhpTui\Tui\Extension\Core\Widget\CompositeWidget;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;
use Tapper\Console\CommandAttributes\KeyPressed;
use Tapper\Console\CommandAttributes\Mouse;
use Tapper\Console\Component;
use Tapper\Console\MessageFormatter;
use Tapper\Console\Palette;
use Tapper\Console\PhpHighlighter;
use Tapper\Console\Support\Scroll;
use Tapper\Console\Support\SpanTruncator;

class Details extends Component
{
    // The whole pane is wrapped in a bordered Block (see view()): 1 column each side,
    // 1 row top/bottom. Every width/height used to lay out content has to account for
    // that, since BlockRenderer only insets the *rendering*, not values computed here
    // beforehand (truncation budgets, paging math).
    private const int BORDER_SIZE = 2;

    private const int SCROLLBAR_GUTTER = 1;

    private const int H_STEP = 4;

    private int $count = 0;

    // How far scrollRight() can still usefully move — the largest (rawWidth - budget)
    // across every line on screen, each measured against its own budget (code lines
    // reserve a prefix, payload lines a 2-space indent, so budgets differ per line
    // type) — computed fresh each view() pass. Must NOT be derived from a single
    // combined "widest line" width compared against a single flat contentWidth():
    // that undercounts by whatever prefix/indent that widest line reserved, clamping
    // scrollRight() a few columns short of the true end and leaving a misleading
    // trailing "…" that implies there's more when there isn't.
    private int $maxHOffset = 0;

    private function trackScrollBound(int $rawWidth, int $budget): void
    {
        if ($rawWidth <= $budget) {
            return;
        }

        // Once hOffset > 0, SpanTruncator::window() always reserves one column for
        // the leading "…" it now has to show — so the *effective* content budget once
        // scrolling is `budget - 1`, not `budget`. Landing exactly on the last
        // character therefore needs one more step than a naive (rawWidth - budget)
        // would suggest, or the clamp stops one short and a trailing "…" lingers even
        // though nothing more is actually hidden.
        $this->maxHOffset = max($this->maxHOffset, $rawWidth - $budget + 1);
    }

    private function contentWidth(): int
    {
        return max(0, $this->area->width - self::BORDER_SIZE);
    }

    private function contentHeight(): int
    {
        return max(0, $this->area->height - self::BORDER_SIZE);
    }

    #[KeyPressed(KeyCode::Backspace)]
    #[KeyPressed(KeyCode::Esc)]
    #[Mouse(MouseEventKind::Down, MouseButton::Right)]
    public function close(): void
    {
        $this->appState->previewLog = null;
        $this->appState->detailsOffset = 0;
        $this->appState->detailsHOffset = 0;
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
        if ($this->appState->detailsOffset < $this->count - $this->contentHeight()) {
            $this->appState->detailsOffset++;
        }
    }

    #[KeyPressed('u', KeyModifiers::CONTROL)]
    public function pageUp(): void
    {
        $halfPage = (int) floor($this->contentHeight() / 2);

        $this->appState->detailsOffset = max(0, $this->appState->detailsOffset - $halfPage);
    }

    #[KeyPressed('d', KeyModifiers::CONTROL)]
    public function pageDown(): void
    {
        $halfPage = (int) floor($this->contentHeight() / 2);

        $this->appState->detailsOffset = min(
            $this->count - $this->contentHeight(),
            $this->appState->detailsOffset + $halfPage,
        );
    }

    #[KeyPressed(KeyCode::Left)]
    #[KeyPressed('h')]
    #[Mouse(MouseEventKind::ScrollLeft)]
    public function scrollLeft(): void
    {
        $this->appState->detailsHOffset = max(0, $this->appState->detailsHOffset - self::H_STEP);
    }

    #[KeyPressed(KeyCode::Right)]
    #[KeyPressed('l')]
    #[Mouse(MouseEventKind::ScrollRight)]
    public function scrollRight(): void
    {
        $this->appState->detailsHOffset = min($this->maxHOffset, $this->appState->detailsHOffset + self::H_STEP);
    }

    private function parseCode(array $code): array
    {
        $numberWidth = array_reduce(
            $code,
            fn (int $width, array $line): int => max($width, strlen((string) $line['number'])),
            1,
        );

        return array_map(function ($line) use ($numberWidth) {
            $active = $line['active'];

            $prefixStyle = $active
                ? Style::default()->fg(RgbColor::fromHex(Palette::ACCENT))
                : Style::default()->darkGray();

            $prefix = Span::styled(
                sprintf(' %s %s│ ', str_pad((string) $line['number'], $numberWidth, ' ', STR_PAD_LEFT), $active ? '➔' : ' '),
                $prefixStyle,
            );

            $rawCodeSpans = PhpHighlighter::highlightLine($line['line']);
            $budget = max(0, $this->contentWidth() - $prefix->width() - self::SCROLLBAR_GUTTER);
            $this->trackScrollBound(array_sum(array_map(fn (Span $s): int => $s->width(), $rawCodeSpans)), $budget);

            $codeSpans = SpanTruncator::window($rawCodeSpans, $this->appState->detailsHOffset, $budget, Style::default()->darkGray());

            $lineSpans = [$prefix, ...$codeSpans];
            $item = ListItem::new(Text::fromLine(new Line($lineSpans)));

            if ($active) {
                $item = $item->style(Style::default()->bg(RgbColor::fromHex(Palette::SELECTION_BG)));
            }

            return $item;
        }, $code);
    }

    private function sectionLabel(string $label): ListItem
    {
        return ListItem::new(Text::fromLine(Line::fromSpan(Span::styled(
            ' '.$label,
            Style::default()->fg(RgbColor::fromHex(Palette::ACCENT))->addModifier(Modifier::BOLD),
        ))));
    }

    private function parseStackTrace(string $rootDir, array $trace, array $code): array
    {
        $budget = max(0, $this->contentWidth() - self::SCROLLBAR_GUTTER);

        $stack = array_map(function ($item) use ($rootDir, $budget) {
            $file = ' '.sprintf(
                '%s:%s',
                str_replace($rootDir, '', $item['file']),
                $item['line'],
            );

            $this->trackScrollBound(mb_strlen($file), $budget);
            $spans = SpanTruncator::window([Span::fromString($file)], $this->appState->detailsHOffset, $budget, Style::default()->darkGray());

            return ListItem::new(Text::fromLine(new Line($spans)))->style(Style::default()->darkGray());
        }, $trace);

        $stack[0]->style(Style::default()->yellow());

        return [
            $this->sectionLabel('Context'),
            ListItem::fromString(''),
            ...$this->parseCode($code),
            ListItem::fromString(''),
            $this->sectionLabel('Stacktrace'),
            ...$stack,
        ];
    }

    protected function view(Area $area): Widget
    {
        $this->maxHOffset = 0;

        $log = $this->appState->previewLog;
        $datetime = DateTime::createFromFormat('U.u', sprintf('%.6f', $log->timestamp));
        $time = $datetime->format('H:i:s.u');

        $timeLine = Line::fromSpan(Span::styled(' '.$time, Style::default()->darkGray()));

        $infoListItems = [
            ListItem::new(Text::fromLine($timeLine)),
            ListItem::fromString(''),
            $this->sectionLabel('Payload'),
        ];

        $formattedMessage = match ($log->kind) {
            'wait' => [Line::fromSpan(Span::styled($log->message, Style::default()->yellow()))],
            'error' => [Line::fromSpan(Span::styled($log->message, Style::default()->fg(RgbColor::fromHex(Palette::ERROR))))],
            default => MessageFormatter::colorizeFormattedJson($log->message),
        };

        $payloadBudget = max(0, $this->contentWidth() - 2 - self::SCROLLBAR_GUTTER);

        foreach ($formattedMessage as $line) {
            $this->trackScrollBound($line->width(), $payloadBudget);
        }

        $formattedListItems = array_map(
            fn (Line $line): ListItem => ListItem::new(Text::fromLine(new Line([
                Span::fromString('  '),
                ...SpanTruncator::window($line->spans, $this->appState->detailsHOffset, $payloadBudget, Style::default()->darkGray()),
            ]))),
            $formattedMessage,
        );

        $allItems = [
            ...$infoListItems,
            ...$formattedListItems,
            ListItem::fromString(''),
            ...$this->parseStackTrace($log->rootDir, $log->trace, $log->code),
        ];

        $this->count = count($allItems);

        $thumbBounds = Scroll::proportionalThumb(
            $this->count,
            $this->contentHeight(),
            $this->appState->detailsOffset,
            $this->contentHeight(),
        );

        $scrollbar = BufferWidget::new(function (BufferContext $context) use ($thumbBounds): void {
            $trackArea = $context->area;
            $x = max(0, $trackArea->right() - 1);

            // List rows paint their background across the *full* row width (including
            // this column, reserved as blank via SCROLLBAR_GUTTER) — an active/highlighted
            // row would otherwise bleed its background tint through here. setChar() alone
            // doesn't touch style (and Style::default() can't clear an inherited color via
            // patchStyle's null-means-keep-existing semantics), so the color has to be
            // reset explicitly with AnsiColor::Reset.
            $neutralStyle = Style::default()->fg(AnsiColor::Reset)->bg(AnsiColor::Reset);

            for ($y = $trackArea->top(); $y < $trackArea->bottom(); $y++) {
                $isThumb = $thumbBounds !== null
                    && ($y - $trackArea->top()) >= $thumbBounds[0]
                    && ($y - $trackArea->top()) < $thumbBounds[1];

                $context->buffer->get(Position::at($x, $y))
                    ->setChar($isThumb ? '█' : ($thumbBounds === null ? ' ' : '│'))
                    ->setStyle($neutralStyle);
            }
        });

        $content = CompositeWidget::fromWidgets(
            ListWidget::default()
                ->items(...$allItems)
                ->offset($this->appState->detailsOffset),
            $scrollbar,
        );

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' Details ')->horizontalAlignment(HorizontalAlignment::Center))
            ->widget($content);
    }
}
