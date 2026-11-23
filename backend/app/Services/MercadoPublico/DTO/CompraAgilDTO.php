<?php

namespace App\Services\MercadoPublico\DTO;

use Carbon\CarbonImmutable;

/**
 * Compra Ágil normalizada. Contrato estable interno: el frontend nunca recibe el payload externo.
 * Fechas en UTC. Montos como string decimal (sin FLOAT).
 */
final class CompraAgilDTO
{
    /**
     * @param  list<CompraAgilItemDTO>  $items
     * @param  list<array{external_id:string, name:?string}>  $documents
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly bool $isDetail,
        public readonly ?string $description,
        public readonly ?string $statusCode,
        public readonly ?string $statusLabel,
        public readonly ?string $statusSourceId,
        public readonly ?int $callNumber,
        public readonly ?CarbonImmutable $publishedAt,
        public readonly ?CarbonImmutable $closesAt,
        public readonly ?CarbonImmutable $firstCallClosesAt,
        public readonly ?CarbonImmutable $secondCallClosesAt,
        public readonly ?CarbonImmutable $cancelledAt,
        public readonly ?CarbonImmutable $sourceUpdatedAt,
        public readonly ?string $buyerName,
        public readonly ?string $buyerRut,
        public readonly ?string $buyerUnit,
        public readonly ?int $regionCode,
        public readonly ?string $regionName,
        public readonly ?string $currency,
        public readonly ?string $budgetType,
        public readonly ?string $estimatedBudget,
        public readonly ?string $availableAmount,
        public readonly ?string $availableAmountClp,
        public readonly ?string $exchangeRate,
        public readonly ?string $deliveryAddress,
        public readonly ?int $deliveryDays,
        public readonly ?int $offersCount,
        public readonly ?bool $hasPurchaseOrder,
        public readonly ?string $purchaseOrderSourceId,
        public readonly ?string $purchaseOrderCode,
        public readonly ?string $cancellationReason,
        public readonly ?string $desertedReason,
        public readonly ?string $selectionReason,
        public readonly array $items,
        public readonly array $documents,
        public readonly array $raw,
        public readonly string $payloadHash,
    ) {}
}
