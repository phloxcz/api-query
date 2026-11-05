<?php

namespace Phlox\ApiQuery\Tests\Fixtures\Entity;

use Phlox\EntityDatabase\Entity\Attributes\Property;
use Phlox\EntityDatabase\Entity\Attributes\Table;
use Phlox\EntityDatabase\Entity\Entity;

/**
 * An item
 */
#[Table(name: 'items')]
class Item extends Entity
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
		col: 'order_id',
		key: false,
		nullable: false,
		searchable: false,
		virtual: false,
		type: 'int',
		access: 'rw',
		nativeType: 'integer',
	)]
	public int $orderId {
		set(int $value) {
			$this->orderId = $value;
			$this->_ctx->modified['orderId'] = true;
		}
		get => $this->_ctx->modified['orderId'] ?? false
		    ? $this->orderId
		    : $this->_ctx->activeRow->order_id ?? null;
	}

	#[Property(
		col: 'name',
		key: false,
		nullable: false,
		searchable: false,
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

	#[Property(type: '\Phlox\ApiQuery\Tests\Fixtures\Entity\Order', ref: 'orders', through: 'order_id', access: 'r')]
	public ?Order $order {
		get => ($row = $this->_ctx->activeRow?->ref('orders', 'order_id')) === null
		    ? null
		    : $this->_ctx->selection?->entityFactory->create($row, $this->_ctx->selection);
	}
}
