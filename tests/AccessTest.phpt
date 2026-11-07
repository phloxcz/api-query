<?php

/**
 * Resources found in their namespace: an entity shown as its resource wherever it's
 * reached, what a viewer can read held everywhere, and fields computed with services.
 */

declare(strict_types=1);

use Phlox\ApiQuery\Processor;
use Phlox\ApiQuery\Query;
use Phlox\ApiQuery\QueryConfig;
use Phlox\ApiQuery\QueryException;
use Phlox\ApiQuery\Tests\Fixtures\Access\OrderResource;
use Phlox\ApiQuery\Tests\Fixtures\Access\OrderSummary;
use Phlox\ApiQuery\Tests\Fixtures\Access\Viewer;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Order;
use Phlox\EntityDatabase\Database\Explorer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/Access/Resources.php';

$container = createContainer(<<<'NEON'
apiQuery:
    resources: Phlox\ApiQuery\Tests\Fixtures\Access

services:
    - Phlox\ApiQuery\Tests\Fixtures\Access\Viewer
NEON);

$explorer = $container->getByType(Explorer::class);
$processor = $container->getByType(Processor::class);
$viewer = $container->getByType(Viewer::class);

$run = static fn(string $table, array $query = [], ?string $of = null): ?array => $processor->process($explorer->table($table), Query::create($query), $of);
$ids = static fn(?array $result): array => array_column($result['data'] ?? [], 'id');
$errors = static fn(callable $call): array => Assert::exception($call, QueryException::class)->getData()['fields'];
$secret = 'is not a known field.';

// --- Found in the namespace, and standing for their entities with no attribute

Assert::same(['id', 'email'], array_keys($run('users', ['pageSize' => 1])['data'][0]), 'the resource of users, not the entity');
Assert::same(['select.fullName' => $secret], $errors(static fn() => $run('users', ['select' => 'fullName'])));
Assert::same(['id', 'total'], array_keys($run('orders', ['pageSize' => 1], OrderSummary::class)['data'][0]), 'another view, where it is named');

// Each processor has its own resources: another one doesn't change those of the first
$plain = new Processor(new QueryConfig(), $container);
Assert::true(in_array('fullName', array_keys($plain->process($explorer->table('users'), Query::create(['pageSize' => 1]))['data'][0]), true), 'with none, the entity by default');
Assert::same(['id', 'email'], array_keys($run('users', ['pageSize' => 1])['data'][0]), 'the first one still has its own');

// An entity without a resource: all of its columns, and no relations
Assert::same(['id', 'name'], array_keys($run('roles', ['pageSize' => 1])['data'][0]));
Assert::same(['select.users' => $secret], $errors(static fn() => $run('roles', ['select' => 'users'])));
Assert::same(['name' => 'admin'], $run('users', ['select' => 'role(name)', 'pageSize' => 1])['data'][0]['role'], 'reached through a resource');

// --- What a viewer can read holds everywhere

$orders = $run('orders', ['select' => 'id']);
Assert::same([1, 2, 3], $ids($orders), 'at the root');
Assert::same(3, $orders['total'], 'in the total');

$users = $run('users', ['select' => 'id,orders(id)', 'pageSize' => 2])['data'];
Assert::same([1, 2, 3], array_column($users[0]['orders']['data'], 'id'), 'in a collection');
Assert::same([], $users[1]['orders']['data'], 'the order of someone else is not there');
Assert::same(0, $users[1]['orders']['total']);

$items = $run('items', ['select' => 'id,order(id)', 'sort' => [['field' => 'id']]])['data'];
Assert::same([['id' => 1], ['id' => 1], ['id' => 2], null], array_column($items, 'order'), 'a single record is null, as if there was none');

Assert::same([1], $ids($run('users', ['select' => 'id', 'filter' => ['field' => 'orders.status', 'value' => 'paid']])), 'in a filter through a relation');
Assert::same([3], $ids($run('items', ['select' => 'id', 'filter' => ['field' => 'order.status', 'value' => 'paid']])));
Assert::same([], $ids($run('items', ['select' => 'id', 'filter' => ['field' => 'order.total', 'operator' => 'gt', 'value' => 30]])), 'the total of what one can not read is not told either');

$byTotal = $ids($run('items', ['select' => 'id', 'sort' => [['field' => 'order.total', 'dir' => 'desc'], ['field' => 'id']]]));
Assert::count(4, $byTotal, 'in a sort, what one can not read sorts as nothing');
Assert::same([3, 1, 2], array_values(array_intersect($byTotal, [1, 2, 3])));

$viewer->userId = 2;
Assert::same([4], $ids($run('orders', ['select' => 'id'])), 'by a service, read as the query runs');
Assert::same([4], $ids($run('items', ['select' => 'id', 'filter' => ['field' => 'order.status', 'value' => 'paid']])));
$viewer->userId = 1;

// One record, as a detail gets it
$detail = $processor->process($explorer->table('users')->get(2), Query::create(['select' => 'id,orders(id)']));
Assert::same([], $detail['orders']['data']);
Assert::same(['id' => 4, 'order' => null], $processor->process($explorer->table('items')->get(4), Query::create(['select' => 'id,order(id)'])));

// --- A computed field, with a service, only when it's asked for

OrderResource::$computed = 0;
Assert::same(['id' => 1], $run('orders', ['select' => 'id', 'pageSize' => 1])['data'][0]);
Assert::same(0, OrderResource::$computed, 'not computed when not selected');
Assert::same(['id' => 1, 'mine' => true], $run('orders', ['select' => 'id,mine', 'pageSize' => 1])['data'][0]);
Assert::same(1, OrderResource::$computed);
Assert::true($run('orders', ['pageSize' => 1])['data'][0]['mine'], 'a star means it too');
Assert::contains('can not be filtered', $errors(static fn() => $run('orders', ['filter' => ['field' => 'mine', 'value' => true]]))['filter.mine']);

// A batch: one call for all the records, by their primary key
OrderResource::$batches = [];
Assert::same([['id' => 1, 'itemCount' => 2], ['id' => 2, 'itemCount' => 1], ['id' => 3, 'itemCount' => 0]], $run('orders', ['select' => 'id,itemCount'])['data']);
Assert::same([[1, 2, 3]], OrderResource::$batches, 'one call for the page');

OrderResource::$batches = [];
$itemOrders = array_column($run('items', ['select' => 'id,order(itemCount)', 'sort' => [['field' => 'id']]])['data'], 'order');
Assert::same([['itemCount' => 2], ['itemCount' => 2], ['itemCount' => 1], null], $itemOrders);
Assert::same([[1, 1, 2]], OrderResource::$batches, 'one call for the single records of all parents');

OrderResource::$batches = [];
Assert::same(['itemCount' => 2], $processor->process($explorer->table('orders')->get(1), Query::create(['select' => 'itemCount'])));
Assert::same([[1]], OrderResource::$batches, 'a detail is a batch of one');

OrderResource::$batches = [];
$run('orders', ['select' => 'id']);
Assert::same([], OrderResource::$batches, 'not computed when not selected');

// Services come from the container
$alone = new Processor(new QueryConfig(resources: [Order::class => OrderResource::class]));
Assert::exception(
    static fn() => $alone->process($explorer->table('orders'), Query::create(['select' => 'id'])),
    LogicException::class,
    'A method of a resource asks for services, so the Processor needs the DI container.%a%',
);
