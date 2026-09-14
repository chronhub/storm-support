<?php

declare(strict_types=1);

namespace Storm\Support\Tests\Fixture;

use Storm\Support\Console\DestructiveConfirmation;
use Symfony\Component\Console\Command\Command;

abstract class DestructiveFixtureCommand extends Command
{
    use DestructiveConfirmation;
}
