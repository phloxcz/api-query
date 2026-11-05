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

/**
 * Turns what a client sent into a Query. Whatever isn't understood is refused,
 * and limits are checked here, before the query reaches a database.
 */
final class QueryParser
{
    private const MaxFilterDepth = 10;
    private const MaxConditions = 200;
    private const MaxSorts = 20;
    private const MaxSelectLength = 4096;
    private const MaxPageNumber = 1_000_000_000;

    /** @var array<string, string> */
    private array $errors = [];

    private int $conditions = 0;

    /**
     * @param array<int|string, mixed> $data
     * @return array{select: string, structure: array<string, mixed>, criteria: array<string, Criteria>}
     * @throws QueryException
     */
    public function parse(array $data): array
    {
        $select = '*';
        $criteria = [];
        $shortcut = [];

        foreach ($data as $key => $value) {
            switch (strtolower((string) $key)) {
                case 'select':
                    if (is_string($value) && trim($value) !== '') {
                        $select = $value;
                    } elseif ($value !== null && $value !== '') {
                        $this->errors['select'] = 'must be a string such as "id,name,role(id,name)".';
                    }
                    break;

                case 'criteria':
                    if (is_array($value)) {
                        foreach ($value as $path => $item) {
                            $criteria[(string) $path] = $this->parseCriteria(self::toArray($item), "criteria.{$path}");
                        }
                    } elseif ($value !== null) {
                        $this->errors['criteria'] = 'must be an object.';
                    }
                    break;

                default:
                    $shortcut[(string) $key] = $value;
            }
        }

        // The members of the root's criteria can be right in the query
        $root = $this->parseCriteria($shortcut, '');
        if (!$root->isEmpty()) {
            $criteria['@'] = $root;
        }

        $structure = $this->parseSelect($select);

        if ($this->errors !== []) {
            throw new QueryException($this->errors);
        }

        return ['select' => $select, 'structure' => $structure, 'criteria' => $criteria];
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function parseCriteria(array $data, string $where): Criteria
    {
        $values = [];
        foreach ($data as $key => $value) {
            $values[strtolower((string) $key)] = $value;
        }

        $at = static fn(string $name): string => $where === '' ? $name : "{$where}.{$name}";

        $search = $values['search'] ?? null;
        if ($search !== null && !is_string($search) && !is_int($search) && !is_float($search)) {
            $this->errors[$at('search')] = 'must be a string.';
            $search = null;
        }

        $page = $this->parseInt($values['page'] ?? null, $at('page'));
        $pageSize = $this->parseInt($values['pagesize'] ?? $values['take'] ?? null, $at(isset($values['take']) ? 'take' : 'pageSize'));
        $skip = $this->parseInt($values['skip'] ?? null, $at('skip'));

        return new Criteria(
            $search === null || trim((string) $search) === '' ? null : (string) $search,
            $page === null ? null : max($page, 1),
            $pageSize !== null && $pageSize > 0 ? $pageSize : null,
            $skip === null ? null : max($skip, 0),
            $this->parseSorts($values['sort'] ?? $values['sorts'] ?? null, $at('sort')),
            $this->parseFilter($values['filter'] ?? $values['filters'] ?? null, $at('filter')),
        );
    }

    private function parseInt(mixed $value, string $where): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || is_bool($value) || abs($int) > self::MaxPageNumber) {
            $this->errors[$where] = 'must be a whole number.';
            return null;
        }

        return $int;
    }

    /**
     * @return list<Sort>
     */
    private function parseSorts(mixed $value, string $where): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (!is_array($value)) {
            $this->errors[$where] = 'must be a list of {field, dir}.';
            return [];
        }

        $items = array_is_list($value) ? $value : [$value];
        if (count($items) > self::MaxSorts) {
            $this->errors[$where] = 'can have at most ' . self::MaxSorts . ' items.';
            return [];
        }

        $sorts = [];
        foreach ($items as $index => $item) {
            $field = is_array($item) ? ($item['field'] ?? null) : null;

            // Kendo UI sends an empty direction for a column it no longer sorts by
            if (is_array($item) && array_key_exists('dir', $item) && ($item['dir'] === null || $item['dir'] === '')) {
                continue;
            }

            $dir = is_array($item) ? ($item['dir'] ?? 'asc') : null;

            if (!is_string($field) || trim($field) === '' || !is_string($dir) || !in_array(strtolower($dir), ['asc', 'desc'], true)) {
                $this->errors["{$where}.{$index}"] = 'must have a field and a direction asc or desc.';
                continue;
            }

            $sorts[] = new Sort(trim($field), strtolower($dir) === 'desc');
        }

        return $sorts;
    }

    private function parseFilter(mixed $value, string $where): ?FilterGroup
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (!is_array($value)) {
            $this->errors[$where] = 'must be an object {logic, filters} or a list of conditions.';
            return null;
        }

        // A list of conditions, a group, or one condition: Kendo UI sends all of them
        if (array_is_list($value)) {
            return $this->parseGroup(['logic' => FilterGroup::And, 'filters' => $value], $where, 1);
        }
        if (array_key_exists('filters', $value) || array_key_exists('logic', $value)) {
            return $this->parseGroup($value, $where, 1);
        }

        return $this->parseGroup(['logic' => FilterGroup::And, 'filters' => [$value]], $where, 1);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function parseGroup(array $data, string $where, int $depth): ?FilterGroup
    {
        if ($depth > self::MaxFilterDepth) {
            $this->errors[$where] = 'is nested too deep, at most ' . self::MaxFilterDepth . ' levels are allowed.';
            return null;
        }

        $logic = strtolower(is_string($data['logic'] ?? null) ? $data['logic'] : FilterGroup::And);
        if ($logic !== FilterGroup::And && $logic !== FilterGroup::Or) {
            $this->errors["{$where}.logic"] = 'must be "and" or "or".';
            return null;
        }

        $items = $data['filters'] ?? [];
        if (!is_array($items)) {
            $this->errors["{$where}.filters"] = 'must be a list.';
            return null;
        }
        if (!array_is_list($items)) {
            $items = [$items];
        }

        $filters = [];
        foreach ($items as $index => $item) {
            $itemAt = "{$where}.filters.{$index}";

            if (!is_array($item)) {
                $this->errors[$itemAt] = 'must be an object.';
                continue;
            }

            if (array_key_exists('filters', $item) || array_key_exists('logic', $item)) {
                $group = $this->parseGroup($item, $itemAt, $depth + 1);
                if ($group !== null && $group->filters !== []) {
                    $filters[] = $group;
                }
                continue;
            }

            if (++$this->conditions > self::MaxConditions) {
                $this->errors[$where] = 'has too many conditions, at most ' . self::MaxConditions . ' are allowed.';
                return null;
            }

            $filter = $this->parseCondition($item, $itemAt);
            if ($filter !== null) {
                $filters[] = $filter;
            }
        }

        return new FilterGroup($logic, $filters);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function parseCondition(array $data, string $where): ?Filter
    {
        $field = $data['field'] ?? null;
        if (!is_string($field) || trim($field) === '') {
            $this->errors["{$where}.field"] = 'is required.';
            return null;
        }

        $given = $data['operator'] ?? Filter::Equal;
        $operator = is_string($given) ? Filter::normalizeOperator($given) : null;
        if ($operator === null) {
            $this->errors["{$where}.operator"] = 'is not a known operator.';
            return null;
        }

        return new Filter(trim($field), $operator, $data['value'] ?? null);
    }

    /**
     * Reads `id,name,role(id,name),orders(*)` into `[id => true, role => [id => true, ...]]`.
     *
     * @return array<string, mixed>
     */
    private function parseSelect(string $select): array
    {
        if (strlen($select) > self::MaxSelectLength) {
            $this->errors['select'] = 'is too long.';
            return [];
        }

        $tokens = preg_split('/([(),])/', $select, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        $root = [];
        $stack = [&$root];
        $level = 0;
        $last = null;

        foreach ($tokens as $token) {
            $token = trim($token);
            switch ($token) {
                case '':
                case ',':
                    $last = $token === ',' ? null : $last;
                    break;

                case '(':
                    if ($last === null) {
                        $this->errors['select'] = 'has "(" with no field before it.';
                        return [];
                    }
                    $stack[$level][$last] = [];
                    $stack[$level + 1] = &$stack[$level][$last];
                    $level++;
                    $last = null;
                    break;

                case ')':
                    if ($level === 0) {
                        $this->errors['select'] = 'has an unmatched ")".';
                        return [];
                    }
                    unset($stack[$level]);
                    $level--;
                    $last = null;
                    break;

                default:
                    if (preg_match('/^(?:\*|[A-Za-z_][A-Za-z0-9_]*)$/D', $token) !== 1) {
                        $this->errors['select'] = "has an invalid name '{$token}'.";
                        return [];
                    }
                    $stack[$level][$token] = true;
                    $last = $token;
            }
        }

        if ($level !== 0) {
            $this->errors['select'] = 'has an unmatched "(".';
            return [];
        }

        return $root;
    }
}
