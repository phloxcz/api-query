<?php

declare(strict_types=1);

/**
 * Resources found in their namespace, one for each entity they show, with what a
 * viewer can read and fields computed with a service. Loaded explicitly by tests.
 */

namespace Phlox\ApiQuery\Tests\Fixtures\Access;

use Phlox\ApiQuery\Attributes\Computed;
use Phlox\ApiQuery\Attributes\Resource;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Item;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Order;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Role;
use Phlox\ApiQuery\Tests\Fixtures\Entity\User;
use Phlox\EntityDatabase\Database\Table\Selection;

/** Who's asking, as a service */
final class Viewer
{
    public function __construct(public int $userId = 1)
    {
    }
}

/** A viewer reads only their own orders. */
#[Resource(Order::class, fields: '*')]
final class OrderResource
{
    public static int $computed = 0;

    /** @var list<list<int>> IDs each call of the batch got */
    public static array $batches = [];

    /**
     * @param list<Item> $items
     */
    public function __construct(
        public ?UserResource $user = null,
        public array $items = [],
        #[Computed] public ?bool $mine = null,
        #[Computed(batch: true)] public ?int $itemCount = null,
    ) {
    }

    /**
     * @param list<Order> $orders
     * @return array<int, int>
     */
    public static function itemCount(array $orders, Viewer $viewer): array
    {
        self::$batches[] = array_map(static fn(Order $order): int => $order->id, $orders);

        $counts = [];
        foreach ($orders as $order) {
            $counts[$order->id] = count($order->items);
        }

        return $counts;
    }

    public static function restrict(Selection $orders, Viewer $viewer): void
    {
        $orders->where('user_id', $viewer->userId);
    }

    public static function mine(Order $order, Viewer $viewer): bool
    {
        self::$computed++;
        return $order->userId === $viewer->userId;
    }
}

#[Resource(User::class, fields: ['id', 'email'])]
final class UserResource
{
    /**
     * @param list<OrderResource> $orders
     */
    public function __construct(
        public ?Role $role = null,
        public array $orders = [],
    ) {
    }
}

#[Resource(Item::class, fields: '*')]
final class ItemResource
{
    public function __construct(public ?OrderResource $order = null)
    {
    }
}

/** Another view of orders, only where it's named. */
#[Resource(Order::class, fields: ['id', 'total'], default: false)]
final class OrderSummary
{
}
