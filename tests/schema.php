<?php

declare(strict_types=1);

/**
 * The test database, the same on every driver.
 *
 * @return array{dsn: string, user: ?string, password: ?string, type: string}
 */
function testDatabase(): array
{
    $dsn = getenv('TEST_DSN') ?: 'sqlite::memory:';

    return [
        'dsn' => $dsn,
        'user' => getenv('TEST_USER') ?: null,
        'password' => getenv('TEST_PASSWORD') ?: null,
        'type' => explode(':', $dsn)[0],
    ];
}

/**
 * A name in SQL. Firebird folds a bare name to uppercase, and the fixtures have lowercase ones.
 */
function q(string $name): string
{
    return testDatabase()['type'] === 'firebird' ? "\"{$name}\"" : $name;
}

function createSchema(PDO $pdo, string $type): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $q = static fn(string $name): string => $type === 'firebird' ? "\"{$name}\"" : $name;

    foreach (['secrets', 'items', 'orders', 'users', 'roles'] as $table) {
        $drop = match ($type) {
            'sqlsrv' => "IF OBJECT_ID('{$table}', 'U') IS NOT NULL DROP TABLE {$table}",
            'firebird' => "DROP TABLE {$q($table)}",
            default => "DROP TABLE IF EXISTS {$table}",
        };
        try {
            $pdo->exec($drop);
        } catch (PDOException $e) {
            if ($type !== 'firebird') { // it has no IF EXISTS
                throw $e;
            }
        }
    }

    $text = $type === 'sqlsrv' ? 'NVARCHAR(200)' : 'VARCHAR(200)';
    $null = $type === 'firebird' ? '' : 'NULL'; // Firebird doesn't know this word here
    $bool = match ($type) {
        'pgsql', 'firebird' => 'BOOLEAN',
        'sqlsrv' => 'BIT',
        default => 'INTEGER',
    };
    $datetime = match ($type) {
        'pgsql', 'firebird' => 'TIMESTAMP',
        'sqlsrv' => 'DATETIME2',
        default => 'DATETIME',
    };

    // Nette keeps dates of SQLite as Unix timestamps
    $at = static fn(string $date): string => $type === 'sqlite' ? (string) strtotime($date) : "'{$date}'";

    $pdo->exec("CREATE TABLE {$q('roles')} ({$q('id')} INTEGER NOT NULL PRIMARY KEY, {$q('name')} {$text} NOT NULL)");
    $pdo->exec("CREATE TABLE {$q('users')} (
        {$q('id')} INTEGER NOT NULL PRIMARY KEY,
        {$q('email')} {$text} NOT NULL,
        {$q('full_name')} {$text} {$null},
        {$q('password_hash')} {$text} {$null},
        {$q('is_active')} {$bool} NOT NULL,
        {$q('age')} INTEGER {$null},
        {$q('created_at')} {$datetime} {$null},
        {$q('birthday')} DATE {$null},
        {$q('role_id')} INTEGER {$null} REFERENCES {$q('roles')}({$q('id')})
    )");
    $pdo->exec("CREATE TABLE {$q('orders')} (
        {$q('id')} INTEGER NOT NULL PRIMARY KEY,
        {$q('user_id')} INTEGER NOT NULL REFERENCES {$q('users')}({$q('id')}),
        {$q('status')} {$text} NOT NULL,
        {$q('total')} FLOAT NOT NULL,
        {$q('created_at')} {$datetime} {$null}
    )");
    $pdo->exec("CREATE TABLE {$q('items')} ({$q('id')} INTEGER NOT NULL PRIMARY KEY, {$q('order_id')} INTEGER NOT NULL REFERENCES {$q('orders')}({$q('id')}), {$q('name')} {$text} NOT NULL)");
    $pdo->exec("CREATE TABLE {$q('secrets')} ({$q('id')} INTEGER NOT NULL PRIMARY KEY, {$q('user_id')} INTEGER NOT NULL REFERENCES {$q('users')}({$q('id')}), {$q('value')} {$text} NOT NULL)");

    // MySQL accepts REFERENCES next to a column, and quietly does nothing with it
    if ($type === 'mysql') {
        foreach ([
            'users (role_id) REFERENCES roles (id)',
            'orders (user_id) REFERENCES users (id)',
            'items (order_id) REFERENCES orders (id)',
            'secrets (user_id) REFERENCES users (id)',
        ] as $key) {
            [$table, $definition] = explode(' ', $key, 2);
            $pdo->exec("ALTER TABLE {$table} ADD FOREIGN KEY {$definition}");
        }
    }

    $true = in_array($type, ['pgsql', 'firebird'], true) ? 'TRUE' : '1';
    $false = in_array($type, ['pgsql', 'firebird'], true) ? 'FALSE' : '0';

    // One at a time, because Firebird has no multi-row VALUES
    foreach ([
        'roles' => ["1, 'admin'", "2, 'editor'"],
        'users' => [
            "1, 'ann@example.com', 'Ann Novak', 'hash-of-ann', {$true}, 30, {$at('2024-01-02 03:04:05')}, {$at('1994-05-06')}, 1",
            "2, 'bob@example.com', 'Bob 100% Smith', 'hash-of-bob', {$false}, 17, {$at('2024-06-10 12:00:00')}, NULL, 2",
            "3, 'cyril_x@example.com', NULL, NULL, {$true}, NULL, {$at(date('Y-m-d') . ' 08:00:00')}, NULL, NULL",
            "4, 'dana@example.com', '', 'hash-of-dana', {$true}, 45, NULL, NULL, 1",
        ],
        'orders' => [
            "1, 1, 'new', 10.5, {$at('2024-01-03 00:00:00')}",
            "2, 1, 'paid', 20, {$at('2024-01-04 00:00:00')}",
            "3, 1, 'cancelled', 30, {$at('2024-01-05 00:00:00')}",
            "4, 2, 'paid', 40, NULL",
        ],
        'items' => ["1, 1, 'apple'", "2, 1, 'pear'", "3, 2, 'plum'", "4, 4, 'apple'"],
        'secrets' => ["1, 1, 'top secret'"],
    ] as $table => $rows) {
        foreach ($rows as $row) {
            $pdo->exec("INSERT INTO {$q($table)} VALUES ({$row})");
        }
    }
}
