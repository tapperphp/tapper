<?php

declare(strict_types=1);

namespace Tapper\Console\Windows;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;
use React\EventLoop\Loop;
use Tapper\Console\Component;
use Tapper\Console\Components\Details;
use Tapper\Console\Components\Header;
use Tapper\Console\Components\LogList;
use Tapper\Console\Components\Navigation;
use Tapper\Console\Components\Splash;
use Tapper\Console\State\LogItem;

class Main extends Component
{
    private const int MIN_WIDTH = 40;

    private const int MIN_HEIGHT = 8;

    protected array $components = [
        Header::class,
        Details::class,
        LogList::class,
        Navigation::class,
        Splash::class,
    ];

    private ?Component $mainPane = null;

    public function afterInit(): void
    {
        $this->componentInstances[LogList::class]->activate();

        $this->appState->observe('previewLog', function (?LogItem $log) {
            Loop::futureTick(function () use ($log) {
                if ($this->appState->popupOpen) {
                    return;
                }

                if ($log) {
                    $this->componentInstances[Details::class]->activate();
                    $this->componentInstances[LogList::class]->deactivate();
                } else {
                    $this->componentInstances[Details::class]->deactivate();
                    $this->componentInstances[LogList::class]->activate();
                }
            });
        });

        $this->appState->observe('popupOpen', function (bool $open) {
            Loop::futureTick(function () use ($open) {
                if ($open) {
                    $this->componentInstances[Details::class]->deactivate();
                    $this->componentInstances[LogList::class]->deactivate();

                    return;
                }

                if ($this->appState->previewLog) {
                    $this->componentInstances[Details::class]->activate();
                } else {
                    $this->componentInstances[LogList::class]->activate();
                }
            });
        });

        // Deliberately synchronous, unlike the observers above: typing a filter query can
        // drain several char events from a single stdin read (fast typing/paste), all
        // dispatched within the same tick. Deferring LogList's deactivation to the next
        // tick left it briefly still-active for those characters, alongside Application's
        // Filter — a one-tick race that dropped/misrouted the first few keystrokes.
        $this->appState->observe('typingMode', function (bool $typing) {
            if ($typing) {
                $this->componentInstances[LogList::class]->deactivate();

                return;
            }

            if ($this->appState->previewLog) {
                $this->componentInstances[Details::class]->activate();
            } else {
                $this->componentInstances[LogList::class]->activate();
            }
        });
    }

    protected function view(Area $area): Widget
    {
        if ($area->width < self::MIN_WIDTH || $area->height < self::MIN_HEIGHT) {
            return BlockWidget::default()
                ->widget(
                    ParagraphWidget::fromLines(
                        Line::fromString('Terminal too small')->alignment(HorizontalAlignment::Center),
                        Line::fromString(sprintf('Resize to at least %dx%d', self::MIN_WIDTH, self::MIN_HEIGHT))
                            ->alignment(HorizontalAlignment::Center)
                            ->darkGray(),
                    ),
                );
        }

        $verticalConstraints = [
            Constraint::length(2),
            Constraint::length($area->height - 4),
            Constraint::length(2),
        ];
        $verticalLayout = Layout::default()
            ->direction(Direction::Vertical)
            ->constraints($verticalConstraints)
            ->split($area);

        $middle = $verticalLayout->get(1);

        if ($this->appState->previewLog !== null) {
            $this->mainPane = $this->getComponent(Details::class);
        } elseif (count($this->appState->logs) > 0) {
            $this->mainPane = $this->getComponent(LogList::class);
        } else {
            $this->mainPane = $this->getComponent(Splash::class);
        }

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$verticalConstraints)
            ->widgets(
                $this->renderComponent(Header::class, $verticalLayout->get(0)),
                $this->mainPane->render($middle),
                $this->renderComponent(Navigation::class, $verticalLayout->get(2)),
            );
    }
}
