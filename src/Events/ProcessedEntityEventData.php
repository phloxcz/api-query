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
 * A record has been turned into output, which can still be changed: through `set`
 * and `unset`, or right in `output`.
 */
final class ProcessedEntityEventData
{
    public bool $shouldSkip = false;

    /** @var array<string, mixed> Fields to add or replace */
    public array $set = [];

    /** @var list<string> Fields to remove */
    public array $unset = [];

    /**
     * @param list<string> $path Where in the query; empty for the root
     * @param list<string> $select Selected fields
     * @param array<string, mixed> $output
     */
    public function __construct(
        public readonly Entity $entity,
        public readonly array $path,
        public readonly array $select,
        public array $output,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getResult(): array
    {
        $result = array_replace($this->output, $this->set);

        foreach ($this->unset as $field) {
            unset($result[$field]);
        }

        return $result;
    }
}
