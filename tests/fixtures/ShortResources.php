<?php

declare(strict_types=1);

/**
 * Resources naming their fields, rather than declaring all of them. Loaded explicitly by tests.
 */

namespace Phlox\ApiQuery\Tests\Fixtures\ShortResources;

use Phlox\ApiCore\Attributes\Schema;
use Phlox\ApiQuery\Attributes\Computed;
use Phlox\ApiQuery\Attributes\Field;
use Phlox\ApiQuery\Attributes\Resource;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Order;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Role;
use Phlox\ApiQuery\Tests\Fixtures\Entity\User;

/** A role, with everything it has. */
#[Resource(Role::class, fields: '*')]
final class StarRole
{
}

#[Resource(Role::class, fields: ['id'])]
final class RoleWithIdOnly
{
}

#[Resource(Order::class, fields: ['id', 'status'])]
final class ListedOrder
{
}

/**
 * Named fields, and what differs from the entity declared.
 */
#[Resource(User::class, fields: ['id', 'email', 'fullName'])]
final readonly class ListedUser
{
    /**
     * @param string|null $fullName How to address them
     * @param list<ListedOrder> $purchases
     */
    public function __construct(
        #[Field(filterable: false)] public ?string $fullName = null,
        public ?StarRole $role = null,
        #[Field('activeOrders')] public array $purchases = [],
        #[Computed('makeLabel'), Schema(['maxLength' => 300])] public ?string $label = null,
    ) {
    }

    public static function makeLabel(User $user): string
    {
        return "<{$user->email}>";
    }
}

#[Resource(User::class, fields: '*', except: ['age', 'fullName', 'orders'])]
final readonly class StarUser
{
    public function __construct(
        #[Field('email')] public ?string $login = null,
        public ?RoleWithIdOnly $role = null,
    ) {
    }
}

// Relations going in a circle

#[Resource(User::class, fields: ['id'])]
final readonly class LoopUser
{
    /** @param list<LoopOrder> $orders */
    public function __construct(public array $orders = [])
    {
    }
}

#[Resource(Order::class, fields: ['id'])]
final readonly class LoopOrder
{
    public function __construct(public ?LoopUser $user = null)
    {
    }
}

// Mistakes

#[Resource(User::class, fields: ['nothingLikeThat'])]
final class ListsUnknown
{
}

#[Resource(User::class, fields: ['passwordHash'])]
final class ListsSecret
{
}

#[Resource(User::class, fields: ['role'])]
final class ListsRelation
{
}

#[Resource(User::class, fields: '*', except: ['passwordHsh'])]
final class ExceptTypo
{
}

#[Resource(User::class, fields: '*', except: ['email'])]
final readonly class ExceptDeclared
{
    public function __construct(public ?string $email = null)
    {
    }
}

#[Resource(User::class, fields: 'all')]
final class FieldsWord
{
}

#[Resource(User::class, fields: ['id'], except: ['email'])]
final class ExceptWithoutStar
{
}

#[Resource(Order::class, fields: '*')]
final class OfAnotherEntity
{
}

final class NotAResource
{
}
