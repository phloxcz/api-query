<?php

declare(strict_types=1);

/**
 * Resources of a whole application, found in their namespace, so controllers need
 * no attribute. Loaded explicitly by tests.
 */

namespace Phlox\ApiQuery\Tests\Fixtures\Patterns;

use Phlox\ApiQuery\Attributes\Computed;
use Phlox\ApiQuery\Attributes\Resource;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Item;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Order;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Role;
use Phlox\ApiQuery\Tests\Fixtures\Entity\User;

#[Resource(User::class, fields: '*')]
final readonly class UserResource
{
    /**
     * @param list<OrderResource> $orders
     * @param list<OrderResource> $activeOrders
     */
    public function __construct(
        public ?RoleResource $role = null,
        public array $orders = [],
        public array $activeOrders = [],
        #[Computed] public ?bool $canManage = null,
    ) {
    }

    public static function canManage(User $user): bool
    {
        return $user->isActive;
    }
}

#[Resource(Order::class, fields: '*')]
final readonly class OrderResource
{
    /**
     * @param list<Item> $items
     */
    public function __construct(
        public ?UserResource $user = null,
        public array $items = [],
    ) {
    }
}

#[Resource(Role::class, fields: '*')]
final readonly class RoleResource
{
    /**
     * @param list<UserResource> $users
     */
    public function __construct(public array $users = [])
    {
    }
}
