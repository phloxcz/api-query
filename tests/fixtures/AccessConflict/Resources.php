<?php

declare(strict_types=1);

/**
 * Two resources of one entity, neither of them `default: false`. Loaded explicitly by tests.
 */

namespace Phlox\ApiQuery\Tests\Fixtures\AccessConflict;

use Phlox\ApiQuery\Attributes\Resource;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Order;

#[Resource(Order::class, fields: '*')]
final class FirstOrder
{
}

#[Resource(Order::class, fields: ['id'])]
final class SecondOrder
{
}
