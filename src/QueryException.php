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

use Phlox\ApiCore\ApiException;
use Phlox\ApiCore\ProtocolError;

/**
 * A query that can't be carried out, with everything wrong with it.
 */
final class QueryException extends ApiException
{
    /**
     * @param array<string, string> $fields Where in the query => what's wrong
     */
    public function __construct(array $fields)
    {
        parent::__construct(400, 'Invalid query.', errorCode: ProtocolError::InvalidParams->value, data: ['fields' => $fields]);
    }

    public static function at(string $where, string $error): self
    {
        return new self([$where => $error]);
    }
}
