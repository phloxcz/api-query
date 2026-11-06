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

final readonly class QueryConfig
{
    /**
     * @param int $maxPageSize Rows of one collection; a larger page is cut to it, and it's the default
     * @param int $maxDepth How deep a query can go through relations
     * @param int $maxRows Rows of one response, nested ones included; a query wanting more is refused
     * @param bool $ignoreDiacritics Whether a search ignores accents; needs the support of the database
     * @param array<string, array{relations?: list<string>, computed?: array<string, string>, resource?: ?string}|object> $entities
     *        By entity class: the relations a query can reach, and the fields events add, with their types;
     *        or the #[Resource] which always stands for the entity
     */
    public function __construct(
        public int $maxPageSize = 100,
        public int $maxDepth = 3,
        public int $maxRows = 10000,
        public bool $ignoreDiacritics = false,
        public array $entities = [],
        public string $dateTimeFormat = \DateTimeInterface::ATOM,
        public string $dateFormat = 'Y-m-d',
        public string $timeFormat = 'H:i:s',
        public string $timeIntervalFormat = '%H:%I:%S',
    ) {
    }
}
