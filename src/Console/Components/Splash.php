<?php

declare(strict_types=1);

namespace Tapper\Console\Components;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;
use Tapper\Console\Component;

class Splash extends Component
{
    private const TAPPER = 'T A P P E R';

    protected function view(Area $area): Widget
    {
        $height = $area->height;
        $half = (int) floor($height / 2);

        $marginTop = $half - 3;
        $space = Line::fromString('');

        return BlockWidget::default()
            ->widget(
                ParagraphWidget::fromLines(...[
                    ...array_fill(0, $marginTop, $space),
                    Line::fromString(self::TAPPER)->alignment(HorizontalAlignment::Center),
                    Line::fromString($this->appState->version)->alignment(HorizontalAlignment::Center)->darkGray(),
                    $space,
                    Line::fromString('Listening...')->alignment(HorizontalAlignment::Center)->darkGray(),
                ])
            );
    }
}
