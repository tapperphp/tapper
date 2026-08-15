<?php

declare(strict_types=1);

namespace Tapper;

use RuntimeException;

class SocketPath
{
    public static function resolve(): string
    {
        $projectPath = realpath(__DIR__.'/..');

        if ($projectPath === false) {
            throw new RuntimeException('[Tapper] could not resolve package root to locate tapper.sock.');
        }

        return $projectPath.'/tapper.sock';
    }
}
