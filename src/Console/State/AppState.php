<?php

declare(strict_types=1);

namespace Tapper\Console\State;

use RuntimeException;

/**
 * @property string $version
 * @property ?int $port
 * @property bool $live
 * @property bool $showDot
 * @property bool $typingMode
 * @property int $cursor
 * @property int $offset
 * @property int $unread
 * @property ?LogItem $previewLog
 * @property array $logs
 * @property int $detailsCursor
 * @property int $detailsOffset
 * @property int $detailsHOffset
 * @property int $pendingWaits
 * @property bool $popupOpen
 * @property ?string $errorNotice
 * @property float $errorNoticeExpiresAt
 * @property string $filter
 */
class AppState
{
    private array $observers = [];

    private $change = null;

    private $batching = false;

    private $changed = [];

    /**
     * @param  LogItem[]  $logs
     */
    public function __construct(
        private string $version = '',
        private ?int $port = null,
        private bool $live = true,
        private bool $showDot = true,
        private bool $typingMode = false,
        private int $cursor = 0,
        private int $offset = 0,
        private int $unread = 0,
        private ?LogItem $previewLog = null,
        private array $logs = [],
        private int $detailsCursor = 0,
        private int $detailsOffset = 0,
        private int $detailsHOffset = 0,
        private int $pendingWaits = 0,
        private bool $popupOpen = false,
        private ?string $errorNotice = null,
        private float $errorNoticeExpiresAt = 0.0,
        private string $filter = '',
    ) {}

    /**
     * @return LogItem[]
     */
    public function logs(): array
    {
        return $this->logs;
    }

    /**
     * @return LogItem[]
     */
    public function filteredLogs(): array
    {
        if ($this->filter === '') {
            return $this->logs;
        }

        return array_values(array_filter(
            $this->logs,
            fn (LogItem $log): bool => str_contains(strtolower($log->message), strtolower($this->filter)),
        ));
    }

    public function setOnChange(callable $change): void
    {
        $this->change = $change;
    }

    /**
     * @return bool true if a new entry was appended, false if it was merged
     *              into the previous identical entry (its repeat counter bumped)
     */
    public function appendLog(LogItem $logItem): bool
    {
        $last = $this->logs[count($this->logs) - 1] ?? null;
        $isRepeat = $last
            && $last->kind === $logItem->kind
            && $last->message === $logItem->message
            && $last->caller === $logItem->caller;

        if ($isRepeat) {
            $last->repeatCount++;
        } else {
            $this->logs[] = $logItem;
        }

        if ($this->batching) {
            $this->changed['logs'] = true;
        } else {
            $this->notifyChange();
            $this->callObservers('logs');
        }

        return ! $isRepeat;
    }

    public function observe(string $name, callable $callable): void
    {
        $params = get_class_vars($this::class);
        unset($params['observers']);
        $params = array_keys($params);

        if (! in_array($name, $params)) {
            throw new RuntimeException(sprintf('Cannot observe state. %s is not defined.', $name));
        }

        if (! array_key_exists($name, $this->observers)) {
            $this->observers[$name] = [];
        }

        $this->observers[$name][] = $callable;
    }

    public function deffer(): void
    {
        $this->batching = true;
    }

    public function commit(): void
    {
        $this->batching = false;

        foreach (array_keys($this->changed) as $field) {
            $this->callObservers($field);
        }

        $this->changed = [];

        $this->notifyChange();
    }

    public function __set($name, $value)
    {
        $this->$name = $value;

        if ($this->batching) {
            // Keyed by field name (a set, not a list) so repeatedly setting the same
            // field while batched — e.g. pressing Enter many times while a tp()->wait()
            // is pending — doesn't grow $changed unbounded or replay observers per write.
            $this->changed[$name] = true;
        } else {
            $this->notifyChange();
            $this->callObservers($name);
        }
    }

    public function __get($name)
    {
        return $this->$name;
    }

    private function notifyChange(): void
    {
        if ($this->change) {
            ($this->change)();
        }
    }

    private function callObservers(string $name): void
    {
        if (! array_key_exists($name, $this->observers)) {
            return;
        }

        foreach ($this->observers[$name] as $observer) {
            $observer($this->$name);
        }
    }
}
