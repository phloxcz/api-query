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
use Nette\Database\SqlLiteral;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Phlox\ApiCore\Schema\Paged;
use Phlox\ApiQuery\Events\ProcessedEntityEventData;
use Phlox\ApiQuery\Events\ProcessEntityEventData;
use Phlox\ApiQuery\Events\ProcessSelectionEventData;
use Phlox\ApiQuery\Events\SearchEventData;
use Phlox\ApiQuery\Fields\Field;
use Phlox\ApiQuery\Fields\FieldMap;
use Phlox\ApiQuery\Fields\ResourceRegistry;
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
 * @phpstan-type PreparedCriteria array{where: array{string, list<mixed>}|null, order: list<array{string, list<mixed>}>, search: ?string, pageSize: int, offset: int}
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

    /** @var Selection<Entity>|null Where tables of other entities come from, for restrict() of resources: the root's */
    private ?Selection $source = null;

    /** Which resource stands for each entity, for this processor alone */
    private readonly ResourceRegistry $resources;

    /**
     * @param Container|null $container Gives services to methods of resources, which ask for them by type
     */
    public function __construct(
        private readonly QueryConfig $config = new QueryConfig(),
        private readonly ?Container $container = null,
    ) {
        $this->resources = new ResourceRegistry($config->resources);
    }

    /**
     * @param Selection<Entity>|Entity|null $what
     * @param class-string|null $of A #[Resource] or an entity; by default what #[QueryOf] of the
     *        parameter says, and the resource of the entity without it
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
        bool|array $skipLimitOffset = false,
    ): ?array {
        if ($what === null) {
            return null;
        }

        // An event or a method of a resource can call this again, in the middle of what's being done
        $state = [$this->rowsLeft, $this->prepared, $this->skipLimitOffset, $this->source];

        try {
            return $this->run($what, $query ?? Query::create(), $of, $skipLimitOffset);
        } finally {
            [$this->rowsLeft, $this->prepared, $this->skipLimitOffset, $this->source] = $state;
        }
    }

    /**
     * @param Selection<Entity>|Entity $what
     * @param bool|list<string> $skipLimitOffset
     * @return array<int|string, mixed>|null
     */
    private function run(Selection|Entity $what, Query $query, ?string $of, bool|array $skipLimitOffset): ?array
    {
        $class = ltrim($what instanceof Selection ? $what->getName() : $what::class, '\\');
        $map = $this->resolveMap($class, $query, $of);

        if ($map->entity !== $class) {
            throw new \LogicException("The query is about {$map->entity}, but it was given {$class}.");
        }

        $this->source = $what instanceof Selection ? $what : $what->getSelection();
        $driver = $this->getDriver($what);
        $compiler = new ConditionCompiler($driver, $this->config->maxDepth, $this->buildRestriction(...));

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

    private function resolveMap(string $class, Query $query, ?string $of): FieldMap
    {
        /** @var class-string $target */
        $target = $of ?? $query->getQueryOf()->class ?? $class;

        return FieldMap::for($target, $this->resources);
    }

    /**
     * Calls a method of a resource, giving services to the parameters after those passed.
     *
     * @param list<mixed> $args
     */
    private function invoke(\Closure $method, array $args): mixed
    {
        if ((new \ReflectionFunction($method))->getNumberOfParameters() <= count($args)) {
            return $method(...$args);
        }

        if ($this->container === null) {
            throw new \LogicException('A method of a resource asks for services, so the Processor needs the DI container. Get it from the container rather than creating it.');
        }

        return $this->container->callMethod($method, $args);
    }

    /**
     * restrict() of a resource as a condition on its table, for a subquery: a filter
     * or a sort through a relation must not see what the client can't read.
     *
     * @return array{string, list<mixed>}|null
     */
    private function buildRestriction(FieldMap $map): ?array
    {
        if ($map->restrict === null) {
            return null;
        }

        $selection = $this->createSelection($map);
        $this->invoke($map->restrict, [$selection]);

        $driver = $selection->nSelection->getExplorer()->getConnection()->getDriver();
        $key = $driver->delimite((string) $map->table) . '.' . $driver->delimite((string) $map->primaryColumn);
        $builder = $selection->nSelection->select($key)->getSqlBuilder();

        // A literal goes in as it is, so Nette doesn't take its names for joins of the outer query
        return ["{$key} IN (?)", [new SqlLiteral($builder->buildSelectQuery(), $builder->getParameters())]];
    }

    /**
     * @return Selection<Entity>
     */
    private function createSelection(FieldMap $map): Selection
    {
        $source = $this->source ?? throw new \LogicException(
            "{$map->resource} has restrict(), but the query got an entity with no selection it came from.",
        );

        return new Selection($source->nSelection->getExplorer()->table((string) $map->table), $source->entityFactory);
    }

    /**
     * Keys of records restrict() lets through, out of those given.
     *
     * @param list<mixed> $keys
     * @return array<int|string, true>
     */
    private function findAllowed(FieldMap $map, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $selection = $this->createSelection($map);
        $this->invoke($map->restrict ?? throw new \LogicException("{$map->resource} has no restrict()."), [$selection]);

        $allowed = [];
        foreach ($selection->nSelection->where((string) $map->primaryColumn, $keys) as $row) {
            $allowed[(string) $row[(string) $map->primaryColumn]] = true;
        }

        return $allowed;
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

        foreach ($prepared['order'] as [$expression, $params]) {
            $selection->order($expression, ...$params);
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

        // What the client can read, in the total too
        if ($node->map->restrict !== null) {
            $this->invoke($node->map->restrict, [$selection]);
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

        return Paged::result($this->processRecords($entities, $node, [], $compiler), $total, $prepared['offset'], $prepared['pageSize']);
    }

    /**
     * @param list<Entity> $entities
     * @param list<string> $path
     * @return list<array<string, mixed>>
     */
    private function processRecords(array $entities, SelectNode $node, array $path, ConditionCompiler $compiler): array
    {
        return array_values(array_filter(
            $this->processAll($entities, $node, $path, $compiler),
            static fn(?array $record): bool => $record !== null,
        ));
    }

    /**
     * @param list<Entity> $entities
     * @param list<string> $path
     * @return list<array<string, mixed>|null> In the order given; null for a record left out
     */
    private function processAll(array $entities, SelectNode $node, array $path, ConditionCompiler $compiler): array
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

        // A single record restrict() hides is null, as if there was none; asked for all parents at once
        $allowed = [];
        foreach ($node->children as $name => $child) {
            $relation = $node->fields[$name]->relation;
            if ($relation === null || $relation->toMany || $child->map->restrict === null) {
                continue;
            }
            $keys = [];
            foreach ($entities as $entity) {
                $key = $entity->toArrayDb()[$relation->localColumn] ?? null;
                if ($key !== null) {
                    $keys[(string) $key] = $key;
                }
            }
            $allowed[$name] = $this->findAllowed($child->map, array_values($keys));
        }

        // Single records of all parents at once, so what's computed for a batch is one call for them
        $singles = [];
        foreach ($node->children as $name => $child) {
            $relation = $node->fields[$name]->relation;
            if ($relation === null || $relation->toMany) {
                continue;
            }
            $property = (string) $node->fields[$name]->property;
            $targets = [];
            foreach ($entities as $index => $entity) {
                if (isset($allowed[$name]) && !isset($allowed[$name][(string) ($entity->toArrayDb()[$relation->localColumn] ?? '')])) {
                    continue;
                }
                $target = $entity->{$property};
                if ($target instanceof Entity) {
                    $targets[$index] = $target;
                }
            }
            $singles[$name] = $targets === []
                ? []
                : array_combine(array_keys($targets), $this->processAll(array_values($targets), $child, [...$path, $name], $compiler));
        }

        $batches = $this->computeBatches($entities, $node);

        $output = [];
        foreach ($entities as $index => $entity) {
            $output[] = $this->processRecord($entity, $node, $path, $compiler, $collections[$index] ?? [], $singles, $index, $batches);
        }

        return $output;
    }

    /**
     * Fields computed for a batch: one call for all the records, giving values by their primary key.
     *
     * @param list<Entity> $entities
     * @return array<string, array<int|string, mixed>> By field
     */
    private function computeBatches(array $entities, SelectNode $node): array
    {
        $values = [];
        foreach ($node->fields as $name => $field) {
            if (!$field->batch || $field->compute === null || $entities === []) {
                continue;
            }

            $result = $this->invoke($field->compute, [$entities]);
            if (!is_array($result)) {
                throw new \LogicException("Computed field '{$name}' of {$node->map->resource} is for a batch, so its method returns values by primary key.");
            }
            $values[(string) $name] = $result;
        }

        return $values;
    }

    /**
     * @param list<string> $path
     * @param array<string, array{Selection<Entity>, int}> $collections
     * @param array<string, array<int, array<string, mixed>|null>> $singles Records of single relations, by relation and the index of the parent
     * @param array<string, array<int|string, mixed>> $batches Values computed for a batch, by field and primary key
     * @return array<string, mixed>|null
     */
    private function processRecord(Entity $entity, SelectNode $node, array $path, ConditionCompiler $compiler, array $collections, array $singles, int $index, array $batches): ?array
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
                if ($field->batch) {
                    $key = $entity->toArrayDb()[(string) $node->map->primaryColumn] ?? null;
                    $output[$name] = $this->formatValue($key === null ? null : $batches[$name][$key] ?? null, $field);
                } elseif ($field->compute !== null) {
                    $output[$name] = $this->formatValue($this->invoke($field->compute, [$entity]), $field);
                }
                continue;
            }

            $property = (string) $field->property;

            if ($field->relation === null) {
                $output[$name] = $this->formatValue($entity->{$property}, $field);
                continue;
            }

            $child = $node->children[$name];
            $childPath = [...$path, $name];

            if (!$field->relation->toMany) {
                // Read for all parents at once; none when there's no record, or restrict() hides it
                $output[$name] = $singles[$name][$index] ?? null;
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

        return Paged::result($this->processRecords($page, $node, $path, $compiler), $count, $prepared['offset'], $prepared['pageSize']);
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
