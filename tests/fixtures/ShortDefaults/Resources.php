<?php

declare(strict_types=1);

/**
 * Resources standing for their entities, found in their namespace, with relations
 * declared by the entity they lead to. Loaded explicitly by tests.
 */

namespace Phlox\ApiQuery\Tests\Fixtures\ShortDefaults;

use Phlox\ApiQuery\Attributes\Resource;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Item;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Order;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Role;
use Phlox\ApiQuery\Tests\Fixtures\Entity\User;

#[Resource(Role::class, fields: ['id'])]
final class IdOnlyRole
{
}

#[Resource(User::class, fields: '*')]
final readonly class ShortUser
{
    /**
     * @param list<Order> $orders
     */
    public function __construct(
        public ?Role $role = null,
        public array $orders = [],
    ) {
    }
}

#[Resource(Order::class, fields: '*')]
final readonly class ShortOrder
{
    /**
     * @param list<Item> $items
     */
    public function __construct(
        public ?User $user = null,
        public array $items = [],
    ) {
    }
}
