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

namespace Phlox\ApiQuery\Sql;

use Phlox\ApiQuery\Fields\Field;
use Phlox\ApiQuery\Fields\FieldMap;

/**
 * What's selected of one record, checked against its FieldMap.
 */
final readonly class SelectNode
{
    /**
     * @param array<string, Field> $fields By name
     * @param array<string, SelectNode> $children What's selected of the relations among $fields
     */
    public function __construct(
        public FieldMap $map,
        public array $fields,
        public array $children,
    ) {
    }

    /**
     * A method computing a field can read anything of the entity.
     */
    public function hasComputedValues(): bool
    {
        return array_any($this->fields, static fn(Field $field): bool => $field->compute !== null);
    }

    /**
     * Columns the records must be read with, delimited: those selected, what the
     * relations need, and the primary key which Nette tells rows apart by.
     *
     * @param list<string> $extraColumns
     * @return list<string>
     */
    public function getColumns(ConditionCompiler $compiler, array $extraColumns = []): array
    {
        $columns = $extraColumns;
        if ($this->map->primaryColumn !== null) {
            $columns[] = $this->map->primaryColumn;
        }

        foreach ($this->fields as $field) {
            if ($field->relation !== null) {
                $columns[] = $field->relation->localColumn;
            } elseif ($field->column !== null) {
                $columns[] = $field->column;
            }
        }

        $table = $compiler->delimite((string) $this->map->table);

        return array_map(
            static fn(string $column): string => $table . '.' . $compiler->delimite($column),
            array_values(array_unique($columns)),
        );
    }
}
