<?php

declare(strict_types=1);

/**
 * What clients see of the entities. The file is loaded explicitly by tests.
 */

namespace Phlox\ApiQuery\Tests\Fixtures\Resources;

use Phlox\ApiQuery\Attributes\Computed;
use Phlox\ApiQuery\Attributes\Field;
use Phlox\ApiQuery\Attributes\Resource;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Item;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Order;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Role;
use Phlox\ApiQuery\Tests\Fixtures\Entity\User;

#[Resource(Role::class)]
final readonly class RoleResource
{
    public function __construct(public int $id, public string $name)
    {
    }
}

#[Resource(Item::class)]
final readonly class ItemResource
{
    public function __construct(public int $id, public string $name)
    {
    }
}

#[Resource(Order::class)]
final readonly class OrderResource
{
    /**
     * @param list<ItemResource> $items
     */
    public function __construct(
        public int $id,
        public string $status,
        public array $items = [],
    ) {
    }
}

/** A user, as clients see one. */
#[Resource(User::class)]
final readonly class UserResource
{
    /**
     * @param string $login What they log in with
     * @param list<OrderResource> $purchases
     */
    public function __construct(
        public int $id,
        #[Field('email', searchable: true)] public string $login,
        #[Field('fullName', searchable: false, filterable: false)] public ?string $displayName,
        public ?RoleResource $role = null,
        #[Field('activeOrders')] public array $purchases = [],
    ) {
    }
}

#[Resource(Role::class)]
final readonly class HiddenRole
{
    public function __construct(public int $id, #[Field(filterable: false)] public string $name)
    {
    }
}

#[Resource(User::class)]
final readonly class NoFilterThroughRelation
{
    public function __construct(
        public int $id,
        #[Field(filterable: false)] public ?HiddenRole $role = null,
    ) {
    }
}

#[Resource(User::class)]
final readonly class ComputedResource
{
    public function __construct(
        public int $id,
        #[Computed('makeLabel')] public string $label,
        #[Computed] public ?bool $canManage = null,
    ) {
    }

    public static function makeLabel(User $user): string
    {
        // Reads what the query didn't select
        return "{$user->email} ({$user->age})";
    }
}

#[Resource(User::class)]
final readonly class BadComputed
{
    public function __construct(#[Computed('nothing')] public string $label)
    {
    }
}

#[Resource(User::class)]
final readonly class BadRelation
{
    public function __construct(public string $role)
    {
    }
}

#[Resource(User::class)]
final readonly class BadField
{
    public function __construct(public string $nothingLikeThat)
    {
    }
}

#[Resource(User::class)]
final readonly class BadSecret
{
    public function __construct(public string $passwordHash)
    {
    }
}

#[Resource(User::class)]
final readonly class BadScalar
{
    public function __construct(public RoleResource $email)
    {
    }
}
