# TIARE — Arquitectura y plan de implementación

## 1. Arquitectura definitiva

- **Backend:** Laravel 12 (PHP 8.2) como monolito modular, API REST versionada `/api/v1`.
- **Frontend:** SPA React 18 + Vite + TypeScript, desacoplada; consume solo la API interna.
- **Base de datos:** MySQL 8 InnoDB utf8mb4 en producción; MariaDB 10.4 (XAMPP) en desarrollo; SQLite en memoria para tests (TD-002).
- **Autenticación:** Laravel Sanctum en modo SPA (cookie de sesión + CSRF). Sin tokens en `localStorage`.
- **Multiempresa:** `tenants` + `tenant_user` con rol por tenant; el tenant activo se resuelve en backend desde la sesión, nunca desde un parámetro del navegador.
- **Colas:** driver `database`. **Scheduler:** Laravel Scheduler (`routes/console.php`).
- **Integraciones:** adaptadores por API bajo `app/Services/MercadoPublico`, llamados solo desde backend.

```text
React SPA ──HTTP (cookie Sanctum)──▶ Laravel /api/v1 ──▶ Domain services ──▶ MySQL
                                           │
                                           └─▶ MercadoPublico adapters ──HTTPS + ticket──▶ api2/api.mercadopublico.cl
Scheduler ─▶ SyncCompraAgilJob ─▶ MercadoPublicoSyncService ─┘
```

## 2. Estructura del repositorio

```text
Tiare Ingeniera Culito Rico/
├── CLAUDE.md
├── README.md
├── .gitignore
├── docs/                       ARCHITECTURE, DECISIONS, IMPLEMENTATION_STATUS, MERCADO_PUBLICO_API
├── TIARE_PROTOTIPO_HTML_CSS_JS/tiare-prototype/   referencia funcional/visual (no se modifica)
├── *.md (raíz)                 especificaciones de INGENERIA JARVIS (otro proyecto, ver TD-001)
├── backend/                    Laravel 12
│   ├── app/
│   │   ├── Domain/PublicProcurement/        enums (CompraAgilStatus, Region)
│   │   ├── Http/Controllers/Api/V1/         controllers delgados
│   │   ├── Http/Requests/                   Form Requests
│   │   ├── Http/Resources/                  API Resources
│   │   ├── Http/Middleware/                 tenant activo, request id
│   │   ├── Jobs/                            SyncCompraAgilJob
│   │   ├── Models/                          Eloquent
│   │   ├── Policies/
│   │   ├── Services/MercadoPublico/
│   │   │   ├── MercadoPublicoConfig.php
│   │   │   ├── Exceptions/                  MercadoPublicoException (+ tipos)
│   │   │   ├── Http/MercadoPublicoV2Client.php   (V1Client en Fase 3)
│   │   │   ├── Adapters/CompraAgilV2Adapter.php
│   │   │   ├── DTO/CompraAgilDTO.php, ...
│   │   │   ├── Normalizers/CompraAgilNormalizer.php
│   │   │   └── Sync/MercadoPublicoSyncService.php, MercadoPublicoQuotaService.php
│   │   └── Support/                         ApiResponse, DateParsing
│   ├── database/migrations | factories | seeders
│   ├── routes/api.php, console.php
│   └── tests/Unit | Feature | Fixtures/mercado_publico/*.json
└── frontend/                   React + Vite + TS
    └── src/
        ├── app/ (router, layout, auth context)
        ├── components/ (Sidebar, Topbar, StatusBadge, SourceAttribution, DemoBanner…)
        ├── features/ (dashboard, public-procurement, procurement, suppliers, crm, logistics, agents, reports)
        ├── services/ (apiClient, publicProcurement)
        ├── types/
        └── styles/ (tokens y estilos portados desde el prototipo)
```

## 3. Inventario del prototipo y destino

| Vista prototipo | Funciones actuales | Destino |
|---|---|---|
| Shell (sidebar, topbar, búsqueda ⌘K, notificaciones, toasts, modal, menú móvil) | Navegación por `data-page`, búsqueda global sobre datos demo | **Migrar** a React (Fase 1) con los mismos estilos |
| Resumen ejecutivo | KPIs fijos, gráfico SVG estático, accesos rápidos, actividad simulada | Migrar en Fase 2; KPIs reales calculados desde API; mientras tanto, banner **DATOS DEMOSTRATIVOS** |
| Solicitudes de compra | Tabla, filtros, alta en `localStorage` | Fase 4 (compras privadas) con API real; se descarta `localStorage` |
| Cotizaciones / comparador | Tabla estática, modal de costo total | Fase 4: motor de costo total en backend |
| Mercado Público | 4 licitaciones ficticias, búsqueda local, "Actualizar" simulado | **Fase 1: reemplazado por Compra Ágil V2 real** con filtros, detalle, fuente y última sincronización |
| Proveedores | Directorio ficticio, alta no persistente | Fase 2 (CRUD + RUT) |
| Clientes / CRM | Directorio con alta en `localStorage` | Fase 2 |
| Logística | Despachos estáticos | Fase 5 |
| Agentes IA | Tarjetas y ejecuciones simuladas | Fase 6; se mantiene vista marcada "demostración" |
| Analítica | KPIs y gráfico estáticos | Fase 5–6 con cifras con fuente/periodo |
| Configuración | Formulario visual sin backend | Fase 2 (tenant, usuarios) |

Se descarta: persistencia en `localStorage`, métricas ficticias presentadas como reales, botones que simulan acciones sin indicarlo.

## 4. Plan por fases

| Fase | Contenido | Criterio de salida |
|---|---|---|
| **0 · Auditoría** ✅ | Inventario, docs, validación real de la API V2 | Este documento + DECISIONS + MERCADO_PUBLICO_API |
| **1 · Base + Compra Ágil V2** | Laravel + Sanctum + tenants/roles/permisos mínimos + auditoría mínima; conector V2 (cliente, errores, cuota, normalizador, DTO); tablas `public_procurement_*` y `mercado_publico_*`; sync incremental idempotente + comando + job; API interna listado/detalle/sync-status/sync; React con shell del prototipo, login y monitor Compra Ágil | Tests verdes (sin internet), build frontend, una consulta real controlada |
| 2a · CRM, proveedores, catálogo ✅ | Organizaciones, contactos, clientes, proveedores (RUT), productos, categorías | CRUD + aislamiento por tenant probado |
| 2b · Administración | Usuarios y empresa, edición en UI, documentos y evaluación de proveedores | — |
| 3 · Mercado Público V1 | Cliente V1 con redacción de ticket; licitaciones, OC públicas, proveedor por RUT, organismos; búsquedas guardadas, matching, alertas, panel de cuota | Tests + validación real |
| 4 · Compras privadas | Solicitudes, cotizaciones, comparador costo total, aprobaciones, OC internas, recepción | Flujo completo auditado |
| 5 · Logística e inventario | Despachos, eventos, transportistas, bodegas, movimientos | — |
| 6 · Agentes IA | Tender Intelligence, comparación privada, orquestador con aprobación humana | — |
| 7 · Producción | Hardening, backups, monitoreo, staging | — |
