<?php

/**
 * Queries over the result of raw SQL, on a real database.
 */

declare(strict_types=1);

use Nette\Database\Connection;
use Phlox\ApiQuery\Events\ProcessedRowEventData;
use Phlox\ApiQuery\Fields\Field;
use Phlox\ApiQuery\Fields\SqlField;
use Phlox\ApiQuery\Query;
use Phlox\ApiQuery\QueryConfig;
use Phlox\ApiQuery\QueryException;
use Phlox\ApiQuery\SqlProcessor;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$container = createContainer();
$processor = $container->getByType(SqlProcessor::class);

define('Report', sprintf(
    'SELECT u.%s AS %s, u.%s AS %s, u.%s AS %s, u.%s, u.%s, r.%s AS %s FROM %s u LEFT JOIN %s r ON r.%s = u.%s WHERE u.%s < ?',
    q('id'), q('user_id'), q('email'), q('mail'), q('password_hash'), q('secret'), q('age'), q('created_at'), q('name'), q('role_name'),
    q('users'), q('roles'), q('id'), q('role_id'), q('id'),
));

$fields = [
    'id' => new SqlField('user_id', Field::Integer),
    'email' => new SqlField('mail', searchable: true),
    'age' => new SqlField('age', Field::Integer),
    'created' => new SqlField('created_at', Field::DateTime),
    'role' => 'role_name',
];

$run = static fn(array $query = [], mixed ...$options): array => $processor->process(Report, [100], Query::create($query), $fields, ...$options);
$ids = static fn(array $result): array => array_column($result['data'], 'id');

$errors = static function (array $query) use ($run): array {
    $e = Assert::exception(static fn() => $run($query), QueryException::class);
    Assert::same(400, $e->getHttpCode());
    return $e->getData()['fields'];
};

// --- The shape

$all = $run(['sort' => [['field' => 'id']]]);
Assert::same(['data', 'total', 'page', 'pageSize', 'from', 'to'], array_keys($all));
Assert::same(4, $all['total']);
Assert::same(['id', 'email', 'age', 'created', 'role'], array_keys($all['data'][0]), 'the names given to it');
Assert::same('admin', $all['data'][0]['role']);

// A column left out of the fields stays hidden
Assert::false(array_key_exists('secret', $all['data'][0]));

// --- Select, sort, page

Assert::same(['email' => 'ann@example.com'], $run(['select' => 'email', 'sort' => [['field' => 'id']], 'pageSize' => 1])['data'][0]);

$page = $run(['select' => 'id', 'sort' => [['field' => 'id', 'dir' => 'desc']], 'page' => 2, 'pageSize' => 2]);
Assert::same([2, 1], $ids($page));
Assert::same(2, $page['page']);
Assert::same(2, $page['from']);
Assert::same(3, $page['to']);

$kendo = $run(['select' => 'id', 'sort' => [['field' => 'id']], 'take' => 1, 'skip' => 2]);
Assert::same([3], $ids($kendo));

Assert::count(4, $run(['select' => 'id'])['data'], 'no order given');
Assert::same(4, $processor->process(Report, [100], null, $fields)['total'], 'no query at all');

$export = (new SqlProcessor($container->getByType(Connection::class), new QueryConfig(maxPageSize: 1)))->process(Report, [100], Query::create(['sort' => [['field' => 'id']]]), $fields, skipLimitOffset: true);
Assert::count(4, $export);
Assert::true(array_is_list($export), 'no limit means a list');

$cut = (new SqlProcessor($container->getByType(Connection::class), new QueryConfig(maxPageSize: 1)))->process(Report, [100], Query::create(['pageSize' => 50, 'sort' => [['field' => 'id']]]), $fields);
Assert::count(1, $cut['data']);
Assert::same(1, $cut['pageSize']);

// --- The parameters of the query, and of the filter after them

Assert::same([1], $ids($processor->process(Report, [2], Query::create(['select' => 'id']), $fields)));
Assert::same([1], $ids($processor->process(Report, [3], Query::create(['select' => 'id', 'filter' => ['field' => 'age', 'operator' => 'gte', 'value' => 18]]), $fields)));

// --- Filters

$filter = static fn(string $field, string $operator, mixed $value = null): array => $ids($run([
    'select' => 'id',
    'sort' => [['field' => 'id']],
    'filter' => ['logic' => 'and', 'filters' => [['field' => $field, 'operator' => $operator, 'value' => $value]]],
]));

Assert::same([1], $filter('email', 'eq', 'ann@example.com'));
Assert::same([2], $filter('age', 'lt', 18));
Assert::same([1, 4], $filter('role', 'eq', 'admin'));
Assert::same([3], $filter('role', 'isnull'));
Assert::same([1], $filter('email', 'startswith', 'ANN'));
Assert::same([3], $filter('email', 'contains', 'l_x'));
Assert::same([], $filter('email', 'contains', 'n_n'));
Assert::same([1, 2], $filter('id', 'in', [1, 2]));
Assert::same([1], $filter('created', 'lt', '2024-06-01'));
Assert::same([3], $filter('created', 'today'));

Assert::same(1, $run(['select' => 'id', 'pageSize' => 1, 'filter' => ['field' => 'email', 'value' => 'ann@example.com']])['total']);

// --- Search

Assert::same([1], $ids($run(['select' => 'id', 'search' => 'ANN@'])));
Assert::same([2], $ids($processor->process(Report, [100], Query::create(['select' => 'id', 'search' => 'editor']), $fields, ['role'])), 'by the name of a field');
Assert::same([2], $ids($processor->process(Report, [100], Query::create(['select' => 'id', 'search' => 'editor']), ['id' => new SqlField('user_id', Field::Integer), 'role' => 'role_name'], ['role_name'])), 'or of its column, as it used to be');
Assert::same(['search' => 'is not available here.'], (function () use ($processor) {
    $e = Assert::exception(static fn() => $processor->process(Report, [100], Query::create(['search' => 'x']), ['id' => 'user_id']), QueryException::class);
    return $e->getData()['fields'];
})());
Assert::exception(static fn() => $processor->process(Report, [100], null, ['id' => 'user_id'], ['nothing']), LogicException::class, "Searchable 'nothing' %A%");

// --- Only the fields given to it, wherever they're asked for

$unknown = 'is not a known field.';
foreach (['secret', 'user_id', 'mail', 'nothing'] as $name) {
    Assert::same(["select.{$name}" => $unknown], $errors(['select' => $name]), $name);
    Assert::same(["filter.{$name}" => $unknown], $errors(['filter' => ['field' => $name, 'value' => 'x']]), $name);
    Assert::same(["sort.{$name}" => $unknown], $errors(['sort' => [['field' => $name]]]), $name);
}

// The old processor put these right into the SQL
foreach (['id = 1 OR 1=1 --', 'user_id; DROP TABLE users', 'LOWER(mail)', '(SELECT password_hash FROM users)', '_view_.secret'] as $attack) {
    Assert::same(["filter.{$attack}" => $unknown], $errors(['filter' => ['field' => $attack, 'value' => 1]]), $attack);
    Assert::same(["sort.{$attack}" => $unknown], $errors(['sort' => [['field' => $attack]]]), $attack);
}
Assert::same(4, $run(['select' => 'id'])['total'], 'still there');

Assert::same(['filter.age' => 'must be a number.'], $errors(['filter' => ['field' => 'age', 'value' => 'abc']]));
Assert::same(['select.id' => 'is not a relation, so it has nothing to select from.'], $errors(['select' => 'id(x)']));
Assert::same(['criteria.orders' => 'is not a selected collection.'], $errors(['criteria' => ['orders' => ['page' => 1]]]));
Assert::same(['filter.role.name' => $unknown], $errors(['filter' => ['field' => 'role.name', 'value' => 1]]), 'no relations here');

Assert::equal(
    ['select.a' => $unknown, 'sort.c' => $unknown, 'filter.b' => $unknown],
    $errors(['select' => 'a', 'filter' => ['field' => 'b', 'value' => 1], 'sort' => [['field' => 'c']]]),
);

// --- Events

$events = new SqlProcessor($container->getByType(Connection::class));
$events->onProcessedRow[] = static function (ProcessedRowEventData $event): void {
    $event->shouldSkip = $event->output['id'] === 2;
    $event->output['upper'] = strtoupper((string) $event->row['mail']);
};
$changed = $events->process(Report, [3], Query::create(['select' => 'id,email', 'sort' => [['field' => 'id']]]), $fields);
Assert::same([['id' => 1, 'email' => 'ann@example.com', 'upper' => 'ANN@EXAMPLE.COM']], $changed['data']);

// --- A date's form

$dated = $run(['select' => 'created', 'sort' => [['field' => 'id']], 'pageSize' => 1])['data'][0]['created'];
Assert::contains('2024-01-02', (string) $dated);
