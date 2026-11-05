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
 * A field of a #[Resource] with no property on the entity. It can be selected, and
 * can't be filtered or sorted by.
 *
 *     public function __construct(
 *         #[Computed('fullName')] public string $label,   // computed by the method below
 *         #[Computed] public bool $canManage = false,     // filled in by onProcessedEntity
 *     )
 *
 *     public static function fullName(User $user): string
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Computed
{
    /**
     * @param string|null $method A public static method of the resource, getting the entity
     */
    public function __construct(public readonly ?string $method = null)
    {
    }
}
