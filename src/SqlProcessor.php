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

namespace Phlox\ApiQuery;

use Nette\Database\Connection;
use Nette\Database\Drivers\MsSqlDriver;
use Nette\Database\Drivers\SqlsrvDriver;
use Nette\Utils\Arrays;
use Phlox\ApiCore\Schema\Paged;
use Phlox\ApiQuery\Events\ProcessedRowEventData;
use Phlox\ApiQuery\Fields\Field;
use Phlox\ApiQuery\Fields\FieldMap;
use Phlox\ApiQuery\Fields\SqlField;
use Phlox\ApiQuery\Sql\ConditionCompiler;
use Phlox\EntityDatabase\Database\Search\SearchCondition;

/**
 * Carries out a Query over the result of raw SQL. The fields are stated with it,
 * and only those can be selected, filtered and sorted by.
 */
final class SqlProcessor
{
    private const Alias = '_view_';

    /** @var array<callable(ProcessedRowEventData): void> After a row is turned into output */
    public array $onProcessedRow = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly QueryConfig $config = new QueryConfig(),
    ) {
    }

    /**
     * @param string $sql The query, whose result gets filtered, sorted and paged
     * @param list<mixed> $params Its parameters
     * @param array<string, string|SqlField> $fields Field for clients => column of the result;
     *        without it, what #[QueryOfSql] of the parameter says
     * @param list<string> $searchable Fields a search looks in
     * @param bool $skipLimitOffset No paging, for exports, and the result is a list; only ever pass what the application decided
     * @return array<int|string, mixed>
     * @throws QueryException
     */
    public function process(string $sql, array $params, ?Query $query, array $fields = [], array $searchable = [], bool $skipLimitOffset = false): array
    {
        $query ??= Query::create();
        $unlimited = $skipLimitOffset;

        if ($fields !== []) {
            $map = FieldMap::forSql($fields, $searchable);
        } else {
            $map = $query->getQueryOfSql()?->getFieldMap()
                ?? throw new \LogicException('A query over SQL needs its fields, given here or by #[QueryOfSql].');
        }
        $driver = $this->connection->getDriver();
        $compiler = new ConditionCompiler($driver, 0);
        $alias = $compiler->delimite(self::Alias);

        $criteria = $query->getCriteriaByPath();
        $errors = [];

        foreach (array_keys($query->getCriteria()) as $path) {
            if ($path !== '@') {
                $errors["criteria.{$path}"] = 'is not a selected collection.';
            }
        }

        $selected = $this->resolveSelect($query->getSelectStructure(), $map, $errors);

        $where = $criteria?->filter === null ? null : $compiler->compileFilter($criteria->filter, $map, $alias, 'filter');

        $order = [];
        foreach ($criteria->sort ?? [] as $sort) {
            $expression = $compiler->compileSort($sort, $map, $alias, 'sort');
            if ($expression !== null) {
                $order[] = $expression;
            }
        }

        $conditions = [];
        $conditionParams = [];
        if ($where !== null) {
            $conditions[] = $where[0];
            array_push($conditionParams, ...$where[1]);
        }

        if ($criteria?->search !== null) {
            $search = $this->buildSearch($criteria->search, $map, $errors);
            if ($search !== null) {
                $conditions[] = $search[0];
                array_push($conditionParams, ...$search[1]);
            }
        }

        $errors += $compiler->getErrors();
        if ($errors !== []) {
            throw new QueryException($errors);
        }

        $from = "FROM ({$sql}) AS {$alias}" . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions));
        $allParams = [...$params, ...$conditionParams];

        $total = $unlimited ? 0 : (int) $this->connection->query("SELECT COUNT(*) {$from}", ...$allParams)->fetchField();

        $columns = implode(', ', array_map(
            static fn(Field $field): string => $alias . '.' . $compiler->delimite((string) $field->column),
            $selected,
        ));
        $dataSql = "SELECT {$columns} {$from}";

        $pageSize = min($criteria->pageSize ?? $this->config->maxPageSize, $this->config->maxPageSize);
        $offset = $criteria?->getOffset($pageSize) ?? 0;

        if (!$unlimited) {
            // SQL Server can't page without an order
            if ($order === [] && ($driver instanceof SqlsrvDriver || $driver instanceof MsSqlDriver)) {
                $order[] = $alias . '.' . $compiler->delimite((string) reset($selected)->column);
            }
        }
        if ($order !== []) {
            $dataSql .= ' ORDER BY ' . implode(', ', $order);
        }
        if (!$unlimited) {
            $driver->applyLimit($dataSql, $pageSize, $offset);
        }

        $data = [];
        foreach ($this->connection->query($dataSql, ...$allParams) as $row) {
            $record = [];
            foreach ($selected as $field) {
                $record[$field->name] = $this->formatValue($row[(string) $field->column], $field);
            }

            $event = new ProcessedRowEventData($row, $record);
            Arrays::invoke($this->onProcessedRow, $event);
            if (!$event->shouldSkip) {
                $data[] = $event->output;
            }
        }

        if ($unlimited) {
            return $data;
        }

        return Paged::result($data, $total, $offset, $pageSize);
    }

    /**
     * @param array<string, mixed> $structure
     * @param array<string, string> $errors (output)
     * @return non-empty-array<string, Field>
     */
    private function resolveSelect(array $structure, FieldMap $map, array &$errors): array
    {
        $selected = [];

        foreach ($structure as $name => $nested) {
            $name = (string) $name;
            if ($name === '*') {
                $selected += $map->fields;
            } elseif (!isset($map->fields[$name])) {
                $errors["select.{$name}"] = 'is not a known field.';
            } elseif (is_array($nested)) {
                $errors["select.{$name}"] = 'is not a relation, so it has nothing to select from.';
            } else {
                $selected[$name] = $map->fields[$name];
            }
        }

        if ($selected === []) {
            return $map->fields === []
                ? throw new \LogicException('A query over SQL needs at least one field.')
                : $map->fields;
        }

        return $selected;
    }

    /**
     * @param array<string, string> $errors (output)
     * @return array{string, list<mixed>}|null
     */
    private function buildSearch(string $term, FieldMap $map, array &$errors): ?array
    {
        $columns = array_values($map->getSearchColumns());
        if ($columns === []) {
            $errors['search'] = 'is not available here.';
            return null;
        }

        return SearchCondition::build($this->connection, $columns, $term, $this->config->ignoreDiacritics);
    }

    private function formatValue(mixed $value, Field $field): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(match ($field->type) {
                Field::Date => $this->config->dateFormat,
                Field::Time => $this->config->timeFormat,
                default => $this->config->dateTimeFormat,
            });
        }

        return $value instanceof \DateInterval ? $value->format($this->config->timeIntervalFormat) : $value;
    }
}
