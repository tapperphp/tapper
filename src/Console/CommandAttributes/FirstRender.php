<?php

declare(strict_types=1);

namespace Tapper\Console\CommandAttributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class FirstRender
{
    public function __construct() {}
}
