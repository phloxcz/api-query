<?php

namespace Phlox\ApiQuery\Tests\Fixtures\FirebirdEntity;

use Nette\Utils\DateTime as NetteDateTime;
use Phlox\EntityDatabase\Database\Table\GroupedSelection;
use Phlox\EntityDatabase\Entity\Attributes\Property;
use Phlox\EntityDatabase\Entity\Attributes\Table;
use Phlox\EntityDatabase\Entity\Entity;
use Phlox\EntityDatabase\Entity\EntityHelpers;
use Phlox\EntityDatabase\Exception\EntityException;

/**
 * A customer
 */
#[Table(name: 'ZAKAZNICI')]
class Customer extends Entity
{
	#[Property(
		col: 'ID',
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
		    : $this->_ctx->activeRow->ID ?? null;
	}

	#[Property(
		col: 'NAZEV',
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
		    : $this->_ctx->activeRow->NAZEV ?? null;
	}

	#[Property(
		col: 'KOD',
		key: false,
		nullable: false,
		searchable: false,
		virtual: false,
		type: 'string',
		access: 'rw',
		nativeType: 'char',
	)]
	public string $code {
		set(string $value) {
			$this->code = $value;
			$this->_ctx->modified['code'] = true;
		}
		get => $this->_ctx->modified['code'] ?? false
		    ? $this->code
		    : $this->_ctx->activeRow->KOD ?? null;
	}

	#[Property(
		col: 'ZALOZEN',
		key: false,
		nullable: true,
		searchable: false,
		virtual: false,
		type: '\DateTime',
		access: 'rw',
		nativeType: 'date',
	)]
	public ?\DateTime $founded = null {
		set(string|\DateTimeInterface|null $value) {
			$this->founded = match(true) {
			    $value === null => null,
			    $value instanceof \DateTimeInterface => NetteDateTime::from($value),
			    default => EntityHelpers::parseDateTime($value, 'Y-m-d H:i:s'),
			};
			$this->_ctx->modified['founded'] = true;
		}
		get => $this->_ctx->modified['founded'] ?? false
		    ? $this->founded
		    : $this->_ctx->activeRow->ZALOZEN ?? null;
	}

	#[Property(
		col: 'AKTIVNI',
		key: false,
		nullable: false,
		searchable: false,
		virtual: false,
		type: 'bool',
		access: 'rw',
		nativeType: 'boolean',
	)]
	public bool $active {
		set(bool $value) {
			$this->active = $value;
			$this->_ctx->modified['active'] = true;
		}
		get => $this->_ctx->modified['active'] ?? false
		    ? $this->active
		    : $this->_ctx->activeRow->AKTIVNI ?? null;
	}

	#[Property(
		col: 'KREDIT',
		key: false,
		nullable: true,
		searchable: false,
		virtual: false,
		type: 'float',
		access: 'rw',
		nativeType: 'numeric',
	)]
	public ?float $credit = null {
		set(?float $value) {
			$this->credit = $value;
			$this->_ctx->modified['credit'] = true;
		}
		get => $this->_ctx->modified['credit'] ?? false
		    ? $this->credit
		    : $this->_ctx->activeRow->KREDIT ?? null;
	}

	#[Property(
		col: 'PIN',
		key: false,
		nullable: true,
		searchable: false,
		virtual: false,
		type: 'string',
		access: 'w',
		nativeType: 'varchar',
	)]
	public ?string $pin = null {
		set(?string $value) {
			$this->pin = $value;
			$this->_ctx->modified['pin'] = true;
		}
		get => throw new EntityException('Property $pin is write-only.', EntityException::WRITE_ONLY_PROPERTY);
	}

	/** @var \Phlox\EntityDatabase\Database\Table\GroupedSelection<\Phlox\ApiQuery\Tests\Fixtures\FirebirdEntity\CustomerOrder> */
	#[Property(related: 'OBJEDNAVKY', through: 'ZAKAZNIK_ID', access: 'r')]
	public GroupedSelection $orders {
		get => $this->_ctx->selection->createGroupedSelection(
		    $this->_ctx->activeRow->related('OBJEDNAVKY', 'ZAKAZNIK_ID')
		);
	}
}
