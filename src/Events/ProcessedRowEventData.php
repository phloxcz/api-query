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

use Nette\Database\Row;

/**
 * A row of raw SQL has been turned into output, which can still be changed.
 */
final class ProcessedRowEventData
{
    public bool $shouldSkip = false;

    /**
     * @param array<string, mixed> $output
     */
    public function __construct(
        public readonly Row $row,
        public array $output,
    ) {
    }
}
