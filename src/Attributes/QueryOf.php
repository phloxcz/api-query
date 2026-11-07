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
use Phlox\ApiCore\Documentation\DescribesMethod;
use Phlox\ApiCore\Documentation\SchemaBuilder;
use Phlox\ApiCore\Schema\Paged;
use Phlox\ApiCore\Types\Type;
use Phlox\ApiQuery\Documentation\QuerySchema;
use Phlox\ApiQuery\Fields\FieldMap;

/**
 * Says what a Query parameter is about, for the Processor and the documentation.
 *
 *     public function list(#[QueryOf(UserResource::class)] Query $query): array
 *     public function list(#[QueryOf(User::class, relations: ['role', 'orders.items'])] Query $query): array
 *
 * It's only needed for a resource, or to differ from `apiQuery.entities`.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class QueryOf implements DescribesMethod
{
    /**
     * @param class-string $class A #[Resource] or an entity
     * @param list<string>|null $relations For an entity, the relations a query can reach; by default those of `apiQuery.entities`
     * @param bool $single The method returns one record rather than a page
     */
    public function __construct(
        public readonly string $class,
        public readonly ?array $relations = null,
        public readonly bool $single = false,
    ) {
    }

    public function getFieldMap(): FieldMap
    {
        return FieldMap::for($this->class, $this->relations);
    }

    public function describeParameter(): string
    {
        return FieldMap::canBeBuilt($this->class) ? $this->getFieldMap()->describe() : '';
    }

    public function describeParameterSchema(SchemaBuilder $builder): ?array
    {
        if (!FieldMap::canBeBuilt($this->class)) {
            return null;
        }

        $name = (new \ReflectionClass($this->class))->getShortName()
            . ($this->relations === null ? '' : '_' . substr(md5((string) json_encode($this->relations)), 0, 6));

        return QuerySchema::describe($builder, $this->getFieldMap(), $name);
    }

    public function describeResponse(SchemaBuilder $builder): array|\stdClass|null
    {
        if (!FieldMap::canBeBuilt($this->class)) {
            return null;
        }

        $builder->addClass($this->class);

        $item = FieldMap::isResource($this->class)
            ? $builder->build(new Type(Type::ClassName, class: $this->class))
            : $this->getFieldMap()->toSchema($builder);

        return $this->single ? $item : Paged::wrap($item);
    }
}
