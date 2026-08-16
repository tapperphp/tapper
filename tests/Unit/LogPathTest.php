<?php

declare(strict_types=1);

use Tapper\LogPath;

it('resolves to tapper.log inside the system temp directory', function () {
    expect(LogPath::resolve())->toBe(sys_get_temp_dir().'/tapper.log');
});
