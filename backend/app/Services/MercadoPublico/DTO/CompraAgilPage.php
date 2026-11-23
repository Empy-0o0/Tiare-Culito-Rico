<?php

namespace App\Services\MercadoPublico\DTO;

final class CompraAgilPage
{
    /** @param list<CompraAgilDTO> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly int $totalPages,
        public readonly int $totalResults,
    ) {}

    public function isLastPage(): bool
    {
        return $this->page >= $this->totalPages;
    }
}
