<?php

declare(strict_types=1);

use Tapper\SocketPath;

it('resolves to tapper.sock inside the system temp directory', function () {
    expect(SocketPath::resolve())->toBe(sys_get_temp_dir().'/tapper.sock');
});
