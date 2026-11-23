<?php

namespace App\Services\MercadoPublico\Persistence;

use App\Models\PublicOpportunityDocument;
use App\Models\PublicOpportunityItem;
use App\Models\PublicProcurementOpportunity;
use App\Models\PublicProcurementRawPayload;
use App\Services\MercadoPublico\DTO\CompraAgilDTO;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Upsert idempotente de Compras Ágiles por (source_system, external_code).
 * - Si el hash del payload del mismo tipo (listado/detalle) no cambió, solo actualiza last_fetched_at.
 * - Un payload de listado no borra campos que solo trae el detalle.
 */
class CompraAgilRepository
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    /** @return self::CREATED|self::UPDATED|self::UNCHANGED */
    public function upsert(CompraAgilDTO $dto, ?CarbonImmutable $fetchedAt = null): string
    {
        $fetchedAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($dto, $fetchedAt): string {
            $opportunity = PublicProcurementOpportunity::query()
                ->where('source_system', PublicProcurementOpportunity::SOURCE_SYSTEM)
                ->where('external_code', $dto->code)
                ->lockForUpdate()
                ->first();

            $hashColumn = $dto->isDetail ? 'detail_payload_hash' : 'list_payload_hash';
            $fetchedColumn = $dto->isDetail ? 'detail_fetched_at' : 'last_fetched_at';

            if ($opportunity && $opportunity->{$hashColumn} === $dto->payloadHash) {
                $opportunity->forceFill(['last_fetched_at' => $fetchedAt, $fetchedColumn => $fetchedAt])->save();

                return self::UNCHANGED;
            }

            $isNew = $opportunity === null;
            $opportunity ??= new PublicProcurementOpportunity([
                'source_system' => PublicProcurementOpportunity::SOURCE_SYSTEM,
                'source_type' => PublicProcurementOpportunity::TYPE_COMPRA_AGIL,
                'external_code' => $dto->code,
            ]);

            // Una respuesta más antigua no pisa datos más recientes. Se compara a nivel de minuto:
            // el detalle informa "HH:MM" y el listado incluye milisegundos para el mismo cambio.
            if (! $isNew && $dto->sourceUpdatedAt && $opportunity->source_updated_at
                && $dto->sourceUpdatedAt->startOfMinute()->lessThan($opportunity->source_updated_at->startOfMinute())) {
                $opportunity->forceFill(['last_fetched_at' => $fetchedAt])->save();

                return self::UNCHANGED;
            }

            $opportunity->fill(array_merge(
                $this->commonAttributes($dto),
                $dto->isDetail ? $this->detailAttributes($dto) : [],
                [
                    'source_api' => 'compra_agil_v2',
                    'source_api_version' => 'v2',
                    $hashColumn => $dto->payloadHash,
                    'last_fetched_at' => $fetchedAt,
                    $fetchedColumn => $fetchedAt,
                ],
            ));
            $opportunity->save();

            $this->syncDocuments($opportunity, $dto);
            if ($dto->isDetail) {
                $this->syncItems($opportunity, $dto);
            }

            PublicProcurementRawPayload::query()->updateOrCreate(
                ['opportunity_id' => $opportunity->id, 'kind' => $dto->isDetail ? 'detail' : 'list'],
                ['payload' => $dto->raw, 'payload_hash' => $dto->payloadHash, 'fetched_at' => $fetchedAt],
            );

            return $isNew ? self::CREATED : self::UPDATED;
        });
    }

    /** @return array<string, mixed> */
    private function commonAttributes(CompraAgilDTO $dto): array
    {
        $attributes = [
            'title' => $dto->title,
            'status_code' => $dto->statusCode,
            'status_label' => $dto->statusLabel,
            'status_source_id' => $dto->statusSourceId,
            'call_number' => $dto->callNumber,
            'published_at' => $dto->publishedAt,
            'closes_at' => $dto->closesAt,
            'cancelled_at' => $dto->cancelledAt,
            'source_updated_at' => $dto->sourceUpdatedAt,
            'buyer_name' => $dto->buyerName,
            'buyer_rut' => $dto->buyerRut,
            'buyer_unit' => $dto->buyerUnit,
            'region_code' => $dto->regionCode,
            'region_name' => $dto->regionName,
            'currency' => $dto->currency,
            'available_amount' => $dto->availableAmount,
            'available_amount_clp' => $dto->availableAmountClp,
            'offers_count' => $dto->offersCount,
            'cancellation_reason' => $dto->cancellationReason,
            'deserted_reason' => $dto->desertedReason,
        ];

        // Campos opcionales: solo se escriben si vienen informados.
        foreach ([
            'first_call_closes_at' => $dto->firstCallClosesAt,
            'second_call_closes_at' => $dto->secondCallClosesAt,
            'selection_reason' => $dto->selectionReason,
        ] as $key => $value) {
            if ($value !== null) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function detailAttributes(CompraAgilDTO $dto): array
    {
        return [
            'description' => $dto->description,
            'budget_type' => $dto->budgetType,
            'estimated_budget' => $dto->estimatedBudget,
            'exchange_rate' => $dto->exchangeRate,
            'delivery_address' => $dto->deliveryAddress,
            'delivery_days' => $dto->deliveryDays,
            'has_purchase_order' => (bool) $dto->hasPurchaseOrder,
            'purchase_order_source_id' => $dto->purchaseOrderSourceId,
            'purchase_order_code' => $dto->purchaseOrderCode,
        ];
    }

    private function syncItems(PublicProcurementOpportunity $opportunity, CompraAgilDTO $dto): void
    {
        PublicOpportunityItem::query()->where('opportunity_id', $opportunity->id)->delete();
        foreach ($dto->items as $item) {
            PublicOpportunityItem::create([
                'opportunity_id' => $opportunity->id,
                'line_number' => $item->lineNumber,
                'product_code' => $item->productCode,
                'name' => $item->name,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_of_measure' => $item->unitOfMeasure,
            ]);
        }
    }

    private function syncDocuments(PublicProcurementOpportunity $opportunity, CompraAgilDTO $dto): void
    {
        $ids = array_column($dto->documents, 'external_id');
        PublicOpportunityDocument::query()
            ->where('opportunity_id', $opportunity->id)
            ->when($ids, fn ($q) => $q->whereNotIn('external_id', $ids))
            ->delete();

        foreach ($dto->documents as $doc) {
            PublicOpportunityDocument::query()->updateOrCreate(
                ['opportunity_id' => $opportunity->id, 'external_id' => $doc['external_id']],
                ['name' => $doc['name']],
            );
        }
    }
}
