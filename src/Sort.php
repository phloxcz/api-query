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

namespace Phlox\ApiQuery;

final class Sort
{
    /** `asc` or `desc`, as clients write it */
    public string $dir {
        get => $this->descending ? 'desc' : 'asc';
    }

    public function __construct(
        public readonly string $field,
        public readonly bool $descending = false,
    ) {
    }
}
