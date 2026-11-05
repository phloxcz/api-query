<?php

declare(strict_types=1);

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/schema.php';

Tester\Environment::setup();

/**
 * Compiles a real container over a database of its own.
 */
function createContainer(string $neon = '', string $extraExtensions = '', bool $debugMode = false): Container
{
    @mkdir(__DIR__ . '/tmp');
    $tempDir = __DIR__ . '/tmp/' . getmypid();
    Tester\Helpers::purge($tempDir);

    $database = testDatabase();
    if ($database['type'] === 'sqlite') {
        $file = $tempDir . '/test.db';
        $database['dsn'] = "sqlite:{$file}";
    }

    createSchema(new PDO($database['dsn'], $database['user'], $database['password']), $database['type']);

    $configurator = new Configurator();
    $configurator->setDebugMode($debugMode);
    $configurator->setTempDirectory($tempDir);
    $configurator->addStaticParameters(['appDir' => __DIR__]);

    $user = json_encode($database['user']);
    $password = json_encode($database['password']);
    $dsn = json_encode($database['dsn']);
    $databaseOptions = $database['type'] === 'firebird' ? 'options: {driverClass: Phlox\FirebirdDatabase\FirebirdDriver}' : '';

    $configFile = $tempDir . '/' . md5($neon . $extraExtensions . (int) $debugMode) . '.neon';
    file_put_contents($configFile, <<<NEON
extensions:
    entityDatabase: Phlox\\EntityDatabase\\DI\\Extension
    apiQuery: Phlox\\ApiQuery\\DI\\Extension
    $extraExtensions

security:
    debugger: false

http:
    frames: null

database:
    default:
        dsn: $dsn
        user: $user
        password: $password
        $databaseOptions

entityDatabase:
    connections:
        default:
            entities:
                namespace: Phlox\\ApiQuery\\Tests\\Fixtures\\Entity
                output: %appDir%/fixtures/Entity
                config: [%appDir%/fixtures/entities.neon]

$neon
NEON);
    $configurator->addConfig($configFile);

    return $configurator->createContainer();
}
