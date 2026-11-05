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

/**
 * Conditions joined by a logic: {logic, filters: [...]}, recursively.
 */
final readonly class FilterGroup
{
    public const And = 'and';
    public const Or = 'or';

    /**
     * @param list<Filter|FilterGroup> $filters
     */
    public function __construct(
        public string $logic = self::And,
        public array $filters = [],
    ) {
    }
}
