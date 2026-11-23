<?php

namespace App\Services\MercadoPublico\Adapters;

use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\Http\MercadoPublicoV1Client;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Services\MercadoPublico\Normalizers\V1Values;

/**
 * BuscarProveedor / BuscarComprador (API V1).
 * La página oficial describe la salida como "Código Empresa" y "Nombre Empresa" sin nombrar
 * las claves JSON: se aceptan las variantes CodigoEmpresa/NombreEmpresa y Codigo/Nombre dentro
 * de listaEmpresas/Listado. PENDIENTE validar con una respuesta real (PEND-T10).
 */
class EmpresasV1Adapter
{
    public function __construct(
        private readonly MercadoPublicoV1Client $client,
        private readonly MercadoPublicoConfig $config,
        private readonly V1Values $v,
    ) {}

    /** @return list<array{code:string, name:string}> */
    public function buyers(): array
    {
        return $this->companies($this->client->get($this->config->v1Path('buyers')));
    }

    /** @param string $formattedRut RUT con puntos, guion y DV (formato exigido por la API). */
    public function supplierByRut(string $formattedRut): ?array
    {
        $companies = $this->companies($this->client->get($this->config->v1Path('supplier_lookup'), ['rutempresaproveedor' => $formattedRut]));

        // Observado el 17-11-2026: un RUT válido devuelve exactamente 1 empresa; un RUT inexistente devolvió
        // 2 empresas sin relación. Solo un resultado único se considera el proveedor buscado.
        return count($companies) === 1 ? $companies[0] : null;
    }

    /** @return list<array{code:string, name:string}> */
    private function companies(array $json): array
    {
        $rows = $this->v->list($json, 'listaEmpresas', 'ListaEmpresas', 'Listado');
        if ($rows === [] && ! isset($json['listaEmpresas']) && ! isset($json['ListaEmpresas']) && ! isset($json['Listado']) && ! isset($json['Cantidad'])) {
            throw new MercadoPublicoException(MercadoPublicoException::INVALID_RESPONSE, 'Mercado Público (API V1) respondió sin el listado de empresas esperado.');
        }

        $out = [];
        foreach ($rows as $row) {
            $code = $this->v->str($row, 'CodigoEmpresa', 32) ?? $this->v->str($row, 'Codigo', 32);
            $name = $this->v->str($row, 'NombreEmpresa', 500) ?? $this->v->str($row, 'Nombre', 500);
            if ($code !== null && $name !== null) {
                $out[] = ['code' => $code, 'name' => $name];
            }
        }

        return $out;
    }
}
