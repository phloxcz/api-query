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

namespace Phlox\ApiQuery\Fields;

/**
 * One thing a query can ask for.
 */
final readonly class Field
{
    public const Integer = 'integer';
    public const Number = 'number';
    public const Boolean = 'boolean';
    public const Text = 'string';
    public const DateTime = 'datetime';
    public const Date = 'date';
    public const Time = 'time';

    /** A value that can be selected, but not compared, such as JSON */
    public const Other = 'other';

    /**
     * @param string $name What clients call it
     * @param string|null $property Property of the entity; null for SQL and for a computed field
     * @param string|null $column Null when it isn't a column, like a virtual property or a relation
     * @param string $type One of the constants
     * @param bool $filterable Whether it can be filtered and sorted by
     * @param bool $computed It has no property: an event, or $compute, gives its value
     * @param (\Closure(\Phlox\EntityDatabase\Entity\Entity): mixed)|null $compute
     */
    public function __construct(
        public string $name,
        public ?string $property,
        public ?string $column,
        public string $type = self::Text,
        public bool $nullable = true,
        public bool $searchable = false,
        public bool $filterable = true,
        public ?Relation $relation = null,
        public string $description = '',
        public bool $computed = false,
        public ?\Closure $compute = null,
    ) {
    }
}
