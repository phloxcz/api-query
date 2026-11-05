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
use Phlox\ApiQuery\Documentation\QuerySchema;
use Phlox\ApiQuery\Fields\FieldMap;
use Phlox\ApiQuery\Fields\SqlField;

/**
 * Says what a Query parameter over raw SQL can reach. SqlProcessor takes the fields
 * from here, and so does the documentation.
 *
 *     #[QueryOfSql(['id' => new SqlField('ID', Field::Integer), 'name' => 'NAME'], searchable: ['name'])]
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class QueryOfSql implements DescribesMethod
{
    /**
     * @param array<string, string|SqlField> $fields Field for clients => column of the result
     * @param list<string> $searchable Fields a search looks in
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $searchable = [],
    ) {
    }

    public function getFieldMap(): FieldMap
    {
        return FieldMap::forSql($this->fields, $this->searchable);
    }

    public function describeParameter(): string
    {
        return $this->getFieldMap()->describe();
    }

    public function describeParameterSchema(SchemaBuilder $builder): array
    {
        return QuerySchema::describe($builder, $this->getFieldMap(), 'Sql' . substr(md5((string) json_encode(array_keys($this->fields))), 0, 6));
    }

    public function describeResponse(SchemaBuilder $builder): array
    {
        return FieldMap::buildPageSchema($this->getFieldMap()->toSchema($builder));
    }
}
