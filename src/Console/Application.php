<?php

namespace Tapper\Console;

use DI\Container;
use PhpTui\Term\Actions;
use PhpTui\Term\Event;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\EventParser;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\Extension\Core\Widget\CompositeWidget;
use React\EventLoop\LoopInterface;
use React\Stream\ReadableResourceStream;
use Tapper\Console\State\AppState;
use Tapper\Console\Windows\Main;
use Tapper\Console\Windows\Popup;
use Tapper\Server;

class Application
{
    const float RESIZE_RATE = 1 / 4;

    const float RENDER_RATE = 1 / 60;

    private Component $window;

    private Popup $popup;

    private Area $area;

    private bool $shouldDraw = true;

    public function __construct(
        private LoopInterface $loop,
        private Terminal $terminal,
        private Display $display,
        private PhpTermBackend $phpTermBackend,
        private EventParser $eventParser,
        private EventBus $eventBus,
        private CommandInvoker $commandInvoker,
        private Container $container,
        private AppState $appState,
        private Server $server,
    ) {}

    public function run(): int
    {
        ErrorHandler::install($this->appState);

        $this->area = $this->phpTermBackend->size();
        $this->appState->version = 'v0.1.1';
        $this->terminal->execute(Actions::alternateScreenEnable());
        $this->terminal->execute(Actions::cursorHide());
        $this->terminal->execute(Actions::enableMouseCapture());
        $this->terminal->enableRawMode();
        $this->terminal->execute(Actions::moveCursor(0, 0));
        $this->display->clear();
        $this->init();
        $this->startRendering();
        $this->startInputHandling();
        $this->server->run();

        $this->loop->addSignal(SIGINT, function () {
            $this->close();
        });
        $this->loop->addSignal(SIGTERM, function () {
            $this->close();
        });

        $this->loop->run();

        return 0;
    }

    private function init(): void
    {
        $this->window = $this->container->make(Main::class);
        $this->popup = $this->container->make(Popup::class);
    }

    private function startRendering(): void
    {
        $this->appState->setOnChange(fn () => $this->shouldDraw = true);

        $this->loop->addPeriodicTimer(self::RESIZE_RATE, function () {
            if ($this->area != $this->phpTermBackend->size()) {
                $this->area = $this->phpTermBackend->size();
                $this->draw($this->area);
                $this->eventBus->emit('resize');
                $this->shouldDraw = true;
            }
        });

        $this->loop->addPeriodicTimer(self::RENDER_RATE, function () {
            if ($this->shouldDraw) {
                $this->shouldDraw = false;
                $this->draw($this->area);
            }
        });
    }

    private function draw(Area $area): void
    {
        $widgets = [$this->window->render($area)];

        if ($this->appState->popupOpen) {
            $widgets[] = $this->popup->render($area);
        }

        $composite = CompositeWidget::fromWidgets(...$widgets);

        $this->display->draw($composite);
    }

    private function startInputHandling(): void
    {
        $stdin = new ReadableResourceStream(STDIN, $this->loop);
        $stdin->on('data', function ($data) {
            $this->eventParser->advance($data, false);

            foreach ($this->eventParser->drain() as $event) {
                $this->handleEvent($event);
                $this->handleEventInTypingMode($event, $data);
            }

            // `stty raw` (enabled by Terminal::enableRawMode()) disables ISIG, so Ctrl+C
            // never reaches us as SIGINT — it arrives as byte 0x03 (ETX) on stdin instead.
            if ($data === 'q' || $data === "\x03") {
                $this->close();
            }
        });
    }

    private function handleEvent(Event $event): void
    {
        $supportedEvents = [
            CharKeyEvent::class,
            CodedKeyEvent::class,
            MouseEvent::class,
        ];

        if (in_array($event::class, $supportedEvents)) {
            $this->eventBus->emit($event);
        }
    }

    private function handleEventInTypingMode(Event $event, $data): void
    {
        if (! $this->appState->typingMode) {
            return;
        }

        if ($event instanceof MouseEvent) {
            if ($event->kind !== MouseEventKind::Down) {
                return;
            }

            $this->appState->typingMode = false;
        }

        if ($event instanceof CharKeyEvent) {
            $this->eventBus->emit('input', ['data' => $data]);

            return;
        }

        if ($event->code === KeyCode::Esc) {
            $this->appState->typingMode = false;

            return;
        }
    }

    public function close(): void
    {
        $this->loop->stop();
        $this->terminal->disableRawMode();
        $this->terminal->execute(Actions::disableMouseCapture());
        $this->terminal->execute(Actions::cursorShow());
        $this->terminal->execute(Actions::alternateScreenDisable());
    }
}
