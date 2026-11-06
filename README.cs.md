# phloxcz/api-query

[English](README.md) | **Česky**

**Dotazovací jazyk pro [API Phlox](https://github.com/phloxcz/api-core/blob/master/README.cs.md): filtrování, řazení, stránkování a výběr polí, omezené na pole, která deklarujete.**

Formát filtrů odpovídá Kendo UI, takže s ním grid může mluvit přímo. Dotaz dosáhne jen na deklarovaná pole, ať se ptá kterákoli jeho část: název, který deklarovaný není, neexistuje. Názvy se berou z mapy a hodnoty jdou do parametrů, takže nic, co klient pošle, se nestane součástí SQL.

> ⚠️ Balíček se aktivně vyvíjí. API zatím není stabilní.

## Požadavky

PHP 8.4+, [`phloxcz/api-core`](https://github.com/phloxcz/api-core/blob/master/README.cs.md) a [`phloxcz/entity-database`](https://github.com/phloxcz/entity-database/blob/master/README.cs.md).

Otestováno na SQLite, MySQL 8, PostgreSQL 16, SQL Serveru 2022 a s [`phloxcz/firebird-database`](https://github.com/phloxcz/firebird-database/blob/master/README.cs.md) na Firebirdu 3, 4 a 5.

## Instalace

```bash
composer require phloxcz/api-query
```

```neon
extensions:
    apiQuery: Phlox\ApiQuery\DI\Extension

apiQuery:
    maxPageSize: 100        # větší stránka se na tuto velikost ořízne a je zároveň výchozí
    maxDepth: 3             # jak hluboko může dotaz jít přes relace
    maxRows: 10000          # řádky kolekcí v jedné odpovědi; dotaz, který chce víc, dostane 400
    ignoreDiacritics: false # vyžaduje podporu databáze, viz níže
    entities:               # na co dotaz dosáhne, pro celou aplikaci
        App\Entity\User:
            relations: [role, orders]
            computed:       # co přidávají události, kvůli dokumentaci
                canManage: bool
        App\Entity\Order:
            relations: [user, items]
        App\Entity\File:
            resource: App\Api\FileResource   # platí pro entitu všude, viz níže
```

## Použití

Kontroleru stačí parametr `Query`:

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

Na co dotaz dosáhne, určuje `apiQuery.entities`. Pokud se má jedno místo lišit, nebo chcete použít Resource, řekněte to přes `#[QueryOf]`. Stejný atribut dodá dokumentaci pole a tvar odpovědi.

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

Chybějící dotaz je prázdný dotaz a `#[QueryOf]` platí i pro něj, takže vynecháním se nic neobejde. Podmínka na selection zůstává a dotaz ji jen zužuje: `$this->db->table('users')->where('company_id', $id)`. Selection si podmínky dotazu potom ponechá, takže ji lze číst znovu, třeba pro report.

Dotaz lze před provedením změnit, například když jedno řazení chcete řešit sami:

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

## Na co dotaz dosáhne

### Entity

Dotaz dosáhne na čitelné vlastnosti entity.

- `access: w`, jako u hesla, pro dotaz neexistuje: nejde vybrat, filtrovat, řadit ani prohledávat, ani přes relaci. **Podle hodnoty, kterou událost jen maskuje ve výstupu, se pořád dá filtrovat,** takže ji klient může uhodnout znak po znaku. Cokoli tajného označte `access: w`.
- Relace jsou povolené jen podle názvu. `apiQuery.entities` je vyjmenovává pro každou entitu a platí na každé úrovni, až do `maxDepth`. `relations: ['role', 'orders.items']` u `#[QueryOf]` nebo `process()` je pro daný dotaz nahradí: povolí `role`, `orders` a pod ním `items` a nic jiného.
- Virtuální vlastnost lze vybrat, když je uvedená, a nelze podle ní filtrovat.
- Co událost přidá přes `set`, jde na výstup vždy. Uveďte to v `computed`, aby to bylo dokumentované a dalo se to uvést v `select`.

### Resource

Resource říká, co klienti z entity vidí. Je to třída s `#[Resource]`, která vyjmenuje pole k zobrazení a deklaruje jen to, čím se od entity liší:

```php
use Phlox\ApiQuery\Attributes\Field;
use Phlox\ApiQuery\Attributes\Resource;

// Všechno z entity, třeba u číselníku. Nový sloupec se objeví sám
#[Resource(Type::class, fields: '*')]
final class TypeResource
{
}

// Všechno kromě těchto
#[Resource(Version::class, fields: '*', except: ['blobVersionId'])]
final class VersionResource
{
}

// Jen tato, takže nový sloupec zůstane skrytý, dokud ho sem nepřidáte
#[Resource(User::class, fields: ['id', 'email', 'createdAt'])]
final readonly class UserResource
{
    /** @param list<OrderResource> $purchases */
    public function __construct(
        #[Field('email', searchable: true)] public string $login,                // pod jiným názvem
        #[Field(filterable: false)] public ?string $previewToken = null,         // lze číst, ne filtrovat ani řadit
        public ?RoleResource $role = null,                                       // relace
        #[Field('activeOrders')] public array $purchases = [],                   // relace pod jiným názvem
    ) {
    }
}
```

| `fields` | Na co dotaz dosáhne |
|---|---|
| neuvedeno | co třída deklaruje |
| seznam názvů | ta pole a co třída deklaruje |
| `'*'` | všechny čitelné vlastnosti kromě relací a co třída deklaruje |

Typy a popisy vyjmenovaných polí se berou z entity. To, co třída deklaruje, má přednost před vyjmenovaným polem stejného názvu, a s hvězdičkou se vlastnost deklarovaná pod jiným názvem nezobrazí zároveň i pod svým.

Klienti používají názvy z Resource a nic dalšího z entity pro ně neexistuje. Hvězdička nikdy nedosáhne na relace, takže relace musí být deklarovaná a jejím typem musí být jiná Resource. Když ji chcete vynechat, prostě ji nedeklarujte.

Chyba v Resource, třeba pole, které entita nemá, pole jen pro zápis nebo překlep v `except`, je výjimka pro vás, ne chyba pro klienta.

Resource říká jen to, co jde ven. Pro data z požadavku použijte třídu s `#[Dto]`, viz [Ukládání dat](https://github.com/phloxcz/rest-api/blob/master/README.cs.md#ukládání-dat):

| | `#[Resource]` | `#[Dto]` |
|---|---|---|
| Směr | ven | dovnitř, a ven, když ho metoda vrátí |
| Instance | nevzniká, dotaz vrací pole | vzniká z požadavku, nebo ji vytvoříte sami |
| Pole | klient si vybírá přes `select` | všechna |
| Zdroj | vlastnost entity | cokoli |
| Povinná pole | žádná | podle deklarace |
| Omezení | nekontrolují se | kontrolují se |
| Relace | jako stránka nebo objekt | obyčejný seznam nebo objekt |

#### Resource pro celou aplikaci

`#[QueryOf]` platí pro jeden parametr a parametr bez něj dostane celou entitu. Aby Resource platila pro entitu všude, uveďte to v konfiguraci:

```neon
apiQuery:
    entities:
        App\Entity\File:
            resource: App\Api\FileResource
```

Obyčejné `Query` nad `File` se pak řídí Resource a stejně tak i cesta k `File` přes relaci jiné entity, v `select`, `filter` i `sort`. Uvedení entity to neobejde. Pokud se má jedno místo lišit, uveďte jinou Resource v `#[QueryOf]` nebo `process()`. Tak se také parametr zdokumentuje, protože obyčejné `Query` neví, čeho se bude týkat.

Entita s Resource tady nemůže mít `relations` ani `computed`, protože ty deklaruje Resource.

Pole bez vlastnosti na entitě je `#[Computed]` a lze ho vybrat, ale ne podle něj filtrovat ani řadit:

```php
use Phlox\ApiQuery\Attributes\Computed;

#[Resource(User::class)]
final readonly class UserResource
{
    public function __construct(
        public int $id,
        #[Computed('makeLabel')] public string $label,   // podle metody níže
        #[Computed] public ?bool $canManage = null,      // z onProcessedEntity, kde lze použít služby
    ) {
    }

    public static function makeLabel(User $user): string
    {
        return "{$user->fullName} <{$user->email}>";
    }
}
```

`process()` vrací pole, ne instance Resource, protože dotaz obvykle vybírá jen některá pole. Ze stejného důvodu není žádné pole Resource v dokumentaci povinné. Resource se dokumentuje podle toho, co z ní skutečně vychází, takže kolekce je stránka, ať je deklarovaná s jakýmkoli typem.

`#[Field(filterable: false)]` na relaci znamená, že přes ni vůbec nejde filtrovat ani řadit.

### Čisté SQL

U reportu, který není entita, vyjmenujte pole spolu s dotazem. Stačí mu připojení Nette Database, takže `entityDatabase` nemusí být nakonfigurované a není co generovat:

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

`SqlProcessor` je běžná služba. Sloupec, který mezi poli není, zůstane skrytý. Aby byla pole i v dokumentaci, deklarujte je u parametru a ve volání je vynechte:

```php
public function report(#[QueryOfSql(['id' => new SqlField('id', Field::Integer), 'email' => 'mail'], searchable: ['email'])] Query $query): array
{
    return $this->sqlProcessor->process('SELECT id, email AS mail FROM users', [], $query);
}
```

### Přepsání

`process()` přijímá totéž i přímo a má přednost před `#[QueryOf]`:

```php
$this->processor->process($selection, $query, UserResource::class);
$this->processor->process($selection, $query, relations: ['role']);
```

## Jazyk

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

**`select`** vyjmenovává pole a v závorkách to, co vybrat z relace. `*`, které je výchozí, znamená všechny sloupce. Samotný název relace znamená všechny její sloupce a žádná relace se nevybere, pokud není uvedená.

**`criteria`** obsahuje `search`, `page`, `pageSize`, `sort` a `filter` pro vnořené kolekce podle jejich cesty. Kritéria kořene mohou být přímo v dotazu, nebo pod `@`.

**Stránkování** používá `page` a `pageSize`, nebo `take` a `skip`, jak je posílá Kendo UI, přičemž `skip` nemusí vycházet na začátek stránky.

**`filter`** může být skupina, seznam podmínek nebo jedna podmínka a skupiny lze zanořovat. Podmínka bez operátoru znamená rovnost. Cesta jako `role.name` nebo `orders.items.name` vede přes povolené relace. Záznam s více shodami v kolekci přijde jednou a počítá se jednou.

| Operátor | Také zapsaný jako |
|---|---|
| `eq` | `=`, `==`, `isequalto`, `equals`, `equalto`, `equal` |
| `neq` | `<>`, `!=`, `isnotequalto`, `notequals`, `notequalto`, `notequal`, `ne` |
| `lt`, `gt` | `<`, `islessthan`, `lessthan`, `less`; `>`, `isgreaterthan`, `greaterthan`, `greater` |
| `lte`, `gte` | `<=`, `islessthanorequalto`, `lessthanequal`, `le`; `>=`, `isgreaterthanorequalto`, `greaterthanequal`, `ge` |
| `startswith`, `endswith` | `starts`; `ends` |
| `contains` | `containing`, `substring`, `substringof` |
| `doesnotcontain`, `doesnotstartwith`, `doesnotendwith` | `notcontains` |
| `in`, `not in` | `notin`; přijímá seznam |
| `isnull`, `isnotnull` | `is null`, `null`; `is not null`, `notnull` |
| `isempty`, `isnotempty` | `isnullorempty`; `isnotnullorempty`; null nebo prázdný text |
| `today`, `thisweek` | |
| `isweek` | datum, nebo číslo týdne v aktuálním roce |

Textové operátory ignorují velikost písmen a fungují jen na textu. `%` a `_` v hodnotě jsou obyčejné znaky. Hodnoty se kontrolují podle typu pole, takže `{"field": "age", "value": "abc"}` skončí chybou a do databáze se nedostane.

**`search`** hledá každé slovo v některém z prohledávatelných sloupců a sloupce jsou alternativy. Aby se ignorovala diakritika, nastavte `ignoreDiacritics: true`. Vyžaduje MySQL 8+, SQL Server nebo PostgreSQL s `CREATE EXTENSION unaccent` a na SQLite selže.

## Odpovědi

Kolekce má vždy stejný tvar, na každé úrovni:

```json
{"data": [{"id": 1, "orders": {"data": [{"id": 5}], "total": 1, "page": 1, "pageSize": 20, "from": 0, "to": 0}}], "total": 42, "page": 2, "pageSize": 20, "from": 20, "to": 39}
```

`from` a `to` se počítají od 0 a za posledním řádkem jsou `null`. Jeden záznam dá objekt a `null` dá `null`. Kolekce bez limitů dá obyčejný seznam, viz [Limity](#limity).

Data se řídí konfigurací: `dateTimeFormat` (výchozí ISO 8601), `dateFormat`, `timeFormat` a `timeIntervalFormat`.

## Chyby

Všechno špatné v dotazu se hlásí najednou, dřív než se cokoli přečte, se stavem `400` a kódem `-32602`:

```json
{"error": {"code": -32602, "message": "Invalid query.", "fields": {
    "select.email": "is not a known field.",
    "filter.age": "must be a number.",
    "orders/sort.nothing": "is not a known field."
}}}
```

Nedeklarované pole, pole jen pro zápis a pole, které neexistuje, dostanou stejnou odpověď, takže je klient nerozliší.

## Limity

| Limit | Hodnota |
|---|---|
| Řádky jedné kolekce | `maxPageSize` |
| Řádky kolekcí v jedné odpovědi, počítané před načtením vnořených | `maxRows` |
| Hloubka `select` a cest ve filtrech | `maxDepth` |
| Hloubka skupin ve filtru | 10 |
| Podmínky jednoho filtru | 200 |
| Hodnoty `in` | 1000 |
| Znaky hodnoty | 1000 |
| Řazení | 20 |

Pro export může aplikace limity řádků zrušit. `process($selection, $query, skipLimitOffset: true)` je zruší pro kořen a `skipLimitOffset: ['orders', 'orders.items']` pro tyto kolekce, s `@` pro kořen. Taková kolekce je obyčejný seznam bez `total` a `maxRows` se na celý dotaz nevztahuje. Nikdy to nenechávejte na rozhodnutí klienta.

## Události

```php
$processor->onSelection[] = function (ProcessSelectionEventData $event): void {
    // Před čtením kolekce, na každé úrovni; pro řízení přístupu
    $event->selection->where('company_id', $this->user->companyId);
};
```

| Událost | Co může |
|---|---|
| `onSelection` | přidat podmínky, vynechat kolekci přes `shouldSkip` |
| `onEntity` | vynechat záznam přes `shouldSkip` |
| `onProcessedEntity` | přidat pole přes `set`, odebrat je přes `unset`, změnit `output`, vynechat záznam přes `shouldSkip` |
| `onSearch` | změnit `columns` |
| `SqlProcessor::$onProcessedRow` | změnit `output`, vynechat řádek přes `shouldSkip` |

Událost dostane entitu a může z ní číst cokoli, i to, co dotaz nevybral. Dokud má `onEntity` nebo `onProcessedEntity` posluchače, čtou se všechny sloupce. Bez něj jen ty potřebné.

`$event->selection->getEntityName()` řekne, jaké entity se kolekce týká.

**Filtr přes relaci neprochází přes `onSelection`.** Říká jen to, zda záznam odpovídá, ale i tím může prozradit obsah kolekce, kterou klient nesmí číst. Nepovolujte relaci, jejíž řádky omezují přístupová práva jen v této události: uveďte omezení jako `condition:` relace, které platí i ve filtrech, nebo použijte Resource bez ní.

## Dokumentace

S `phloxcz/rest-api` nebo `phloxcz/rpc-api` se parametr `Query` dokumentuje s celou strukturou. Entita uvedená kdekoli v dokumentaci, třeba v `#[Response(200, ['@pager', User::class])]`, se popíše podle toho, na co z ní dotaz dosáhne. `#[QueryOf]` a `#[QueryOfSql]` navíc vyjmenují, co lze uvést ve filtru a řazení, a dodají tvar odpovědi:

```php
public function detail(int $id, #[QueryOf(UserResource::class, single: true)] Query $query): ?array
```

`single: true` znamená jeden záznam místo stránky.

## Přechod z abrad450/api-server-nette

Celý postup je v [`MIGRATION.cs.md`](https://github.com/phloxcz/api-core/blob/master/MIGRATION.cs.md). Tento balíček se týká:

| Staré | Nové |
|---|---|
| `AbraD450\ApiServer\Query\Query` | `Phlox\ApiQuery\Query` |
| `AbraD450\ApiServer\Query\Processor` | `Phlox\ApiQuery\Processor` |
| `AbraD450\ApiServer\Query\SqlQueryProcessor` | `Phlox\ApiQuery\SqlProcessor` |
| `$this->processor` z `ApiController` | `use UsesQueryProcessor` ve vašem kontroleru |
| `apiServer: query:` | `apiQuery:` |

Formát dotazů zůstává, včetně `criteria`, `take`, `skip` a operátorů. Změny, které je potřeba v existujícím projektu zkontrolovat:

- **Odpověď kolekce má jeden tvar,** ten z `pagination: true`: `{data, total, ...}` na každé úrovni a obyčejný seznam se `skipLimitOffset`. S `pagination: auto` nebo `false` se tím mění, co klienti dostávají. `$this->processor->process()` si `skipLimitOffset` ponechává se stejným významem: `true` se týká kořene a seznam uvádí cesty s tečkami.
- **Relace musí být povolené.** Dotaz na relaci neuvedenou v `apiQuery.entities`, `relations` nebo Resource dostane `400`. V `apiQuery.entities` vyjmenujte, co vaši klienti používají.
- **Neexistující názvy v `select` dostanou `400`,** včetně toho, co přidává událost. Uveďte to v `computed`.
- **Špatné názvy dostanou `400`.** Neznámá pole se dřív tiše přeskakovala, i ve filtrech, které pak vracely víc řádků, než měly. Zkontrolujte pole, která posílají vaše gridy.
- **Špatné hodnoty dostanou `400`,** třeba text místo čísla, a stejně tak textové operátory na čemkoli jiném než textu, jako `contains` na čísle. Neznámé operátory a směry dřív skončily výjimkou, nebo prošly.
- **`access: w` je skryté všude.** Dřív bylo skryté jen v `select`, zatímco filtrování, řazení i hledání podle něj fungovalo. Podle sloupce ponechaného jako `rw` a maskovaného událostí, třeba hesla, se pořád dá filtrovat, jako vždycky. Změňte ho na `w` a maskování odstraňte, protože čtení takové vlastnosti vyhodí výjimku.
- **Filtry přes relace jsou poddotazy.** Záznamy se už neopakují pro každou shodu a `total` je správně. Podmínka na kolekci teď znamená „má záznam odpovídající podmínce". `role.name` s `isnull` neodpovídá záznamu bez role, protože není s čím porovnávat.
- **`isempty`** znamená null nebo prázdný text, stejně jako `isnullorempty`. Dřív znamenalo jen null, takže může odpovídat víc záznamů.
- **`isweek` s číslem** znamená daný týden aktuálního roku. Dřív odpovídal danému týdnu kteréhokoli roku.
- **`SqlProcessor` potřebuje svá pole.** Název chybějící v mapě se dřív předal rovnou do SQL. Teď se odmítne a vrací se jen uvedené sloupce.
- **Změnily se argumenty `process()`.** `$strict` je pryč a `$skipLimitOffset` je teď pojmenovaný argument na páté pozici. `processEntity()` a `processSelection()` jsou pryč, protože `process()` přijímá obojí, a stejně tak `configure()`, `getConfig()`, `getQueryAdapter()` a `generateFilter()`. Události si ponechávají `shouldSkip`, `set` a `unset` a třídy se přesouvají do `Phlox\ApiQuery\Events`.
- **Criteria drží obyčejné seznamy.** `$criteria->getSort()` je pole objektů `Sort`, takže z `->count()` je `count()` a z `new SortArrayList()` je `[]`. `$criteria->setSort()` a `$sort->field` se `$sort->dir` fungují jako dřív.
- **`$selection->getEntityName()`** zůstává jako dřív. Samotné entity se přesouvají z `abrad450/mapped-database-nette` do `phloxcz/entity-database`.
- **Vnořené stránkování funguje.** Offsety vnořených kolekcí Nette u více rodičů dřív ztrácelo. Klienti, kteří na tom stavěli, dostanou jiné stránky.
- **Hledání potřebuje prohledávatelné pole,** jinak dostane `400`, místo aby se ignorovalo. PostgreSQL už si `unaccent` nevytváří sám a diakritiku ignoruje jen s `ignoreDiacritics: true`.
- **Firebird** potřebuje [`phloxcz/firebird-database`](https://github.com/phloxcz/firebird-database/blob/master/README.cs.md). Hledání používá `CONTAINING` jako dřív a `ignoreDiacritics` tam není k dispozici, protože to závisí na collation sloupce.

## Licence

[MIT](LICENSE)
