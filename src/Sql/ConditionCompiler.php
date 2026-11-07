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

use Nette\Database\Driver;
use Phlox\ApiCore\Binding\ConversionException;
use Phlox\ApiCore\Binding\ParameterBinder;
use Phlox\ApiCore\Binding\ValueConverter;
use Phlox\ApiCore\Types\Type;
use Phlox\ApiQuery\Fields\Field;
use Phlox\ApiQuery\Fields\FieldMap;
use Phlox\ApiQuery\Fields\Relation;
use Phlox\ApiQuery\Filter;
use Phlox\ApiQuery\FilterGroup;
use Phlox\ApiQuery\Sort;
use Phlox\EntityDatabase\Database\Table\SqlLiterals;

/**
 * Turns filters and sorts into SQL, for the fields of a FieldMap only. Names come
 * from the map and values go to parameters, so nothing of a client's text ever
 * becomes part of the SQL.
 *
 * Going through a relation is a subquery rather than a join. It can't multiply
 * rows, which would break paging and `total`, it holds the condition of a relation,
 * and it works the same on every database. All names are delimited, which also
 * keeps Nette from reading `table.column` as a request for a join of its own.
 */
final class ConditionCompiler
{
    private const LikeEscape = '!';
    private const MaxInValues = 1000;
    private const MaxTextLength = 1000;

    private readonly ValueConverter $dates;

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param int $maxDepth How many relations a field's path can go through
     * @param (\Closure(FieldMap): (array{string, list<mixed>}|null))|null $restriction What of a table the
     *        client can read, as a condition on it, for subqueries through relations
     */
    public function __construct(
        private readonly Driver $driver,
        private readonly int $maxDepth,
        private readonly ?\Closure $restriction = null,
    ) {
        $this->dates = new ValueConverter(ParameterBinder::DefaultDateTimeFormats);
    }

    /**
     * @return array<string, string> Where in the query => what's wrong
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function delimite(string $name): string
    {
        return implode('.', array_map($this->driver->delimite(...), explode('.', $name)));
    }

    /**
     * @param string $qualifier The table of the map, delimited
     * @param string $where Prefix of error keys
     * @return array{string, list<mixed>}|null
     */
    public function compileFilter(FilterGroup $group, FieldMap $map, string $qualifier, string $where): ?array
    {
        $parts = [];
        $params = [];

        foreach ($group->filters as $filter) {
            $compiled = $filter instanceof FilterGroup
                ? $this->compileFilter($filter, $map, $qualifier, $where)
                : $this->compilePath(self::splitPath($filter->field), $filter, $map, $qualifier, "{$where}.{$filter->field}", 0);

            if ($compiled !== null) {
                $parts[] = $compiled[0];
                array_push($params, ...$compiled[1]);
            }
        }

        return $parts === []
            ? null
            : ['(' . implode(' ' . strtoupper($group->logic) . ' ', $parts) . ')', $params];
    }

    /**
     * @return array{string, list<mixed>}|null An expression with a direction, for ORDER BY, and its parameters
     */
    public function compileSort(Sort $sort, FieldMap $map, string $qualifier, string $where): ?array
    {
        $at = "{$where}.{$sort->field}";
        $params = [];
        $expression = $this->compileSortPath(self::splitPath($sort->field), $map, $qualifier, $at, 0, $params);

        return $expression === null ? null : [$expression . ($sort->descending ? ' DESC' : ' ASC'), $params];
    }

    /**
     * @return array{string, list<mixed>}|null
     */
    private function restrict(FieldMap $map): ?array
    {
        return $this->restriction === null ? null : ($this->restriction)($map);
    }

    /**
     * Older clients write `:orders.status` as Nette does.
     *
     * @return list<string>
     */
    private static function splitPath(string $field): array
    {
        return array_map(static fn(string $part): string => ltrim($part, ':'), explode('.', $field));
    }

    private function findField(string $name, FieldMap $map, string $at): ?Field
    {
        $field = $map->fields[$name] ?? null;
        if ($field === null) {
            $this->errors[$at] = 'is not a known field.';
        }

        return $field;
    }

    /**
     * @param list<string> $path
     * @return array{string, list<mixed>}|null
     */
    private function compilePath(array $path, Filter $filter, FieldMap $map, string $qualifier, string $at, int $depth): ?array
    {
        $name = array_shift($path);
        $field = $name === null ? null : $this->findField($name, $map, $at);
        if ($field === null) {
            return null;
        }

        if ($path !== []) {
            if ($field->relation !== null && !$field->filterable) {
                $this->errors[$at] = 'can not be filtered by.';
                return null;
            }

            return $field->relation === null
                ? $this->unknownField($at)
                : $this->compileThroughRelation($field->relation, $path, $filter, $qualifier, $at, $depth + 1);
        }

        if ($field->relation !== null) {
            $this->errors[$at] = 'is a relation, so name a field of it, like ' . $field->name . '.id.';
            return null;
        }

        if ($field->column === null || !$field->filterable) {
            $this->errors[$at] = 'can not be filtered by.';
            return null;
        }

        return $this->compileCondition($field, $qualifier . '.' . $this->delimite($field->column), $filter, $at);
    }

    /**
     * @return null
     */
    private function unknownField(string $at): mixed
    {
        $this->errors[$at] = 'is not a known field.';

        return null;
    }

    /**
     * @param list<string> $path
     * @return array{string, list<mixed>}|null
     */
    private function compileThroughRelation(Relation $relation, array $path, Filter $filter, string $qualifier, string $at, int $depth): ?array
    {
        if ($depth > $this->maxDepth) {
            $this->errors[$at] = 'goes through too many relations.';
            return null;
        }

        $table = $this->delimite($relation->table);
        $inner = $this->compilePath($path, $filter, $relation->getTarget(), $table, $at, $depth);
        if ($inner === null) {
            return null;
        }

        $condition = $inner[0];
        $params = $inner[1];
        if ($relation->condition !== null) {
            [$relationCondition, $relationParams] = SqlLiterals::extract($relation->condition);
            $condition = "({$relationCondition}) AND {$condition}";
            $params = [...$relationParams, ...$params];
        }

        // A filter would otherwise tell what's in records the client can't read
        $restricted = $this->restrict($relation->getTarget());
        if ($restricted !== null) {
            $condition = "{$condition} AND {$restricted[0]}";
            $params = [...$params, ...$restricted[1]];
        }

        return [
            "{$qualifier}.{$this->delimite($relation->localColumn)} IN (SELECT {$table}.{$this->delimite($relation->remoteColumn)} FROM {$table} WHERE {$condition})",
            $params,
        ];
    }

    /**
     * @param list<string> $path
     * @param list<mixed> $params (output)
     */
    private function compileSortPath(array $path, FieldMap $map, string $qualifier, string $at, int $depth, array &$params): ?string
    {
        $name = array_shift($path);
        $field = $name === null ? null : $this->findField($name, $map, $at);
        if ($field === null) {
            return null;
        }

        if ($path === []) {
            if ($field->relation !== null || $field->column === null || !$field->filterable) {
                $this->errors[$at] = 'can not be sorted by.';
                return null;
            }
            return $qualifier . '.' . $this->delimite($field->column);
        }

        if ($field->relation === null) {
            return $this->unknownField($at);
        }

        if ($field->relation->toMany) {
            $this->errors[$at] = 'goes through a collection, so it can not be sorted by.';
            return null;
        }

        if (!$field->filterable) {
            $this->errors[$at] = 'can not be sorted by.';
            return null;
        }

        if ($depth + 1 > $this->maxDepth) {
            $this->errors[$at] = 'goes through too many relations.';
            return null;
        }

        $relation = $field->relation;
        $table = $this->delimite($relation->table);
        $innerParams = [];
        $inner = $this->compileSortPath($path, $relation->getTarget(), $table, $at, $depth + 1, $innerParams);
        if ($inner === null) {
            return null;
        }

        // What the client can't read sorts as nothing
        $condition = "{$table}.{$this->delimite($relation->remoteColumn)} = {$qualifier}.{$this->delimite($relation->localColumn)}";
        $restricted = $this->restrict($relation->getTarget());
        if ($restricted !== null) {
            $condition .= " AND {$restricted[0]}";
        }

        // In the order they stand in the SQL: the inner expression, then the restriction
        array_push($params, ...$innerParams, ...($restricted[1] ?? []));

        return "(SELECT {$inner} FROM {$table} WHERE {$condition})";
    }

    /**
     * @return array{string, list<mixed>}|null
     */
    private function compileCondition(Field $field, string $column, Filter $filter, string $at): ?array
    {
        $operator = $filter->operator;
        $value = $filter->value;

        if ($value === null && $operator === Filter::Equal) {
            $operator = Filter::IsNull;
        } elseif ($value === null && $operator === Filter::NotEqual) {
            $operator = Filter::IsNotNull;
        }

        switch ($operator) {
            case Filter::IsNull:
                return ["{$column} IS NULL", []];

            case Filter::IsNotNull:
                return ["{$column} IS NOT NULL", []];

            case Filter::IsEmpty:
                return $field->type === Field::Text
                    ? ["({$column} IS NULL OR {$column} = '')", []]
                    : ["{$column} IS NULL", []];

            case Filter::IsNotEmpty:
                return $field->type === Field::Text
                    ? ["({$column} IS NOT NULL AND {$column} <> '')", []]
                    : ["{$column} IS NOT NULL", []];

            case Filter::StartsWith:
            case Filter::NotStartsWith:
            case Filter::EndsWith:
            case Filter::NotEndsWith:
            case Filter::Contains:
            case Filter::NotContains:
                return $this->compileLike($field, $column, $operator, $value, $at);

            case Filter::In:
            case Filter::NotIn:
                return $this->compileIn($field, $column, $operator, $value, $at);

            case Filter::IsWeek:
            case Filter::ThisWeek:
            case Filter::Today:
                return $this->compileDateRange($field, $column, $operator, $value, $at);
        }

        $param = $this->convertValue($field, $value, $at);

        return $param === null ? null : ["{$column} {$operator} {$this->placeholder($field)}", [$param[0]]];
    }

    /**
     * @return array{string, list<mixed>}|null
     */
    private function compileLike(Field $field, string $column, string $operator, mixed $value, string $at): ?array
    {
        if ($field->type !== Field::Text) {
            $this->errors[$at] = 'is not a text, so it can not be searched by parts.';
            return null;
        }
        if (!is_scalar($value) || is_bool($value)) {
            $this->errors[$at] = 'needs a text to look for.';
            return null;
        }
        if (mb_strlen((string) $value) > self::MaxTextLength) {
            $this->errors[$at] = 'is too long, at most ' . self::MaxTextLength . ' characters are allowed.';
            return null;
        }

        $escaped = str_replace(
            [self::LikeEscape, '%', '_'],
            [self::LikeEscape . self::LikeEscape, self::LikeEscape . '%', self::LikeEscape . '_'],
            (string) $value,
        );

        [$pattern, $not] = match ($operator) {
            Filter::StartsWith => ["{$escaped}%", ''],
            Filter::NotStartsWith => ["{$escaped}%", 'NOT '],
            Filter::EndsWith => ["%{$escaped}", ''],
            Filter::NotEndsWith => ["%{$escaped}", 'NOT '],
            Filter::Contains => ["%{$escaped}%", ''],
            default => ["%{$escaped}%", 'NOT '],
        };

        return ["LOWER({$column}) {$not}LIKE LOWER({$this->placeholder($field)}) ESCAPE '" . self::LikeEscape . "'", [$pattern]];
    }

    /**
     * @return array{string, list<mixed>}|null
     */
    private function compileIn(Field $field, string $column, string $operator, mixed $value, string $at): ?array
    {
        $values = is_array($value) ? array_values($value) : [$value];
        if (count($values) > self::MaxInValues) {
            $this->errors[$at] = 'has too many values, at most ' . self::MaxInValues . ' are allowed.';
            return null;
        }

        $params = [];
        foreach ($values as $item) {
            $param = $this->convertValue($field, $item, $at);
            if ($param === null) {
                return null;
            }
            $params[] = $param[0];
        }

        if ($params === []) {
            return [$operator === Filter::In ? '1 = 0' : '1 = 1', []];
        }

        $keyword = $operator === Filter::In ? 'IN' : 'NOT IN';

        return ["{$column} {$keyword} (" . implode(', ', array_fill(0, count($params), $this->placeholder($field))) . ')', $params];
    }

    /**
     * As a range of dates, which every database understands and which can use an index.
     *
     * @return array{string, list<mixed>}|null
     */
    private function compileDateRange(Field $field, string $column, string $operator, mixed $value, string $at): ?array
    {
        if ($field->type !== Field::Date && $field->type !== Field::DateTime) {
            $this->errors[$at] = 'is not a date.';
            return null;
        }

        $today = new \DateTimeImmutable('today');

        if ($operator === Filter::Today) {
            $from = $today;
            $to = $today->modify('+1 day');
        } else {
            $monday = $operator === Filter::ThisWeek ? $today : $this->findDayOfWeek($value, $today, $at);
            if ($monday === null) {
                return null;
            }
            $from = $monday->modify('monday this week');
            $to = $from->modify('+7 days');
        }

        return ["({$column} >= ? AND {$column} < ?)", [$from, $to]];
    }

    /**
     * A date, or a number of a week in the current year.
     */
    private function findDayOfWeek(mixed $value, \DateTimeImmutable $today, string $at): ?\DateTimeImmutable
    {
        $week = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;

        if ($week !== null) {
            if ($week < 1 || $week > 53) {
                $this->errors[$at] = 'must be a date or a number of a week from 1 to 53.';
                return null;
            }
            return $today->setISODate((int) $today->format('o'), $week);
        }

        $date = $this->parseDate($value);
        if ($date === null) {
            $this->errors[$at] = 'must be a date or a number of a week from 1 to 53.';
        }

        return $date;
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        try {
            $date = $this->dates->convert(new Type(Type::ClassName, class: \DateTimeImmutable::class), $value);
        } catch (ConversionException) {
            return null;
        }

        // Stored in the time zone of the application
        return $date instanceof \DateTimeImmutable ? $date->setTimezone(new \DateTimeZone(date_default_timezone_get())) : null;
    }

    /**
     * Firebird sizes a parameter by the column it's compared with, and fails on a
     * longer value instead of finding nothing. Twice the longest text allowed leaves
     * room for escaping, in characters of any character set.
     */
    private function placeholder(Field $field): string
    {
        return $field->type === Field::Text && is_a($this->driver, 'Phlox\FirebirdDatabase\FirebirdDriver')
            ? 'CAST(? AS VARCHAR(' . (self::MaxTextLength * 2 + 2) . '))'
            : '?';
    }

    /**
     * Checked here, because a database would otherwise fail on `id = 'abc'`, and
     * some would quietly compare it as 0.
     *
     * @return array{mixed}|null In an array, because the value itself can be null
     */
    private function convertValue(Field $field, mixed $value, string $at): ?array
    {
        if ($value === null) {
            $this->errors[$at] = 'needs a value.';
            return null;
        }

        $converted = match ($field->type) {
            Field::Integer, Field::Number => self::toNumber($value),
            Field::Boolean => self::toBool($value),
            Field::DateTime, Field::Date, Field::Time => $this->toDateValue($field, $value),
            default => is_scalar($value) && !is_bool($value) ? (string) $value : null,
        };

        if (is_string($converted) && mb_strlen($converted) > self::MaxTextLength) {
            $this->errors[$at] = 'is too long, at most ' . self::MaxTextLength . ' characters are allowed.';
            return null;
        }

        if ($converted === null) {
            $this->errors[$at] = match ($field->type) {
                Field::Integer, Field::Number => 'must be a number.',
                Field::Boolean => 'must be true or false.',
                Field::DateTime, Field::Date => 'must be a date.',
                Field::Time => 'must be a time.',
                default => 'must be a text.',
            };
            return null;
        }

        return [$converted];
    }

    private static function toNumber(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (!is_string($value) || !is_numeric($value)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : (float) $value;
    }

    private static function toBool(mixed $value): ?bool
    {
        return match (true) {
            in_array($value, [true, 1, '1', 'true'], true) => true,
            in_array($value, [false, 0, '0', 'false'], true) => false,
            default => null,
        };
    }

    /**
     * A date stays an object, so that the driver writes it in the form of its database.
     */
    private function toDateValue(Field $field, mixed $value): \DateTimeImmutable|string|null
    {
        $date = $this->parseDate($value);

        return match (true) {
            $date === null => null,
            $field->type === Field::Time => $date->format('H:i:s'),
            $field->type === Field::Date => $date->setTime(0, 0),
            default => $date,
        };
    }
}
