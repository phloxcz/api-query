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
    entities:               # what a query can reach, for the whole application
        App\Entity\User:
            relations: [role, orders]
            computed:       # what events add, for the documentation
                canManage: bool
        App\Entity\Order:
            relations: [user, items]
        App\Entity\File:
            resource: App\Api\FileResource   # stands for the entity everywhere, see below
```

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

What the query can reach comes from `apiQuery.entities`. To differ from it at one place, or to use a resource, say so with `#[QueryOf]`. The same attribute gives the documentation its fields and the shape of the response.

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
    public function list(#[FromBody, QueryOf(User::class, relations: ['role', 'orders.items'])] Query $query): array
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

### Entities

A query can reach the readable properties of the entity.

- `access: w`, like a password, doesn't exist for a query: it can't be selected, filtered, sorted or searched, not even through a relation. **A value which an event only masks in the output can still be filtered by**, so that a client can guess it character by character. Make anything secret `access: w`.
- Relations are allowed only by name. `apiQuery.entities` lists them for each entity, and they apply at every level, up to `maxDepth`. `relations: ['role', 'orders.items']` of `#[QueryOf]` or `process()` replaces them for that query: it allows `role`, `orders` and `items` under it, and nothing else.
- A virtual property can be selected when named, and can't be filtered by.
- What an event adds with `set` is always output. List it under `computed` for it to be documented and named in `select`.

### Resources

A resource says what clients see of an entity. It's a class with `#[Resource]`, which names the fields to show, and declares only what differs from the entity:

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
        public ?RoleResource $role = null,                                       // a relation
        #[Field('activeOrders')] public array $purchases = [],                   // a relation under another name
    ) {
    }
}
```

| `fields` | What the query can reach |
|---|---|
| leaving it out | what the class declares |
| a list of names | those, and what the class declares |
| `'*'` | all readable properties but relations, and what the class declares |

Types and descriptions of named fields come from the entity. What the class declares wins over a named field of the same name, and with a star, a property declared under another name isn't shown under its own as well.

Clients use the names of the resource, and everything else of the entity isn't there. A star never reaches relations, so a relation has to be declared, with another resource as its type. To leave one out, just don't declare it.

A mistake in a resource, such as a field the entity doesn't have, a write-only one or a misspelled `except`, is an exception for you rather than an error for a client.

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

#### A resource for the whole application

`#[QueryOf]` holds for one parameter, and a parameter without it gets the whole entity. To have a resource stand for an entity everywhere, say so in the configuration:

```neon
apiQuery:
    entities:
        App\Entity\File:
            resource: App\Api\FileResource
```

Then a plain `Query` over `File` follows the resource, and so does reaching `File` through a relation of another entity, in `select`, `filter` and `sort`. Naming the entity doesn't get around it. For one place to differ, name another resource with `#[QueryOf]` or `process()`, which is also how to document a parameter, because a plain `Query` doesn't know what it's going to be about.

An entity with a resource can't have `relations` or `computed` here, as the resource declares them.

A field with no property on the entity is `#[Computed]`, and can be selected but not filtered or sorted by:

```php
use Phlox\ApiQuery\Attributes\Computed;

#[Resource(User::class)]
final readonly class UserResource
{
    public function __construct(
        public int $id,
        #[Computed('makeLabel')] public string $label,   // by the method below
        #[Computed] public ?bool $canManage = null,      // by onProcessedEntity, which can use services
    ) {
    }

    public static function makeLabel(User $user): string
    {
        return "{$user->fullName} <{$user->email}>";
    }
}
```

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

`process()` also takes these directly, and they win over `#[QueryOf]`:

```php
$this->processor->process($selection, $query, UserResource::class);
$this->processor->process($selection, $query, relations: ['role']);
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

```php
$processor->onSelection[] = function (ProcessSelectionEventData $event): void {
    // Before a collection is read, at every level; for access control
    $event->selection->where('company_id', $this->user->companyId);
};
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

**A filter through a relation doesn't go through `onSelection`.** It only tells whether a record matches, but that can reveal what's in a collection a client can't read. Don't allow a relation whose rows are limited by access rights only in this event: state the limit as `condition:` of the relation, which holds in filters too, or use a resource without it.

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
- **Relations have to be allowed.** A query reaching a relation not named in `apiQuery.entities`, `relations` or a resource gets `400`. List what your clients use in `apiQuery.entities`.
- **Names in `select` that don't exist get `400`,** including what an event adds. List that under `computed`.
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
