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
 *
 *     #[Resource(Type::class, fields: '*')]
 *     #[Resource(File::class, fields: ['id', 'name'])]
 *     #[Resource(Version::class, fields: '*', except: ['blobVersionId'])]
 *
 * Fields named here take everything from the entity. What differs from it, such as a
 * relation, a new name or a limit, is declared in the constructor, and that wins.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Resource extends Dto
{
    /**
     * @param class-string<\Phlox\EntityDatabase\Entity\Entity> $entity
     * @param string|list<string>|null $fields Properties of the entity to show. A star means all readable
     *        ones but relations, so a new column shows up by itself. Null means only what the class declares
     * @param list<string> $except With a star, properties to leave out
     */
    public function __construct(
        public readonly string $entity,
        public readonly string|array|null $fields = null,
        public readonly array $except = [],
    ) {
        if (is_string($fields) && $fields !== '*') {
            throw new \LogicException("Fields of a resource of {$entity} are a list, or '*', not '{$fields}'.");
        }
        if ($except !== [] && $fields !== '*') {
            throw new \LogicException("A resource of {$entity} can use 'except' only with fields: '*'.");
        }
    }

    /**
     * A query selects only some of the fields.
     */
    public function isPartial(): bool
    {
        return true;
    }

    /**
     * It says what clients see. What they send is a #[Dto].
     */
    public function isInput(): bool
    {
        return false;
    }
}
