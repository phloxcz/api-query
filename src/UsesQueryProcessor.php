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
 * Gives a controller `$this->processor`. Use it on a shared base controller.
 */
trait UsesQueryProcessor
{
    protected Processor $processor;

    public function injectQueryProcessor(Processor $processor): void
    {
        $this->processor = $processor;
    }
}
