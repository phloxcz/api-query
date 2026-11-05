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

use Phlox\ApiCore\ApiConstructible;
use Phlox\ApiCore\Binding\BoundToParameter;
use Phlox\ApiCore\Documentation\SchemaBuilder;
use Phlox\ApiCore\Documentation\SelfDescribing;
use Phlox\ApiQuery\Attributes\QueryOf;
use Phlox\ApiQuery\Attributes\QueryOfSql;
use Phlox\ApiQuery\Documentation\QuerySchema;

/**
 * What a client asks for:
 *
 *     {
 *         "select": "id,name,role(id,name),orders(id,status)",
 *         "search": "ann", "page": 1, "pageSize": 20,
 *         "sort": [{"field": "name", "dir": "asc"}],
 *         "filter": {"logic": "and", "filters": [{"field": "name", "operator": "contains", "value": "a"}]},
 *         "criteria": {"orders": {"pageSize": 5, "sort": [{"field": "id", "dir": "desc"}]}}
 *     }
 *
 * `criteria` holds the same for nested collections by their path, like
 * `orders/items`, and for the root by `@`.
 */
final class Query implements ApiConstructible, BoundToParameter, SelfDescribing
{
    private ?QueryOf $queryOf = null;

    private ?QueryOfSql $queryOfSql = null;

    /**
     * @param array<string, mixed> $selectStructure
     * @param array<string, Criteria> $criteria
     */
    private function __construct(
        private string $select,
        private array $selectStructure,
        private array $criteria,
    ) {
    }

    /**
     * @param array<int|string, mixed>|object $data
     * @throws QueryException
     */
    public static function create(array|object $data = []): self
    {
        // Nested objects too, whichever way the JSON was decoded
        $array = is_object($data)
            ? (array) json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
            : $data;

        $parsed = (new QueryParser())->parse($array);

        return new self($parsed['select'], $parsed['structure'], $parsed['criteria']);
    }

    public static function apiConstruct(mixed $value): static
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value) && !is_object($value)) {
            throw QueryException::at('query', 'must be an object.');
        }

        return self::create($value);
    }

    public static function createEmpty(): static
    {
        return self::create();
    }

    public function bindToParameter(\ReflectionParameter $parameter): void
    {
        $this->queryOf = ($parameter->getAttributes(QueryOf::class)[0] ?? null)?->newInstance();
        $this->queryOfSql = ($parameter->getAttributes(QueryOfSql::class)[0] ?? null)?->newInstance();
    }

    public static function describeSchema(SchemaBuilder $builder): array
    {
        return QuerySchema::describe($builder);
    }

    /**
     * What the controller's parameter says this query is about.
     */
    public function getQueryOf(): ?QueryOf
    {
        return $this->queryOf;
    }

    public function getQueryOfSql(): ?QueryOfSql
    {
        return $this->queryOfSql;
    }

    public function getSelect(): string
    {
        return $this->select;
    }

    /**
     * @throws QueryException
     */
    public function setSelect(string $select): static
    {
        $parsed = (new QueryParser())->parse(['select' => $select]);
        $this->select = $parsed['select'];
        $this->selectStructure = $parsed['structure'];

        return $this;
    }

    /**
     * @param array<string, Criteria> $criteria By path; `@` is the root
     */
    public function setCriteria(array $criteria): static
    {
        $this->criteria = $criteria;

        return $this;
    }

    /**
     * @return array<string, mixed> Field => true, or the structure of what's nested in it
     */
    public function getSelectStructure(): array
    {
        return $this->selectStructure;
    }

    /**
     * @return array<string, Criteria> By path; `@` is the root
     */
    public function getCriteria(): array
    {
        return $this->criteria;
    }

    /**
     * The criteria themselves, so changing them changes the query.
     *
     * @param list<string> $path
     */
    public function getCriteriaByPath(array $path = []): ?Criteria
    {
        return $this->criteria[$path === [] ? '@' : implode('/', $path)] ?? null;
    }
}
