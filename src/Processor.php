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

use Nette\Database\Driver;
use Nette\Utils\Arrays;
use Phlox\ApiQuery\Events\ProcessedEntityEventData;
use Phlox\ApiQuery\Events\ProcessEntityEventData;
use Phlox\ApiQuery\Events\ProcessSelectionEventData;
use Phlox\ApiQuery\Events\SearchEventData;
use Phlox\ApiQuery\Fields\Field;
use Phlox\ApiQuery\Fields\FieldMap;
use Phlox\ApiQuery\Sql\ConditionCompiler;
use Phlox\ApiQuery\Sql\SelectNode;
use Phlox\EntityDatabase\Database\Table\Selection;
use Phlox\EntityDatabase\Entity\Entity;

/**
 * Carries out a Query over entities.
 *
 * The whole query is checked against the FieldMap before anything is read, so a
 * wrong name gets an error rather than a partial answer.
 *
 * A collection gives `{data, total, page, pageSize, from, to}`, at every level, and
 * a list when `skipLimitOffset` lifts its limits. A nested collection is paged here:
 * for several parents, Nette removes the limit from the SQL anyway and loses the
 * offset. So the number of rows is checked first, and a query wanting too many gets
 * an error instead of exhausting the memory.
 *
 * @phpstan-type PreparedCriteria array{where: array{string, list<mixed>}|null, order: list<string>, search: ?string, pageSize: int, offset: int}
 */
final class Processor
{
    /** @var array<callable(ProcessSelectionEventData): void> Before a collection is read; add conditions for access control here */
    public array $onSelection = [];

    /** @var array<callable(ProcessEntityEventData): void> Before a record is turned into output */
    public array $onEntity = [];

    /** @var array<callable(ProcessedEntityEventData): void> After a record is turned into output */
    public array $onProcessedEntity = [];

    /** @var array<callable(SearchEventData): void> Before a search; can change the columns */
    public array $onSearch = [];

    private int $rowsLeft = 0;

    /** @var array<string, PreparedCriteria> */
    private array $prepared = [];

    /** @var bool|list<string> */
    private bool|array $skipLimitOffset = false;

    public function __construct(private readonly QueryConfig $config = new QueryConfig())
    {
        FieldMap::configureEntities($config->entities);
    }

    /**
     * @param Selection<Entity>|Entity|null $what
     * @param class-string|null $of A #[Resource] or an entity; by default what #[QueryOf] of the
     *        parameter says, and the entity itself without it
     * @param list<string>|null $relations For an entity, the relations a query can reach, like
     *        `role` and `orders.items`. By default what #[QueryOf] or `apiQuery.entities` says
     * @param bool|list<string> $skipLimitOffset Collections without limits, which come as lists:
     *        true for the root, or their paths like `@`, `orders` and `orders.items`. It also
     *        turns off `maxRows`, so only ever pass what the application decided
     * @return array<int|string, mixed>|null
     * @throws QueryException
     */
    public function process(
        Selection|Entity|null $what,
        ?Query $query = null,
        ?string $of = null,
        ?array $relations = null,
        bool|array $skipLimitOffset = false,
    ): ?array {
        if ($what === null) {
            return null;
        }

        // An event can call this again, in the middle of what's being done
        $state = [$this->rowsLeft, $this->prepared, $this->skipLimitOffset];

        try {
            return $this->run($what, $query ?? Query::create(), $of, $relations, $skipLimitOffset);
        } finally {
            [$this->rowsLeft, $this->prepared, $this->skipLimitOffset] = $state;
        }
    }

    /**
     * @param Selection<Entity>|Entity $what
     * @param list<string>|null $relations
     * @param bool|list<string> $skipLimitOffset
     * @return array<int|string, mixed>|null
     */
    private function run(Selection|Entity $what, Query $query, ?string $of, ?array $relations, bool|array $skipLimitOffset): ?array
    {
        $class = ltrim($what instanceof Selection ? $what->getName() : $what::class, '\\');
        $map = $this->resolveMap($class, $query, $of, $relations);

        if ($map->entity !== $class) {
            throw new \LogicException("The query is about {$map->entity}, but it was given {$class}.");
        }

        $driver = $this->getDriver($what);
        $compiler = new ConditionCompiler($driver, $this->config->maxDepth);

        $errors = [];
        $collections = [];
        $node = $this->resolveSelect($query->getSelectStructure(), $map, [], $collections, $errors);

        $this->prepared = [];
        $this->prepareCriteria($query, $map, $node, [], $collections, $compiler, $errors);

        foreach (array_keys($query->getCriteria()) as $path) {
            if ($path !== '@' && !isset($collections[$path])) {
                $errors["criteria.{$path}"] = 'is not a selected collection.';
            }
        }

        $errors += $compiler->getErrors();
        if ($errors !== []) {
            throw new QueryException($errors);
        }

        $this->skipLimitOffset = is_array($skipLimitOffset)
            ? array_map(static fn(string $path): string => strtr($path, '.', '/'), $skipLimitOffset)
            : $skipLimitOffset;
        $this->rowsLeft = $skipLimitOffset === false || $skipLimitOffset === [] ? $this->config->maxRows : PHP_INT_MAX;

        return $what instanceof Selection
            ? $this->processRoot($what, $node, $compiler)
            : $this->processRecords([$what], $node, [], $compiler)[0] ?? null;
    }

    /**
     * @param list<string>|null $relations
     */
    private function resolveMap(string $class, Query $query, ?string $of, ?array $relations): FieldMap
    {
        $queryOf = $query->getQueryOf();

        if ($of !== null) {
            /** @var class-string $of */
            return FieldMap::for($of, $relations);
        }
        if ($queryOf !== null) {
            return FieldMap::for($queryOf->class, $relations ?? $queryOf->relations);
        }

        /** @var class-string $class */
        return FieldMap::for($class, $relations);
    }

    /**
     * @param Selection<Entity>|Entity $what
     */
    private function getDriver(Selection|Entity $what): Driver
    {
        if ($what instanceof Selection) {
            return $what->getExplorer()->getConnection()->getDriver();
        }

        // Entity passes it on to its ActiveRow
        $table = $what->__call('getTable', []);
        if (!$table instanceof \Nette\Database\Table\Selection) {
            throw new \LogicException('The entity has no table to read from.');
        }

        return $table->getExplorer()->getConnection()->getDriver();
    }

    /**
     * @param array<string, mixed> $structure
     * @param list<string> $path
     * @param array<string, true> $collections Collection paths found (output)
     * @param array<string, string> $errors (output)
     */
    private function resolveSelect(array $structure, FieldMap $map, array $path, array &$collections, array &$errors): SelectNode
    {
        $fields = [];
        $children = [];
        $prefix = $path === [] ? 'select' : 'select.' . implode('.', $path);

        foreach ($structure === [] ? ['*' => true] : $structure as $name => $nested) {
            $name = (string) $name;

            if ($name === '*') {
                // A virtual property, or what an event fills in, has to be named or is set by the event
                foreach ($map->fields as $field) {
                    if ($field->relation === null && ($field->column !== null || $field->compute !== null)) {
                        $fields[$field->name] = $field;
                    }
                }
                continue;
            }

            $field = $map->fields[$name] ?? null;
            if ($field === null) {
                $errors["{$prefix}.{$name}"] = 'is not a known field.';
                continue;
            }

            $fields[$name] = $field;

            if ($field->relation === null) {
                if (is_array($nested)) {
                    $errors["{$prefix}.{$name}"] = 'is not a relation, so it has nothing to select from.';
                }
                continue;
            }

            $childPath = [...$path, $name];
            if (count($childPath) > $this->config->maxDepth) {
                $errors["{$prefix}.{$name}"] = 'is nested too deep, at most ' . $this->config->maxDepth . ' levels are allowed.';
                continue;
            }

            if ($field->relation->toMany) {
                $collections[implode('/', $childPath)] = true;
            }

            // A relation named alone means all of its fields
            $children[$name] = $this->resolveSelect(is_array($nested) ? $nested : [], $field->relation->getTarget(), $childPath, $collections, $errors);
        }

        return new SelectNode($map, $fields, $children);
    }

    /**
     * @param list<string> $path
     * @param array<string, true> $collections
     * @param array<string, string> $errors (output)
     */
    private function prepareCriteria(Query $query, FieldMap $map, SelectNode $node, array $path, array $collections, ConditionCompiler $compiler, array &$errors): void
    {
        $key = $path === [] ? '@' : implode('/', $path);

        if ($path === [] || isset($collections[$key])) {
            $criteria = $query->getCriteriaByPath($path);
            $where = $path === [] ? '' : "{$key}/";
            $table = $compiler->delimite((string) $map->table);

            $order = [];
            foreach ($criteria->sort ?? [] as $sort) {
                $expression = $compiler->compileSort($sort, $map, $table, "{$where}sort");
                if ($expression !== null) {
                    $order[] = $expression;
                }
            }

            if ($criteria?->search !== null && $map->getSearchColumns() === []) {
                $errors["{$where}search"] = 'is not available here.';
            }

            $pageSize = min($criteria->pageSize ?? $this->config->maxPageSize, $this->config->maxPageSize);

            $this->prepared[$key] = [
                'where' => $criteria?->filter === null ? null : $compiler->compileFilter($criteria->filter, $map, $table, "{$where}filter"),
                'order' => $order,
                'search' => $criteria?->search,
                'pageSize' => $pageSize,
                'offset' => $criteria?->getOffset($pageSize) ?? 0,
            ];
        }

        foreach ($node->children as $name => $child) {
            $this->prepareCriteria($query, $child->map, $child, [...$path, $name], $collections, $compiler, $errors);
        }
    }

    /**
     * `true` means the root alone: what's nested in it keeps its limits.
     *
     * @param list<string> $path
     */
    private function isSkipped(array $path): bool
    {
        if ($this->skipLimitOffset === true) {
            return $path === [];
        }
        if ($this->skipLimitOffset === false) {
            return false;
        }

        return in_array($path === [] ? '@' : implode('/', $path), $this->skipLimitOffset, true)
            || ($path === [] && in_array('', $this->skipLimitOffset, true));
    }

    /**
     * @param list<string> $path
     */
    private function reserveRows(int $rows, array $path): void
    {
        $this->rowsLeft -= $rows;
        if ($this->rowsLeft < 0) {
            throw QueryException::at(
                $path === [] ? 'select' : 'select.' . implode('.', $path),
                'would return too many rows, at most ' . $this->config->maxRows . ' are allowed. Select less, or use smaller pages.',
            );
        }
    }

    /**
     * Conditions, sorting and columns of a collection, without its limit.
     *
     * @param Selection<Entity> $selection
     * @param list<string> $path
     * @param list<string> $extraColumns
     * @return bool False when an event wants it left out
     */
    private function applyCriteria(Selection $selection, SelectNode $node, array $path, ConditionCompiler $compiler, array $extraColumns = []): bool
    {
        $prepared = $this->prepared[$path === [] ? '@' : implode('/', $path)];

        if ($prepared['where'] !== null) {
            $selection->where($prepared['where'][0], ...$prepared['where'][1]);
        }

        if ($prepared['search'] !== null) {
            $event = new SearchEventData(array_values($node->map->getSearchColumns()), (string) $node->map->table, (string) $node->map->entity);
            Arrays::invoke($this->onSearch, $event);
            if ($event->columns !== []) {
                $selection->searchIn($event->columns, $prepared['search'], $this->config->ignoreDiacritics);
            }
        }

        foreach ($prepared['order'] as $expression) {
            $selection->order($expression);
        }

        // Paging needs a stable order, and SQL Server won't do it without one
        if ($selection->nSelection->getSqlBuilder()->getOrder() === [] && $node->map->primaryColumn !== null) {
            $selection->order($compiler->delimite((string) $node->map->table) . '.' . $compiler->delimite($node->map->primaryColumn));
        }

        // Nette can't load a column missing from an explicit select, and a property would
        // quietly read null. Whoever gets the entity may read anything, so nothing is left out.
        if ($this->onEntity === [] && $this->onProcessedEntity === [] && !$node->hasComputedValues()) {
            $selection->select(implode(', ', $node->getColumns($compiler, $extraColumns)));
        }

        $event = new ProcessSelectionEventData($selection, $path, array_map(strval(...), array_keys($node->fields)));
        Arrays::invoke($this->onSelection, $event);

        return !$event->shouldSkip;
    }

    /**
     * @param Selection<Entity> $selection
     * @return array<int|string, mixed>|null
     */
    private function processRoot(Selection $selection, SelectNode $node, ConditionCompiler $compiler): ?array
    {
        if (!$this->applyCriteria($selection, $node, [], $compiler)) {
            return null;
        }

        if ($this->isSkipped([])) {
            return $this->processRecords(iterator_to_array($selection, false), $node, [], $compiler);
        }

        $total = (int) $selection->count('*');
        $prepared = $this->prepared['@'];

        $selection->limit($prepared['pageSize'], $prepared['offset']);
        $entities = iterator_to_array($selection, false);
        $this->reserveRows(count($entities), []);

        return self::buildPage($this->processRecords($entities, $node, [], $compiler), $total, $prepared['offset'], $prepared['pageSize']);
    }

    /**
     * @param list<mixed> $data
     * @return array{data: list<mixed>, total: int, page: int, pageSize: int, from: ?int, to: ?int}
     */
    private static function buildPage(array $data, int $total, int $offset, int $pageSize): array
    {
        $last = min($offset + $pageSize, $total) - 1;

        return [
            'data' => $data,
            'total' => $total,
            'page' => (int) floor($offset / $pageSize) + 1,
            'pageSize' => $pageSize,
            'from' => $offset > $last ? null : $offset,
            'to' => $offset > $last ? null : $last,
        ];
    }

    /**
     * @param list<Entity> $entities
     * @param list<string> $path
     * @return list<array<string, mixed>>
     */
    private function processRecords(array $entities, SelectNode $node, array $path, ConditionCompiler $compiler): array
    {
        // Prepared up front, to know how many rows it comes to before Nette loads them
        $collections = [];
        foreach ($node->children as $name => $child) {
            $relation = $node->fields[$name]->relation;
            if ($relation === null || !$relation->toMany) {
                continue;
            }
            $childPath = [...$path, $name];
            $prepared = $this->prepared[implode('/', $childPath)];
            $property = (string) $node->fields[$name]->property;
            $skipped = $this->isSkipped($childPath);
            $expected = 0;

            foreach ($entities as $index => $entity) {
                /** @var Selection<Entity> $selection */
                $selection = $entity->{$property};
                if (!$this->applyCriteria($selection, $child, $childPath, $compiler, [$relation->remoteColumn])) {
                    continue;
                }

                $count = $skipped ? 0 : (int) $selection->count('*');
                $collections[$index][$name] = [$selection, $count];
                $expected += min(max($count - $prepared['offset'], 0), $prepared['pageSize']);
            }

            $this->reserveRows($expected, $childPath);
        }

        $output = [];
        foreach ($entities as $index => $entity) {
            $record = $this->processRecord($entity, $node, $path, $compiler, $collections[$index] ?? []);
            if ($record !== null) {
                $output[] = $record;
            }
        }

        return $output;
    }

    /**
     * @param list<string> $path
     * @param array<string, array{Selection<Entity>, int}> $collections
     * @return array<string, mixed>|null
     */
    private function processRecord(Entity $entity, SelectNode $node, array $path, ConditionCompiler $compiler, array $collections): ?array
    {
        $select = array_map(strval(...), array_keys($node->fields));

        $event = new ProcessEntityEventData($entity, $path, $select);
        Arrays::invoke($this->onEntity, $event);
        if ($event->shouldSkip) {
            return null;
        }

        $output = [];
        foreach ($node->fields as $name => $field) {
            $name = (string) $name;

            if ($field->computed) {
                if ($field->compute !== null) {
                    $output[$name] = $this->formatValue(($field->compute)($entity), $field);
                }
                continue; // otherwise it's up to an event
            }

            $property = (string) $field->property;

            if ($field->relation === null) {
                $output[$name] = $this->formatValue($entity->{$property}, $field);
                continue;
            }

            $child = $node->children[$name];
            $childPath = [...$path, $name];

            if (!$field->relation->toMany) {
                $target = $entity->{$property};
                $output[$name] = $target instanceof Entity
                    ? ($this->processRecords([$target], $child, $childPath, $compiler)[0] ?? null)
                    : null;
                continue;
            }

            if (!isset($collections[$name])) {
                continue; // an event wanted it left out
            }

            [$selection, $count] = $collections[$name];
            $output[$name] = $this->processCollection($selection, $count, $child, $childPath, $compiler);
        }

        $processed = new ProcessedEntityEventData($entity, $path, $select, $output);
        Arrays::invoke($this->onProcessedEntity, $processed);

        return $processed->shouldSkip ? null : $processed->getResult();
    }

    /**
     * @param Selection<Entity> $selection
     * @param list<string> $path
     * @return array<int|string, mixed>
     */
    private function processCollection(Selection $selection, int $count, SelectNode $node, array $path, ConditionCompiler $compiler): array
    {
        $entities = iterator_to_array($selection, false);

        if ($this->isSkipped($path)) {
            return $this->processRecords($entities, $node, $path, $compiler);
        }

        $prepared = $this->prepared[implode('/', $path)];
        $page = array_slice($entities, $prepared['offset'], $prepared['pageSize']);

        return self::buildPage($this->processRecords($page, $node, $path, $compiler), $count, $prepared['offset'], $prepared['pageSize']);
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
