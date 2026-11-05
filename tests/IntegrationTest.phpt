<?php

/**
 * Queries through real controllers of both transports, and in their documents.
 */

declare(strict_types=1);

use Phlox\ApiQuery\Processor;
use Phlox\ApiQuery\QueryConfig;
use Phlox\RestApi\Dispatcher;
use Phlox\RestApi\OpenApi;
use Phlox\RpcApi\Dispatcher as RpcDispatcher;
use Phlox\RpcApi\OpenRpc;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/Resources.php';

$container = createContainer(<<<'NEON'
apiQuery:
    maxPageSize: 3

restApi:
    endpoints:
        api:
            namespace: Phlox\ApiQuery\Tests\Fixtures\Docs
            documentation:
                expose: true

rpcApi:
    endpoints:
        rpc:
            namespace: Phlox\ApiQuery\Tests\Fixtures\Docs\Rpc
            documentation:
                expose: true

services:
    - Phlox\ApiQuery\Tests\Fixtures\Docs\QueryController
    - Phlox\ApiQuery\Tests\Fixtures\Docs\Rpc\QueryRpcController
NEON, <<<'NEON'
restApi: Phlox\RestApi\DI\Extension
    rpcApi: Phlox\RpcApi\DI\Extension
NEON);

$rest = $container->getByType(Dispatcher::class);
$post = static fn(string $path, array $body): Phlox\RestApi\Result => $rest->handle('api', 'POST', $path, [], 'application/json', json_encode($body));

// --- The configuration is used

Assert::same(3, $container->getService('apiQuery.config')->maxPageSize);
Assert::type(QueryConfig::class, $container->getService('apiQuery.config'));

// --- #[QueryOf] decides, from one place

$resources = $post('query/resources', ['select' => 'id,login', 'pageSize' => 1]);
Assert::same(200, $resources->status ?? 200);
Assert::same(['data' => [['id' => 1, 'login' => 'ann@example.com']], 'total' => 4, 'page' => 1, 'pageSize' => 1, 'from' => 0, 'to' => 0], $resources->data);

$hidden = $post('query/resources', ['select' => 'email']);
Assert::same(400, $hidden->status);
Assert::same(['select.email' => 'is not a known field.'], $hidden->data['error']['fields']);
Assert::same(-32602, $hidden->data['error']['code']);

$entities = $post('query/entities', ['select' => 'email,role(name),orders(id,items(name))', 'pageSize' => 1]);
Assert::same('admin', $entities->data['data'][0]['role']['name']);
Assert::same('apple', $entities->data['data'][0]['orders']['data'][0]['items']['data'][0]['name']);
Assert::same(['select.orders.user' => 'is not a known field.'], $post('query/entities', ['select' => 'orders(user(id))'])->data['error']['fields'], 'only the relations it names');

Assert::same(['select.role' => 'is not a known field.'], $post('query/plain', ['select' => 'role(id)'])->data['error']['fields'], 'nothing declared means no relations');
Assert::same(3, $post('query/plain', ['select' => 'id'])->data['pageSize'], 'the limit of the configuration');

$malformed = $post('query/plain', ['select' => 'a(', 'page' => 'x']);
Assert::same(400, $malformed->status, 'a malformed query is the same kind of an error');
Assert::equal(['page' => 'must be a whole number.', 'select' => 'has an unmatched "(".'], $malformed->data['error']['fields']);

// --- Leaving the query out avoids nothing

$missing = $rest->handle('api', 'GET', 'query/from-url');
Assert::same(['id', 'login', 'displayName'], array_keys($missing->data['data'][0]), 'what the parameter says, though nothing came');

$fromUrl = $rest->handle('api', 'GET', 'query/from-url', ['query' => ['select' => 'login', 'filter' => ['filters' => [['field' => 'id', 'operator' => 'gt', 'value' => '3']]]]]);
Assert::same([['login' => 'dana@example.com']], $fromUrl->data['data']);

Assert::same(400, $rest->handle('api', 'GET', 'query/from-url', ['query' => ['select' => 'email']])->status);

// --- One record

Assert::same(['login' => 'ann@example.com'], $rest->handle('api', 'GET', 'query/1', ['query' => ['select' => 'login']])->data);
Assert::same(['id' => 1, 'login' => 'ann@example.com', 'displayName' => 'Ann Novak'], $rest->handle('api', 'GET', 'query/1')->data);
Assert::null($rest->handle('api', 'GET', 'query/99')->data);

// --- JSON-RPC

$rpc = $container->getByType(RpcDispatcher::class);
$call = static fn(array $params): array => $rpc->handle('rpc', json_encode(['jsonrpc' => '2.0', 'method' => 'queryRpc.users', 'params' => $params, 'id' => 1]));

Assert::same([['login' => 'ann@example.com']], $call(['query' => ['select' => 'login', 'pageSize' => 1]])['result']['data']);
Assert::same(['select.email' => 'is not a known field.'], $call(['query' => ['select' => 'email']])['error']['fields']);
Assert::same(-32602, $call(['query' => ['select' => 'email']])['error']['code']);
Assert::same(3, $call([])['result']['pageSize'], 'the parameter can be left out');

// --- A service shared by the controllers

Assert::type(Processor::class, $container->getByType(Processor::class));

// --- OpenAPI

$document = json_decode($container->getByType(OpenApi::class)->toJson('api'), true);
$schemas = $document['components']['schemas'];
$paths = $document['paths'];

foreach (['Query', 'QueryFilter', 'QueryFilterGroup', 'QuerySort', 'QueryCriteria', 'Query_UserResource'] as $name) {
    Assert::true(isset($schemas[$name]), $name);
}

$operation = $paths['/query/resources']['post'];
Assert::same(['$ref' => '#/components/schemas/Query_UserResource'], $operation['requestBody']['content']['application/json']['schema']);
Assert::same(['id', 'login', 'role.id', 'role.name', 'purchases.id', 'purchases.status', 'purchases.items.id', 'purchases.items.name'], $schemas['QueryFilter_UserResource']['properties']['field']['enum'], 'what a filter can name');
Assert::same(['id', 'login', 'role.id', 'role.name'], $schemas['QuerySort_UserResource']['properties']['field']['enum'], 'a sort can not go through a collection');
Assert::false(in_array('displayName', $schemas['QueryFilter_UserResource']['properties']['field']['enum'], true), 'what can not be filtered by');
Assert::false(isset($schemas['UserResource']['required']), 'a query selects only some of the fields');
Assert::contains('Fields: `id`, `login`, `displayName`.', $operation['requestBody']['description']);
Assert::contains('Relations: `role`, `purchases`.', $operation['requestBody']['description']);
Assert::contains('Search looks in: `login`.', $operation['requestBody']['description']);

$page = $operation['responses'][200]['content']['application/json']['schema'];
Assert::same(['data', 'total', 'page', 'pageSize', 'from', 'to'], array_keys($page['properties']));
Assert::same(['$ref' => '#/components/schemas/UserResource'], $page['properties']['data']['items']);
Assert::same('A user, as clients see one.', $schemas['UserResource']['description']);
Assert::same(['id', 'login'], array_slice(array_keys($schemas['UserResource']['properties']), 0, 2));
Assert::false(isset($schemas['UserResource']['properties']['email']));
Assert::contains('RoleResource', json_encode($schemas['UserResource']['properties']['role']));

$item = $paths['/query/entities']['post']['responses'][200]['content']['application/json']['schema']['properties']['data']['items'];
Assert::contains('integer', (array) $item['properties']['id']['type'], 'an entity');
Assert::same(['string', 'null'], $item['properties']['fullName']['type']);
Assert::same('date-time', $item['properties']['createdAt']['format']);
Assert::same('date', $item['properties']['birthday']['format']);
Assert::false(isset($item['properties']['passwordHash']), 'a write-only column is not documented either');
Assert::false(isset($item['properties']['activeOrders']), 'nor a relation it does not name');
Assert::true(isset($item['properties']['orders']['properties']['data']['items']['properties']['items']['properties']['data']['items']['properties']['name']), 'nested collections are pages too');

Assert::same(['$ref' => '#/components/schemas/UserResource'], $paths['/query/{id}']['get']['responses'][200]['content']['application/json']['schema'], 'one record');
Assert::same(['data', 'total', 'page', 'pageSize', 'from', 'to'], array_keys($paths['/query/created']['post']['responses'][201]['content']['application/json']['schema']['properties']), '#[Response(201)] takes it too');

$parameter = $paths['/query/from-url']['get']['parameters'][0];
Assert::same('query', $parameter['in']);
Assert::same('deepObject', $parameter['style']);

// Everything the document refers to exists
preg_match_all('~"#/components/schemas/([^"]+)"~', str_replace('\/', '/', $container->getByType(OpenApi::class)->toJson('api')), $references);
foreach (array_unique($references[1]) as $reference) {
    Assert::true(isset($schemas[$reference]), "#/components/schemas/{$reference}");
}

// --- OpenRPC

$openRpc = json_decode($container->getByType(OpenRpc::class)->toJson('rpc'), true);
$method = $openRpc['methods'][0];
Assert::same('queryRpc.users', $method['name']);
Assert::same(['$ref' => '#/components/schemas/Query_UserResource'], $method['params'][0]['schema']);
Assert::contains('Fields:', $method['params'][0]['description']);
Assert::same(['$ref' => '#/components/schemas/UserResource'], $method['result']['schema']['properties']['data']['items']);
