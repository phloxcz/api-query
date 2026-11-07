# TODO

## Kontrola názvů metod, aby překlep neprošel potichu

Knihovna hledá některé metody podle jména. Když se jmenují jinak, nic nenahlásí:

- `restrict()` v Resource: s překlepem (`restict()`) nebo jiným názvem (`restrictAccess()`) se nezavolá a data jdou ven bez omezení. Je to bezpečnostní riziko.
- Metody posluchačů s `QueryListener`: s překlepem (`onProcesedEntity()`) se nezaregistrují.
- Metody `#[Computed]` jsou v pořádku: pole je deklarované, takže chybějící metoda skončí výjimkou.

Rozhodnuto (2026-11-07):

1. **Posluchače: rozhraní na každou událost** místo obecného `QueryListener`, tedy `SelectionListener`, `EntityListener`, `ProcessedEntityListener`, `SearchListener` a `ProcessedRowListener`. Signaturu pak hlídá PHP a IDE metodu doplní. Služby dál přes konstruktor.
2. **`restrict()` a metody dopočítaných polí: konvence zůstává, přibude kontrola.** Resource nesmí mít veřejnou statickou metodu, kterou knihovna nepoužije, tedy která není `restrict()` ani metodou pole `#[Computed]`. Chyba při načtení Resource, takže se ozve už při kompilaci kontejneru (dokumentace Resource načítá), nejpozději při prvním dotazu. Pomocné metody budou `private`. Služby zůstanou jako povinné parametry.

Zamítnuto: rozhraní nebo abstraktní třída s `#[\Override]` pro `restrict()`. Rozhraní dovolí jen nepovinné parametry navíc, takže by služby musely být nullable (`?User $user = null`), PHPStan by chtěl ošetřit `null`, který nikdy nepřijde, a zapomenuté rozhraní by stejně nechalo tichou metodu.
