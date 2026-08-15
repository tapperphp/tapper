<?php

declare(strict_types=1);

namespace Tapper;

use RuntimeException;

class LogPath
{
    public static function resolve(): string
    {
        $projectPath = realpath(__DIR__.'/..');

        if ($projectPath === false) {
            throw new RuntimeException('[Tapper] could not resolve package root to locate tapper.log.');
        }

        return $projectPath.'/tapper.log';
    }
}
