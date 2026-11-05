<?php

namespace Phlox\ApiQuery\Tests\Fixtures\Entity;

use Nette\Utils\DateTime as NetteDateTime;
use Phlox\EntityDatabase\Database\Table\GroupedSelection;
use Phlox\EntityDatabase\Entity\Attributes\Property;
use Phlox\EntityDatabase\Entity\Attributes\Table;
use Phlox\EntityDatabase\Entity\Entity;
use Phlox\EntityDatabase\Entity\EntityHelpers;
use Phlox\EntityDatabase\Exception\EntityException;

/**
 * A user
 */
#[Table(name: 'users')]
class User extends Entity
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
		col: 'email',
		key: false,
		nullable: false,
		searchable: true,
		virtual: false,
		type: 'string',
		access: 'rw',
		nativeType: 'varchar',
	)]
	public string $email {
		set(string $value) {
			$this->email = $value;
			$this->_ctx->modified['email'] = true;
		}
		get => $this->_ctx->modified['email'] ?? false
		    ? $this->email
		    : $this->_ctx->activeRow->email ?? null;
	}

	#[Property(
		col: 'full_name',
		key: false,
		nullable: true,
		searchable: true,
		virtual: false,
		type: 'string',
		access: 'rw',
		nativeType: 'varchar',
	)]
	public ?string $fullName = null {
		set(?string $value) {
			$this->fullName = $value;
			$this->_ctx->modified['fullName'] = true;
		}
		get => $this->_ctx->modified['fullName'] ?? false
		    ? $this->fullName
		    : $this->_ctx->activeRow->full_name ?? null;
	}

	#[Property(
		col: 'password_hash',
		key: false,
		nullable: true,
		searchable: true,
		virtual: false,
		type: 'string',
		access: 'w',
		nativeType: 'varchar',
	)]
	public ?string $passwordHash = null {
		set(?string $value) {
			$this->passwordHash = $value;
			$this->_ctx->modified['passwordHash'] = true;
		}
		get => throw new EntityException('Property $passwordHash is write-only.', EntityException::WRITE_ONLY_PROPERTY);
	}

	#[Property(
		col: 'is_active',
		key: false,
		nullable: false,
		searchable: false,
		virtual: false,
		type: 'bool',
		access: 'rw',
		nativeType: 'integer',
	)]
	public bool $isActive {
		set(bool $value) {
			$this->isActive = $value;
			$this->_ctx->modified['isActive'] = true;
		}
		get => $this->_ctx->modified['isActive'] ?? false
		    ? $this->isActive
		    : $this->_ctx->activeRow->is_active ?? null;
	}

	#[Property(
		col: 'age',
		key: false,
		nullable: true,
		searchable: false,
		virtual: false,
		type: 'int',
		access: 'rw',
		nativeType: 'integer',
	)]
	public ?int $age = null {
		set(?int $value) {
			$this->age = $value;
			$this->_ctx->modified['age'] = true;
		}
		get => $this->_ctx->modified['age'] ?? false
		    ? $this->age
		    : $this->_ctx->activeRow->age ?? null;
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

	#[Property(
		col: 'birthday',
		key: false,
		nullable: true,
		searchable: false,
		virtual: false,
		type: '\DateTime',
		access: 'rw',
		nativeType: 'date',
	)]
	public ?\DateTime $birthday = null {
		set(string|\DateTimeInterface|null $value) {
			$this->birthday = match(true) {
			    $value === null => null,
			    $value instanceof \DateTimeInterface => NetteDateTime::from($value),
			    default => EntityHelpers::parseDateTime($value, 'Y-m-d H:i:s'),
			};
			$this->_ctx->modified['birthday'] = true;
		}
		get => $this->_ctx->modified['birthday'] ?? false
		    ? $this->birthday
		    : $this->_ctx->activeRow->birthday ?? null;
	}

	#[Property(
		col: 'role_id',
		key: false,
		nullable: true,
		searchable: false,
		virtual: false,
		type: 'int',
		access: 'rw',
		nativeType: 'integer',
	)]
	public ?int $roleId = null {
		set(?int $value) {
			$this->roleId = $value;
			$this->_ctx->modified['roleId'] = true;
		}
		get => $this->_ctx->modified['roleId'] ?? false
		    ? $this->roleId
		    : $this->_ctx->activeRow->role_id ?? null;
	}

	/** Filled in by the application */
	#[Property(col: null, key: false, nullable: true, searchable: false, virtual: true, type: 'string', access: 'rw')]
	public ?string $nickname = null {
		set(?string $value) {
			$this->nickname = $value;
		}
		get => $this->nickname;
	}

	/** The role */
	#[Property(type: '\Phlox\ApiQuery\Tests\Fixtures\Entity\Role', ref: 'roles', through: 'role_id', access: 'r')]
	public ?Role $role {
		get => ($row = $this->_ctx->activeRow?->ref('roles', 'role_id')) === null
		    ? null
		    : $this->_ctx->selection?->entityFactory->create($row, $this->_ctx->selection);
	}

	/** @var \Phlox\EntityDatabase\Database\Table\GroupedSelection<\Phlox\ApiQuery\Tests\Fixtures\Entity\Order> */
	#[Property(related: 'orders', through: 'user_id', access: 'r')]
	public GroupedSelection $orders {
		get => $this->_ctx->selection->createGroupedSelection(
		    $this->_ctx->activeRow->related('orders', 'user_id')
		);
	}

	/** @var \Phlox\EntityDatabase\Database\Table\GroupedSelection<\Phlox\ApiQuery\Tests\Fixtures\Entity\Order> */
	#[Property(related: 'orders', through: 'user_id', access: 'r', condition: 'status <> \'cancelled\'')]
	public GroupedSelection $activeOrders {
		get => $this->_ctx->selection->createGroupedSelection(
		    $this->_ctx->activeRow->related('orders', 'user_id')
		)
		    ->where('status <> \'cancelled\'');
	}
}
