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

namespace Phlox\ApiQuery\Documentation;

use Phlox\ApiCore\Documentation\SchemaBuilder;
use Phlox\ApiQuery\Fields\FieldMap;

/**
 * JSON Schema of a Query. With a FieldMap, it lists what can be filtered and sorted by.
 */
final class QuerySchema
{
    /**
     * @param string $name Tells this query's schemas from those of others
     * @return array{'$ref': string}
     */
    public static function describe(SchemaBuilder $builder, ?FieldMap $map = null, string $name = ''): array
    {
        $suffix = $name === '' ? '' : "_{$name}";

        $field = static fn(array $paths): array => ['type' => 'string'] + ($paths === [] ? [] : ['enum' => $paths]);
        $filterField = $field($map?->getPaths() ?? []);
        $sortField = $field($map?->getPaths(forSort: true) ?? []);

        $filter = $builder->define("QueryFilter{$suffix}", static fn(): array => [
            'type' => 'object',
            'required' => ['field'],
            'properties' => [
                'field' => $filterField + ['description' => 'A field, or a path through relations like `role.name`.'],
                'operator' => ['type' => 'string', 'default' => 'eq', 'description' => 'Kendo UI operators such as `eq`, `neq`, `lt`, `gte`, `contains`, `startswith`, `isnull`, and also `in`, `not in`, `isweek`, `thisweek`, `today`.'],
                'value' => ['description' => 'What to compare with; a list for `in`.'],
            ],
        ]);

        $group = $builder->define("QueryFilterGroup{$suffix}", static fn(): array => [
            'type' => 'object',
            'properties' => [
                'logic' => ['type' => 'string', 'enum' => ['and', 'or'], 'default' => 'and'],
                'filters' => [
                    'type' => 'array',
                    'items' => ['anyOf' => [$filter, ['$ref' => "#/components/schemas/QueryFilterGroup{$suffix}"]]],
                ],
            ],
        ]);

        $sort = $builder->define("QuerySort{$suffix}", static fn(): array => [
            'type' => 'object',
            'required' => ['field'],
            'properties' => [
                'field' => $sortField,
                'dir' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
            ],
        ]);

        $criteriaProperties = [
            'search' => ['type' => 'string', 'description' => 'Every word must appear in one of the searchable fields.'],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'pageSize' => ['type' => 'integer', 'minimum' => 1],
            'take' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The same as `pageSize`.'],
            'skip' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Rows to leave out, instead of `page`.'],
            'sort' => ['type' => 'array', 'items' => $sort],
            'filter' => ['anyOf' => [$group, ['type' => 'array', 'items' => $group], $filter]],
        ];

        // Nested collections have fields of their own, so their criteria aren't limited to these
        $criteria = $builder->define('QueryCriteria', static fn(): array => [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string'],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'pageSize' => ['type' => 'integer', 'minimum' => 1],
                'take' => ['type' => 'integer', 'minimum' => 1],
                'skip' => ['type' => 'integer', 'minimum' => 0],
                'sort' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['field' => ['type' => 'string'], 'dir' => ['type' => 'string', 'enum' => ['asc', 'desc']]]]],
                'filter' => ['description' => 'The same form as `filter` of the query, with the fields of that collection.'],
            ],
        ]);

        return $builder->define("Query{$suffix}", static fn(): array => [
            'type' => 'object',
            'properties' => [
                'select' => [
                    'type' => 'string',
                    'default' => '*',
                    'description' => 'Fields, with what to select of relations in brackets. A star means all columns, without relations.',
                    'examples' => ['id,name,role(id,name)'],
                ],
                'criteria' => [
                    'type' => 'object',
                    'description' => 'The same as the members below, for nested collections by their path like `orders` or `orders/items`.',
                    'additionalProperties' => $criteria,
                ],
            ] + $criteriaProperties,
        ]);
    }
}
