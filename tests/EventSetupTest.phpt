<?php

/**
 * Listeners registered once, by the configuration, rather than by every call of a controller.
 */

declare(strict_types=1);

use Phlox\ApiQuery\Events\ProcessedEntityEventData;
use Phlox\ApiQuery\Processor;
use Phlox\ApiQuery\Query;
use Phlox\EntityDatabase\Database\Explorer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

final class CountingListener
{
    public int $calls = 0;

    public function onProcessedEntity(ProcessedEntityEventData $event): void
    {
        $this->calls++;
        $event->set['seen'] = true;
    }
}

$container = createContainer(<<<'NEON'
services:
    listener: CountingListener
    apiQuery.processor:
        setup:
            - '$onProcessedEntity[]' = [@listener, onProcessedEntity]
NEON);

$processor = $container->getByType(Processor::class);
$listener = $container->getByType(CountingListener::class);
$users = $container->getByType(Explorer::class)->table('users');

Assert::count(1, $processor->onProcessedEntity, 'registered once');
Assert::true($processor->process($users, Query::create(['select' => 'id', 'pageSize' => 1]))['data'][0]['seen']);
$processor->process($container->getByType(Explorer::class)->table('users'), Query::create(['select' => 'id', 'pageSize' => 1]));
Assert::same(2, $listener->calls, 'once a record, however many queries');
