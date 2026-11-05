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
 * A way from a record to another record, or to more of them.
 */
final class Relation
{
    private ?FieldMap $target = null;

    /**
     * @param bool $toMany A collection rather than one record
     * @param string $table Table of the target
     * @param string $localColumn Column on this side
     * @param string $remoteColumn Column of the target it's equal to
     * @param string|null $condition What limits the collection, written by the developer
     * @param \Closure(): FieldMap $createTarget Late, because relations can go in a circle
     */
    public function __construct(
        public readonly bool $toMany,
        public readonly string $table,
        public readonly string $localColumn,
        public readonly string $remoteColumn,
        public readonly ?string $condition,
        private readonly \Closure $createTarget,
    ) {
    }

    public function getTarget(): FieldMap
    {
        return $this->target ??= ($this->createTarget)();
    }
}
