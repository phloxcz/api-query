# phloxcz/api-query

**English** | [Česky](README.cs.md)

**Query language for [Phlox APIs](https://github.com/phloxcz/api-core): filtering, sorting, paging and field selection, limited to the fields you declare.**

The filter format is the one of Kendo UI, so a grid can talk to it directly. A query can only reach declared fields, whichever part of it asks: a name that isn't declared doesn't exist. Names come from a map and values go to parameters, so nothing a client sends becomes part of the SQL.

> ⚠️ This package is under active development. The API is not yet stable.

## Requirements

PHP 8.4+, [`phloxcz/api-core`](https://github.com/phloxcz/api-core) and [`phloxcz/entity-database`](https://github.com/phloxcz/entity-database).

Tested on SQLite, MySQL 8, PostgreSQL 16, SQL Server 2022 and, with [`phloxcz/firebird-database`](https://github.com/phloxcz/firebird-database), Firebird 3, 4 and 5.

## Installation

```bash
composer require phloxcz/api-query
```

```neon
extensions:
    apiQuery: Phlox\ApiQuery\DI\Extension

apiQuery:
    maxPageSize: 100        # a larger page is cut to it, and it's the default
    maxDepth: 3             # how deep a query can go through relations
    maxRows: 10000          # rows of collections in one response; a query wanting more gets 400
    ignoreDiacritics: false # needs the support of the database, see below
    resources: App\Api\Resource   # where resources are; a list for more namespaces
```

There's nothing more to configure. What a query can reach is in [resources](#resources), and an entity without one is shown [by default](#an-entity-without-a-resource).

## Usage

A controller needs nothing more than a `Query` parameter:

```php
class UserController extends ApiController
{
    use UsesQueryProcessor;

    #[HttpPost]
    #[Route('query')]
    public function query(#[FromBody] ?Query $query = null): array
    {
        return $this->processor->process($this->db->table('users'), $query);
    }
}
```

The query follows the resource of `User`, wherever it's used. `#[QueryOf]` is only for where one place differs, naming another resource, and to tell the documentation what a parameter is about:

```php
use Phlox\ApiCore\ApiController;
use Phlox\ApiQuery\Attributes\QueryOf;
use Phlox\ApiQuery\Query;
use Phlox\ApiQuery\UsesQueryProcessor;
use Phlox\RestApi\Attributes\FromBody;
use Phlox\RestApi\Attributes\HttpPost;

class UserController extends ApiController
{
    use UsesQueryProcessor;

    public function __construct(private readonly Explorer $db)
    {
    }

    #[HttpPost]
    public function list(#[FromBody, QueryOf(UserResource::class)] Query $query): array
    {
        return $this->processor->process($this->db->table('users'), $query);
    }
}
```

A missing query is an empty one, and `#[QueryOf]` holds for it too, so leaving it out avoids nothing. A condition on the selection stays, and a query only narrows it down: `$this->db->table('users')->where('company_id', $id)`. The selection keeps the conditions of the query afterwards, so it can be read again, such as for a report.

A query can be changed before it's carried out, such as to handle one sort on your own:

```php
$criteria = $query->getCriteriaByPath();
$remaining = [];
foreach ($criteria->getSort() as $sort) {
    if ($sort->field === 'number') {
        $selection->order("NATURAL_SORT(number) {$sort->dir}");
    } else {
        $remaining[] = $sort;
    }
}
$criteria->setSort($remaining);
```

## What a query can reach

Everything a client sees of an entity is in one place: its resource. A resource stands for its entity wherever a query reaches it, at the root or through a relation, in `select`, `filter` and `sort`, and naming the entity doesn't get around it.

### An entity without a resource

It's shown with all its readable columns, no relations and no limits. That suits code lists, which anyone may read, so they need no class at all. An entity needing relations, limits or other fields gets a resource.

- `access: w` in `entities.neon`, like a password, doesn't exist for a query: it can't be selected, filtered, sorted or searched, not even through a relation. **A value which an event only masks in the output can still be filtered by**, so that a client can guess it character by character. Make anything secret `access: w`.
- A virtual property can be selected when named, and can't be filtered by.
- No relation leads anywhere from it, so it can't open a way to other tables. It's reached only where your code says: a controller reading its table, or a resource with a relation to it.

### Resources

A resource is a class with `#[Resource]`, found in a namespace of `apiQuery.resources`. It names the fields to show, and declares only what differs from the entity:

```php
use Phlox\ApiQuery\Attributes\Field;
use Phlox\ApiQuery\Attributes\Resource;

// Everything of the entity, such as for a code list. A new column shows up by itself
#[Resource(Type::class, fields: '*')]
final class TypeResource
{
}

// Everything but these
#[Resource(Version::class, fields: '*', except: ['blobVersionId'])]
final class VersionResource
{
}

// Only these, so a new column stays hidden until it's added here
#[Resource(User::class, fields: ['id', 'email', 'createdAt'])]
final readonly class UserResource
{
    /** @param list<OrderResource> $purchases */
    public function __construct(
        #[Field('email', searchable: true)] public string $login,                // under another name
        #[Field(filterable: false)] public ?string $previewToken = null,         // can be read, not filtered or sorted by
        public ?Role $role = null,                                               // a relation, shown as Role is
        #[Field('activeOrders')] public array $purchases = [],                   // a relation under another name
    ) {
    }
}
```

A relation is declared with the type of what it leads to: a resource, or the entity, which is then shown as its own resource, or by default without one. Only declared relations can be reached, and a star never reaches one. To leave one out, just don't declare it.

| `fields` | What the query can reach |
|---|---|
| leaving it out | what the class declares |
| a list of names | those, and what the class declares |
| `'*'` | all readable properties but relations, and what the class declares |

Types and descriptions of named fields come from the entity. What the class declares wins over a named field of the same name, and with a star, a property declared under another name isn't shown under its own as well.

Clients use the names of the resource, and everything else of the entity isn't there.

A mistake in a resource, such as a field the entity doesn't have, a write-only one, a misspelled `except` or a relation typed with a resource of another entity, is an exception for you rather than an error for a client.

An entity has one resource standing for it. Two of them in `apiQuery.resources` fail when the container compiles, unless all but one say `#[Resource(..., default: false)]`. Such another view is used only where `#[QueryOf]` or `process()` names it.

### What can be read

A public static `restrict()` of a resource limits what of its entity can be read. It gets the selection, and services by type after it:

```php
use Nette\Security\User;
use Phlox\EntityDatabase\Database\Table\Selection;

#[Resource(Project::class, fields: '*')]
final readonly class ProjectResource
{
    public static function restrict(Selection $projects, User $user): void
    {
        if (!$user->isInRole('admin')) {
            $projects->where('owner_id', $user->getId());
        }
    }
}
```

It holds wherever projects are read:

- at the root, and in `total`,
- in a collection of another record, such as `owner.projects`,
- for a single record through a relation, such as `task.project`, which is null when it can't be read,
- in a filter or a sort through a relation, which would otherwise tell what's in records the client can't read.

The entity needs a single primary key, as the last two use it in a subquery. Services come from the container, so the `Processor` has to come from there too.

### Computed fields

A field with no property on the entity is `#[Computed]`, by a public static method named as the field, or the one given. It gets the entity, and services by type after it:

```php
use Phlox\ApiQuery\Attributes\Computed;

#[Resource(Task::class, fields: '*')]
final readonly class TaskResource
{
    public function __construct(
        #[Computed] public ?bool $canEdit = null,
        #[Computed('makeLabel')] public string $label = '',
    ) {
    }

    public static function canEdit(Task $task, User $user): bool
    {
        return $user->isInRole('admin') || $task->assigneeId === $user->getId();
    }

    public static function makeLabel(Task $task): string
    {
        return "#{$task->id} {$task->title}";
    }
}
```

It's computed only when a query selects it, by name or by a star, and it can't be filtered or sorted by. The method can read anything of the entity, so the record is read with all its columns then.

A resource only says what goes out. To take data from a request, use a class with `#[Dto]`, see [Saving data](https://github.com/phloxcz/rest-api#saving-data):

| | `#[Resource]` | `#[Dto]` |
|---|---|---|
| Direction | out | in, and out when a method returns it |
| Instance | none, a query gives arrays | created from the request, or by you |
| Fields | a client picks by `select` | all of them |
| Its source | a property of an entity | anything |
| Required fields | none | as declared |
| Constraints | not checked | checked |
| Relations | as a page, or an object | a plain list, or an object |

### What comes out

`process()` returns arrays, not instances of the resource, because a query usually selects only some of the fields. For the same reason, no field of a resource is documented as required. A resource is documented as what comes out of it, so a collection is a page, whatever type it's declared with.

`#[Field(filterable: false)]` on a relation means it can't be filtered or sorted through at all.

### Raw SQL

For a report that isn't an entity, list the fields with the query. It needs only a connection of Nette Database, so `entityDatabase` doesn't have to be configured and there's nothing to generate:

```php
use Phlox\ApiQuery\Fields\Field;
use Phlox\ApiQuery\Fields\SqlField;

$this->sqlProcessor->process(
    'SELECT u.id, u.email AS mail, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.company_id = ?',
    [$companyId],
    $query,
    [
        'id' => new SqlField('id', Field::Integer),
        'email' => new SqlField('mail', searchable: true),
        'role' => 'role_name',
    ],
);
```

`SqlProcessor` is an ordinary service. A column left out of the fields stays hidden. To have the fields documented as well, declare them on the parameter, and leave them out of the call:

```php
public function report(#[QueryOfSql(['id' => new SqlField('id', Field::Integer), 'email' => 'mail'], searchable: ['email'])] Query $query): array
{
    return $this->sqlProcessor->process('SELECT id, email AS mail FROM users', [], $query);
}
```

### Overriding it

`process()` also takes a resource directly, and it wins over `#[QueryOf]`:

```php
$this->processor->process($selection, $query, UserListResource::class);
```

## The language

```json
{
    "select": "id,name,role(id,name),orders(id,items(name))",
    "search": "ann",
    "page": 1,
    "pageSize": 20,
    "sort": [{"field": "name", "dir": "asc"}],
    "filter": {
        "logic": "and",
        "filters": [
            {"field": "name", "operator": "contains", "value": "a"},
            {"logic": "or", "filters": [
                {"field": "age", "operator": "gte", "value": 18},
                {"field": "role.name", "value": "admin"}
            ]}
        ]
    },
    "criteria": {
        "orders": {"pageSize": 5, "sort": [{"field": "id", "dir": "desc"}]},
        "orders/items": {"filter": {"field": "name", "operator": "startswith", "value": "a"}}
    }
}
```

**`select`** lists fields, with what to select of a relation in brackets. `*`, which is the default, means all columns. A relation named alone means all of its columns, and no relation is selected unless named.

**`criteria`** holds `search`, `page`, `pageSize`, `sort` and `filter` for nested collections by their path. The root's can be right in the query, or under `@`.

**Paging** takes `page` and `pageSize`, or `take` and `skip` as Kendo UI sends them, where `skip` need not fall on a page.

**`filter`** can be a group, a list of conditions or one condition, and groups can be nested. A condition with no operator means equality. A path like `role.name` or `orders.items.name` goes through allowed relations. A record with several matches in a collection comes once and counts once.

| Operator | Also written |
|---|---|
| `eq` | `=`, `==`, `isequalto`, `equals`, `equalto`, `equal` |
| `neq` | `<>`, `!=`, `isnotequalto`, `notequals`, `notequalto`, `notequal`, `ne` |
| `lt`, `gt` | `<`, `islessthan`, `lessthan`, `less`; `>`, `isgreaterthan`, `greaterthan`, `greater` |
| `lte`, `gte` | `<=`, `islessthanorequalto`, `lessthanequal`, `le`; `>=`, `isgreaterthanorequalto`, `greaterthanequal`, `ge` |
| `startswith`, `endswith` | `starts`; `ends` |
| `contains` | `containing`, `substring`, `substringof` |
| `doesnotcontain`, `doesnotstartwith`, `doesnotendwith` | `notcontains` |
| `in`, `not in` | `notin`; takes a list |
| `isnull`, `isnotnull` | `is null`, `null`; `is not null`, `notnull` |
| `isempty`, `isnotempty` | `isnullorempty`; `isnotnullorempty`; null or an empty text |
| `today`, `thisweek` | |
| `isweek` | a date, or a number of a week in the current year |

Text operators ignore case and work only on text. `%` and `_` in a value are ordinary characters. Values are checked against the type of a field, so `{"field": "age", "value": "abc"}` gets an error rather than reaching the database.

**`search`** looks for every word in one of the searchable columns, and the columns are alternatives. To ignore accents, set `ignoreDiacritics: true`. It needs MySQL 8+, SQL Server, or PostgreSQL with `CREATE EXTENSION unaccent`, and it fails on SQLite.

## Responses

A collection always gives the same shape, at every level:

```json
{"data": [{"id": 1, "orders": {"data": [{"id": 5}], "total": 1, "page": 1, "pageSize": 20, "from": 0, "to": 0}}], "total": 42, "page": 2, "pageSize": 20, "from": 20, "to": 39}
```

`from` and `to` count from 0, and they're `null` past the last row. One record gives an object, and `null` gives `null`. A collection without limits gives a plain list, see [Limits](#limits).

Dates follow the configuration: `dateTimeFormat` (ISO 8601 by default), `dateFormat`, `timeFormat` and `timeIntervalFormat`.

## Errors

Everything wrong with a query is reported at once, before anything is read, with status `400` and code `-32602`:

```json
{"error": {"code": -32602, "message": "Invalid query.", "fields": {
    "select.email": "is not a known field.",
    "filter.age": "must be a number.",
    "orders/sort.nothing": "is not a known field."
}}}
```

An undeclared field, a write-only one and one that doesn't exist get the same answer, so a client can't tell them apart.

## Limits

| Limit | Value |
|---|---|
| Rows of one collection | `maxPageSize` |
| Rows of collections in one response, counted before nested ones are loaded | `maxRows` |
| Depth of `select` and of filter paths | `maxDepth` |
| Depth of filter groups | 10 |
| Conditions of one filter | 200 |
| Values of `in` | 1000 |
| Characters of a value | 1000 |
| Sorts | 20 |

For an export, the application can lift the limits of rows. `process($selection, $query, skipLimitOffset: true)` lifts them for the root, and `skipLimitOffset: ['orders', 'orders.items']` for those collections, with `@` for the root. Such a collection is a plain list without `total`, and `maxRows` doesn't apply to the whole query. Never let a client decide this.

## Events

Events are for what a resource can't say. What can be read belongs to [`restrict()`](#what-can-be-read), which holds in filters and sorts through relations too, where an event doesn't.

Register a listener once, in the configuration. The processor is a shared service, so a listener added in `startup()` of a controller is added again on every call, such as for each call of a JSON-RPC batch:

```neon
services:
    auditListener: App\Api\AuditListener
    apiQuery.processor:
        setup:
            - '$onProcessedEntity[]' = [@auditListener, onProcessedEntity]
```

| Event | Can do |
|---|---|
| `onSelection` | add conditions, leave out a collection with `shouldSkip` |
| `onEntity` | leave out a record with `shouldSkip` |
| `onProcessedEntity` | add fields with `set`, remove them with `unset`, change `output`, leave out a record with `shouldSkip` |
| `onSearch` | change `columns` |
| `SqlProcessor::$onProcessedRow` | change `output`, leave out a row with `shouldSkip` |

An event gets the entity, and can read anything of it, also what the query didn't select. So as long as `onEntity` or `onProcessedEntity` has a listener, all columns are read. Without one, only those needed.

`$event->selection->getEntityName()` tells which entity a collection is of.

`onSelection` isn't called for a filter or a sort through a relation, nor for a single record reached through one. That's why access control belongs to `restrict()`.

## Documentation

With `phloxcz/rest-api` or `phloxcz/rpc-api`, a `Query` parameter is documented with its full structure. An entity named anywhere in documentation, such as in `#[Response(200, new Paged(User::class))]`, is described by what a query can reach of it. `#[QueryOf]` and `#[QueryOfSql]` also list what a filter and a sort can name, and give the shape of the response:

```php
public function detail(int $id, #[QueryOf(UserResource::class, single: true)] Query $query): ?array
```

`single: true` means one record rather than a page. The page is built and described by [`Paged`](https://github.com/phloxcz/api-core#describing-a-response) of `phloxcz/api-core`, so it's the same everywhere.

That shape is what `process()` returns. A method returning something else, such as the result in an envelope, says so by `#[Response]`, which takes precedence:

```php
#[Response(200, new Shape(['value' => new Paged(UserResource::class)]))]
public function list(#[QueryOf(UserResource::class)] ?Query $query = null): array
{
    return ['value' => $this->processor->process($this->db->table('users'), $query)];
}
```

## Migrating from abrad450/api-server-nette

The whole way is in [`MIGRATION.md`](https://github.com/phloxcz/api-core/blob/master/MIGRATION.md). What concerns this package:

| Old | New |
|---|---|
| `AbraD450\ApiServer\Query\Query` | `Phlox\ApiQuery\Query` |
| `AbraD450\ApiServer\Query\Processor` | `Phlox\ApiQuery\Processor` |
| `AbraD450\ApiServer\Query\SqlQueryProcessor` | `Phlox\ApiQuery\SqlProcessor` |
| `$this->processor` from `ApiController` | `use UsesQueryProcessor` on your controller |
| `apiServer: query:` | `apiQuery:` |

The format of queries stays, including `criteria`, `take`, `skip` and the operators. Changes that need checking in an existing project:

- **The response of a collection has one shape,** the one of `pagination: true`: `{data, total, ...}` at every level, and a plain list with `skipLimitOffset`. With `pagination: auto` or `false`, this changes what clients receive. `$this->processor->process()` keeps `skipLimitOffset` with its meaning: `true` is about the root, and a list names paths with dots.
- **What a query can reach is in resources.** An entity your clients query with relations gets a resource declaring them, and an entity without one shows its columns and no relations. A query reaching anything else gets `400`. Name the namespace of resources in `apiQuery.resources`.
- **Access control goes to `restrict()` of a resource,** from `onSelection`, and computed fields to a method of the resource, from `onProcessedEntity` with `set`. Both get services as parameters.
- **Names in `select` that don't exist get `400`,** including what an event adds. Make it a `#[Computed]` field.
- **Wrong names get `400`.** Unknown fields used to be skipped silently, also in filters, which then returned more rows than asked for. Check the fields your grids send.
- **Wrong values get `400`,** such as text for a number, and so do text operators on anything but text, like `contains` on a number. Unknown operators and directions were an exception or passed through before.
- **`access: w` is hidden everywhere.** It used to be hidden only from `select`, while filtering and sorting by it worked, and so did searching in it. A column kept `rw` and masked by an event, such as a password, can still be filtered by, as it always could. Change it to `w`, and drop the masking, because reading such a property throws.
- **Filters through relations are subqueries.** Records no longer repeat for each match, and `total` is right. A condition on a collection now means "has a record matching this". `role.name` with `isnull` doesn't match a record with no role, as there's no name to compare.
- **`isempty`** means null or an empty text, as `isnullorempty` does. It used to mean only null, so it can match more.
- **`isweek` with a number** means that week of the current year. It used to match that week of any year.
- **`SqlProcessor` needs its fields.** A name missing from the map used to be passed right into the SQL. Now it's refused, and only listed columns are returned.
- **The arguments of `process()` changed.** `$strict` is gone, and `$skipLimitOffset` is now a named argument for the fifth position. `processEntity()` and `processSelection()` are gone, as `process()` takes both, and so are `configure()`, `getConfig()`, `getQueryAdapter()` and `generateFilter()`. Events keep `shouldSkip`, `set` and `unset`, and classes move to `Phlox\ApiQuery\Events`.
- **Criteria hold plain lists.** `$criteria->getSort()` is an array of `Sort`, so `->count()` becomes `count()` and `new SortArrayList()` becomes `[]`. `$criteria->setSort()` and `$sort->field` with `$sort->dir` work as before.
- **`$selection->getEntityName()`** is there as before. Entities themselves move from `abrad450/mapped-database-nette` to `phloxcz/entity-database`.
- **Nested paging works.** Offsets of nested collections used to be lost by Nette for more than one parent. Clients relying on that get different pages.
- **A search needs a searchable field,** otherwise it gets `400` rather than being ignored. PostgreSQL no longer creates `unaccent` on its own and ignores accents only with `ignoreDiacritics: true`.
- **Firebird** needs [`phloxcz/firebird-database`](https://github.com/phloxcz/firebird-database). A search uses `CONTAINING` as before, and `ignoreDiacritics` isn't available there, because that's up to the collation of a column.

## License

[MIT](LICENSE)
