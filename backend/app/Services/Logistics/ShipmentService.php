<?php

namespace App\Services\Logistics;

use App\Models\LogisticsIncident;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Procurement\DocumentNumberService;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Despachos coordinados con operadores aliados (CLAUDE.md §5.6): estados con transiciones explícitas,
 * eventos inmutables, incidencias y evidencia de entrega en disco privado.
 */
class ShipmentService
{
    public const STATUSES = [
        'PLANNED' => 'Programado',
        'IN_TRANSIT' => 'En tránsito',
        'DELIVERED' => 'Entregado',
        'FAILED' => 'Entrega fallida',
        'RETURNED' => 'Devuelto',
        'CANCELLED' => 'Anulado',
    ];

    /** Transiciones permitidas. DELIVERED se alcanza solo con confirmación de entrega. */
    private const TRANSITIONS = [
        'PLANNED' => ['IN_TRANSIT', 'CANCELLED'],
        'IN_TRANSIT' => ['FAILED', 'RETURNED'],
        'FAILED' => ['IN_TRANSIT', 'RETURNED', 'CANCELLED'],
    ];

    public const SERVICE_TYPES = ['STANDARD' => 'Estándar', 'EXPRESS' => 'Express', 'COURIER' => 'Courier', 'FREIGHT' => 'Carga', 'PICKUP' => 'Retiro en bodega'];

    public const INCIDENT_TYPES = ['DELAY' => 'Atraso', 'DAMAGE' => 'Daño', 'LOSS' => 'Pérdida', 'WRONG_ADDRESS' => 'Dirección errónea', 'REJECTED' => 'Rechazado por el destinatario', 'OTHER' => 'Otro'];

    private const EVIDENCE_MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly CurrentTenant $tenant,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $user): Shipment
    {
        return DB::transaction(function () use ($data, $user) {
            $shipment = Shipment::create($data + [
                'number' => $this->numbers->next($this->tenant->id(), DocumentNumberService::SHIPMENT),
                'status' => 'PLANNED',
                'created_by' => $user->getKey(),
            ]);
            $this->event($shipment, 'PLANNED', 'Despacho programado', null, now(), $user);
            $this->audit->log('shipments.created', $user->getKey(), 'shipment', $shipment->public_id);

            return $shipment;
        });
    }

    /** @param array<string, mixed> $data Solo datos operativos editables antes de entregar. */
    public function update(Shipment $shipment, array $data, User $user): Shipment
    {
        if (in_array($shipment->status, ['DELIVERED', 'CANCELLED', 'RETURNED'], true) && array_diff(array_keys($data), ['actual_cost', 'notes']) !== []) {
            throw ValidationException::withMessages(['status' => 'Un despacho cerrado solo admite costo real y notas.']);
        }
        $shipment->update($data);
        $this->audit->log('shipments.updated', $user->getKey(), 'shipment', $shipment->public_id, ['fields' => array_keys($data)]);

        return $shipment;
    }

    public function changeStatus(Shipment $shipment, string $to, ?string $description, ?string $location, ?string $occurredAt, User $user): Shipment
    {
        if (! in_array($to, self::TRANSITIONS[$shipment->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'No se puede pasar de "'.self::STATUSES[$shipment->status].'" a "'.(self::STATUSES[$to] ?? $to).'".']);
        }

        return DB::transaction(function () use ($shipment, $to, $description, $location, $occurredAt, $user) {
            $at = $occurredAt ? CarbonImmutable::parse($occurredAt, 'America/Santiago')->utc() : now();
            $extra = $to === 'IN_TRANSIT' && ! $shipment->picked_up_at ? ['picked_up_at' => $at] : [];
            $shipment->forceFill(['status' => $to] + $extra)->save();
            $this->event($shipment, $to, $description ?: self::STATUSES[$to], $location, $at, $user);

            return $shipment;
        });
    }

    /** Novedad sin cambio de estado (p. ej. "llegó al centro de distribución"). */
    public function note(Shipment $shipment, string $description, ?string $location, ?string $occurredAt, User $user): ShipmentEvent
    {
        return $this->event($shipment, null, $description, $location, $occurredAt ? CarbonImmutable::parse($occurredAt, 'America/Santiago')->utc() : now(), $user);
    }

    /** Confirmación de entrega con evidencia opcional (foto o PDF firmado). */
    public function deliver(Shipment $shipment, string $receivedBy, ?string $notes, ?string $deliveredAt, ?UploadedFile $evidence, User $user): Shipment
    {
        if (! in_array($shipment->status, ['PLANNED', 'IN_TRANSIT', 'FAILED'], true)) {
            throw ValidationException::withMessages(['status' => 'El despacho ya está cerrado.']);
        }
        $path = null;
        $mime = null;
        if ($evidence) {
            $mime = (string) $evidence->getMimeType();
            if (! isset(self::EVIDENCE_MIMES[$mime])) {
                throw ValidationException::withMessages(['evidence' => 'La evidencia debe ser PDF, JPG o PNG.']);
            }
            $path = Storage::disk('local')->putFileAs('tenants/'.$shipment->tenant_id.'/shipments/'.$shipment->public_id, $evidence, Str::uuid()->toString().'.'.self::EVIDENCE_MIMES[$mime]);
        }

        try {
            return DB::transaction(function () use ($shipment, $receivedBy, $notes, $deliveredAt, $path, $mime, $user) {
                $at = $deliveredAt ? CarbonImmutable::parse($deliveredAt, 'America/Santiago')->utc() : now();
                $shipment->forceFill([
                    'status' => 'DELIVERED',
                    'delivered_at' => $at,
                    'received_by_name' => $receivedBy,
                    'delivery_notes' => $notes,
                    'evidence_path' => $path ?? $shipment->evidence_path,
                    'evidence_mime' => $mime ?? $shipment->evidence_mime,
                    'picked_up_at' => $shipment->picked_up_at ?? $at,
                ])->save();
                $this->event($shipment, 'DELIVERED', 'Entregado a '.$receivedBy, null, $at, $user);
                $this->audit->log('shipments.delivered', $user->getKey(), 'shipment', $shipment->public_id, ['evidence' => $path !== null]);

                return $shipment;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    public function reportIncident(Shipment $shipment, string $type, string $description, User $user): LogisticsIncident
    {
        return DB::transaction(function () use ($shipment, $type, $description, $user) {
            $incident = LogisticsIncident::create(['shipment_id' => $shipment->getKey(), 'type' => $type, 'description' => $description, 'reported_by' => $user->getKey()]);
            $this->event($shipment, null, 'Incidencia: '.(self::INCIDENT_TYPES[$type] ?? $type).'. '.$description, null, now(), $user);
            $this->audit->log('shipments.incident_reported', $user->getKey(), 'shipment', $shipment->public_id, ['type' => $type]);

            return $incident;
        });
    }

    public function resolveIncident(LogisticsIncident $incident, string $resolution, User $user): LogisticsIncident
    {
        if ($incident->status === 'RESOLVED') {
            throw ValidationException::withMessages(['status' => 'La incidencia ya está resuelta.']);
        }
        $incident->update(['status' => 'RESOLVED', 'resolution' => $resolution, 'resolved_at' => now()]);
        $shipment = Shipment::query()->findOrFail($incident->shipment_id);
        $this->event($shipment, null, 'Incidencia resuelta: '.$resolution, null, now(), $user);

        return $incident;
    }

    private function event(Shipment $shipment, ?string $status, string $description, ?string $location, $at, User $user): ShipmentEvent
    {
        return ShipmentEvent::create([
            'shipment_id' => $shipment->getKey(),
            'status' => $status,
            'description' => Str::limit($description, 495),
            'location' => $location,
            'occurred_at' => $at,
            'user_id' => $user->getKey(),
        ]);
    }
}
