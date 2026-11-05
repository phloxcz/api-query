<?php

namespace Phlox\ApiQuery\Tests\Fixtures\Entity;

use Phlox\EntityDatabase\Entity\Attributes\Property;
use Phlox\EntityDatabase\Entity\Attributes\Table;
use Phlox\EntityDatabase\Entity\Entity;

/**
 * No API may reach this
 */
#[Table(name: 'secrets')]
class Secret extends Entity
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
		col: 'value',
		key: false,
		nullable: false,
		searchable: false,
		virtual: false,
		type: 'string',
		access: 'rw',
		nativeType: 'varchar',
	)]
	public string $value {
		set(string $value) {
			$this->value = $value;
			$this->_ctx->modified['value'] = true;
		}
		get => $this->_ctx->modified['value'] ?? false
		    ? $this->value
		    : $this->_ctx->activeRow->value ?? null;
	}

	#[Property(type: '\Phlox\ApiQuery\Tests\Fixtures\Entity\User', ref: 'users', through: 'user_id', access: 'r')]
	public ?User $user {
		get => ($row = $this->_ctx->activeRow?->ref('users', 'user_id')) === null
		    ? null
		    : $this->_ctx->selection?->entityFactory->create($row, $this->_ctx->selection);
	}
}
