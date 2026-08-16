<?php

declare(strict_types=1);

namespace Tapper;

class LogPath
{
    public static function resolve(): string
    {
        return sys_get_temp_dir().'/tapper.log';
    }
}
