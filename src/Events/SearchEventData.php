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
 * A search is about to be applied, and the columns can still change.
 */
final class SearchEventData
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public array $columns,
        public readonly string $table,
        public readonly string $entity,
    ) {
    }
}
