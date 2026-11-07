<?php
/**
 * This file is part of phloxcz/api-query.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phlox\ApiQuery\Events;

/**
 * A service listening to events of queries. The extension finds it and registers
 * the methods it has, once, when the container compiles:
 *
 *     public function onSelection(ProcessSelectionEventData $event): void
 *     public function onEntity(ProcessEntityEventData $event): void
 *     public function onProcessedEntity(ProcessedEntityEventData $event): void
 *     public function onSearch(SearchEventData $event): void
 *     public function onProcessedRow(ProcessedRowEventData $event): void   // of SqlProcessor
 *
 * Register it as any service. Its own services come through the constructor.
 */
interface QueryListener
{
}
