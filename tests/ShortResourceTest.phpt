<?php

/**
 * Resources which name their fields, and resources standing for their entities, found in their namespace.
 */

declare(strict_types=1);

use Phlox\ApiCore\Attributes\PatchOf;
use Phlox\ApiCore\Binding\ParameterBinder;
use Phlox\ApiCore\Patch;
use Phlox\ApiQuery\Processor;
use Phlox\ApiQuery\Query;
use Phlox\ApiQuery\QueryConfig;
use Phlox\ApiQuery\QueryException;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Role;
use Phlox\ApiQuery\Tests\Fixtures\ShortDocs\ShortController;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\ExceptDeclared;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\ExceptTypo;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\ExceptWithoutStar;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\FieldsWord;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\ListedUser;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\ListsRelation;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\ListsSecret;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\ListsUnknown;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\LoopUser;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\OfAnotherEntity;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\StarRole;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\StarUser;
use Phlox\EntityDatabase\Database\Explorer;
use Phlox\RestApi\Dispatcher;
use Phlox\RestApi\OpenApi;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/ShortResources.php';
require __DIR__ . '/fixtures/ShortDefaults/Resources.php';

$controller = ShortController::class;

$container = createContainer(<<<NEON
apiQuery:
    resources: Phlox\\ApiQuery\\Tests\\Fixtures\\ShortDefaults

restApi:
    endpoints:
        api:
            namespace: Phlox\\ApiQuery\\Tests\\Fixtures\\ShortDocs
            documentation:
                expose: true

services:
    - {$controller}
NEON, 'restApi: Phlox\RestApi\DI\Extension');

$explorer = $container->getByType(Explorer::class);
$users = static fn() => $explorer->table('users');
$roles = static fn() => $explorer->table('roles');
$resources = $container->getService('apiQuery.config')->resources;
$processor = new Processor(new QueryConfig(resources: $resources));

$errors = static function (callable $call): array {
    return Assert::exception($call, QueryException::class)->getData()['fields'];
};
$unknown = 'is not a known field.';

// --- A star: everything of the entity

$of = static fn(string $class, array $query = []): ?array => $processor->process($roles(), Query::create($query), $class);
Assert::same(['id' => 1, 'name' => 'admin'], $of(StarRole::class, ['pageSize' => 1])['data'][0]);
Assert::same([1], array_column($of(StarRole::class, ['filter' => ['field' => 'name', 'value' => 'admin']])['data'], 'id'), 'and it can be filtered by');
Assert::same([2, 1], array_column($of(StarRole::class, ['sort' => [['field' => 'name', 'dir' => 'desc']]])['data'], 'id'), 'and sorted by');
Assert::same(['select.users' => $unknown], $errors(static fn() => $of(StarRole::class, ['select' => 'users'])), 'a star does not reach relations');
Assert::same(['filter.users.email' => $unknown], $errors(static fn() => $of(StarRole::class, ['filter' => ['field' => 'users.email', 'value' => 'x']])));

// A star with exceptions

$star = static fn(array $query): ?array => $processor->process($users(), Query::create($query), StarUser::class);
$record = $star(['pageSize' => 1])['data'][0];
Assert::same('ann@example.com', $record['login'], 'declared under another name');
Assert::same(1, $record['id']);
Assert::true(array_key_exists('createdAt', $record), 'what is not an exception shows up by itself');

foreach (['age', 'fullName', 'email', 'passwordHash', 'orders'] as $hidden) {
    Assert::false(array_key_exists($hidden, $record), $hidden);
    Assert::same(["select.{$hidden}" => $unknown], $errors(static fn() => $star(['select' => $hidden])), $hidden);
    Assert::same(["filter.{$hidden}" => $unknown], $errors(static fn() => $star(['filter' => ['field' => $hidden, 'value' => 'x']])), $hidden);
}

Assert::same([1], array_column($star(['select' => 'id', 'filter' => ['field' => 'login', 'value' => 'ann@example.com']])['data'], 'id'));
Assert::same(['id' => 1], $star(['select' => 'role(id)', 'pageSize' => 1])['data'][0]['role'], 'a relation has to be declared');
Assert::same(['select.role.name' => $unknown], $errors(static fn() => $star(['select' => 'role(name)'])), 'by what it is declared as');
Assert::null($star(['select' => 'nickname', 'pageSize' => 1])['data'][0]['nickname'], 'a virtual property when named, like on an entity');
Assert::false(array_key_exists('nickname', $record), 'and not by a star');

// --- A list: only what's named

$listed = static fn(array $query): ?array => $processor->process($users(), Query::create($query), ListedUser::class);
Assert::same(
    ['id' => 1, 'email' => 'ann@example.com', 'fullName' => 'Ann Novak', 'label' => '<ann@example.com>'],
    $listed(['pageSize' => 1])['data'][0],
);

foreach (['age', 'createdAt', 'passwordHash', 'orders'] as $hidden) {
    Assert::same(["select.{$hidden}" => $unknown], $errors(static fn() => $listed(['select' => $hidden])), $hidden);
    Assert::same(["sort.{$hidden}" => $unknown], $errors(static fn() => $listed(['sort' => [['field' => $hidden]]])), $hidden);
}

Assert::contains('can not be filtered', $errors(static fn() => $listed(['filter' => ['field' => 'fullName', 'value' => 'x']]))['filter.fullName'], 'what is declared wins');
Assert::same([1, 2, 3, 4], array_column($listed(['select' => 'id', 'sort' => [['field' => 'email']]])['data'], 'id'));
Assert::same([1], array_column($listed(['select' => 'id', 'search' => 'ann@'])['data'], 'id'), 'searchable as the entity says');

$tree = $listed(['select' => 'id,role(name),purchases(status)', 'pageSize' => 1])['data'][0];
Assert::same(['name' => 'admin'], $tree['role']);
Assert::same([['status' => 'new'], ['status' => 'paid']], $tree['purchases']['data'], 'a relation, its condition, and named fields under it');
Assert::same(['select.purchases.total' => $unknown], $errors(static fn() => $listed(['select' => 'purchases(total)'])));

// --- Mistakes are the developer's, so that nothing shows by accident

$use = static fn(string $class) => static fn() => $processor->process($users(), Query::create(), $class);
Assert::exception($use(ListsUnknown::class), LogicException::class, "%A%'nothingLikeThat'%A%");
Assert::exception($use(ListsSecret::class), LogicException::class, "%A%'passwordHash'%A%");
Assert::exception($use(ListsRelation::class), LogicException::class, '%A%is a relation%A%');
Assert::exception($use(ExceptTypo::class), LogicException::class, "%A%'passwordHsh'%A%");
Assert::exception($use(ExceptDeclared::class), LogicException::class, '%A%declares itself%A%');
Assert::exception($use(FieldsWord::class), LogicException::class, "%A%'all'%A%");
Assert::exception($use(ExceptWithoutStar::class), LogicException::class, "%A%only with fields%A%");
Assert::exception($use(OfAnotherEntity::class), LogicException::class, '%A%was given%A%');

// --- A resource is for what goes out

final class ResourceAsInput
{
    public function body(StarRole $role): void
    {
    }

    /** @param list<StarRole> $roles */
    public function list(array $roles): void
    {
    }

    public function patch(#[PatchOf(StarRole::class)] Patch $role): void
    {
    }
}

$binder = new ParameterBinder();
foreach (['body' => ['role' => ['name' => 'x']], 'list' => ['roles' => [['name' => 'x']]], 'patch' => ['role' => ['name' => 'x']]] as $method => $parameters) {
    Assert::exception(
        static fn() => $binder->bind(new ReflectionMethod(ResourceAsInput::class, $method), $parameters),
        LogicException::class,
        '%A%describes what goes out%A%',
    );
}

// --- A resource found in its namespace stands for the entity everywhere

Assert::same(['id' => 1], $processor->process($roles(), Query::create(['pageSize' => 1]))['data'][0], 'with nothing said');
Assert::same(['select.name' => $unknown], $errors(static fn() => $processor->process($roles(), Query::create(['select' => 'name']))));
Assert::same(['filter.name' => $unknown], $errors(static fn() => $processor->process($roles(), Query::create(['filter' => ['field' => 'name', 'value' => 'admin']]))));
Assert::same(['sort.name' => $unknown], $errors(static fn() => $processor->process($roles(), Query::create(['sort' => [['field' => 'name']]]))));

Assert::same(['id' => 1], $processor->process($roles()->fetch(), Query::create()), 'one record');
Assert::same(['id' => 1], $processor->process($roles(), Query::create(['pageSize' => 1]), Role::class)['data'][0], 'naming the entity does not get around it');

// Through a relation of another entity, which is the way around it that matters
Assert::same(['id' => 1], $processor->process($users(), Query::create(['select' => 'role', 'pageSize' => 1]))['data'][0]['role']);
Assert::same(['select.role.name' => $unknown], $errors(static fn() => $processor->process($users(), Query::create(['select' => 'role(name)']))));
Assert::same(['filter.role.name' => $unknown], $errors(static fn() => $processor->process($users(), Query::create(['filter' => ['field' => 'role.name', 'value' => 'admin']]))));
Assert::same(['sort.role.name' => $unknown], $errors(static fn() => $processor->process($users(), Query::create(['sort' => [['field' => 'role.name']]]))));
Assert::same(['select.orders.user.role.name' => $unknown], $errors(static fn() => $processor->process($users(), Query::create(['select' => 'orders(user(role(name)))']))), 'however deep');


// Another resource is how one place differs
Assert::same(['id' => 1, 'name' => 'admin'], $of(StarRole::class, ['pageSize' => 1])['data'][0]);

// --- Through a controller

$rest = $container->getByType(Dispatcher::class);
$post = static fn(string $path, array $body = []): Phlox\RestApi\Result => $rest->handle('api', 'POST', $path, [], 'application/json', json_encode($body));

Assert::same([['id' => 1], ['id' => 2]], $post('short/roles')->data['data'], 'a plain Query follows the resource of the entity');
Assert::same(['select.name' => $unknown], $post('short/roles', ['select' => 'name'])->data['error']['fields']);
Assert::same('admin', $post('short/users', ['select' => 'role(name)', 'pageSize' => 1])->data['data'][0]['role']['name'], 'the attribute says another resource');
Assert::same(1, $post('short/loop', ['select' => 'orders(user(id))', 'pageSize' => 1])->data['data'][0]['orders']['data'][0]['user']['id']);

// --- Documentation is of what comes out

$document = json_decode($container->getByType(OpenApi::class)->toJson('api'), true);
$schemas = $document['components']['schemas'];
$paths = $document['paths'];

Assert::same(['id', 'name'], array_keys($schemas['StarRole']['properties']), 'named fields have no declaration to read from');
Assert::same('integer', $schemas['StarRole']['properties']['id']['type'], 'types come from the entity');
Assert::same('A role, with everything it has.', $schemas['StarRole']['description']);
Assert::false(isset($schemas['StarRole']['required']), 'a query selects only some of the fields');

Assert::same(['id', 'email', 'fullName', 'role', 'purchases', 'label'], array_keys($schemas['ListedUser']['properties']));
Assert::same('Named fields, and what differs from the entity declared.', $schemas['ListedUser']['description']);
Assert::same('How to address them', $schemas['ListedUser']['properties']['fullName']['description']);
Assert::same(['string', 'null'], $schemas['ListedUser']['properties']['fullName']['type']);
Assert::same(300, $schemas['ListedUser']['properties']['label']['maxLength'], '#[Schema] of a field');
Assert::same(['string', 'null'], $schemas['ListedUser']['properties']['label']['type']);

$purchases = $schemas['ListedUser']['properties']['purchases'];
Assert::same('object', $purchases['type'], 'a collection comes as a page, whatever the docblock says');
Assert::same(['$ref' => '#/components/schemas/ListedOrder'], $purchases['properties']['data']['items']);
Assert::same([['$ref' => '#/components/schemas/StarRole'], ['type' => 'null']], $schemas['ListedUser']['properties']['role']['anyOf']);

$fields = $schemas['QueryFilter_ListedUser']['properties']['field']['enum'];
Assert::contains('email', $fields);
Assert::contains('role.name', $fields);
Assert::contains('purchases.status', $fields);
Assert::false(in_array('fullName', $fields, true), 'what can not be filtered by');
Assert::false(in_array('age', $fields, true));

Assert::same(['$ref' => '#/components/schemas/StarRole'], $paths['/short/described']['post']['responses'][200]['content']['application/json']['schema']['properties']['data']['items'], '#[Response] with a resource');
Assert::same(['$ref' => '#/components/schemas/IdOnlyRole'], $paths['/short/entity']['post']['responses'][200]['content']['application/json']['schema'], 'an entity is documented as what stands for it');
Assert::same(['id'], array_keys($schemas['IdOnlyRole']['properties']));

Assert::same(['$ref' => '#/components/schemas/LoopOrder'], $schemas['LoopUser']['properties']['orders']['properties']['data']['items'], 'relations can go in a circle');
Assert::same([['$ref' => '#/components/schemas/LoopUser'], ['type' => 'null']], $schemas['LoopOrder']['properties']['user']['anyOf']);

$json = str_replace('\/', '/', $container->getByType(OpenApi::class)->toJson('api'));
Assert::notContains('passwordHash', $json);
preg_match_all('~"#/components/schemas/([^"]+)"~', $json, $references);
foreach (array_unique($references[1]) as $reference) {
    Assert::true(isset($schemas[$reference]), "#/components/schemas/{$reference}");
}
