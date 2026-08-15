<?php

declare(strict_types=1);

namespace Tapper\Console\State;

class LogItem
{
    public function __construct(
        public int $id,
        public float $timestamp,
        public string $message,
        public string $caller,
        public array $trace,
        public string $rootDir,
        public array $code,
        public string $kind = 'log',
    ) {}
}
