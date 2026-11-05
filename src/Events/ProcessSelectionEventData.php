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

use Phlox\EntityDatabase\Database\Table\Selection;

/**
 * A collection is about to be read. The place to add conditions for access control.
 */
final class ProcessSelectionEventData
{
    public bool $shouldSkip = false;

    /**
     * @param Selection<\Phlox\EntityDatabase\Entity\Entity> $selection
     * @param list<string> $path Where in the query; empty for the root
     * @param list<string> $select Selected fields
     */
    public function __construct(
        public readonly Selection $selection,
        public readonly array $path,
        public readonly array $select,
    ) {
    }
}
