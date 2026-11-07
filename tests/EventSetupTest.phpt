<?php

/**
 * Listeners registered once, rather than by every call of a controller: found by
 * their interface, or set up in the configuration.
 */

declare(strict_types=1);

use Phlox\ApiQuery\Events\ProcessedEntityEventData;
use Phlox\ApiQuery\Events\ProcessedRowEventData;
use Phlox\ApiQuery\Events\ProcessSelectionEventData;
use Phlox\ApiQuery\Events\QueryListener;
use Phlox\ApiQuery\Fields\SqlField;
use Phlox\ApiQuery\Processor;
use Phlox\ApiQuery\Query;
use Phlox\ApiQuery\SqlProcessor;
use Phlox\EntityDatabase\Database\Explorer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

/** Found by its interface, with the methods it has */
final class MaskingListener implements QueryListener
{
    public int $calls = 0;

    public function onSelection(ProcessSelectionEventData $event): void
    {
        if ($event->path === []) {
            $event->selection->where('users.id < ?', 3);
        }
    }

    public function onProcessedEntity(ProcessedEntityEventData $event): void
    {
        $this->calls++;
        $event->unset[] = 'email';
    }

    public function onProcessedRow(ProcessedRowEventData $event): void
    {
        $event->output['seen'] = true;
    }
}

/** Set up in the configuration */
final class CountingListener
{
    public int $calls = 0;

    public function onProcessedEntity(ProcessedEntityEventData $event): void
    {
        $this->calls++;
    }
}

$container = createContainer(<<<'NEON'
services:
    - MaskingListener
    counting: CountingListener
    apiQuery.processor:
        setup:
            - '$onProcessedEntity[]' = [@counting, onProcessedEntity]
NEON);

$processor = $container->getByType(Processor::class);
$masking = $container->getByType(MaskingListener::class);
$counting = $container->getByType(CountingListener::class);
$users = static fn() => $container->getByType(Explorer::class)->table('users');

Assert::count(1, $processor->onSelection, 'found by its interface, once');
Assert::count(2, $processor->onProcessedEntity, 'one of each');
Assert::same([], $processor->onEntity, 'a method it does not have is not registered');

$result = $processor->process($users(), Query::create(['select' => 'id,email']));
Assert::same([['id' => 1], ['id' => 2]], $result['data'], 'it limits and removes fields');
$processor->process($users(), Query::create(['select' => 'id']));
Assert::same([4, 4], [$masking->calls, $counting->calls], 'once a record, however many queries');

$rows = $container->getByType(SqlProcessor::class)->process('SELECT ' . q('id') . ' FROM ' . q('users'), [], Query::create(['pageSize' => 1]), ['id' => new SqlField('id')]);
Assert::true($rows['data'][0]['seen'], 'SqlProcessor too');
