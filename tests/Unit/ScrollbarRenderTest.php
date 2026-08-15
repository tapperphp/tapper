<?php

declare(strict_types=1);

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer;
use PhpTui\Tui\Extension\Core\Widget\Scrollbar\ScrollbarOrientation;
use PhpTui\Tui\Extension\Core\Widget\Scrollbar\ScrollbarSymbols;
use PhpTui\Tui\Extension\Core\Widget\ScrollbarRenderer;
use PhpTui\Tui\Extension\Core\Widget\ScrollbarWidget;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Widget\WidgetRenderer\AggregateWidgetRenderer;
use Tapper\Console\Support\Scroll;

const THUMB_SYMBOL = '█';

/**
 * @return string[] one character per row of the rendered scrollbar track
 */
function renderScrollbarColumn(int $count, int $visible, int $offset, int $trackHeight): array
{
    $area = Area::fromScalars(0, 0, 3, $trackHeight);
    $buffer = Buffer::empty($area);

    $widget = ScrollbarWidget::default()
        ->state(Scroll::scrollbarState($count, $visible, $offset))
        ->orientation(ScrollbarOrientation::VerticalRight)
        ->symbols(new ScrollbarSymbols('│', THUMB_SYMBOL, '', ''))
        ->endSymbol(null)
        ->beginSymbol(null);

    (new ScrollbarRenderer)->render(new AggregateWidgetRenderer([]), $widget, $buffer, $area);

    $column = [];
    for ($y = 0; $y < $trackHeight; $y++) {
        $column[] = $buffer->get(Position::at($area->right() - 1, $y))->char;
    }

    return $column;
}

it('places the thumb at the very top when scrolled to the top', function () {
    $column = renderScrollbarColumn(count: 100, visible: 20, offset: 0, trackHeight: 40);

    expect($column[0])->toBe(THUMB_SYMBOL);
});

it('places the thumb at the very bottom when scrolled to the bottom', function () {
    $column = renderScrollbarColumn(count: 100, visible: 20, offset: 80, trackHeight: 40);

    expect($column[count($column) - 1])->toBe(THUMB_SYMBOL);
});

it('sizes the thumb proportionally instead of collapsing to a single row', function () {
    $column = renderScrollbarColumn(count: 100, visible: 20, offset: 0, trackHeight: 40);

    $thumbRows = count(array_filter($column, fn (string $char) => $char === THUMB_SYMBOL));

    expect($thumbRows)->toBeGreaterThan(1);
});

it('draws nothing when everything already fits on screen', function () {
    $column = renderScrollbarColumn(count: 5, visible: 20, offset: 0, trackHeight: 40);

    $thumbRows = count(array_filter($column, fn (string $char) => $char === THUMB_SYMBOL));

    expect($thumbRows)->toBe(0);
});
