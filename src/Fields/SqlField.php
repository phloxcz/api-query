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

namespace Phlox\ApiQuery\Fields;

/**
 * A column of a result of raw SQL, for SqlProcessor.
 */
final readonly class SqlField
{
    /**
     * @param string $column Name of the column in the result
     * @param string $type One of the constants of Field
     */
    public function __construct(
        public string $column,
        public string $type = Field::Text,
        public bool $searchable = false,
    ) {
    }
}
