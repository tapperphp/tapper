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

    public function setData(?LogItemState $log): void
    {
        $this->log = $log;
    }

    #[Mouse(MouseEventKind::Down, global: true)]
    public function mouseMove(array $data): void
    {
        /** @var MouseEvent $event */
        $event = $data['event'];

        if (! $this->log) {
            return;
        }

        $elementPosInView = ($this->log->id - $this->appState->offset);
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
        if ($this->appState->cursor === $this->log->id) {
            $this->appState->previewLog = $this->log;
        } else {
            $this->appState->cursor = $this->log->id;
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

        $mark = $this->appState->cursor === $this->log->id;

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
