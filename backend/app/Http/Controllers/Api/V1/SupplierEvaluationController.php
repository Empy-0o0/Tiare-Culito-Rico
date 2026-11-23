<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierEvaluation;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Evaluaciones de desempeño de un proveedor. Lectura con suppliers.read; registro con suppliers.evaluate.
 * El puntaje global es el promedio simple de los cinco criterios (metodología visible en la respuesta).
 */
class SupplierEvaluationController extends Controller
{
    public function index(Supplier $supplier): JsonResponse
    {
        Gate::authorize('view', $supplier);

        $evaluations = SupplierEvaluation::query()
            ->where('supplier_id', $supplier->getKey())
            ->with('evaluator:id,name')
            ->orderByDesc('evaluated_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return ApiResponse::ok($evaluations->map(fn (SupplierEvaluation $e) => $this->present($e))->values(), [
            'summary' => $this->summary($supplier),
            'criteria' => collect(SupplierEvaluation::CRITERIA)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'methodology' => 'Escala de 1 (deficiente) a 5 (excelente). El puntaje global es el promedio simple de los cinco criterios; el resumen promedia todas las evaluaciones registradas.',
        ]);
    }

    public function store(Request $request, Supplier $supplier, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('evaluate', $supplier);
        $score = ['required', 'integer', 'between:1,5'];
        $v = $request->validate(array_merge(
            array_fill_keys(array_keys(SupplierEvaluation::CRITERIA), $score),
            [
                'evaluated_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'reference' => ['nullable', 'string', 'max:120'],
                'had_incident' => ['sometimes', 'boolean'],
                'comment' => ['nullable', 'string', 'max:2000', 'required_if:had_incident,true'],
            ],
        ), ['comment.required_if' => 'Describe el incidente en el comentario.']);

        $overall = array_sum(array_intersect_key($v, SupplierEvaluation::CRITERIA)) / count(SupplierEvaluation::CRITERIA);

        $evaluation = SupplierEvaluation::create($v + [
            'supplier_id' => $supplier->getKey(),
            'evaluated_by' => $request->user()->getKey(),
            'overall_score' => round($overall, 2),
            'had_incident' => (bool) ($v['had_incident'] ?? false),
        ]);
        $audit->log('suppliers.evaluated', auditableType: 'supplier', auditableId: $supplier->public_id, metadata: [
            'evaluation' => $evaluation->public_id,
            'overall' => $evaluation->overall_score,
        ]);

        return ApiResponse::ok($this->present($evaluation->load('evaluator:id,name')), ['summary' => $this->summary($supplier)], 201);
    }

    /** @return array{count:int, overall:?string, by_criterion: array<string, ?string>, incidents:int, last_evaluated_on:?string} */
    private function summary(Supplier $supplier): array
    {
        $base = SupplierEvaluation::query()->where('supplier_id', $supplier->getKey());
        $count = (clone $base)->count();
        $avg = fn (string $col) => $count ? number_format((float) (clone $base)->avg($col), 2, '.', '') : null;

        return [
            'count' => $count,
            'overall' => $avg('overall_score'),
            'by_criterion' => collect(SupplierEvaluation::CRITERIA)->keys()->mapWithKeys(fn ($k) => [$k => $avg($k)])->all(),
            'incidents' => (clone $base)->where('had_incident', true)->count(),
            'last_evaluated_on' => ($last = (clone $base)->max('evaluated_on')) ? CarbonImmutable::parse($last)->toDateString() : null,
        ];
    }

    private function present(SupplierEvaluation $e): array
    {
        return [
            'public_id' => $e->public_id,
            'evaluated_on' => $e->evaluated_on?->toDateString(),
            'reference' => $e->reference,
            'scores' => collect(SupplierEvaluation::CRITERIA)->keys()->mapWithKeys(fn ($k) => [$k => $e->{$k}])->all(),
            'overall' => $e->overall_score,
            'had_incident' => $e->had_incident,
            'comment' => $e->comment,
            'evaluated_by' => $e->evaluator?->name,
            'created_at' => $e->created_at?->toIso8601String(),
            'data_origin' => 'client',
        ];
    }
}
