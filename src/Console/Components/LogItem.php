<?php

declare(strict_types=1);

namespace Tapper\Console\Components;

use DateTime;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Color\RgbColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use Tapper\Console\CommandAttributes\Mouse;
use Tapper\Console\Component;
use Tapper\Console\MessageFormatter;
use Tapper\Console\Palette;
use Tapper\Console\State\LogItem as LogItemState;

class LogItem extends Component
{
    public const int HEIGHT = 2;

    private const int SCROLLBAR_GUTTER = 1;

    private ?LogItemState $log = null;

    // Position of $log within the currently displayed (possibly filtered) list — NOT
    // $log->id, which is a permanent identifier assigned when the log was received and
    // only coincidentally matches list position when nothing is filtered out.
    private ?int $index = null;

    public function setData(?LogItemState $log, ?int $index = null): void
    {
        $this->log = $log;
        $this->index = $index;
    }

    #[Mouse(MouseEventKind::Down, global: true)]
    public function mouseMove(array $data): void
    {
        /** @var MouseEvent $event */
        $event = $data['event'];

        if (! $this->log || $this->index === null) {
            return;
        }

        $elementPosInView = ($this->index - $this->appState->offset);
        $itemPosition = ($elementPosInView * self::HEIGHT) + 1;

        if ($event->row > $itemPosition
            && $event->row < $itemPosition + self::HEIGHT
        ) {
            $this->click();

            return;
        }
    }

    public function click(): void
    {
        if ($this->index === null) {
            return;
        }

        if ($this->appState->cursor === $this->index) {
            $this->appState->previewLog = $this->log;
        } else {
            $this->appState->cursor = $this->index;
        }
    }

    /**
     * @param  Span[]  $spans
     * @return Span[]
     */
    private function truncateSpans(array $spans, int $maxWidth, Style $ellipsisStyle): array
    {
        $totalWidth = array_sum(array_map(fn (Span $span): int => $span->width(), $spans));

        if ($totalWidth <= $maxWidth) {
            return $spans;
        }

        $budget = max(0, $maxWidth - 1);
        $truncated = [];

        foreach ($spans as $span) {
            if ($budget <= 0) {
                break;
            }

            if ($span->width() <= $budget) {
                $truncated[] = $span;
                $budget -= $span->width();

                continue;
            }

            $chars = array_slice(mb_str_split($span->content), 0, $budget);
            $truncated[] = Span::styled(implode('', $chars), $span->style);
            $budget = 0;
        }

        $truncated[] = Span::styled('…', $ellipsisStyle);

        return $truncated;
    }

    protected function view(Area $area): Widget
    {
        if (! $this->log) {
            return BlockWidget::default();
        }

        $dt = DateTime::createFromFormat('U.u', sprintf('%.6f', $this->log->timestamp));
        $time = $dt->format('H:i:s.u');

        $mark = $this->index !== null && $this->appState->cursor === $this->index;

        $darkGray = Style::default()->darkGray();
        $markerColor = RgbColor::fromHex(Palette::SELECTION_BG);
        $mStyle = Style::default()->bg($markerColor);

        $message = $this->log->message;
        $messageWidth = max(0, $area->width - 17 - self::SCROLLBAR_GUTTER);

        $timeColumn = ParagraphWidget::fromSpans(
            Span::styled($time, Style::default()->fg(RgbColor::fromHex(Palette::ACCENT))),
        );

        $messageSpans = match ($this->log->kind) {
            'wait' => [Span::styled($message, Style::default()->yellow())],
            'error' => [Span::styled($message, Style::default()->fg(RgbColor::fromHex(Palette::ERROR)))],
            default => MessageFormatter::colorizeInlineJson($message),
        };

        $repeatBadge = $this->log->repeatCount > 1
            ? Span::styled(sprintf(' ×%d', $this->log->repeatCount), Style::default()->fg(RgbColor::fromHex(Palette::ACCENT)))
            : null;

        $messageSpans = $this->truncateSpans($messageSpans, $messageWidth - ($repeatBadge?->width() ?? 0), $darkGray);

        if ($repeatBadge) {
            $messageSpans[] = $repeatBadge;
        }

        $wMess = ParagraphWidget::fromSpans(...$messageSpans);
        $wFile = ParagraphWidget::fromSpans(
            ...$this->truncateSpans([Span::styled(sprintf('↪ %s', $this->log->caller), $darkGray)], $messageWidth, $darkGray),
        );

        if ($mark) {
            $timeColumn->style($mStyle);
            $wMess->style($mStyle);
            $wFile->style($mStyle);
        }

        $messageColumn = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::length(1))
            ->widgets(
                $wMess,
                $wFile,
            );

        return BlockWidget::default()
            ->widget(
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(
                        Constraint::length(17),
                        Constraint::length($messageWidth),
                    )->widgets(
                        $timeColumn,
                        $messageColumn,
                    ),
            );
    }
}
