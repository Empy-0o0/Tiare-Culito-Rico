<?php

namespace App\Services\MercadoPublico\DTO;

final class CompraAgilItemDTO
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly ?string $productCode,
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly ?string $quantity,
        public readonly ?string $unitOfMeasure,
    ) {}
}
