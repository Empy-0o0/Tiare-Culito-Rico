<?php

namespace App\Services\MercadoPublico\Persistence;

use App\Models\PublicOpportunityItem;
use App\Models\PublicProcurementOpportunity;
use App\Models\PublicProcurementRawPayload;
use App\Services\MercadoPublico\DTO\V1Record;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Upsert idempotente de licitaciones V1 en public_procurement_opportunities
 * (unique source_system + external_code, source_type = licitacion).
 * Un listado solo actualiza título, estado y cierre: no borra lo que trajo el detalle.
 */
class LicitacionRepository
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
            $model = PublicProcurementOpportunity::query()
                ->where('source_system', PublicProcurementOpportunity::SOURCE_SYSTEM)
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
            $model ??= new PublicProcurementOpportunity([
                'source_system' => PublicProcurementOpportunity::SOURCE_SYSTEM,
                'source_type' => PublicProcurementOpportunity::TYPE_LICITACION,
                'external_code' => $record->code,
            ]);

            $model->fill($record->attributes + [
                'source_api' => 'licitaciones_v1',
                'source_api_version' => 'v1',
            ]);
            $model->forceFill([$hashColumn => $record->payloadHash, 'last_fetched_at' => $fetchedAt, $fetchedColumn => $fetchedAt]);
            $model->save();

            if ($record->isDetail) {
                PublicOpportunityItem::query()->where('opportunity_id', $model->id)->delete();
                foreach ($record->items as $item) {
                    PublicOpportunityItem::create($item + ['opportunity_id' => $model->id]);
                }
            }

            PublicProcurementRawPayload::query()->updateOrCreate(
                ['opportunity_id' => $model->id, 'kind' => $record->isDetail ? 'detail' : 'list'],
                ['payload' => $record->raw, 'payload_hash' => $record->payloadHash, 'fetched_at' => $fetchedAt],
            );

            return $isNew ? 'created' : 'updated';
        });
    }
}
