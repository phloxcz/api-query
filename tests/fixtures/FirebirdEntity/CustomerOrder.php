<?php

namespace Phlox\ApiQuery\Tests\Fixtures\FirebirdEntity;

use Nette\Utils\DateTime as NetteDateTime;
use Phlox\EntityDatabase\Entity\Attributes\Property;
use Phlox\EntityDatabase\Entity\Attributes\Table;
use Phlox\EntityDatabase\Entity\Entity;
use Phlox\EntityDatabase\Entity\EntityHelpers;

/**
 * An order
 */
#[Table(name: 'OBJEDNAVKY')]
class CustomerOrder extends Entity
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
		col: 'ZAKAZNIK_ID',
		key: false,
		nullable: false,
		searchable: false,
		virtual: false,
		type: 'int',
		access: 'rw',
		nativeType: 'integer',
	)]
	public int $customerId {
		set(int $value) {
			$this->customerId = $value;
			$this->_ctx->modified['customerId'] = true;
		}
		get => $this->_ctx->modified['customerId'] ?? false
		    ? $this->customerId
		    : $this->_ctx->activeRow->ZAKAZNIK_ID ?? null;
	}

	#[Property(
		col: 'CENA',
		key: false,
		nullable: false,
		searchable: false,
		virtual: false,
		type: 'float',
		access: 'rw',
		nativeType: 'double precision',
	)]
	public float $price {
		set(float $value) {
			$this->price = $value;
			$this->_ctx->modified['price'] = true;
		}
		get => $this->_ctx->modified['price'] ?? false
		    ? $this->price
		    : $this->_ctx->activeRow->CENA ?? null;
	}

	#[Property(
		col: 'VYTVORENA',
		key: false,
		nullable: true,
		searchable: false,
		virtual: false,
		type: '\DateTime',
		access: 'rw',
		nativeType: 'timestamp',
	)]
	public ?\DateTime $created = null {
		set(string|\DateTimeInterface|null $value) {
			$this->created = match(true) {
			    $value === null => null,
			    $value instanceof \DateTimeInterface => NetteDateTime::from($value),
			    default => EntityHelpers::parseDateTime($value, 'Y-m-d H:i:s'),
			};
			$this->_ctx->modified['created'] = true;
		}
		get => $this->_ctx->modified['created'] ?? false
		    ? $this->created
		    : $this->_ctx->activeRow->VYTVORENA ?? null;
	}

	#[Property(
		col: 'POZNAMKA',
		key: false,
		nullable: true,
		searchable: false,
		virtual: false,
		type: 'string',
		access: 'rw',
		nativeType: 'blob',
	)]
	public ?string $note = null {
		set(?string $value) {
			$this->note = $value;
			$this->_ctx->modified['note'] = true;
		}
		get => $this->_ctx->modified['note'] ?? false
		    ? $this->note
		    : $this->_ctx->activeRow->POZNAMKA ?? null;
	}

	#[Property(type: '\Phlox\ApiQuery\Tests\Fixtures\FirebirdEntity\Customer', ref: 'ZAKAZNICI', through: 'ZAKAZNIK_ID', access: 'r')]
	public ?Customer $customer {
		get => ($row = $this->_ctx->activeRow?->ref('ZAKAZNICI', 'ZAKAZNIK_ID')) === null
		    ? null
		    : $this->_ctx->selection?->entityFactory->create($row, $this->_ctx->selection);
	}
}
