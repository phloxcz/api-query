<?php
/**
 * This file is part of phloxcz/api-query.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phlox\ApiQuery\Attributes;

use Attribute;

/**
 * A field of a #[Resource] with no property on the entity, computed by a public static
 * method of the resource, only when a query selects it. It can't be filtered or sorted by.
 *
 *     public function __construct(
 *         #[Computed] public ?bool $canManage = null,       // by canManage()
 *         #[Computed('fullName')] public string $label = '',
 *     )
 *
 *     public static function canManage(User $user, Nette\Security\User $viewer): bool
 *     public static function fullName(User $user): string
 *
 * The method gets the entity, and services by type after it.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Computed
{
    /**
     * @param string|null $method By default the name of the field
     */
    public function __construct(public readonly ?string $method = null)
    {
    }
}
