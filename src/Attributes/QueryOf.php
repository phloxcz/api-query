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
 *     public function list(#[QueryOf(UserListResource::class)] Query $query): array
 *
 * It's only needed where the query is about something else than the resource of the
 * entity, which holds without it. It also says what's documented.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class QueryOf implements DescribesMethod
{
    /**
     * @param class-string $class A #[Resource] or an entity
     * @param bool $single The method returns one record rather than a page
     */
    public function __construct(
        public readonly string $class,
        public readonly bool $single = false,
    ) {
    }

    public function getFieldMap(): FieldMap
    {
        return FieldMap::for($this->class);
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

        // An entity with a resource is queried as the resource
        $map = $this->getFieldMap();
        $name = (new \ReflectionClass($map->resource ?? $this->class))->getShortName();

        return QuerySchema::describe($builder, $map, $name);
    }

    public function describeResponse(SchemaBuilder $builder): array|\stdClass|null
    {
        if (!FieldMap::canBeBuilt($this->class)) {
            return null;
        }

        $builder->addClass($this->class);

        $resource = $this->getFieldMap()->resource;
        $item = $resource !== null
            ? $builder->build(new Type(Type::ClassName, class: $resource))
            : $this->getFieldMap()->toSchema($builder);

        return $this->single ? $item : Paged::wrap($item);
    }
}
