<?php

/**
 * Mistakes in finding resources fail when the container compiles.
 */

declare(strict_types=1);

use Nette\DI\InvalidConfigurationException;
use Nette\InvalidStateException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/AccessConflict/Resources.php';

$e = Assert::exception(static fn() => createContainer(<<<'NEON'
apiQuery:
    resources: Phlox\ApiQuery\Tests\Fixtures\AccessConflict
NEON), InvalidStateException::class);
Assert::contains('Both Phlox\ApiQuery\Tests\Fixtures\AccessConflict\FirstOrder and Phlox\ApiQuery\Tests\Fixtures\AccessConflict\SecondOrder are resources of Phlox\ApiQuery\Tests\Fixtures\Entity\Order.', $e->getMessage());
Assert::contains('default: false', $e->getMessage());

Assert::exception(static fn() => createContainer(<<<'NEON'
apiQuery:
    entities:
        Phlox\ApiQuery\Tests\Fixtures\Entity\User:
            relations: [role]
NEON), InvalidConfigurationException::class, 'apiQuery.entities is gone.%a%');
