<?php

/**
 * Entities generated from a database as Firebird usually has it, and queries over
 * them. The other tests run on Firebird too; these are about what's specific to it.
 */

declare(strict_types=1);

use Nette\Bootstrap\Configurator;
use Nette\Database\Connection;
use Phlox\ApiQuery\Attributes\Resource;
use Phlox\ApiQuery\Fields\Field;
use Phlox\ApiQuery\Fields\SqlField;
use Phlox\ApiQuery\Processor;
use Phlox\ApiQuery\Query;
use Phlox\ApiQuery\QueryException;
use Phlox\ApiQuery\SqlProcessor;
use Phlox\ApiQuery\Tests\Fixtures\FirebirdEntity\Customer;
use Phlox\ApiQuery\Tests\Fixtures\FirebirdEntity\CustomerOrder;
use Phlox\EntityDatabase\Database\Explorer;
use Phlox\EntityDatabase\Database\Table\Selection;
use Phlox\EntityDatabase\Metadata\MetaStorage;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/firebird-schema.php';

$database = testDatabase();
if ($database['type'] !== 'firebird') {
    Tester\Environment::skip('TEST_DSN is not a Firebird database.');
}

createFirebirdSchema(new PDO($database['dsn'], $database['user'], $database['password']));

#[Resource(Customer::class, fields: '*')]
final readonly class FirebirdCustomer
{
    /** @param list<CustomerOrder> $orders */
    public function __construct(public array $orders = [])
    {
    }
}

#[Resource(CustomerOrder::class, fields: '*')]
final readonly class FirebirdOrder
{
    public function __construct(public ?Customer $customer = null)
    {
    }
}

/** Only orders from a price, as access rights would limit them. */
#[Resource(CustomerOrder::class, fields: '*')]
final readonly class DearOrder
{
    public function __construct(public ?LimitedCustomer $customer = null)
    {
    }

    public static function restrict(Selection $orders): void
    {
        $orders->where('CENA >= ?', 3);
    }
}

/** Only one customer. */
#[Resource(Customer::class, fields: '*')]
final readonly class LimitedCustomer
{
    /** @param list<DearOrder> $orders */
    public function __construct(public array $orders = [])
    {
    }

    public static function restrict(Selection $customers): void
    {
        $customers->where('ID', 1);
    }
}

@mkdir(__DIR__ . '/tmp');
$tempDir = __DIR__ . '/tmp/' . getmypid();
Tester\Helpers::purge($tempDir);

$dsn = json_encode($database['dsn']);
$config = $tempDir . '/firebird.neon';
file_put_contents($config, <<<NEON
extensions:
    entityDatabase: Phlox\\EntityDatabase\\DI\\Extension
    apiQuery: Phlox\\ApiQuery\\DI\\Extension

security:
    debugger: false

http:
    frames: null

database:
    default:
        dsn: $dsn
        user: {$database['user']}
        password: {$database['password']}
        options:
            driverClass: Phlox\\FirebirdDatabase\\FirebirdDriver

entityDatabase:
    connections:
        default:
            entities:
                namespace: Phlox\\ApiQuery\\Tests\\Fixtures\\FirebirdEntity
                output: %appDir%/fixtures/FirebirdEntity
                config: [%appDir%/fixtures/firebird-entities.neon]
NEON);

$configurator = new Configurator();
$configurator->setDebugMode(false);
$configurator->setTempDirectory($tempDir);
$configurator->addStaticParameters(['appDir' => __DIR__]);
$configurator->addConfig($config);
$container = $configurator->createContainer();

$explorer = $container->getByType(Explorer::class);
$processor = $container->getByType(Processor::class);

$customers = static fn() => $explorer->table('ZAKAZNICI');
$run = static fn(array $query, mixed ...$options): ?array => $processor->process($customers(), Query::create($query), ...$options);
$ids = static fn(?array $result): array => array_column($result['data'], 'id');
$filter = static fn(string $field, string $operator, mixed $value = null): array => $ids($run([
    'select' => 'id',
    'filter' => ['field' => $field, 'operator' => $operator, 'value' => $value],
]));

// --- Generated from the database, with its types

$properties = MetaStorage::get(Customer::class)->byProp;
Assert::same('int', $properties['id']->type);
Assert::same('integer', $properties['id']->nativeType);
Assert::same('ID', $properties['id']->col);
Assert::true($properties['id']->key);
Assert::same('string', $properties['name']->type);
Assert::same('varchar', $properties['name']->nativeType);
Assert::same('string', $properties['code']->type);
Assert::same('\DateTime', $properties['founded']->type);
Assert::same('date', $properties['founded']->nativeType);
Assert::same('bool', $properties['active']->type, 'BOOLEAN');
Assert::same('float', $properties['credit']->type, 'NUMERIC is not an integer');
Assert::same('numeric', $properties['credit']->nativeType);
Assert::false($properties['name']->nullable);
Assert::true($properties['credit']->nullable);

// --- Reading

$first = $run(['pageSize' => 1])['data'][0];
Assert::same(1, $first['id']);
Assert::same('Žluťoučký', $first['name'], 'text keeps its accents');
Assert::same('0123', $first['code'], 'a code keeps its zero');
Assert::same('2020-01-02', $first['founded'], 'a date stays a date');
Assert::true($first['active']);
Assert::same(10.5, $first['credit']);
Assert::false(array_key_exists('pin', $first), 'a write-only column');

Assert::same(
    [
        ['price' => 5.0, 'created' => date_create('2024-01-02 03:04:05')->format(DATE_ATOM), 'note' => 'První'],
        ['price' => 2.5, 'created' => date_create('2024-02-03')->format(DATE_ATOM), 'note' => null],
    ],
    $run(['select' => 'id,orders(price,created,note)', 'pageSize' => 1], of: FirebirdCustomer::class)['data'][0]['orders']['data'],
    'a whole DOUBLE stays a number, in a collection',
);
Assert::same('20240203', $processor->process($explorer->table('OBJEDNAVKY')->where('ID', 3), Query::create(['select' => 'note']))['data'][0]['note'], 'digits in a BLOB stay text');

// --- Paging, with FIRST and SKIP, and the total

$page = $run(['select' => 'id', 'page' => 2, 'pageSize' => 2]);
Assert::same([3], $ids($page));
Assert::same(3, $page['total']);
Assert::same(2, $page['from']);

// --- Filters

Assert::same([1], $filter('code', 'eq', '0123'));
Assert::same([1, 3], $filter('active', 'eq', true));
Assert::same([2], $filter('active', 'eq', false));
Assert::same([3], $filter('credit', 'lt', 6));
Assert::same([1], $filter('founded', 'eq', '2020-01-02'), 'a date without a time');
Assert::same([1], $filter('founded', 'eq', '2020-01-02T12:00:00'), 'the time of day means nothing to a date');
Assert::same([3], $filter('founded', 'gt', '2020-06-01'));
Assert::same([2], $filter('founded', 'isnull'));

// Case, with accents
Assert::same([1], $filter('name', 'contains', 'ŽLUŤ'));
Assert::same([1], $filter('name', 'startswith', 'žluť'));
Assert::same([3], $filter('name', 'endswith', 'CHA'));

// A value longer than the column doesn't fail
Assert::same([], $filter('name', 'contains', str_repeat('a', 300)));
Assert::same([], $filter('name', 'contains', str_repeat('ž', 1000)), 'in characters of more bytes');
Assert::same([], $filter('name', 'contains', str_repeat('%', 1000)), 'when escaping makes it longer');
Assert::same([], $filter('name', 'eq', str_repeat('a', 300)));
Assert::same([], $filter('name', 'in', [str_repeat('a', 300)]));

// A wildcard is a character
Assert::same([], $filter('name', 'contains', '%'));
Assert::same([], $filter('name', 'contains', '_'));

// Dates in a collection
$orders = static fn(string $operator, mixed $value = null): array => array_column($processor->process(
    $explorer->table('OBJEDNAVKY'),
    Query::create(['select' => 'id', 'filter' => ['field' => 'created', 'operator' => $operator, 'value' => $value]]),
)['data'], 'id');
Assert::same([1], $orders('isweek', '2024-01-03'));
Assert::same([2], $orders('gte', '2024-02-03'));
Assert::same([1], $orders('lt', '2024-02-03'));

// --- Through relations

Assert::same([1], $ids($run(['select' => 'id', 'filter' => ['field' => 'orders.price', 'operator' => 'lt', 'value' => 3]], of: FirebirdCustomer::class)));
$byOrder = $processor->process($explorer->table('OBJEDNAVKY'), Query::create([
    'select' => 'id,customer(name)',
    'sort' => [['field' => 'customer.name', 'dir' => 'desc'], ['field' => 'id']],
    'filter' => ['field' => 'customer.name', 'operator' => 'contains', 'value' => 'kůň'],
]), FirebirdOrder::class);
Assert::same([['id' => 3, 'customer' => ['name' => 'Kůň']]], $byOrder['data']);

// --- What restrict() hides, in subqueries of Firebird too

$dear = static fn(string $table, array $query, string $of): ?array => $processor->process($explorer->table($table), Query::create($query), $of);
Assert::same([1], $ids($dear('ZAKAZNICI', ['select' => 'id'], LimitedCustomer::class)), 'at the root');
Assert::same([5.0], array_column($dear('ZAKAZNICI', ['select' => 'id,orders(price)'], LimitedCustomer::class)['data'][0]['orders']['data'], 'price'), 'in a collection');
Assert::same([], $ids($dear('ZAKAZNICI', ['select' => 'id', 'filter' => ['field' => 'orders.price', 'operator' => 'lt', 'value' => 3]], LimitedCustomer::class)), 'in a filter through a relation');

$dearOrders = $dear('OBJEDNAVKY', ['select' => 'id,customer(id)', 'sort' => [['field' => 'customer.name'], ['field' => 'id']]], DearOrder::class)['data'];
Assert::true($dearOrders !== [], 'a sort through a relation runs, with the restriction as a parameter');
foreach ($dearOrders as $order) {
    Assert::same($order['customer'] === null ? null : 1, $order['customer']['id'] ?? null, 'a customer one can not read is null');
}

// --- A write-only column isn't there

$e = Assert::exception(static fn() => $run(['filter' => ['field' => 'pin', 'value' => '1111']]), QueryException::class);
Assert::same(['filter.pin' => 'is not a known field.'], $e->getData()['fields']);

// --- Search

Assert::same([1], $ids($run(['select' => 'id', 'search' => 'žluť'])));
Assert::same([1], $ids($run(['select' => 'id', 'search' => 'ŽLUŤOUČKÝ'])), 'case with accents');
Assert::same([], $ids($run(['select' => 'id', 'search' => str_repeat('a', 300)])), 'a term longer than the column');
Assert::same([], $ids($run(['select' => 'id', 'search' => '1111'])), 'not in a write-only column');

// --- Raw SQL

$report = $container->getByType(SqlProcessor::class)->process(
    'SELECT z.ID, z.NAZEV, z.KOD, SUM(o.CENA) AS SOUCET FROM ZAKAZNICI z LEFT JOIN OBJEDNAVKY o ON o.ZAKAZNIK_ID = z.ID WHERE z.ID < ? GROUP BY z.ID, z.NAZEV, z.KOD',
    [3],
    Query::create(['sort' => [['field' => 'id', 'dir' => 'desc']], 'filter' => ['field' => 'name', 'operator' => 'contains', 'value' => 'ŽLUŤ']]),
    ['id' => new SqlField('ID', Field::Integer), 'name' => 'NAZEV', 'code' => 'KOD', 'sum' => new SqlField('SOUCET', Field::Number)],
);
Assert::same(1, $report['total']);
Assert::same(1, $report['data'][0]['id']);
Assert::same('0123', $report['data'][0]['code'], 'a code under no column of its own keeps its zero');
Assert::same(7.5, $report['data'][0]['sum']);

$paged = $container->getByType(SqlProcessor::class)->process('SELECT ID FROM ZAKAZNICI', [], Query::create(['pageSize' => 1, 'page' => 2, 'sort' => [['field' => 'id']]]), ['id' => new SqlField('ID', Field::Integer)]);
Assert::same([['id' => 2]], $paged['data']);

// --- Saving through an entity

$entity = $customers()->get(2);
$entity->name = 'Kůň 2';
Assert::true($entity->saveModified());
Assert::same('Kůň 2', $container->getByType(Connection::class)->fetchField('SELECT NAZEV FROM ZAKAZNICI WHERE ID = 2'));

$entity = $customers()->get(2);
$entity->founded = new DateTime('2022-03-04');
Assert::true($entity->saveModified(), 'a date to a DATE column');
Assert::same('2022-03-04', $customers()->get(2)->founded->format('Y-m-d'));

$entity = $customers()->get(3);
$entity->credit = 7.0;
Assert::true($entity->saveModified());
Assert::same(7.0, $customers()->get(3)->credit, 'a whole number to NUMERIC, not 0.07');
$customers()->where('ID', 3)->update(['KREDIT' => 8]);
Assert::same(8.0, $customers()->get(3)->credit, 'an integer to NUMERIC, not 0.08');

$created = $customers()->insert(['ID' => 9, 'NAZEV' => 'Nový', 'KOD' => '0009', 'AKTIVNI' => true]);
Assert::same(9, $created->id);
Assert::same('0009', $created->code);

// --- A new row gives its entity, without the key being given

$orders = static fn() => $explorer->table('OBJEDNAVKY');
$ordersBefore = $orders()->count('*');

$identity = $orders()->insert(['ZAKAZNIK_ID' => 1, 'CENA' => 9.5]);
Assert::type(CustomerOrder::class, $identity, 'an identity column');
Assert::true($identity->id >= 100, 'its real key'); // Firebird 3 starts one above START WITH
Assert::same(9.5, $identity->price);
Assert::same($ordersBefore + 1, $orders()->count('*'), 'inserted once');

$trigger = $customers()->insert(['NAZEV' => 'Trigger', 'KOD' => '0500', 'AKTIVNI' => true]);
Assert::type(Customer::class, $trigger, 'a key from a trigger');
Assert::true($trigger->id >= 500, 'its real key');
Assert::same('Trigger', $trigger->name);

// Through a relation, where the parent is added
$parent = $customers()->get(2);
$related = $parent->orders->insert(['CENA' => 4]);
Assert::type(CustomerOrder::class, $related);
Assert::same(2, $related->customerId);
Assert::same($identity->id + 1, $related->id);

// And so a query can answer right after it
Assert::same(['id' => $identity->id + 2, 'price' => 1.5], $processor->process($orders()->insert(['ZAKAZNIK_ID' => 1, 'CENA' => 1.5]), Query::create(['select' => 'id,price'])));

// In a transaction, parent and children together
$saved = $explorer->transaction(static function (Explorer $db): Customer {
    $customer = $db->table('ZAKAZNICI')->insert(['NAZEV' => 'Spolu', 'KOD' => '0501', 'AKTIVNI' => false]);
    $db->table('OBJEDNAVKY')->insert(['ZAKAZNIK_ID' => $customer->id, 'CENA' => 2]);
    $db->table('OBJEDNAVKY')->insert(['ZAKAZNIK_ID' => $customer->id, 'CENA' => 3]);
    return $customer;
});
Assert::same(2, $saved->orders->count('*'));

$beforeFailure = $customers()->count('*');
Assert::exception(static fn() => $explorer->transaction(static function (Explorer $db): void {
    $customer = $db->table('ZAKAZNICI')->insert(['NAZEV' => 'Zpět', 'KOD' => '0502', 'AKTIVNI' => false]);
    $db->table('OBJEDNAVKY')->insert(['ZAKAZNIK_ID' => $customer->id + 1000, 'CENA' => 2]);
}), Nette\Database\ForeignKeyConstraintViolationException::class);
Assert::same($beforeFailure, $customers()->count('*'), 'a failure takes back all of it');

// What was fine stays fine
$withKey = $orders()->insert(['ID' => 50, 'ZAKAZNIK_ID' => 1, 'CENA' => 1]);
Assert::same(50, $withKey->id, 'a key given');
Assert::exception(static fn() => $customers()->insert(['ID' => $trigger->id, 'NAZEV' => 'x', 'KOD' => '0000', 'AKTIVNI' => true]), Nette\Database\UniqueConstraintViolationException::class);
Assert::exception(static fn() => $customers()->insert(['NAZEV' => 'y', 'KOD' => '0000']), Nette\Database\NotNullConstraintViolationException::class);

// Also a column name which has to be delimited, and a value with what's special in SQL
$quoted = $customers()->insert(['NAZEV' => "O'Neil ?", 'KOD' => '0503', 'AKTIVNI' => true]);
Assert::same("O'Neil ?", $quoted->name);
