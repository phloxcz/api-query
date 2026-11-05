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

namespace Phlox\ApiQuery\Attributes;

use Attribute;

/**
 * How a field of a #[Resource] relates to its entity.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Field
{
    /**
     * @param string|null $property Property of the entity; by default of the same name
     * @param bool|null $searchable By default what the entity says
     * @param bool $filterable Whether it can be filtered and sorted by
     */
    public function __construct(
        public readonly ?string $property = null,
        public readonly ?bool $searchable = null,
        public readonly bool $filterable = true,
    ) {
    }
}
