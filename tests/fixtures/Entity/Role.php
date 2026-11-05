<?php

namespace Phlox\ApiQuery\Tests\Fixtures\Entity;

use Phlox\EntityDatabase\Database\Table\GroupedSelection;
use Phlox\EntityDatabase\Entity\Attributes\Property;
use Phlox\EntityDatabase\Entity\Attributes\Table;
use Phlox\EntityDatabase\Entity\Entity;

/**
 * A role
 */
#[Table(name: 'roles')]
class Role extends Entity
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
		col: 'name',
		key: false,
		nullable: false,
		searchable: true,
		virtual: false,
		type: 'string',
		access: 'rw',
		nativeType: 'varchar',
	)]
	public string $name {
		set(string $value) {
			$this->name = $value;
			$this->_ctx->modified['name'] = true;
		}
		get => $this->_ctx->modified['name'] ?? false
		    ? $this->name
		    : $this->_ctx->activeRow->name ?? null;
	}

	/** @var \Phlox\EntityDatabase\Database\Table\GroupedSelection<\Phlox\ApiQuery\Tests\Fixtures\Entity\User> */
	#[Property(related: 'users', through: 'role_id', access: 'r')]
	public GroupedSelection $users {
		get => $this->_ctx->selection->createGroupedSelection(
		    $this->_ctx->activeRow->related('users', 'role_id')
		);
	}
}
