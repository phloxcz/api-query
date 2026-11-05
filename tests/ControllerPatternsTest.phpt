<?php

/**
 * What real controllers do with queries: passing a missing one on, changing it,
 * reading anything of an entity in an event, using the selection afterwards.
 */

declare(strict_types=1);

use Phlox\ApiCore\Documentation\SchemaBuilder;
use Phlox\ApiCore\Types\Type;
use Phlox\ApiQuery\Attributes\QueryOf;
use Phlox\ApiQuery\Attributes\QueryOfSql;
use Phlox\ApiQuery\Events\ProcessedEntityEventData;
use Phlox\ApiQuery\Events\ProcessSelectionEventData;
use Phlox\ApiQuery\Fields\Field;
use Phlox\ApiQuery\Fields\FieldMap;
use Phlox\ApiQuery\Fields\SqlField;
use Phlox\ApiQuery\Processor;
use Phlox\ApiQuery\Query;
use Phlox\ApiQuery\QueryConfig;
use Phlox\ApiQuery\QueryException;
use Phlox\ApiQuery\Sort;
use Phlox\ApiQuery\SqlProcessor;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Order;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Role;
use Phlox\ApiQuery\Tests\Fixtures\Entity\User;
use Phlox\ApiQuery\Tests\Fixtures\Resources\BadComputed;
use Phlox\ApiQuery\Tests\Fixtures\Resources\ComputedResource;
use Phlox\ApiQuery\Tests\Fixtures\Resources\NoFilterThroughRelation;
use Phlox\EntityDatabase\Database\Explorer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/Resources.php';

$container = createContainer(<<<'NEON'
apiQuery:
    entities:
        Phlox\ApiQuery\Tests\Fixtures\Entity\User:
            relations: [role, orders, activeOrders]
            computed:
                canManage: bool
        Phlox\ApiQuery\Tests\Fixtures\Entity\Order:
            relations: [user, items]
        Phlox\ApiQuery\Tests\Fixtures\Entity\Role:
            relations: [users]
NEON);

$explorer = $container->getByType(Explorer::class);
$users = static fn() => $explorer->table('users');
$fieldsOf = static function (callable $call): array {
    $e = Assert::exception($call, QueryException::class);
    return $e->getData()['fields'];
};

$entities = $container->getService('apiQuery.config')->entities;
$create = static fn(mixed ...$config): Processor => new Processor(new QueryConfig(...$config, entities: $entities));

$processor = $create();

// --- Relations for the whole application, so that controllers need no attribute

$tree = $processor->process($users(), Query::create(['select' => 'email,role(name),orders(status,items(name))', 'pageSize' => 1]))['data'][0];
Assert::same('admin', $tree['role']['name']);
Assert::same('apple', $tree['orders']['data'][0]['items']['data'][0]['name'], 'each level by what its entity allows');

$around = $processor->process($users(), Query::create(['select' => 'orders(user(role(name)))', 'pageSize' => 1]))['data'][0];
Assert::same('admin', $around['orders']['data'][0]['user']['role']['name'], 'relations can go in a circle');

Assert::same(['select.secrets' => 'is not a known field.'], $fieldsOf(static fn() => $processor->process($users(), Query::create(['select' => 'secrets']))));
Assert::same(['select.orders.user.orders.user' => 'is nested too deep, at most 3 levels are allowed.'], $fieldsOf(static fn() => $processor->process($users(), Query::create(['select' => 'orders(user(orders(user(id))))']))));

// A list given here replaces them
Assert::same(['select.role' => 'is not a known field.'], $fieldsOf(static fn() => $processor->process($users(), Query::create(['select' => 'role(id)']), relations: ['orders'])));

// --- A missing query, also one passed on to another method

Assert::same(4, $processor->process($users(), null)['total']);
Assert::same(4, $processor->process($users())['total']);
Assert::same('ann@example.com', $processor->process($users()->get(1), null)['email']);

// A record reached through another one
Assert::same(1, $processor->process($explorer->table('orders')->get(1)->user, null)['id']);
Assert::null($processor->process($explorer->table('users')->get(3)->role, null), 'a relation with no record');

// --- Changing the query: a sort the application handles on its own, by an expression

$query = Query::create(['select' => 'id', 'sort' => [['field' => 'modifiedAt', 'dir' => 'desc'], ['field' => 'id', 'dir' => 'desc']]]);
$selection = $users();
$criteria = $query->getCriteriaByPath();
$remaining = [];
foreach ($criteria->getSort() as $sort) {
    /** @var Sort $sort */
    if ($sort->field === 'modifiedAt') {
        Assert::same('desc', $sort->dir);
        $selection->order('COALESCE(' . q('age') . ', 0) ' . strtoupper($sort->dir));
        continue;
    }
    $remaining[] = $sort;
}
$criteria->setSort($remaining);
Assert::same([4, 1, 2, 3], array_column($processor->process($selection, $query)['data'], 'id'), 'an order of its own, then the rest');

$changed = Query::create(['select' => 'id']);
Assert::same(['id', 'email'], array_keys($processor->process($users(), $changed->setSelect('id,email'))['data'][0]));
Assert::exception(static fn() => $changed->setSelect('a('), QueryException::class);

$getters = Query::create(['search' => 'x', 'page' => 2, 'pageSize' => 3, 'filter' => ['field' => 'a', 'value' => 1]])->getCriteriaByPath();
Assert::same(['x', 2, 3, 'a'], [$getters->getSearch(), $getters->getPage(), $getters->getPageSize(), $getters->getFilter()->filters[0]->field]);

// --- The selection is used afterwards, as a report does

$report = $users();
$processor->process($report, Query::create(['filter' => ['field' => 'isActive', 'value' => true]]), skipLimitOffset: true);
$read = [];
foreach ($report as $user) {
    $read[] = $user->id;
}
Assert::same([1, 3, 4], $read, 'the conditions of the query stay on it');

// --- skipLimitOffset gives lists, and a whole tree for offline use

$lots = $create(maxPageSize: 1);
$offline = $lots->process($users()->get(1), Query::create(['select' => '*,role(*),orders(*,user(*),items(*))']), skipLimitOffset: ['orders', 'orders.items']);
Assert::count(3, $offline['orders']);
Assert::count(2, $offline['orders'][0]['items']);
Assert::same('Ann Novak', $offline['orders'][0]['user']['fullName']);
foreach ($offline['orders'] as $order) {
    Assert::type('int', $order['id']); // walked as a plain list
}

// One record per parent doesn't count as rows, however many parents there are
Assert::count(2, $create(maxRows: 2)->process($users()->where('id', [1, 2]), Query::create(['select' => 'id,role(name)']))['data']);

// --- An event reads anything of an entity, also what the query didn't select

$seen = [];
$reading = $create();
$reading->onProcessedEntity[] = static function (ProcessedEntityEventData $event) use (&$seen): void {
    if ($event->entity instanceof User) {
        $seen[] = [$event->entity->age, $event->entity->roleId, $event->entity->role?->name];
    }
};
$reading->process($users()->where('id', [1, 3]), Query::create(['select' => 'id']));
Assert::same([[30, 1, 'admin'], [null, null, null]], $seen);

$seenNested = [];
$reading = $create();
$reading->onProcessedEntity[] = static function (ProcessedEntityEventData $event) use (&$seenNested): void {
    if ($event->entity instanceof Order) {
        $seenNested[] = $event->entity->status;
    }
};
$reading->process($users()->where('id', 2), Query::create(['select' => 'orders(id)']));
Assert::same(['paid'], $seenNested, 'in a collection too');

// --- set and unset, whatever was selected

$masking = $create();
$masking->onProcessedEntity[] = static function (ProcessedEntityEventData $event): void {
    if ($event->entity instanceof User) {
        $event->set['canManage'] = $event->entity->isActive;
        $event->set['email'] = '***';
        $event->unset[] = 'age';
    }
};
Assert::same(
    ['id' => 1, 'email' => '***', 'canManage' => true],
    $masking->process($users()->get(1), Query::create(['select' => 'id,email,age'])),
);

// What events add can be named, and the query can't use it
Assert::same(['email' => '***', 'canManage' => true], $masking->process($users()->get(1), Query::create(['select' => 'canManage,email'])));
Assert::same(['filter.canManage' => 'can not be filtered by.'], $fieldsOf(static fn() => $masking->process($users(), Query::create(['filter' => ['field' => 'canManage', 'value' => true]]))));

// --- Access control in an event

$acl = $create();
$acl->onSelection[] = static function (ProcessSelectionEventData $event): void {
    if ($event->selection->getEntityName() === Order::class) {
        $event->selection->where('1=2');
    }
    if ($event->selection->getEntityName() === User::class && $event->path === []) {
        $event->selection->where(':orders(user_id).status = ? OR users.id = ?', 'new', 3);
    }
};
$restricted = $acl->process($users(), Query::create(['select' => 'id,orders(id)']));
Assert::same([1, 3], array_column($restricted['data'], 'id'), 'a join of its own in a condition');
Assert::same(2, $restricted['total']);
Assert::same([], $restricted['data'][0]['orders']['data'], 'nothing allowed in a collection');
Assert::same(0, $restricted['data'][0]['orders']['total']);

// --- An event calling the processor doesn't disturb what's going on

$calling = $create();
$calling->onProcessedEntity[] = static function (ProcessedEntityEventData $event) use ($calling, $explorer): void {
    if ($event->entity instanceof User) {
        $event->set['roleCount'] = $calling->process($explorer->table('roles'), Query::create(['select' => 'id', 'pageSize' => 1]))['total'];
    }
};
$outer = $calling->process($users(), Query::create(['select' => 'id,orders(id)', 'pageSize' => 2, 'criteria' => ['orders' => ['pageSize' => 1]]]));
Assert::same([1], array_column($outer['data'][0]['orders']['data'], 'id'), 'the criteria of the outer query stay');
Assert::same(2, $outer['data'][0]['roleCount']);

// --- A relation which says it can't be filtered, can't be filtered through

$of = static fn(array $query): array => $fieldsOf(static fn() => $processor->process($users(), Query::create($query), NoFilterThroughRelation::class));
Assert::same(['filter.role.id' => 'can not be filtered by.'], $of(['filter' => ['field' => 'role.id', 'value' => 1]]));
Assert::same(['sort.role.id' => 'can not be sorted by.'], $of(['sort' => [['field' => 'role.id']]]));
Assert::same(['filter.role.name' => 'can not be filtered by.'], $of(['filter' => ['field' => 'role.name', 'value' => 'x']]));
Assert::same(['role' => ['id' => 1, 'name' => 'admin']], $processor->process($users(), Query::create(['select' => 'role', 'pageSize' => 1]), NoFilterThroughRelation::class)['data'][0], 'it can still be selected');
Assert::same(['id'], FieldMap::forResource(NoFilterThroughRelation::class)->getPaths(), 'and the documentation says so');

// --- Computed fields of a resource

$computedQuery = static fn(array $query, ?Processor $with = null): array => ($with ?? $processor)->process($users(), Query::create($query), ComputedResource::class)['data'][0];

Assert::same(['id' => 1, 'label' => 'ann@example.com (30)'], $computedQuery(['pageSize' => 1]), 'a method, reading what was not selected');
Assert::same(['label' => 'ann@example.com (30)'], $computedQuery(['select' => 'label', 'pageSize' => 1]));

$filling = $create();
$filling->onProcessedEntity[] = static function (ProcessedEntityEventData $event): void {
    $event->set['canManage'] = true;
};
Assert::same(['id' => 1, 'canManage' => true], $computedQuery(['select' => 'id,canManage', 'pageSize' => 1], $filling), 'or an event');
Assert::same(['id' => 1], $computedQuery(['select' => 'id,canManage', 'pageSize' => 1]), 'nothing without one');

Assert::same(['filter.label' => 'can not be filtered by.'], $fieldsOf(static fn() => $processor->process($users(), Query::create(['filter' => ['field' => 'label', 'value' => 'x']]), ComputedResource::class)));
Assert::same(['sort.label' => 'can not be sorted by.'], $fieldsOf(static fn() => $processor->process($users(), Query::create(['sort' => [['field' => 'label']]]), ComputedResource::class)));
Assert::exception(static fn() => $processor->process($users(), Query::create(), BadComputed::class), LogicException::class, "%A%needs a public static method nothing()%A%");

// --- Documentation

$builder = new SchemaBuilder();
SchemaBuilder::addClassDescriber(static fn(string $class, SchemaBuilder $b): ?array => is_a($class, Phlox\EntityDatabase\Entity\Entity::class, true) ? FieldMap::forEntity($class)->toSchema($b) : null);
Assert::same(['$ref' => '#/components/schemas/User'], $builder->build(new Type(Type::ClassName, class: User::class)), 'an entity named anywhere');
$schemas = $builder->getSchemas();
foreach (['User', 'Order', 'Item', 'Role'] as $name) {
    Assert::true(isset($schemas[$name]), $name);
}
Assert::false(isset($schemas['User']['properties']['passwordHash']), 'without what is write-only');
Assert::same(['boolean', 'null'], $schemas['User']['properties']['canManage']['type'], 'with what events add');
Assert::same(['$ref' => '#/components/schemas/Order'], $schemas['User']['properties']['orders']['properties']['data']['items'], 'relations by reference, so they can go in a circle');
Assert::same(['anyOf' => [['$ref' => '#/components/schemas/User'], ['type' => 'null']]], $schemas['Order']['properties']['user']);

$plain = new QueryOf(User::class);
Assert::contains('Relations: `role`, `orders`, `activeOrders`.', $plain->describeParameter(), 'what the configuration says');
$byParameter = $plain->describeParameterSchema($queryBuilder = new SchemaBuilder());
Assert::same(['$ref' => '#/components/schemas/Query_User'], $byParameter);
$paths = $queryBuilder->getSchemas()['QueryFilter_User']['properties']['field']['enum'];
Assert::contains('email', $paths);
Assert::contains('role.name', $paths);
Assert::contains('orders.items.name', $paths);
Assert::notContains('passwordHash', $paths);
Assert::notContains('canManage', $paths);

// Entities are generated by a tool which needs the container, so a missing one can't stop it from compiling
$missing = new QueryOf('App\NotGeneratedYet');
Assert::same('', $missing->describeParameter());
Assert::null($missing->describeParameterSchema(new SchemaBuilder()));
Assert::null($missing->describeResponse(new SchemaBuilder()));
Assert::same(['type' => 'object'], (new SchemaBuilder())->build(new Type(Type::ClassName, class: 'App\NotGeneratedYet')));

// --- SQL, with its fields in one place

$sql = new QueryOfSql(['id' => new SqlField('user_id', Field::Integer), 'email' => new SqlField('mail', searchable: true)]);
Assert::same(['$ref' => '#/components/schemas/Query_Sql' . substr(md5('["id","email"]'), 0, 6)], $sql->describeParameterSchema($sqlBuilder = new SchemaBuilder()));
Assert::same(['id', 'email'], $sqlBuilder->getSchemas()['QuerySort_Sql' . substr(md5('["id","email"]'), 0, 6)]['properties']['field']['enum']);
Assert::same(['integer', 'null'], $sql->describeResponse(new SchemaBuilder())['properties']['data']['items']['properties']['id']['type']);
Assert::contains('Search looks in: `email`.', $sql->describeParameter());

final class SqlTarget
{
    public function report(#[QueryOfSql(['id' => new SqlField('user_id', Field::Integer), 'email' => new SqlField('mail', searchable: true)])] Query $query): void
    {
    }
}

$bound = Query::create(['select' => 'email', 'sort' => [['field' => 'id']], 'pageSize' => 1]);
$bound->bindToParameter((new ReflectionMethod(SqlTarget::class, 'report'))->getParameters()[0]);
$fromAttribute = $container->getByType(SqlProcessor::class)->process(sprintf('SELECT %s AS %s, %s AS %s FROM %s', q('id'), q('user_id'), q('email'), q('mail'), q('users')), [], $bound);
Assert::same([['email' => 'ann@example.com']], $fromAttribute['data'], 'the fields come from the attribute');
Assert::exception(static fn() => $container->getByType(SqlProcessor::class)->process('SELECT 1', [], Query::create()), LogicException::class, '%A%needs its fields%A%');

Assert::same(User::class, $users()->getEntityName());
