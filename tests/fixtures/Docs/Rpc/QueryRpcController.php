<?php

declare(strict_types=1);

namespace Phlox\ApiQuery\Tests\Fixtures\Docs\Rpc;

use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\ApiQuery\Attributes\QueryOf;
use Phlox\ApiQuery\Query;
use Phlox\ApiQuery\Tests\Fixtures\Resources\UserResource;
use Phlox\ApiQuery\UsesQueryProcessor;
use Phlox\EntityDatabase\Database\Explorer;

#[AllowAnonymous]
class QueryRpcController extends ApiController
{
    use UsesQueryProcessor;

    public function __construct(private readonly Explorer $db)
    {
    }

    public function users(#[QueryOf(UserResource::class)] Query $query): array
    {
        return $this->processor->process($this->db->table('users'), $query);
    }
}
