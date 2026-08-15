#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$scenarios = [
    '1' => ['label' => 'Simple string', 'run' => fn () => tp('👋 Hello from the dev menu')],
    '2' => ['label' => 'Small structured array', 'run' => fn () => tp(['fruits' => ['apple', 'banana', 'pineapple']])],
    '3' => ['label' => 'Large nested payload', 'run' => fn () => tp([
        'fruits' => ['apple', 'banana', 'pineapple', 'orange', 'strawberry', 'blueberry', 'pear'],
        'accounts' => [
            ['name' => 'Alice', 'email' => 'alice@ec.net', 'subscribe' => false, 'age' => 26],
            ['name' => 'John', 'email' => 'john@ec.net', 'subscribe' => true, 'age' => 21],
            ['name' => 'Jane', 'email' => 'jane@ec.net', 'subscribe' => true, 'age' => 31],
        ],
    ])],
    '4' => ['label' => 'Long string (wrapping test)', 'run' => fn () => tp(str_repeat('This is a long log line used to test wrapping and scrolling behaviour. ', 5))],
    '5' => ['label' => 'wait() — pauses until Enter is pressed in the Tapper window', 'run' => function () {
        tp('Paused — press Enter in the Tapper window to continue')->wait();
        tp('Resumed after wait()');
    }],
    '6' => ['label' => 'Burst of 20 logs (scroll/perf test)', 'run' => function () {
        foreach (range(1, 20) as $i) {
            tp("Burst log #{$i}");
        }
    }],
    '7' => ['label' => 'Primitives: null, bool, int, float', 'run' => function () {
        tp(null);
        tp(true);
        tp(false);
        tp(42);
        tp(3.14159);
    }],
    '8' => ['label' => 'Custom message (typed here)', 'run' => function () {
        echo 'Message: ';
        $line = fgets(STDIN);
        tp(trim($line ?: ''));
    }],
    '9' => ['label' => 'Exception (tp($throwable))', 'run' => function () {
        try {
            throw new RuntimeException('Something went wrong while processing the request');
        } catch (Throwable $e) {
            tp($e);
        }
    }],
    '10' => ['label' => 'Repeated identical log (×N counter test)', 'run' => function () {
        for ($i = 0; $i < 5; $i++) {
            tp('Same message every time');
        }
    }],
];

echo "Tapper dev menu — make sure `php bin/tapper` is running in another terminal.\n";

while (true) {
    echo "\nPick a scenario (q to quit):\n";
    foreach ($scenarios as $key => $scenario) {
        echo "  {$key}) {$scenario['label']}\n";
    }
    echo '> ';

    $choice = trim(fgets(STDIN) ?: '');

    if ($choice === 'q' || $choice === 'quit') {
        break;
    }

    if (! isset($scenarios[$choice])) {
        echo "Unknown option: {$choice}\n";

        continue;
    }

    try {
        $scenarios[$choice]['run']();
        echo "Sent.\n";
    } catch (Throwable $e) {
        echo 'Error: '.$e->getMessage()."\n";
        echo "Is `php bin/tapper` running?\n";
    }
}
