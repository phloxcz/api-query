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
use Phlox\ApiCore\Attributes\Dto;

/**
 * A DTO saying what clients see of an entity. Its fields are all a query can
 * select, filter, sort and search by. They take the same name on the entity,
 * unless #[Field] says otherwise.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Resource extends Dto
{
    /**
     * @param class-string<\Phlox\EntityDatabase\Entity\Entity> $entity
     */
    public function __construct(public readonly string $entity)
    {
    }

    /**
     * A query selects only some of the fields.
     */
    public function isPartial(): bool
    {
        return true;
    }
}
