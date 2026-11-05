<?php

/**
 * Reading what clients send, including everything Kendo UI can send.
 */

declare(strict_types=1);

use Phlox\ApiQuery\Filter;
use Phlox\ApiQuery\FilterGroup;
use Phlox\ApiQuery\Query;
use Phlox\ApiQuery\QueryException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$errors = static function (array $data): array {
    $e = Assert::exception(static fn() => Query::create($data), QueryException::class);
    Assert::same(400, $e->getHttpCode());
    Assert::same(-32602, $e->getCode());
    return $e->getData()['fields'];
};

// --- Nothing

$empty = Query::create();
Assert::same('*', $empty->getSelect());
Assert::same(['*' => true], $empty->getSelectStructure());
Assert::same([], $empty->getCriteria());
Assert::null($empty->getCriteriaByPath());

// --- Select

Assert::same(
    ['id' => true, 'name' => true, 'role' => ['id' => true, 'perms' => ['*' => true]], 'posts' => ['x' => true], 'z' => true],
    Query::create(['select' => ' id, name ,role(id,perms(*)),posts( x ),z'])->getSelectStructure(),
);

foreach ([
    'a(b' => 'unmatched "("',
    'a)' => 'unmatched ")"',
    '(a)' => 'no field before it',
    'a,(b)' => 'no field before it',
    'a;drop' => "invalid name 'a;drop'",
    'a b' => "invalid name 'a b'",
    '1a' => "invalid name '1a'",
    "a'" => 'invalid name',
    'a.b' => "invalid name 'a.b'",
] as $select => $expected) {
    Assert::contains($expected, $errors(['select' => $select])['select'], $select);
}
Assert::contains('must be a string', $errors(['select' => ['id']])['select']);
Assert::contains('too long', $errors(['select' => str_repeat('a,', 3000)])['select']);

// --- The root's criteria, in the query or under @

$short = Query::create([
    'select' => 'id',
    'search' => 'ann',
    'page' => '2',
    'pageSize' => 20,
    'sort' => [['field' => 'name', 'dir' => 'DESC'], ['field' => 'id']],
    'filter' => ['logic' => 'or', 'filters' => [['field' => 'a', 'operator' => 'eq', 'value' => 1]]],
])->getCriteriaByPath();

Assert::same('ann', $short->search);
Assert::same(2, $short->page);
Assert::same(20, $short->pageSize);
Assert::same(20, $short->getOffset(20));
Assert::true($short->sort[0]->descending);
Assert::false($short->sort[1]->descending);
Assert::same(FilterGroup::Or, $short->filter->logic);
Assert::same('a', $short->filter->filters[0]->field);
Assert::same(Filter::Equal, $short->filter->filters[0]->operator);
Assert::same(1, $short->filter->filters[0]->value);

$nested = Query::create(['criteria' => ['@' => ['pageSize' => 5], 'orders/items' => ['page' => 3]]]);
Assert::same(5, $nested->getCriteriaByPath()->pageSize);
Assert::same(3, $nested->getCriteriaByPath(['orders', 'items'])->page);
Assert::null($nested->getCriteriaByPath(['orders']));

$rootWins = Query::create(['pageSize' => 7, 'criteria' => ['@' => ['pageSize' => 5]]]);
Assert::same(7, $rootWins->getCriteriaByPath()->pageSize, 'what is in the query is what the client meant');

// --- What Kendo UI sends

$kendo = Query::create(['take' => 10, 'skip' => 25, 'sort' => [['field' => 'a', 'dir' => null], ['field' => 'b', 'dir' => 'asc']]])->getCriteriaByPath();
Assert::same(10, $kendo->pageSize);
Assert::same(25, $kendo->getOffset(10), 'skip need not fall on a page');
Assert::count(1, $kendo->sort, 'an empty direction means the column is no longer sorted');
Assert::same('b', $kendo->sort[0]->field);

$list = Query::create(['filter' => [['field' => 'a', 'value' => 1], ['field' => 'b', 'value' => 2]]])->getCriteriaByPath()->filter;
Assert::same(FilterGroup::And, $list->logic);
Assert::count(2, $list->filters);

$one = Query::create(['filter' => ['field' => 'a', 'operator' => 'gte', 'value' => 1]])->getCriteriaByPath()->filter;
Assert::same(Filter::GreaterOrEqual, $one->filters[0]->operator);

$sortOne = Query::create(['sort' => ['field' => 'a', 'dir' => 'desc']])->getCriteriaByPath()->sort;
Assert::true($sortOne[0]->descending, 'one sort needs no list');

$aliases = Query::create(['sorts' => [['field' => 'a']], 'filters' => [['field' => 'b']]])->getCriteriaByPath();
Assert::same('a', $aliases->sort[0]->field);
Assert::same('b', $aliases->filter->filters[0]->field);

$nestedGroups = Query::create(['filter' => ['logic' => 'and', 'filters' => [
    ['field' => 'a', 'value' => 1],
    ['logic' => 'or', 'filters' => [['field' => 'b', 'value' => 2], ['logic' => 'and', 'filters' => [['field' => 'c', 'value' => 3]]]]],
    ['logic' => 'or', 'filters' => []],
]]])->getCriteriaByPath()->filter;
Assert::count(2, $nestedGroups->filters, 'an empty group is nothing');
Assert::same(FilterGroup::Or, $nestedGroups->filters[1]->logic);
Assert::same('c', $nestedGroups->filters[1]->filters[1]->filters[0]->field);

Assert::same('a', Query::create(['filter' => ['logic' => 'and', 'filters' => ['field' => 'a', 'value' => 1]]])->getCriteriaByPath()->filter->filters[0]->field, 'one condition needs no list');

Assert::null(Query::create(['filter' => null, 'search' => '  ', 'page' => null])->getCriteriaByPath(), 'empty values are nothing');
Assert::null(Query::create(['take' => 0])->getCriteriaByPath(), 'take 0 means no size of its own');
Assert::same(0, Query::create(['skip' => 0])->getCriteriaByPath()->skip, 'zero is a value');
Assert::same(1, Query::create(['page' => -5])->getCriteriaByPath()->page);
Assert::same(0, Query::create(['skip' => -5])->getCriteriaByPath()->skip);

// --- Operators

foreach ([
    Filter::Equal => ['=', '==', 'eq', 'isequalto', 'equals', 'equalto', 'equal', 'EQ'],
    Filter::NotEqual => ['<>', '!=', 'neq', 'isnotequalto', 'notequals', 'notequalto', 'notequal', 'ne'],
    Filter::Less => ['<', 'lt', 'islessthan', 'lessthan', 'less'],
    Filter::Greater => ['>', 'gt', 'isgreaterthan', 'greaterthan', 'greater'],
    Filter::LessOrEqual => ['<=', 'lte', 'islessthanorequalto', 'lessthanequal', 'le'],
    Filter::GreaterOrEqual => ['>=', 'gte', 'isgreaterthanorequalto', 'greaterthanequal', 'ge'],
    Filter::StartsWith => ['starts', 'startswith'],
    Filter::NotStartsWith => ['doesnotstartwith'],
    Filter::EndsWith => ['ends', 'endswith'],
    Filter::NotEndsWith => ['doesnotendwith'],
    Filter::Contains => ['containing', 'contains', 'substring', 'substringof'],
    Filter::NotContains => ['doesnotcontain', 'notcontains'],
    Filter::In => ['in'],
    Filter::NotIn => ['not in', 'notin'],
    Filter::IsNull => ['is null', 'isnull', 'null'],
    Filter::IsNotNull => ['is not null', 'isnotnull', 'notnull'],
    Filter::IsEmpty => ['isempty', 'isnullorempty'],
    Filter::IsNotEmpty => ['isnotempty', 'isnotnullorempty'],
    Filter::IsWeek => ['isweek'],
    Filter::ThisWeek => ['thisweek'],
    Filter::Today => ['today'],
] as $canonical => $aliases) {
    foreach ($aliases as $alias) {
        Assert::same($canonical, Filter::normalizeOperator($alias), $alias);
    }
}

// --- What's refused

Assert::same(['filter.filters.0.operator' => 'is not a known operator.'], $errors(['filter' => [['field' => 'a', 'operator' => "=' OR 1=1 --"]]]));
Assert::same(['filter.filters.0.operator' => 'is not a known operator.'], $errors(['filter' => [['field' => 'a', 'operator' => ['eq']]]]));
Assert::same(['filter.filters.0.field' => 'is required.'], $errors(['filter' => [['value' => 1]]]));
Assert::same(['filter.logic' => 'must be "and" or "or".'], $errors(['filter' => ['logic' => 'xor', 'filters' => []]]));
Assert::same(['filter.filters.0' => 'must be an object.'], $errors(['filter' => ['text']]));
Assert::same(['filter' => 'must be an object {logic, filters} or a list of conditions.'], $errors(['filter' => 'text']));
Assert::same(['sort.0' => 'must have a field and a direction asc or desc.'], $errors(['sort' => [['field' => 'a', 'dir' => 'sideways']]]));
Assert::same(['sort.0' => 'must have a field and a direction asc or desc.'], $errors(['sort' => [['dir' => 'asc']]]));
Assert::same(['sort' => 'must be a list of {field, dir}.'], $errors(['sort' => 'a']));
Assert::same(['page' => 'must be a whole number.'], $errors(['page' => 'x']));
Assert::same(['take' => 'must be a whole number.'], $errors(['take' => 1.5]));
Assert::same(['pageSize' => 'must be a whole number.'], $errors(['pageSize' => true]));
Assert::same(['search' => 'must be a string.'], $errors(['search' => ['a']]));
Assert::same(['criteria' => 'must be an object.'], $errors(['criteria' => 'x']));
Assert::same(['criteria.orders.page' => 'must be a whole number.'], $errors(['criteria' => ['orders' => ['page' => 'x']]]));
Assert::contains('whole number', $errors(['page' => PHP_INT_MAX])['page'], 'an offset must not overflow');

// All problems at once
$all = array_keys($errors(['select' => 'a(', 'page' => 'x', 'sort' => [['field' => '']]]));
sort($all);
Assert::same(['page', 'select', 'sort.0'], $all);

// --- Limits

$deep = ['field' => 'a', 'value' => 1];
for ($i = 0; $i < 12; $i++) {
    $deep = ['logic' => 'and', 'filters' => [$deep]];
}
Assert::contains('nested too deep', implode(' ', $errors(['filter' => $deep])));

Assert::contains('too many conditions', implode(' ', $errors(['filter' => array_fill(0, 201, ['field' => 'a', 'value' => 1])])));
Assert::contains('at most 20', $errors(['sort' => array_fill(0, 21, ['field' => 'a'])])['sort']);

// --- From a request

$fromJson = Query::apiConstruct(json_decode('{"select":"id","filter":{"logic":"and","filters":[{"field":"a","value":1}]},"criteria":{"x":{"page":2}}}'));
Assert::same('a', $fromJson->getCriteriaByPath()->filter->filters[0]->field, 'objects at any depth');
Assert::same(2, $fromJson->getCriteriaByPath(['x'])->page);

Assert::same('id', Query::apiConstruct('{"select":"id"}')->getSelect());
Assert::exception(static fn() => Query::apiConstruct(5), QueryException::class);
Assert::exception(static fn() => Query::apiConstruct('nonsense'), QueryException::class);
