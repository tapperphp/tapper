<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Tapper\Console\Application;

$builder = new ContainerBuilder;
$builder->useAutowiring(true);
$builder->useAttributes(true);
$builder->addDefinitions([
    LoopInterface::class => fn () => Loop::get(),
    Terminal::class => fn () => Terminal::new(),
    PhpTermBackend::class => fn () => PhpTermBackend::new(),
    Display::class => fn ($c) => DisplayBuilder::default($c->get(PhpTermBackend::class))->build(),
]);

$container = $builder->build();

return $container->get(Application::class);
