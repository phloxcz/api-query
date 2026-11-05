<?php

namespace Phlox\ApiQuery\Tests\Fixtures\Entity;

use Nette\Utils\DateTime as NetteDateTime;
use Phlox\EntityDatabase\Database\Table\GroupedSelection;
use Phlox\EntityDatabase\Entity\Attributes\Property;
use Phlox\EntityDatabase\Entity\Attributes\Table;
use Phlox\EntityDatabase\Entity\Entity;
use Phlox\EntityDatabase\Entity\EntityHelpers;

/**
 * An order
 */
#[Table(name: 'orders')]
class Order extends Entity
{
	#[Property(
		col: 'id',
		key: true,
		nullable: false,
		searchable: false,
		virtual: false,
		type: 'int',
		access: 'rw',
		nativeType: 'integer',
	)]
	public int $id {
		set(int $value) {
			$this->id = $value;
			$this->_ctx->modified['id'] = true;
		}
		get => $this->_ctx->modified['id'] ?? false
		    ? $this->id
		    : $this->_ctx->activeRow->id ?? null;
	}

	#[Property(
		col: 'user_id',
		key: false,
		nullable: false,
		searchable: false,
		virtual: false,
		type: 'int',
		access: 'rw',
		nativeType: 'integer',
	)]
	public int $userId {
		set(int $value) {
			$this->userId = $value;
			$this->_ctx->modified['userId'] = true;
		}
		get => $this->_ctx->modified['userId'] ?? false
		    ? $this->userId
		    : $this->_ctx->activeRow->user_id ?? null;
	}

	#[Property(
		col: 'status',
		key: false,
		nullable: false,
		searchable: false,
		virtual: false,
		type: 'string',
		access: 'rw',
		nativeType: 'varchar',
	)]
	public string $status {
		set(string $value) {
			$this->status = $value;
			$this->_ctx->modified['status'] = true;
		}
		get => $this->_ctx->modified['status'] ?? false
		    ? $this->status
		    : $this->_ctx->activeRow->status ?? null;
	}

	#[Property(
		col: 'total',
		key: false,
		nullable: false,
		searchable: false,
		virtual: false,
		type: 'float',
		access: 'rw',
		nativeType: 'float',
	)]
	public float $total {
		set(float $value) {
			$this->total = $value;
			$this->_ctx->modified['total'] = true;
		}
		get => $this->_ctx->modified['total'] ?? false
		    ? $this->total
		    : $this->_ctx->activeRow->total ?? null;
	}

	#[Property(
		col: 'created_at',
		key: false,
		nullable: true,
		searchable: false,
		virtual: false,
		type: '\DateTime',
		access: 'rw',
		nativeType: 'datetime',
	)]
	public ?\DateTime $createdAt = null {
		set(string|\DateTimeInterface|null $value) {
			$this->createdAt = match(true) {
			    $value === null => null,
			    $value instanceof \DateTimeInterface => NetteDateTime::from($value),
			    default => EntityHelpers::parseDateTime($value, 'Y-m-d H:i:s'),
			};
			$this->_ctx->modified['createdAt'] = true;
		}
		get => $this->_ctx->modified['createdAt'] ?? false
		    ? $this->createdAt
		    : $this->_ctx->activeRow->created_at ?? null;
	}

	#[Property(type: '\Phlox\ApiQuery\Tests\Fixtures\Entity\User', ref: 'users', through: 'user_id', access: 'r')]
	public ?User $user {
		get => ($row = $this->_ctx->activeRow?->ref('users', 'user_id')) === null
		    ? null
		    : $this->_ctx->selection?->entityFactory->create($row, $this->_ctx->selection);
	}

	/** @var \Phlox\EntityDatabase\Database\Table\GroupedSelection<\Phlox\ApiQuery\Tests\Fixtures\Entity\Item> */
	#[Property(related: 'items', through: 'order_id', access: 'r')]
	public GroupedSelection $items {
		get => $this->_ctx->selection->createGroupedSelection(
		    $this->_ctx->activeRow->related('items', 'order_id')
		);
	}
}
