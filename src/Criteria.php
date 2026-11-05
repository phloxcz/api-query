<?php
/**
 * This file is part of phloxcz/api-query.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phlox\ApiQuery;

/**
 * What to do with one collection of a query: search, filter, sort, page. An
 * application can change it before the query is carried out, such as to take a
 * sort it handles on its own out of it.
 */
final class Criteria
{
    /**
     * @param list<Sort> $sort
     * @param int|null $skip Rows to leave out; `take` and `skip` of Kendo UI need not fall on a page
     */
    public function __construct(
        public ?string $search = null,
        public ?int $page = null,
        public ?int $pageSize = null,
        public ?int $skip = null,
        public array $sort = [],
        public ?FilterGroup $filter = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->search === null
            && $this->page === null
            && $this->pageSize === null
            && $this->skip === null
            && $this->sort === []
            && $this->filter === null;
    }

    /**
     * The offset of the first row when pages are given by the size.
     */
    public function getOffset(int $pageSize): int
    {
        return $this->skip ?? (max($this->page ?? 1, 1) - 1) * $pageSize;
    }

    public function getSearch(): ?string
    {
        return $this->search;
    }

    public function getPage(): ?int
    {
        return $this->page;
    }

    public function getPageSize(): ?int
    {
        return $this->pageSize;
    }

    /**
     * @return list<Sort>
     */
    public function getSort(): array
    {
        return $this->sort;
    }

    public function getFilter(): ?FilterGroup
    {
        return $this->filter;
    }

    public function setSearch(?string $search): static
    {
        $this->search = $search;
        return $this;
    }

    public function setPage(?int $page): static
    {
        $this->page = $page;
        $this->skip = null;
        return $this;
    }

    public function setPageSize(?int $pageSize): static
    {
        $this->pageSize = $pageSize;
        return $this;
    }

    /**
     * @param list<Sort> $sort
     */
    public function setSort(array $sort): static
    {
        $this->sort = $sort;
        return $this;
    }

    public function setFilter(?FilterGroup $filter): static
    {
        $this->filter = $filter;
        return $this;
    }
}
