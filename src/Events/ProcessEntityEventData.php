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

use Phlox\EntityDatabase\Entity\Entity;

/**
 * A record is about to be turned into output.
 */
final class ProcessEntityEventData
{
    public bool $shouldSkip = false;

    /**
     * @param list<string> $path Where in the query; empty for the root
     * @param list<string> $select Selected fields
     */
    public function __construct(
        public readonly Entity $entity,
        public readonly array $path,
        public readonly array $select,
    ) {
    }
}
