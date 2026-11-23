<?php

namespace App\Services\MercadoPublico\Persistence;

use App\Models\PublicPurchaseOrder;
use App\Services\MercadoPublico\DTO\V1Record;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Upsert idempotente de órdenes de compra públicas (unique source_system + external_code). */
class PurchaseOrderRepository
{
    /** @param list<V1Record> $records @return array{created:int, updated:int, unchanged:int} */
    public function upsertMany(array $records, ?CarbonImmutable $fetchedAt = null): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach (array_chunk($records, 200) as $chunk) {
            DB::transaction(function () use ($chunk, $fetchedAt, &$summary): void {
                foreach ($chunk as $record) {
                    $summary[$this->upsert($record, $fetchedAt)]++;
                }
            });
        }

        return $summary;
    }

    /** @return 'created'|'updated'|'unchanged' */
    public function upsert(V1Record $record, ?CarbonImmutable $fetchedAt = null): string
    {
        $fetchedAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($record, $fetchedAt): string {
            $model = PublicPurchaseOrder::query()
                ->where('source_system', PublicPurchaseOrder::SOURCE_SYSTEM)
                ->where('external_code', $record->code)
                ->lockForUpdate()
                ->first();

            $hashColumn = $record->isDetail ? 'detail_payload_hash' : 'list_payload_hash';
            $fetchedColumn = $record->isDetail ? 'detail_fetched_at' : 'last_fetched_at';

            if ($model && $model->{$hashColumn} === $record->payloadHash) {
                $model->forceFill(['last_fetched_at' => $fetchedAt, $fetchedColumn => $fetchedAt])->save();

                return 'unchanged';
            }

            $isNew = $model === null;
            $model ??= new PublicPurchaseOrder([
                'source_system' => PublicPurchaseOrder::SOURCE_SYSTEM,
                'external_code' => $record->code,
            ]);

            $attributes = $record->attributes + ['source_api' => 'ordenes_compra_v1'];
            if ($record->isDetail) {
                $attributes['raw_payload'] = $record->raw;
            }
            $model->fill($attributes);
            $model->forceFill([$hashColumn => $record->payloadHash, 'last_fetched_at' => $fetchedAt, $fetchedColumn => $fetchedAt]);
            $model->save();

            return $isNew ? 'created' : 'updated';
        });
    }
}
