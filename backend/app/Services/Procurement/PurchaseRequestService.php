<?php

namespace App\Services\Procurement;

use App\Domain\Procurement\PurchaseRequestStatus as Status;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseApproval;
use App\Models\PurchaseRequest;
use App\Models\SupplierQuotation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida de una solicitud de compra (CLAUDE.md §5.4), con transiciones explícitas,
 * versión del documento, auditoría y separación de funciones en la aprobación:
 * quien propone la compra no puede aprobarla, y cada aprobador tiene un límite en CLP.
 */
class PurchaseRequestService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly CurrentTenant $tenant,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $user): PurchaseRequest
    {
        return DB::transaction(function () use ($data, $user) {
            $request = PurchaseRequest::create($this->header($data) + [
                'number' => $this->numbers->next($this->tenant->id(), DocumentNumberService::REQUEST),
                'status' => Status::Draft,
                'currency' => 'CLP',
                'requested_by' => $user->getKey(),
            ]);
            $this->replaceItems($request, $data['items']);
            $this->audit->log('purchase_requests.created', $user->getKey(), 'purchase_request', $request->public_id);

            return $request;
        });
    }

    /** Solo en borrador o enviada (antes de cotizar). Cada edición sube la versión. */
    public function update(PurchaseRequest $request, array $data, User $user): PurchaseRequest
    {
        $this->assertStatus($request, [Status::Draft, Status::Submitted], 'Solo se edita una solicitud en borrador o recién enviada.');

        return DB::transaction(function () use ($request, $data, $user) {
            $request->fill($this->header($data));
            $request->version++;
            $request->save();
            if (array_key_exists('items', $data)) {
                $this->replaceItems($request, $data['items']);
            }
            $this->audit->log('purchase_requests.updated', $user->getKey(), 'purchase_request', $request->public_id, ['version' => $request->version]);

            return $request;
        });
    }

    public function submit(PurchaseRequest $request, User $user): PurchaseRequest
    {
        $this->assertStatus($request, [Status::Draft], 'Solo se envía una solicitud en borrador.');
        if ($request->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => 'Agrega al menos un ítem antes de enviar.']);
        }

        return $this->transition($request, Status::Submitted, $user, ['submitted_at' => now()]);
    }

    /** Compras toma la solicitud y empieza a pedir cotizaciones. */
    public function startQuoting(PurchaseRequest $request, User $user): PurchaseRequest
    {
        $this->assertStatus($request, [Status::Submitted], 'Solo se cotiza una solicitud enviada.');

        return $this->transition($request, Status::Quoting, $user);
    }

    /** Propone una cotización para aprobación (paso 6–7). */
    public function propose(PurchaseRequest $request, SupplierQuotation $quotation, ?string $justification, User $user): PurchaseRequest
    {
        $this->assertStatus($request, [Status::Quoting], 'La solicitud debe estar en cotización para proponer una compra.');
        if ($quotation->purchase_request_id !== $request->getKey() || $quotation->status === 'DISCARDED') {
            throw ValidationException::withMessages(['quotation_public_id' => 'La cotización no pertenece a esta solicitud o fue descartada.']);
        }
        if ($quotation->valid_until && $quotation->valid_until->lessThan(CarbonImmutable::now('America/Santiago')->startOfDay())) {
            throw ValidationException::withMessages(['quotation_public_id' => 'La cotización está vencida. Pide al proveedor que la renueve.']);
        }
        $missing = $request->items()->whereNotIn('id', $quotation->items()->pluck('purchase_request_item_id'))->count();
        if ($missing > 0) {
            throw ValidationException::withMessages(['quotation_public_id' => "La cotización no cubre {$missing} ítem(s) de la solicitud."]);
        }

        return DB::transaction(function () use ($request, $quotation, $justification, $user) {
            SupplierQuotation::query()->where('purchase_request_id', $request->getKey())->where('status', 'SELECTED')->update(['status' => 'RECEIVED']);
            $quotation->update(['status' => 'SELECTED']);

            return $this->transition($request, Status::PendingApproval, $user, [
                'selected_quotation_id' => $quotation->getKey(),
                'proposed_by' => $user->getKey(),
                'proposed_at' => now(),
                'proposal_justification' => $justification,
            ], ['quotation' => $quotation->public_id, 'total_clp' => $quotation->total_clp]);
        });
    }

    /**
     * Aprueba o rechaza la compra propuesta. Reglas:
     * - Quien propuso la compra no la aprueba (separación de funciones; aplica también a agentes IA).
     * - El monto en CLP no puede superar el límite de aprobación de la persona en esta empresa.
     * - Se registra usuario, versión, cotización, monto y comentario (inmutable).
     * Un rechazo devuelve la solicitud a cotización.
     */
    public function decide(PurchaseRequest $request, bool $approve, ?string $comment, User $user): PurchaseRequest
    {
        $this->assertStatus($request, [Status::PendingApproval], 'No hay una compra esperando aprobación.');
        if ($request->proposed_by === $user->getKey()) {
            throw ValidationException::withMessages(['decision' => 'Quien propuso la compra no puede aprobarla ni rechazarla. Debe decidir otra persona.']);
        }
        if (! $approve && trim((string) $comment) === '') {
            throw ValidationException::withMessages(['comment' => 'Indica el motivo del rechazo.']);
        }

        $quotation = $request->selectedQuotation;
        $amount = (string) $quotation->total_clp;
        if ($approve) {
            $limit = $this->tenant->get()?->users()->whereKey($user->getKey())->first()?->pivot->approval_limit_clp;
            if ($limit !== null && Money::cmp($amount, (string) $limit) > 0) {
                throw ValidationException::withMessages(['decision' => 'El monto supera tu límite de aprobación ('.number_format((float) $limit, 0, ',', '.').' CLP). Debe aprobar alguien con un límite mayor.']);
            }
        }

        return DB::transaction(function () use ($request, $approve, $comment, $user, $quotation, $amount) {
            PurchaseApproval::create([
                'purchase_request_id' => $request->getKey(),
                'user_id' => $user->getKey(),
                'decision' => $approve ? 'APPROVED' : 'REJECTED',
                'comment' => $comment,
                'request_version' => $request->version,
                'quotation_id' => $quotation->getKey(),
                'amount_clp' => $amount,
            ]);
            if (! $approve) {
                $quotation->update(['status' => 'RECEIVED']);
            }

            return $this->transition($request, $approve ? Status::Approved : Status::Quoting, $user, [
                'decided_at' => now(),
                'selected_quotation_id' => $approve ? $quotation->getKey() : null,
            ], ['decision' => $approve ? 'APPROVED' : 'REJECTED']);
        });
    }

    public function cancel(PurchaseRequest $request, string $reason, User $user): PurchaseRequest
    {
        if (! $request->status->isCancellable()) {
            throw ValidationException::withMessages(['status' => 'La solicitud ya no se puede anular (tiene una orden emitida o está cerrada).']);
        }

        return $this->transition($request, Status::Cancelled, $user, ['cancelled_at' => now(), 'cancel_reason' => $reason]);
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function header(array $data): array
    {
        $out = array_intersect_key($data, array_flip(['title', 'description', 'cost_center', 'delivery_address', 'needed_by', 'priority']));
        if (array_key_exists('customer_public_id', $data)) {
            $out['customer_id'] = $data['customer_public_id'] ? Customer::query()->where('public_id', $data['customer_public_id'])->value('id') : null;
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $items */
    private function replaceItems(PurchaseRequest $request, array $items): void
    {
        $request->items()->delete();
        $total = '0';
        $complete = true;
        foreach (array_values($items) as $i => $item) {
            $product = isset($item['product_public_id']) ? Product::query()->where('public_id', $item['product_public_id'])->first() : null;
            $request->items()->create([
                'line_number' => $i + 1,
                'product_id' => $product?->getKey(),
                'description' => $item['description'] ?? $product?->name,
                'quantity' => $item['quantity'],
                'unit_code' => $item['unit_code'] ?? $product?->unit?->code ?? 'EA',
                'estimated_unit_price' => $item['estimated_unit_price'] ?? null,
                'notes' => $item['notes'] ?? null,
            ]);
            if (isset($item['estimated_unit_price'])) {
                $total = Money::add($total, Money::mul((string) $item['quantity'], (string) $item['estimated_unit_price']));
            } else {
                $complete = false;
            }
        }
        // Estimado solo si todos los ítems tienen precio de referencia (no se inventan valores).
        $request->update(['estimated_total' => $complete && $items ? Money::round($total) : null]);
    }

    /** @param list<Status> $allowed */
    private function assertStatus(PurchaseRequest $request, array $allowed, string $message): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function transition(PurchaseRequest $request, Status $to, User $user, array $extra = [], array $meta = []): PurchaseRequest
    {
        $from = $request->status;
        $request->forceFill(['status' => $to] + $extra)->save();
        $this->audit->log('purchase_requests.status_changed', $user->getKey(), 'purchase_request', $request->public_id, ['from' => $from->value, 'to' => $to->value] + $meta);

        return $request;
    }
}
