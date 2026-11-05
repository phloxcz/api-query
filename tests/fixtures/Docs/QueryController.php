<?php

declare(strict_types=1);

namespace Phlox\ApiQuery\Tests\Fixtures\Docs;

use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\ApiQuery\Attributes\QueryOf;
use Phlox\ApiQuery\Query;
use Phlox\ApiQuery\Tests\Fixtures\Entity\User;
use Phlox\ApiQuery\Tests\Fixtures\Resources\UserResource;
use Phlox\ApiQuery\UsesQueryProcessor;
use Phlox\EntityDatabase\Database\Explorer;
use Phlox\RestApi\Attributes\FromBody;
use Phlox\RestApi\Attributes\HttpGet;
use Phlox\RestApi\Attributes\HttpPost;
use Phlox\RestApi\Attributes\Response;
use Phlox\RestApi\Attributes\Route;

#[Route('query')]
#[AllowAnonymous]
class QueryController extends ApiController
{
    use UsesQueryProcessor;

    public function __construct(private readonly Explorer $db)
    {
    }

    /**
     * Users as resources.
     */
    #[HttpPost]
    #[Route('resources')]
    public function resources(#[FromBody, QueryOf(UserResource::class)] Query $query): array
    {
        return $this->processor->process($this->db->table('users'), $query);
    }

    /**
     * Users as entities.
     */
    #[HttpPost]
    #[Route('entities')]
    public function entities(#[FromBody, QueryOf(User::class, relations: ['role', 'orders.items'])] Query $query): array
    {
        return $this->processor->process($this->db->table('users'), $query);
    }

    /**
     * Nothing declared, and the entity without relations.
     */
    #[HttpPost]
    #[Route('plain')]
    public function plain(#[FromBody] Query $query): array
    {
        return $this->processor->process($this->db->table('users'), $query);
    }

    #[HttpGet]
    #[Route('from-url')]
    public function fromUrl(#[QueryOf(UserResource::class)] ?Query $query = null): array
    {
        return $this->processor->process($this->db->table('users'), $query);
    }

    #[HttpGet]
    #[Route('{id:int}')]
    public function detail(int $id, #[QueryOf(UserResource::class, single: true)] Query $query): ?array
    {
        return $this->processor->process($this->db->table('users')->get($id), $query);
    }

    #[HttpPost]
    #[Route('created')]
    #[Response(201)]
    public function created(#[FromBody, QueryOf(UserResource::class)] Query $query): array
    {
        return [];
    }
}
