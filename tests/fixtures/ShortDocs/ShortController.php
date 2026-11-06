<?php

declare(strict_types=1);

namespace Phlox\ApiQuery\Tests\Fixtures\ShortDocs;

use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\ApiQuery\Attributes\QueryOf;
use Phlox\ApiQuery\Query;
use Phlox\ApiQuery\Tests\Fixtures\Entity\Role;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\ListedUser;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\LoopUser;
use Phlox\ApiQuery\Tests\Fixtures\ShortResources\StarRole;
use Phlox\ApiQuery\UsesQueryProcessor;
use Phlox\EntityDatabase\Database\Explorer;
use Phlox\RestApi\Attributes\FromBody;
use Phlox\RestApi\Attributes\HttpPost;
use Phlox\RestApi\Attributes\Response;
use Phlox\RestApi\Attributes\Route;

#[Route('short')]
#[AllowAnonymous]
class ShortController extends ApiController
{
    use UsesQueryProcessor;

    public function __construct(private readonly Explorer $db)
    {
    }

    #[HttpPost]
    #[Route('users')]
    public function users(#[FromBody, QueryOf(ListedUser::class)] ?Query $query = null): array
    {
        return $this->processor->process($this->db->table('users'), $query);
    }

    /**
     * Nothing said here, so the configuration decides.
     */
    #[HttpPost]
    #[Route('roles')]
    public function roles(#[FromBody] ?Query $query = null): array
    {
        return $this->processor->process($this->db->table('roles'), $query);
    }

    #[HttpPost]
    #[Route('loop')]
    public function loop(#[FromBody, QueryOf(LoopUser::class)] ?Query $query = null): array
    {
        return $this->processor->process($this->db->table('users'), $query);
    }

    #[HttpPost]
    #[Route('described')]
    #[Response(200, ['@pager', StarRole::class])]
    public function described(): array
    {
        return [];
    }

    #[HttpPost]
    #[Route('entity')]
    #[Response(200, Role::class)]
    public function entity(): array
    {
        return [];
    }
}
