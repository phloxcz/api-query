<?php

/**
 * Queries over entities and resources, on a real database.
 */

declare(strict_types=1);

use Phlox\ApiQuery\Events\ProcessedEntityEventData;
use Phlox\ApiQuery\Events\ProcessEntityEventData;
use Phlox\ApiQuery\Events\ProcessSelectionEventData;
use Phlox\ApiQuery\Events\SearchEventData;
use Phlox\ApiQuery\Processor;
use Phlox\ApiQuery\Query;
use Phlox\ApiQuery\QueryConfig;
use Phlox\ApiQuery\QueryException;
use Phlox\ApiQuery\Tests\Fixtures\Entity\User;
use Phlox\ApiQuery\Tests\Fixtures\Resources\BadField;
use Phlox\ApiQuery\Tests\Fixtures\Resources\BadRelation;
use Phlox\ApiQuery\Tests\Fixtures\Resources\BadScalar;
use Phlox\ApiQuery\Tests\Fixtures\Resources\BadSecret;
use Phlox\ApiQuery\Tests\Fixtures\Resources\TreeOrder;
use Phlox\ApiQuery\Tests\Fixtures\Resources\TreeUser;
use Phlox\ApiQuery\Tests\Fixtures\Resources\WrongTarget;
use Phlox\ApiQuery\Tests\Fixtures\Resources\UserResource;
use Phlox\EntityDatabase\Database\Explorer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/Resources.php';

$container = createContainer();
$explorer = $container->getByType(Explorer::class);
$processor = $container->getByType(Processor::class);

$users = static fn() => $explorer->table('users');

$run = static fn(array $query, mixed ...$options): ?array => $processor->process($users(), Query::create($query), ...$options);

$ids = static fn(?array $result): array => array_column($result['data'], 'id');

$errors = static function (array $query, mixed ...$options) use ($run): array {
    $e = Assert::exception(static fn() => $run($query, ...$options), QueryException::class);
    Assert::same(400, $e->getHttpCode());
    return $e->getData()['fields'];
};

// --- The shape is always the same

$all = $run(['select' => 'id']);
Assert::same(['data', 'total', 'page', 'pageSize', 'from', 'to'], array_keys($all));
Assert::same([1, 2, 3, 4], $ids($all));
Assert::same(4, $all['total']);
Assert::same(1, $all['page']);
Assert::same(100, $all['pageSize'], 'the most allowed, by default');
Assert::same(0, $all['from']);
Assert::same(3, $all['to']);

// --- Select

$first = $run(['select' => 'id,email', 'pageSize' => 1])['data'][0];
Assert::same(['id' => 1, 'email' => 'ann@example.com'], $first);

$star = $run(['pageSize' => 1])['data'][0];
Assert::same(['id', 'email', 'fullName', 'isActive', 'age', 'createdAt', 'birthday', 'roleId'], array_keys($star), 'a star means all readable columns');
Assert::true($star['isActive']);
Assert::same('2024-01-02T03:04:05' . date('P', strtotime('2024-01-02 03:04:05')), $star['createdAt']);
Assert::same('1994-05-06', $star['birthday'], 'a date column stays a date');

Assert::same('nick', (function () use ($processor, $users) {
    $processor->onEntity[] = static function (ProcessEntityEventData $event): void {
        $event->entity->nickname = 'nick';
    };
    $result = $processor->process($users(), Query::create(['select' => 'nickname', 'pageSize' => 1]));
    array_pop($processor->onEntity);
    return $result['data'][0]['nickname'];
})(), 'a virtual property can be named');

// --- Paging

$page = $run(['select' => 'id', 'page' => 2, 'pageSize' => 2]);
Assert::same([3, 4], $ids($page));
Assert::same(4, $page['total']);
Assert::same(2, $page['page']);
Assert::same(2, $page['pageSize']);
Assert::same(2, $page['from']);
Assert::same(3, $page['to']);

$past = $run(['select' => 'id', 'page' => 9, 'pageSize' => 2]);
Assert::same([], $past['data']);
Assert::same(4, $past['total']);
Assert::null($past['from']);
Assert::null($past['to']);

$kendo = $run(['select' => 'id', 'take' => 2, 'skip' => 1]);
Assert::same([2, 3], $ids($kendo), 'skip need not fall on a page');
Assert::same(1, $kendo['from']);
Assert::same(2, $kendo['to']);

Assert::same(100, $run(['select' => 'id', 'pageSize' => 100000])['pageSize'], 'a larger page is cut');

// --- Sort

Assert::same([4, 1, 2], $ids($run(["select" => "id", "sort" => [["field" => "age", "dir" => "desc"]], "filter" => ["field" => "age", "operator" => "isnotnull"]])));
Assert::same([2, 1, 4], $ids($run(["select" => "id", "sort" => [["field" => "age"]], "filter" => ["field" => "age", "operator" => "isnotnull"]])));
Assert::same([3, 1], $ids($run(['select' => 'id', 'sort' => [['field' => 'isActive', 'dir' => 'desc'], ['field' => 'id', 'dir' => 'desc']], 'filter' => ['field' => 'id', 'operator' => 'lt', 'value' => 4], 'pageSize' => 2])), 'several columns');

// --- Filters

$filter = static fn(string $field, string $operator, mixed $value = null): array => $ids($run([
    'select' => 'id',
    'filter' => ['logic' => 'and', 'filters' => [['field' => $field, 'operator' => $operator, 'value' => $value]]],
]));

Assert::same([1], $filter('email', 'eq', 'ann@example.com'));
Assert::same([2, 3, 4], $filter('email', 'neq', 'ann@example.com'));
Assert::same([2], $filter('age', 'lt', 18));
Assert::same([2], $filter('age', 'lt', '18'), 'a number from a query string');
Assert::same([1, 4], $filter('age', 'gte', 18));
Assert::same([4], $filter('age', 'gt', 30));
Assert::same([1, 2], $filter('age', 'lte', 30));
Assert::same([1, 3, 4], $filter('isActive', 'eq', true));
Assert::same([2], $filter('isActive', 'eq', 'false'));
Assert::same([1, 3], $filter('email', 'in', ['ann@example.com', 'cyril_x@example.com']));
Assert::same([2, 4], $filter('email', 'not in', ['ann@example.com', 'cyril_x@example.com']));
Assert::same([1], $filter('email', 'in', 'ann@example.com'), 'one value needs no list');
Assert::same([], $filter('id', 'in', []));
Assert::same([1, 2, 3, 4], $filter('id', 'not in', []));
Assert::same([1, 3], $filter('id', 'in', [1, '3']));

Assert::same([1], $filter('email', 'startswith', 'ANN'), 'text operators ignore case');
Assert::same([2, 3, 4], $filter('email', 'doesnotstartwith', 'ann'));
Assert::same([1, 2, 3, 4], $filter('email', 'endswith', '.COM'));
Assert::same([], $filter('email', 'doesnotendwith', '.com'));
Assert::same([1], $filter('fullName', 'contains', 'novak'));
Assert::same([1, 2, 4], $filter('fullName', 'doesnotcontain', 'zzz'), 'NULL is neither');

// LIKE wildcards are ordinary characters for clients
Assert::same([2], $filter('fullName', 'contains', '100%'));
Assert::same([], $filter('fullName', 'contains', '%100'));
Assert::same([3], $filter('email', 'contains', 'l_x'));
Assert::same([], $filter('email', 'contains', 'n_n'), 'an underscore is no wildcard');
Assert::same([], $filter('email', 'contains', '!'), 'nor is the escape character');
Assert::same([], $filter('email', 'startswith', '%'));

Assert::same([3], $filter('fullName', 'isnull'));
Assert::same([3], $filter('fullName', 'eq', null), 'comparing with null means IS NULL');
Assert::same([1, 2, 4], $filter('fullName', 'neq', null));
Assert::same([1, 2, 4], $filter('fullName', 'isnotnull'));
Assert::same([3, 4], $filter('fullName', 'isempty'), 'empty counts both');
Assert::same([1, 2], $filter('fullName', 'isnotempty'));
Assert::same([3], $filter('age', 'isempty'), 'a number can only be null');
Assert::same([1, 2, 4], $filter('age', 'isnotempty'));

// Dates
Assert::same([1], $filter('createdAt', 'lt', '2024-06-01'));
Assert::same([1], $filter('createdAt', 'eq', '2024-01-02T03:04:05'));
Assert::same([1], $filter('createdAt', 'eq', '2024-01-02 03:04:05'));
Assert::same([1], $filter('birthday', 'eq', '1994-05-06'));
Assert::same([1], $filter('birthday', 'eq', '1994-05-06T10:00:00'), 'the time of day means nothing to a date');
Assert::same([3], $filter('createdAt', 'today'));
Assert::same([3], $filter('createdAt', 'thisweek'));
Assert::same([1], $filter('createdAt', 'isweek', '2024-01-03'));
Assert::same([], $filter('createdAt', 'isweek', '2023-01-03'));
Assert::same([3], $filter('createdAt', 'isweek', (int) date('W')));
Assert::same([3], $filter('createdAt', 'isweek', date('W')));

// Groups
Assert::same([1, 2], $ids($run(['select' => 'id', 'filter' => ['logic' => 'or', 'filters' => [
    ['field' => 'email', 'value' => 'ann@example.com'],
    ['field' => 'email', 'value' => 'bob@example.com'],
]]])));
Assert::same([1], $ids($run(['select' => 'id', 'filter' => ['logic' => 'and', 'filters' => [
    ['field' => 'isActive', 'value' => true],
    ['logic' => 'or', 'filters' => [['field' => 'age', 'operator' => 'lt', 'value' => 40], ['field' => 'email', 'value' => 'bob@example.com']]],
]]])));

// A filter counts in the total
$filtered = $run(['select' => 'id', 'pageSize' => 1, 'filter' => ['field' => 'isActive', 'value' => true]]);
Assert::same(3, $filtered['total']);
Assert::same(0, $filtered['to']);

// --- A value of a wrong type doesn't reach the database

Assert::same(['filter.age' => 'must be a number.'], $errors(['filter' => ['field' => 'age', 'value' => 'abc']]));
Assert::same(['filter.age' => 'must be a number.'], $errors(['filter' => ['field' => 'age', 'operator' => 'in', 'value' => [1, 'abc']]]));
Assert::same(['filter.age' => 'needs a value.'], $errors(['filter' => ['field' => 'age', 'operator' => 'gt']]));
Assert::same(['filter.isActive' => 'must be true or false.'], $errors(['filter' => ['field' => 'isActive', 'value' => 'maybe']]));
Assert::same(['filter.createdAt' => 'must be a date.'], $errors(['filter' => ['field' => 'createdAt', 'value' => 'x']]));
Assert::same(['filter.email' => 'must be a text.'], $errors(['filter' => ['field' => 'email', 'value' => ['a']]]));
Assert::same(['filter.email' => 'needs a text to look for.'], $errors(['filter' => ['field' => 'email', 'operator' => 'contains', 'value' => ['a']]]));
Assert::contains('not a text', $errors(['filter' => ['field' => 'age', 'operator' => 'contains', 'value' => 1]])['filter.age']);
Assert::contains('not a date', $errors(['filter' => ['field' => 'age', 'operator' => 'today']])['filter.age']);
Assert::contains('week', $errors(['filter' => ['field' => 'createdAt', 'operator' => 'isweek', 'value' => 54]])['filter.createdAt']);
Assert::contains('too many values', $errors(['filter' => ['field' => 'id', 'operator' => 'in', 'value' => range(1, 1001)]])['filter.id']);

Assert::contains('too long', $errors(['filter' => ['field' => 'email', 'value' => str_repeat('a', 1001)]])['filter.email']);
Assert::contains('too long', $errors(['filter' => ['field' => 'email', 'operator' => 'contains', 'value' => str_repeat('a', 1001)]])['filter.email']);
Assert::contains('too long', $errors(['filter' => ['field' => 'email', 'operator' => 'in', 'value' => [str_repeat('a', 1001)]]])['filter.email']);
Assert::same([], $filter('email', 'eq', str_repeat('a', 1000)), 'longer than the column finds nothing');
Assert::same([], $filter('email', 'contains', str_repeat('a', 1000)));
Assert::same([], $filter('email', 'contains', str_repeat('%', 1000)), 'escaping makes it longer');

// --- A write-only column doesn't exist, wherever it's asked for

$secret = 'is not a known field.';
Assert::same(['select.passwordHash' => $secret], $errors(['select' => 'id,passwordHash']));
Assert::same(['filter.passwordHash' => $secret], $errors(['filter' => ['field' => 'passwordHash', 'value' => 'hash-of-ann']]));
Assert::same(['sort.passwordHash' => $secret], $errors(['sort' => [['field' => 'passwordHash']]]));
Assert::same(['select.password_hash' => $secret], $errors(['select' => 'password_hash']), 'nor by the name of its column');
Assert::same(['filter.password_hash' => $secret], $errors(['filter' => ['field' => 'password_hash', 'value' => 'x']]));
Assert::false(array_key_exists('passwordHash', $run([])['data'][0]), 'nor in a star');

// Neither can it be found by searching, though it's marked searchable
Assert::same([], $ids($run(['select' => 'id', 'search' => 'hash-of-ann'])));
Assert::same([], $ids($run(['select' => 'id', 'search' => 'hash'])));

// --- Unknown names and attempts at SQL

Assert::same(['select.nothing' => $secret], $errors(['select' => 'nothing']));
Assert::same(['filter.nothing' => $secret], $errors(['filter' => ['field' => 'nothing', 'value' => 1]]));
Assert::same(['sort.nothing' => $secret], $errors(['sort' => [['field' => 'nothing']]]));
foreach (["id = 1 OR 1=1 --", "id; DROP TABLE users", "LOWER(email)", "users.email", '1', '*', "\"id\"", 'id)'] as $attack) {
    Assert::same(["filter.{$attack}" => $secret], $errors(['filter' => ['field' => $attack, 'value' => 1]]), $attack);
    Assert::same(["sort.{$attack}" => $secret], $errors(['sort' => [['field' => $attack]]]), $attack);
}

// Values are parameters
Assert::same([], $filter('email', 'eq', "' OR '1'='1"));
Assert::same([], $filter('email', 'contains', "'; DROP TABLE users; --"));
Assert::same(4, $run(['select' => 'id'])['total'], 'still there');

// All problems at once
Assert::equal(
    ['select.a' => $secret, 'sort.c' => $secret, 'filter.b' => $secret],
    $errors(['select' => 'a', 'filter' => ['field' => 'b', 'value' => 1], 'sort' => [['field' => 'c']]]),
);

// --- An entity without a resource has no relations

$notAllowed = ['select.role' => $secret];
Assert::same($notAllowed, $errors(['select' => 'id,role(id)']));
Assert::same(['filter.role.name' => $secret], $errors(['filter' => ['field' => 'role.name', 'value' => 'admin']]));
Assert::same(['sort.role.name' => $secret], $errors(['sort' => [['field' => 'role.name']]]));
Assert::same(['select.orders' => $secret], $errors(['select' => 'orders']));
Assert::same(['filter.role.name' => $secret, 'filter.orders.status' => $secret], $errors(['filter' => [
    ['field' => 'role.name', 'value' => 'x'],
    ['field' => 'orders.status', 'value' => 'x'],
]]));

// --- Relations, of a resource: to another resource, or to an entity shown by default

$withRole = $run(['select' => 'id,role(name)', 'pageSize' => 3], of: TreeUser::class)['data'];
Assert::same(['id' => 1, 'role' => ['name' => 'admin']], $withRole[0]);
Assert::same(['id' => 3, 'role' => null], $withRole[2], 'no record');

$alone = $run(['select' => 'role', 'pageSize' => 1], of: TreeUser::class)['data'][0];
Assert::same(['role' => ['id' => 1, 'name' => 'admin']], $alone, 'a relation named alone means all of its fields');
Assert::same(['select.role.users' => $secret], $errors(['select' => 'role(users)'], of: TreeUser::class), 'an entity shown by default has no relations');
Assert::same(['filter.role.users.id' => $secret], $errors(['filter' => ['field' => 'role.users.id', 'value' => 1]], of: TreeUser::class));

$nested = $run(['select' => 'id,orders(id,items(name))', 'pageSize' => 2], of: TreeUser::class)['data'];
Assert::same([1, 2, 3], array_column($nested[0]['orders']['data'], 'id'), 'a page, as at the root');
Assert::same(['data', 'total', 'page', 'pageSize', 'from', 'to'], array_keys($nested[0]['orders']));
Assert::same(3, $nested[0]['orders']['total']);
Assert::same([['name' => 'apple'], ['name' => 'pear']], $nested[0]['orders']['data'][0]['items']['data']);
Assert::same([], $nested[0]['orders']['data'][2]['items']['data']);
Assert::same(0, $nested[0]['orders']['data'][2]['items']['total']);
Assert::same([4], array_column($nested[1]['orders']['data'], 'id'));
Assert::same([['name' => 'apple']], $nested[1]['orders']['data'][0]['items']['data']);

Assert::same([1, 2], array_column($run(['select' => 'activeOrders(id)', 'pageSize' => 1], of: TreeUser::class)['data'][0]['activeOrders']['data'], 'id'), 'the condition of a relation holds');

// Criteria of nested collections, with more parents than one
$sorted = $run(['select' => 'id,orders(id)', 'pageSize' => 2, 'criteria' => ['orders' => ['sort' => [['field' => 'id', 'dir' => 'desc']]]]], of: TreeUser::class)['data'];
Assert::same([3, 2, 1], array_column($sorted[0]['orders']['data'], 'id'));

$paged = $run(['select' => 'id,orders(id)', 'pageSize' => 2, 'criteria' => ['orders' => ['pageSize' => 2, 'page' => 1]]], of: TreeUser::class)['data'];
Assert::same([1, 2], array_column($paged[0]['orders']['data'], 'id'), 'a limit per parent');
Assert::same(3, $paged[0]['orders']['total'], 'and the total of that parent');
Assert::same([4], array_column($paged[1]['orders']['data'], 'id'));
Assert::same(1, $paged[1]['orders']['total']);

$secondPage = $run(['select' => 'id,orders(id)', 'pageSize' => 2, 'criteria' => ['orders' => ['pageSize' => 2, 'page' => 2]]], of: TreeUser::class)['data'];
Assert::same([3], array_column($secondPage[0]['orders']['data'], 'id'), 'an offset too, which Nette loses');
Assert::same(2, $secondPage[0]['orders']['page']);
Assert::same(2, $secondPage[0]['orders']['from']);
Assert::same([], $secondPage[1]['orders']['data']);
Assert::null($secondPage[1]['orders']['from']);

$nestedFilter = $run(['select' => 'id,orders(id)', 'pageSize' => 2, 'criteria' => ['orders' => ['filter' => ['field' => 'status', 'value' => 'paid']]]], of: TreeUser::class)['data'];
Assert::same([2], array_column($nestedFilter[0]['orders']['data'], 'id'));
Assert::same([4], array_column($nestedFilter[1]['orders']['data'], 'id'));

$deep = $run(['select' => 'id,orders(id,items(id))', 'pageSize' => 1, 'criteria' => ['orders/items' => ['filter' => ['field' => 'name', 'value' => 'pear']]]], of: TreeUser::class)['data'][0];
Assert::same([['id' => 2]], $deep['orders']['data'][0]['items']['data']);

Assert::same(['criteria.orders' => 'is not a selected collection.'], $errors(['select' => 'id', 'criteria' => ['orders' => ['pageSize' => 1]]], of: TreeUser::class));
Assert::same(['criteria.role' => 'is not a selected collection.'], $errors(['select' => 'role(id)', 'criteria' => ['role' => ['pageSize' => 1]]], of: TreeUser::class));
Assert::same(['orders/filter.nothing' => $secret], $errors(['select' => 'orders(id)', 'criteria' => ['orders' => ['filter' => ['field' => 'nothing', 'value' => 1]]]], of: TreeUser::class));

// Criteria of what wasn't reached are still checked
Assert::same(['orders/sort.nothing' => $secret], $errors(['select' => 'orders(id)', 'filter' => ['field' => 'id', 'value' => 999], 'criteria' => ['orders' => ['sort' => [['field' => 'nothing']]]]], of: TreeUser::class));

// --- Filtering and sorting through relations

Assert::same([1, 4], $ids($run(['select' => 'id', 'filter' => ['field' => 'role.name', 'value' => 'admin']], of: TreeUser::class)));
Assert::same([1, 4], $ids($run(['select' => 'id', 'filter' => ['field' => 'role.name', 'operator' => 'contains', 'value' => 'ADM']], of: TreeUser::class)));

$paid = $run(['select' => 'id', 'filter' => ['field' => 'orders.status', 'operator' => 'in', 'value' => ['new', 'paid']]], of: TreeUser::class);
Assert::same([1, 2], $ids($paid), 'a record with several matches comes once');
Assert::same(2, $paid['total'], 'and counts once');

Assert::same([1], $ids($run(['select' => 'id', 'filter' => ['field' => 'orders.items.name', 'value' => 'pear']], of: TreeUser::class)));
Assert::same([1, 2], $ids($run(['select' => 'id', 'filter' => ['field' => ':orders.items.name', 'value' => 'apple']], of: TreeUser::class)), 'as older clients write it');

Assert::same([], $ids($run(['select' => 'id', 'filter' => ['field' => 'activeOrders.status', 'value' => 'cancelled']], of: TreeUser::class)), 'the condition of a relation holds in a filter too');
Assert::same([1], $ids($run(['select' => 'id', 'filter' => ['field' => 'orders.status', 'value' => 'cancelled']], of: TreeUser::class)));

Assert::contains('name a field', $errors(['filter' => ['field' => 'role', 'value' => 1]], of: TreeUser::class)['filter.role']);
Assert::same(['filter.email.x' => $secret], $errors(['filter' => ['field' => 'email.x', 'value' => 1]], of: TreeUser::class));
Assert::same(['filter.role.nothing' => $secret], $errors(['filter' => ['field' => 'role.nothing', 'value' => 1]], of: TreeUser::class));

$byRole = $ids($run(['select' => 'id', 'sort' => [['field' => 'role.name', 'dir' => 'desc'], ['field' => 'id']], 'filter' => ['field' => 'role.name', 'operator' => 'isnotnull']], of: TreeUser::class));
Assert::same([2, 1, 4], $byRole, 'sorted through a relation');
Assert::contains('collection', $errors(['sort' => [['field' => 'orders.id']]], of: TreeUser::class)['sort.orders.id']);
Assert::contains('can not be sorted', $errors(['sort' => [['field' => 'role']]], of: TreeUser::class)['sort.role']);

// A write-only column can't be reached through a relation either
Assert::same(['filter.user.passwordHash' => $secret], (function () use ($processor, $explorer) {
    $e = Assert::exception(static fn() => $processor->process($explorer->table('orders'), Query::create(['filter' => ['field' => 'user.passwordHash', 'value' => 'x']]), TreeOrder::class), QueryException::class);
    return $e->getData()['fields'];
})());
Assert::same(['select.user.passwordHash' => $secret], (function () use ($processor, $explorer) {
    $e = Assert::exception(static fn() => $processor->process($explorer->table('orders'), Query::create(['select' => 'user(passwordHash)']), TreeOrder::class), QueryException::class);
    return $e->getData()['fields'];
})());

// --- Depth

Assert::contains('nested too deep', $errors(['select' => 'orders(user(orders(user(id))))'], of: TreeUser::class)['select.orders.user.orders.user']);
Assert::contains('too many relations', $errors(['filter' => ['field' => 'orders.user.orders.user.id', 'value' => 1]], of: TreeUser::class)['filter.orders.user.orders.user.id']);

// --- Search

Assert::same([1], $ids($run(['select' => 'id', 'search' => 'ann'])));
Assert::same([1], $ids($run(['select' => 'id', 'search' => 'NOVAK ANN'])), 'words in any order');
Assert::same([], $ids($run(['select' => 'id', 'search' => 'ann smith'])), 'all words in one column');
Assert::same([2], $ids($run(['select' => 'id', 'search' => 'bob 100%'])));
Assert::same(1, $run(['select' => 'id', 'search' => 'ann', 'pageSize' => 1])['total']);

$explorerRoles = $processor->process($explorer->table('roles'), Query::create(['select' => 'id', 'search' => 'adm']));
Assert::same([1], array_column($explorerRoles['data'], 'id'));

$e = Assert::exception(static fn() => $processor->process($explorer->table('orders'), Query::create(['search' => 'x'])), QueryException::class);
Assert::same(['search' => 'is not available here.'], $e->getData()['fields'], 'nothing to search in');

// --- Limits of a response

$tight = new Processor(new QueryConfig(maxPageSize: 2, maxDepth: 1, maxRows: 10));
$tightRun = static fn(array $query, mixed ...$options): ?array => $tight->process($users(), Query::create($query), ...$options);

Assert::same(2, $tightRun(['select' => 'id', 'pageSize' => 100])['pageSize']);
Assert::same([1, 2], array_column($tightRun(['select' => 'id'])['data'], 'id'));
Assert::same(2, count($tightRun(['select' => 'orders(id)'], of: TreeUser::class)['data'][0]['orders']['data']), 'nested collections are cut as well');

$e = Assert::exception(static fn() => (new Processor(new QueryConfig(maxRows: 3)))->process($users(), Query::create(['select' => 'id'])), QueryException::class);
Assert::contains('too many rows', $e->getData()['fields']['select']);

$e = Assert::exception(static fn() => (new Processor(new QueryConfig(maxRows: 5)))->process($users(), Query::create(['select' => 'id,orders(id)']), of: TreeUser::class), QueryException::class);
Assert::contains('too many rows', $e->getData()['fields']['select.orders'], 'counted before they are loaded');

$e = Assert::exception(static fn() => $tightRun(['select' => 'orders(user(id))'], of: TreeUser::class), QueryException::class);
Assert::contains('nested too deep', $e->getData()['fields']['select.orders.user']);

// What the application decides
$export = (new Processor(new QueryConfig(maxPageSize: 2, maxRows: 1)))->process($users(), Query::create(['select' => 'id,orders(id)', 'pageSize' => 1]), of: TreeUser::class, skipLimitOffset: true);
Assert::true(array_is_list($export), 'no limit means a list');
Assert::count(4, $export);
Assert::count(2, $export[0]['orders']['data'], 'true is about the root, what is nested keeps its limit');
Assert::same(3, $export[0]['orders']['total']);

$partly = (new Processor(new QueryConfig(maxPageSize: 2)))->process($users(), Query::create(['select' => 'id,orders(id)']), of: TreeUser::class, skipLimitOffset: ['orders']);
Assert::count(2, $partly['data'], 'the root has its limit');
Assert::count(3, $partly['data'][0]['orders'], 'the collection does not, and it is a list');

$rootOnly = (new Processor(new QueryConfig(maxPageSize: 2)))->process($users(), Query::create(['select' => 'id,orders(id)']), of: TreeUser::class, skipLimitOffset: ['@']);
Assert::count(4, $rootOnly);
Assert::count(2, $rootOnly[0]['orders']['data']);

$dotted = (new Processor(new QueryConfig(maxPageSize: 1)))->process($users()->get(1), Query::create(['select' => 'orders(items(name))']), of: TreeUser::class, skipLimitOffset: ['orders', 'orders.items']);
Assert::count(3, $dotted['orders'], 'a path written with dots');
Assert::same([['name' => 'apple'], ['name' => 'pear']], $dotted['orders'][0]['items']);

// --- One record, and none

$one = $processor->process($users()->get(1), Query::create(['select' => 'id,email,role(name),orders(id)', 'criteria' => ['orders' => ['pageSize' => 1, 'sort' => [['field' => 'id', 'dir' => 'desc']]]]]), of: TreeUser::class);
Assert::same('ann@example.com', $one['email']);
Assert::same(['name' => 'admin'], $one['role']);
Assert::same([['id' => 3]], $one['orders']['data']);
Assert::same(3, $one['orders']['total']);
Assert::same(1, $one['orders']['pageSize']);
Assert::same('ann@example.com', $processor->process($users()->get(1))['email'], 'no query means everything allowed');
Assert::false(array_key_exists('passwordHash', $processor->process($users()->get(1))));
Assert::null($processor->process(null, Query::create()));
Assert::same(['id' => 1], $processor->process($users()->get(1), Query::create(['select' => 'id'])));

// --- A query narrows what the application prepared

Assert::same([1], $ids($processor->process($users()->where('id', [1, 2]), Query::create(['select' => 'id', 'filter' => ['field' => 'isActive', 'value' => true]]))));
Assert::same(1, $processor->process($users()->where('id', [1, 2]), Query::create(['select' => 'id', 'filter' => ['field' => 'isActive', 'value' => true]]))['total']);

// --- Events

$events = new Processor();
$events->onSelection[] = static function (ProcessSelectionEventData $event): void {
    if ($event->path === []) {
        $event->selection->where('users.id < ?', 3); // access control
    }
    if ($event->path === ['orders']) {
        $event->selection->where('status <> ?', 'new');
    }
};
$acl = $events->process($users(), Query::create(['select' => 'id,orders(id)']), of: TreeUser::class);
Assert::same(2, $acl['total'], 'conditions of the event count in the total');
Assert::same([1, 2], $ids($acl));
Assert::same([2, 3], array_column($acl['data'][0]['orders']['data'], 'id'), 'also on nested collections');

$skip = new Processor();
$skip->onSelection[] = static function (ProcessSelectionEventData $event): void {
    $event->shouldSkip = $event->path === ['orders'];
};
$skipped = $skip->process($users(), Query::create(['select' => 'id,orders(id)', 'pageSize' => 1]), of: TreeUser::class)['data'][0];
Assert::same(['id' => 1], $skipped, 'a collection left out');

$skipRoot = new Processor();
$skipRoot->onSelection[] = static function (ProcessSelectionEventData $event): void {
    $event->shouldSkip = true;
};
Assert::null($skipRoot->process($users(), Query::create(['select' => 'id'])));

$entityEvents = new Processor();
$entityEvents->onEntity[] = static function (ProcessEntityEventData $event): void {
    $event->shouldSkip = $event->path === [] && $event->entity->id === 2;
};
$entityEvents->onProcessedEntity[] = static function (ProcessedEntityEventData $event): void {
    if ($event->path === []) {
        $event->output['extra'] = $event->entity->id * 2;
        unset($event->output['email']);
        $event->shouldSkip = $event->entity->id === 4;
    }
};
$changed = $entityEvents->process($users(), Query::create(['select' => 'id,email']));
Assert::same([['id' => 1, 'extra' => 2], ['id' => 3, 'extra' => 6]], $changed['data']);

$searchEvent = new Processor();
$searchEvent->onSearch[] = static function (SearchEventData $event): void {
    Assert::same('users', $event->table);
    $event->columns = ['email'];
};
Assert::same([], array_column($searchEvent->process($users(), Query::create(['select' => 'id', 'search' => 'novak']))['data'], 'id'), 'the columns changed');

// --- Resources

$resource = static fn(array $query): ?array => $processor->process($users(), Query::create($query), UserResource::class);
$resourceErrors = static function (array $query) use ($resource): array {
    $e = Assert::exception(static fn() => $resource($query), QueryException::class);
    return $e->getData()['fields'];
};

Assert::same(['id' => 1, 'login' => 'ann@example.com', 'displayName' => 'Ann Novak'], $resource(['pageSize' => 1])['data'][0], 'its names, and only its fields');
Assert::same(['login' => 'bob@example.com'], $resource(['select' => 'login', 'page' => 2, 'pageSize' => 1])['data'][0]);

Assert::same([1], array_column($resource(['select' => 'id', 'filter' => ['field' => 'login', 'value' => 'ann@example.com']])['data'], 'id'), 'filters by its name');
Assert::same([4, 3, 2, 1], array_column($resource(['select' => 'id', 'sort' => [['field' => 'login', 'dir' => 'desc']]])['data'], 'id'));

// What it doesn't declare isn't there, even though the entity has it
foreach (['email', 'fullName', 'age', 'passwordHash', 'createdAt', 'orders'] as $hidden) {
    Assert::same(["select.{$hidden}" => $secret], $resourceErrors(['select' => $hidden]), $hidden);
    Assert::same(["filter.{$hidden}" => $secret], $resourceErrors(['filter' => ['field' => $hidden, 'value' => 'x']]), $hidden);
    Assert::same(["sort.{$hidden}" => $secret], $resourceErrors(['sort' => [['field' => $hidden]]]), $hidden);
}

Assert::contains('can not be filtered', $resourceErrors(['filter' => ['field' => 'displayName', 'value' => 'x']])['filter.displayName']);
Assert::contains('can not be sorted', $resourceErrors(['sort' => [['field' => 'displayName']]])['sort.displayName']);

// Search looks only where it says
Assert::same([1], array_column($resource(['select' => 'id', 'search' => 'ann@'])['data'], 'id'));
Assert::same([], $resource(['select' => 'id', 'search' => 'novak'])['data'], 'the name is not searchable here');

// Its relations, with their names and fields
$tree = $resource(['select' => 'id,role(name),purchases(id,items(name))', 'pageSize' => 1])['data'][0];
Assert::same(['name' => 'admin'], $tree['role']);
Assert::same([1, 2], array_column($tree['purchases']['data'], 'id'), 'a renamed relation, with its condition');
Assert::same([['name' => 'apple'], ['name' => 'pear']], $tree['purchases']['data'][0]['items']['data']);

Assert::same(['select.purchases.total' => $secret], $resourceErrors(['select' => 'purchases(total)']), 'the fields of the related resource');
Assert::same(['filter.purchases.total' => $secret], $resourceErrors(['filter' => ['field' => 'purchases.total', 'value' => 1]]));
Assert::same([1, 4], array_column($resource(['select' => 'id', 'filter' => ['field' => 'role.name', 'value' => 'admin']])['data'], 'id'));
Assert::same([1], array_column($resource(['select' => 'id', 'filter' => ['field' => 'purchases.items.name', 'value' => 'pear']])['data'], 'id'));
Assert::false(array_key_exists('role', $resource(['pageSize' => 1])['data'][0]), 'a star leaves relations out');

// Mistakes in a resource are the developer's
Assert::exception(static fn() => $processor->process($users(), Query::create(), BadField::class), LogicException::class, "%A%'nothingLikeThat'%A%");
Assert::exception(static fn() => $processor->process($users(), Query::create(), BadSecret::class), LogicException::class, "%A%'passwordHash'%A%");
Assert::exception(static fn() => $processor->process($users(), Query::create(), BadRelation::class), LogicException::class, '%A%is a relation%A%');
Assert::exception(static fn() => $processor->process($users(), Query::create(), BadScalar::class), LogicException::class, '%A%not a relation%A%');
Assert::exception(static fn() => $processor->process($explorer->table('roles'), Query::create(), UserResource::class), LogicException::class, '%A%was given%A%');
Assert::exception(static fn() => $processor->process($users(), Query::create(['select' => 'role']), WrongTarget::class), LogicException::class, "%A%leads to Phlox\\ApiQuery\\Tests\\Fixtures\\Entity\\Role, but its type is a #[Resource] of Phlox\\ApiQuery\\Tests\\Fixtures\\Entity\\Item.");
Assert::exception(static fn() => $processor->process($users(), Query::create(), stdClass::class), LogicException::class, '%A%neither an entity%A%');
