# TIARE — Estado de implementación

**Última actualización:** 17-11-2026 · **Hito actual:** superadministrador y portal de clientes y proveedores (§20). Antes: contraseña de inicio también desde la empresa (§19). Antes: contraseña de inicio, Mi cuenta y oferta/capacidad de proveedores (§18). Antes: documentos MP, eliminación CRM y mantenedor de cuentas/roles (§17). Antes: alertas de licitaciones (§15). V1 validada (§14); agentes IA sin modelo activo (§12).

## 1. Auditoría inicial (Fase 0)

### Inventario encontrado
| Elemento | Estado |
|---|---|
| `CLAUDE.md` | Leído completo. Fuente principal de alcance. |
| Prototipo `TIARE_PROTOTIPO_HTML_CSS_JS/tiare-prototype/` (index.html, styles.css, app.js 148 líneas minificadas, README) | Leído. 10 vistas, datos ficticios, `localStorage` para solicitudes y clientes. Se conserva intacto. Ver mapa en `ARCHITECTURE.md` §3. |
| `TIARE_PROTOTIPO_HTML_CSS_JS.zip` | No existe (el prototipo ya está descomprimido). |
| `PRODUCT_REQUIREMENTS.md`, `DATABASE_SPEC.md`, `API_SPEC.md`, `LARAVEL_DATABASE_IMPLEMENTATION.md`, `DECISIONS.md`, `INGENERIA_JARVIS_MASTER_SPEC.md` | **Pertenecen a otro proyecto (INGENERIA JARVIS / Ergo SaniTas SpA).** Se reutilizan solo convenciones técnicas (TD-001). |
| `PLAN_NEGOCIOS_TIARE_SPA.md`, `AUTHORIZATION_MATRIX.md` | No existen. |
| Git | No había repositorio. Se agregó `.gitignore`; **no se inicializó Git ni se hizo commit**. |
| Entorno | PHP 8.2.12 (XAMPP), Composer 2.8.9, Node 20.19, MariaDB 10.4.32 (XAMPP, no responde al handshake — PEND-T06). |
| Documentación oficial ChileCompra | Revisada: página API (05-11-2026) y Guía Compra Ágil v2 v3.0 (mayo 2026). |

## 2. Fase 1 — implementado

### Backend (`backend/`, Laravel 12.72 + Sanctum 4)
- **Migraciones** (reversibles, probadas con `migrate:rollback`):
  - `2026_11_14_100000_create_tenancy_and_rbac_tables`: `tenants`, `roles`, `permissions`, `permission_role`, `tenant_user`, columnas en `users` (`public_id`, `platform_role_id`, `is_active`, `last_login_at`), `audit_logs`.
  - `2026_11_14_100100_create_public_procurement_tables`: `public_procurement_opportunities` (unique `source_system+external_code`, índices por estado, publicación, cierre, último cambio, región, comprador, monto), `public_opportunity_items`, `public_opportunity_documents`, `public_procurement_raw_payloads`.
  - `2026_11_14_100200_create_mercado_publico_sync_tables`: `mercado_publico_sync_runs`, `mercado_publico_sync_state`, `mercado_publico_api_usage`.
- **Conector Compra Ágil V2** (`app/Services/MercadoPublico/`): config tipada, cliente HTTP (header `ticket`, TLS, timeouts, reintentos 5xx con backoff+jitter, 429 con `Retry-After` o bloqueo hasta el día siguiente), control de cuota local por día calendario de Chile, DTOs, normalizador tolerante a las diferencias reales de la API, adaptador listado/detalle, repositorio con upsert idempotente por hash, servicio de sincronización incremental (`ttl_cambio_ms` + solapamiento, paginación hasta `total_paginas`, checkpoints, lock de ejecución única), servicio de detalle con caché y copia "desactualizada" ante fallas.
- **Job** `SyncCompraAgilJob` (único) + **Scheduler** (desactivado por defecto) + **Comando** `tiare:mp:sync-compra-agil` (`--window`, `--region`, `--estado`, `--max-pages`, `--full`).
- **API interna `/api/v1`**: `health`, `auth/login|logout|me|tenant`, `public-procurement/catalogs`, `compra-agil` (filtros q/estado/región/RUT/cierre/monto/abiertas, orden con allowlist, paginación), `compra-agil/{code}`, `sync-status`, `POST sync` (permiso `public_procurement.sync`).
- **Seguridad**: Sanctum SPA, tenant resuelto en backend, RBAC con 11 roles y 23 permisos (CLAUDE.md §9), rate limiting, auditoría, formato de error uniforme. Ver `SECURITY.md`.

### Frontend (`frontend/`, React 18 + Vite 5 + TS)
- Estilos del prototipo portados sin cambios (`src/styles/prototype.css`) + complementos (`app.css`); íconos SVG extraídos del prototipo.
- Shell (sidebar, topbar, búsqueda, footer), login, **Resumen ejecutivo** solo con cifras verificables, **Monitor Compra Ágil** (filtros, orden, paginación, tarjetas del prototipo, panel de sincronización y cuota, ficha con productos, documentos, OC, fuente y fecha de actualización).
- Módulos no migrados muestran banner **DATOS DEMOSTRATIVOS** y la fase planificada; no simulan operaciones.

## 3. Pruebas ejecutadas (resultado real)

| Comando | Resultado |
|---|---|
| `cd backend && php artisan test` | **76 pruebas, 253 aserciones, todas OK** (≈5 s, sin internet) |
| `php artisan migrate:fresh --seed` / `migrate:rollback --step=3` / `migrate` | OK en SQLite |
| `cd frontend && npm run lint` | 0 errores, 1 advertencia (fast-refresh en `AuthContext.tsx`) |
| `cd frontend && npx vitest run` | 6 pruebas OK |
| `cd frontend && npm run build` (incluye `tsc -b`) | OK |
| Búsqueda del ticket en `frontend/dist`, `frontend/src`, `docs`, código y tests backend | Sin coincidencias |
| Login + dashboard + monitor en navegador (localhost:5173 → :8000) | OK |

### Validación real contra Mercado Público (ticket del propietario, 14-11-2026)
| Operación | Resultado |
|---|---|
| Sondeo manual (curl) listado/detalle/errores | 200 / 404 / 401 / 400 (`tamano_pagina` mín. 10) — ver `MERCADO_PUBLICO_API.md` §2.8 |
| `tiare:mp:sync-compra-agil --window=30` | OK, 0 cambios, 1 solicitud |
| `--window=360 --max-pages=4` | `partial` (4/8 páginas), 200 registros creados, 4 solicitudes |
| Detalle real vía `CompraAgilDetailService` | OK; OC detectada por `id_orden_compra` |
| Carga `--full --estado=publicada` (1.er intento) | `failed` en página 10/189 por `QueryException` (muy probablemente bloqueo de SQLite por lecturas concurrentes del servidor web; no se registró el mensaje). Se agregó `busy_timeout=5000` + WAL para SQLite y el log ahora incluye el mensaje |
| Carga `--full --estado=publicada` (2.º intento, 02:50 hora de Chile) | **`succeeded`: 189/189 páginas, 9.445 registros (8.488 nuevos, 957 sin cambios), 189 solicitudes** |
| Consumo total del día tras las pruebas | 208 solicitudes (registradas en SQLite) |
| Recarga `--full --estado=publicada` en **MariaDB** | `quota_exhausted` en página 63/189: **429 real de ChileCompra** tras ~275 solicitudes en el día. Quedaron 2.938 registros; sin reintentos; bloqueo hasta las 00:00 de Chile (TD-011) |
| Ejecución posterior con cuota bloqueada | 0 solicitudes enviadas (el bloqueo local funcionó) |
| Ficha real en la UI (`2281-2093-COT26`) | OK: descripción, comprador, entrega, fechas, presupuesto, OC, fuente |

## 4. Migraciones
- **MariaDB 10.4.32 (base `tiare`)**: `migrate --seed`, `migrate:rollback --step=3` y re-`migrate` OK. 23 tablas InnoDB, utf8mb4_unicode_ci.
- **Suite contra MariaDB** (`DB_CONNECTION=mariadb DB_DATABASE=tiare_test php artisan test`): 76/76 OK.
- SQLite (`backend/database/database.sqlite`) ya no se usa; se conserva como referencia.
- Pendiente: validar contra MySQL 8 antes de producción.

## 5. Variables de entorno
Ver `backend/.env.example` y `docs/MERCADO_PUBLICO_API.md` §5. Obligatorias: `SEED_ADMIN_EMAIL`, `SEED_ADMIN_PASSWORD`, `MERCADO_PUBLICO_ENABLED`, `MERCADO_PUBLICO_TICKET`.

## 6. Riesgos y pendientes
- Ticket expuesto en el chat → reemplazarlo (PEND-T04).
- Zona horaria de fechas V2 inferida, no confirmada (TD-005, PEND-T03).
- La cuota real del ticket V2 es de ~275/día (TD-011): limita las cargas completas y el uso intensivo de fichas de detalle.
- Completar la carga de publicadas: `php artisan tiare:mp:sync-compra-agil --full --estado=publicada --from-page=60 --max-pages=150`, después de las 00:00 y antes de las 07:00.
- El disco C: llegó a llenarse y dañó una tabla del sistema de MariaDB (recuperada, TD-010). Mantener espacio libre.
- `proveedores_cotizando[]` se conserva en el payload crudo pero aún no se normaliza.
- No existe especificación funcional propia de TIARE (PEND-T01).

## 7. Fase 2a — CRM, proveedores y catálogo (14-11-2026)

### Implementado
- **Migración** `2026_11_14_200000_create_crm_and_catalog_tables`: `organizations` (RUT único por tenant), `contacts`, `customers`, `suppliers`, `supplier_categories`, `categories`, `units_of_measure` (global), `products` (SKU único por tenant y código de catálogo de Mercado Público opcional).
- **Aislamiento por tenant** (TD-012): trait `BelongsToTenant` (global scope que falla cerrado, asigna y bloquea `tenant_id`), Policies (`Customer`, `Supplier`, `Product`, `Category`, `Organization`), middleware de tenant antes del binding.
- **Dominio**: enums `OrganizationKind`, `CustomerStatus`, `SupplierStatus`; `ChileanRut` (módulo 11) y regla `ValidChileanRut`; servicios `OrganizationService` y `PartyService` (transaccionales y auditados).
- **API**: `GET /references`; `GET|POST /customers`, `GET|PATCH /customers/{id}`; `GET|POST /suppliers`, `GET|PATCH /suppliers/{id}`; `POST /organizations/{id}/contacts`; `GET|POST /categories`; `GET|POST /products`, `GET|PATCH /products/{id}`.
- **Permisos nuevos**: `customers.read|create|update` y `products.read|manage`, asignados por rol.
- **Seeders**: `UnitsOfMeasureSeeder` (15 unidades, incluye EA y KG de Compra Ágil) y `DemoDataSeeder` (6 clientes, 6 proveedores, 7 categorías y 3 productos ficticios del prototipo, solo en el tenant demo).
- **Frontend**: páginas Clientes, Proveedores (categorías, cobertura, estados) y Catálogo (categorías y productos/servicios); validación de RUT en el cliente; banner de datos demostrativos en el tenant demo.

### Pruebas
| Comando | Resultado |
|---|---|
| `php artisan test` (SQLite) | **113 pruebas, 389 aserciones OK** |
| `DB_CONNECTION=mariadb DB_DATABASE=tiare_test php artisan test` | **113 OK** |
| Pruebas de aislamiento (IDOR) | Lectura, edición, contactos y referencias cruzadas de otro tenant → 404/422; sin tenant activo no hay filas; no se puede cambiar `tenant_id` |
| `npm run lint` / `npx vitest run` / `npm run build` | 0 errores (1 advertencia de fast-refresh) / 8 OK / OK |
| Navegador | Listados demo, alta de cliente con RUT inválido (bloqueada) y válido (creado como Prospecto), catálogo OK |

### Pendiente de la Fase 2
- **2b**: administración de usuarios y empresa (Configuración), edición desde la UI (hoy solo alta y consulta; la API ya soporta PATCH), documentos y certificaciones de proveedores con vigencia, evaluación de desempeño.

## 8. API completa de Mercado Público y rediseño (17-11-2026)

Instrucción del propietario que modifica CLAUDE.md (TD-013).

### Backend
- **Migración** `2026_11_17_100000_create_mercado_publico_v1_tables` (reversible; `migrate:rollback --step=1` y `migrate` OK en MariaDB): columnas `procedure_type`, `buyer_code`, `awarded_at`, `details` en oportunidades; adjudicación por ítem; tablas `public_purchase_orders`, `public_buyers`, `public_suppliers`.
- **Conector V1** (`app/Services/MercadoPublico/`): `Http/MercadoPublicoV1Client` (ticket en query sin registrarlo, una solicitud a la vez, cuota `v1`, errores HTML/JSON/HTTP), `DTO/V1SearchQuery` (combinaciones documentadas, `ddmmaaaa`), adaptadores de licitaciones, OC y empresas, normalizadores (montos DECIMAL, fechas de Chile a UTC, enlaces solo a mercadopublico.cl, sin datos personales de contacto), repositorios idempotentes y servicios `V1/TenderService`, `PurchaseOrderService`, `BuyerDirectoryService`, `SupplierLookupService`.
- **API**: `GET tenders`, `tenders/{codigo}`, `purchase-orders`, `purchase-orders/{codigo}`, `buyers`, `suppliers/lookup`; catálogos V1 en `catalogs`; cuota V1 en `sync-status`. Límite `mp-v1` 40/min. Detalle en `MERCADO_PUBLICO_API.md` §3.
- **Compra Ágil**: la ficha expone `quotes` (proveedores que cotizan, desde `proveedores_cotizando[]`, solo datos comerciales).

### Frontend (rediseño, `DESIGN.md`)
- Sistema visual nuevo `src/styles/tiare.css`. Se eliminaron `prototype.css` y `app.css` (copias; el prototipo original sigue intacto).
- Riel de navegación claro, módulos planificados bajo "En desarrollo", sección **Mercado Público** con pestañas Compra Ágil, Licitaciones, Órdenes de compra, Organismos y Proveedores, y franja de sincronización y cuota V1/V2.
- Compra Ágil en filas con ficha lateral (incluye proveedores que cotizan) y apertura directa de cualquier código `…-COT..` aunque no esté sincronizado.
- Licitaciones y OC: barra de consulta a Mercado Público (fecha, estado, organismo, proveedor) más filtros locales; fichas con hitos, ítems, adjudicación, acta oficial y vínculo OC → licitación. Filtros y ficha abierta quedan en la URL.
- Organismos (directorio con accesos a sus licitaciones y OC del día) y Proveedores (búsqueda por RUT con validación de DV).
- Resumen encabezado por la línea de cierres de 72 h; pantalla de acceso rediseñada.

### Pruebas
| Comando | Resultado |
|---|---|
| `cd backend && php artisan test` | **134 pruebas, 511 aserciones OK** (21 nuevas de V1: cliente, redacción del ticket, errores, normalizadores, API, caché y validación) |
| `php artisan migrate:rollback --step=1` + `migrate` (MariaDB) | OK |
| `cd frontend && npx tsc -b` | OK |
| `npm run lint` | 0 errores, 1 advertencia previa (fast-refresh en `AuthContext.tsx`) |
| `npx vitest run` | 12 pruebas OK |
| `npm run build` | OK (296 kB JS, 21 kB CSS) |
| Consulta real V1 (`licitaciones.json`, 2 solicitudes) | **HTTP 400 con HTML "Runtime Error"**: V1 sin validar con datos reales (PEND-T10) |
| Navegador | Pantalla de acceso verificada; vistas con sesión iniciada pendientes de revisión visual |

### Variables nuevas
`MERCADO_PUBLICO_V1_LIST_CACHE_MINUTES` (30), `MERCADO_PUBLICO_V1_BUYERS_REFRESH_HOURS` (24), `MERCADO_PUBLICO_V1_HTTP_TIMEOUT` (45) y, opcionales, `MERCADO_PUBLICO_V1_*_PATH`.

### Riesgos
- V1 no validado con respuesta real; si el ticket no está habilitado para V1 hay que gestionarlo con ChileCompra.
- Grafías del filtro `estado` de OC sin verificar (PEND-T11).
- Los listados V1 solo traen código, nombre, estado y cierre: tipo, región y organismo aparecen al abrir cada ficha (una solicitud por ficha nueva).
- Archivo se carga desde Google Fonts; sin conexión se usa la fuente del sistema.

### Rediseño Material rosado (17-11-2026, TD-016)
- MUI 6 con tema Material 3 rosado (`src/app/theme.ts`); componentes compartidos `ResultCard`, `FilterBar`, `DataTable`, `Drawer` (MUI), `ListStates` (alertas, vacíos, esqueletos, paginación).
- Migrados: menú y barra superior, acceso, Resumen, Mercado Público completo, Clientes, Proveedores, Catálogo y página de módulos en desarrollo. Nuevos componentes `PageHeader` y `FormDialog`; `DataTable` con filas clicables; fichas laterales de cliente (con contactos) y proveedor (categorías y cobertura); selección múltiple con chips; avisos al guardar.
- Eliminados: `components/Modal.tsx`, `components/Icon.tsx`, `components/iconPaths.ts`; `tiare.css` reducido a utilidades y línea de cierres.
- `npx tsc -b` OK · `npm run lint` 0 errores (1 advertencia previa en `AuthContext.tsx`) · `npx vitest run` **15 OK** (incluye prueba de renderizado de Clientes, Proveedores y Catálogo con datos simulados) · `npm run build` OK.
- Navegador: acceso verificado a 1366×860; vistas con sesión iniciada pendientes de revisión visual.

## 9. Fase 2b — Empresa, usuarios, edición y proveedores (17-11-2026)

### Backend
- **Migración** `2026_11_17_200000_create_supplier_evaluations_and_documents_tables` (aplicada en MariaDB): `supplier_evaluations` (inmutable, cinco criterios 1–5 y promedio) y `supplier_documents` (tipo, vigencia, archivo privado, SHA-256, archivado reversible).
- **Permisos nuevos** `company.manage` y `users.manage` (asignados a `tenant_admin`; seeder re-ejecutado).
- **API**: `GET|PATCH /company`; `GET /company/users`, `GET /company/roles`, `POST /company/users` (invitar), `PATCH /company/users/{id}` (rol y acceso); `POST /auth/forgot-password`, `POST /auth/reset-password`; `GET|POST /suppliers/{id}/evaluations`; `GET|POST /suppliers/{id}/documents`, `GET …/documents/{doc}/download`, `PATCH …/documents/{doc}` (archivar). El listado de proveedores incluye promedio de evaluación y documentos vencidos.
- `MembershipService` (invitaciones, cambios de rol con resguardos), `PasswordResetController`, correo en español con enlace al fragmento `/restablecer#…`.

### Frontend
- **Edición**: clientes (datos, plan y estado comercial), proveedores (datos, categorías, cobertura, plazo, condiciones de pago, estado de habilitación y notas) y productos (incluye activar/desactivar). Los campos vaciados se envían como `null` para borrarlos.
- **Ficha de proveedor** con pestañas Resumen, Evaluación (estrellas por criterio, resumen, historial, incidentes) y Documentos (arrastrar y soltar, vigencia: vigente, por vencer ≤30 días, vencido, sin vencimiento; descarga y archivado). La tabla muestra estrellas y aviso de documentos vencidos.
- **Configuración** (antes "en desarrollo"): datos de la empresa con validación de RUT; personas con rol editable, interruptor de acceso, invitación por correo.
- **Acceso**: "¿Olvidaste tu contraseña?" y página `/restablecer` para invitaciones y recuperación.

### Pruebas
| Comando | Resultado |
|---|---|
| `cd backend && php artisan test` | **153 pruebas, 622 aserciones OK** (19 nuevas: empresa, usuarios, invitación, restablecimiento, evaluaciones, documentos, aislamiento) |
| `php artisan migrate` (MariaDB) | OK |
| `npx tsc -b` / `npm run lint` / `npm run build` | OK / 0 errores (1 advertencia previa) / OK |
| `npx vitest run` | **17 OK** (nuevas: Configuración, pestañas de proveedor) |
| Navegador | Acceso, diálogo de recuperación y `/restablecer` sin errores de consola. Vistas con sesión iniciada no revisadas en el panel |

### Pendiente de la fase
- Antimalware de adjuntos y proveedor de correo real (ver `SECURITY.md`).
- Gestión de contactos adicionales (hoy: contacto principal al crear).
- Criterios para considerar "habilitado" a un proveedor según documentos exigibles (PEND-T08).

## 10. Fase 3 — compras privadas (18-11-2026)

**Backend terminado y probado** (`php artisan test`: **160 OK, 727 aserciones**; migración `2026_11_18_100000_create_private_procurement_tables` aplicada y reversible en MariaDB; permisos re-sembrados):
- Flujo DRAFT → SUBMITTED → QUOTING → PENDING_APPROVAL → APPROVED → ORDERED → CLOSED (+ CANCELLED; rechazo vuelve a QUOTING).
- Servicios en `app/Services/Procurement/`: solicitudes (versión por edición, correlativo SC-AAAA-NNNN por empresa), cotizaciones (totales con bcmath, IVA incluido o no, moneda extranjera con tipo de cambio obligatorio), comparador explicable con instantánea inmutable (pesos, fórmula, supuestos; excluye vencidas/incompletas), aprobación (quien propone no aprueba; límite `approval_limit_clp` por persona; registro inmutable con versión y monto), orden interna OCI-AAAA-NNNN con pagador/titular de factura/comprador/receptor, recepciones parciales con incidencias.
- API: `/purchase-requests` (+ submit, start-quoting, quotation-requests, quotations, discard, compare, propose, decision, cancel, order), `/purchase-orders` (+ sent, cancel, receipts), `/procurement/references`; `approval_limit_clp` en `/company/users`.
- Prueba: `tests/Feature/Procurement/PrivateProcurementFlowTest.php`.

**Frontend** (`features/procurement/`, módulos activos en el menú):
- `/solicitudes`: listado con filtros por estado y conteos; formulario con editor de ítems (catálogo o texto libre, estimado automático).
- `/solicitudes/:id`: pasos del flujo, acción siguiente según permisos (`actions` del backend), pestañas Resumen, Cotizaciones (pedidos a proveedores, registro con precio por ítem, IVA, moneda y tipo de cambio), Comparación (pesos ajustables, puntaje por criterio, recomendada, metodología y supuestos, propuesta con justificación) y Aprobación (aprobar/rechazar, historial inmutable). Emisión de orden con responsables sugeridos.
- `/cotizaciones`: solicitudes en cotización o esperando aprobación.
- `/ordenes`: órdenes internas, ficha lateral, marcar enviada, anular y registrar recepción (máximo lo pendiente, rechazos e incidencias).
- Configuración: columna "Límite de aprobación" editable.

**Pruebas:** backend 160 OK; frontend `vitest` **19 OK** (nuevas: aprobación en la ficha, recepción limitada a lo pendiente); `tsc`, lint (0 errores, 1 advertencia previa) y build OK. En el navegador los módulos cargan sin errores; las pantallas con sesión iniciada no se revisaron en el panel.

### Pasos 11–12: facturas e indicadores (18-11-2026)
- **Migración** `2026_11_18_200000_create_invoices_table` (reversible): facturas, facturas exentas y notas de crédito por orden; folio único por proveedor y tipo; pago informado (fecha, referencia, quién informa, usuario y hora).
- **Permiso nuevo** `invoices.manage` (tenant_admin, procurement_manager, finance_analyst). Lectura con `purchase_orders.read`; indicadores con `reports.read`.
- **API**: `POST /purchase-orders/{id}/invoices`, `POST /invoices/{id}/payment`, `GET /invoices` (por pagar, vencidas, pagadas), `GET /procurement/indicators?from&to` (máx. 2 años; por defecto 90 días). La ficha de orden incluye facturas y conciliación (facturado vs total, diferencia, pagado).
- **Indicadores** (`ProcurementIndicatorsService`), cada uno con fórmula, n y supuestos; null si no hay datos: gasto comprometido, ahorro vs precio de referencia (neto), ahorro por competencia (promedio de cotizaciones completas − adjudicada), mediana de días envío→aprobación, aprobación→orden, orden→recepción completa, % entregas a tiempo, % recepciones con incidencia, cotizaciones por compra; además solicitudes por estado y resumen de facturas por pagar/vencidas/con diferencia.
- **Frontend**: sección Facturas en la ficha de orden (registro con IVA sugerido, conciliación, informar pago); página **Analítica e informes** (`/informes`, activa en el menú) con selector de período, tarjetas con "Cómo se calcula", estados y cuentas por pagar.
- **Pruebas**: backend **163 OK (770 aserciones)**; frontend **20 OK**; `tsc`, lint (0 errores) y build OK.

**Pendiente:** envío de la orden por correo desde TIARE (hoy se marca como enviada manualmente); exportación de informes (`reports.export`).

## 11. Fase 5 — logística e inventario (17-11-2026)

**Backend** (`php artisan test`: **168 OK, 854 aserciones**):
- **Migración** `2026_11_19_100000_create_logistics_and_inventory_tables` (reversible): `warehouses`, `inventory_items` (stock por bodega y producto, mínimo/máximo, ubicación), `inventory_movements` (inmutable; entrada, salida, ajustes, traspasos, saldo resultante, costo unitario, lote, vencimiento, motivo, referencia), `carriers`, `shipments` (DSP-AAAA-NNNN), `shipment_events` (inmutable), `logistics_incidents`.
- **Permisos** nuevos `inventory.read` e `inventory.manage`. `tenant_admin` recibe `shipments.*` e `inventory.*`; `logistics_operator` recibe `inventory.*`; usuarios de compras y solo lectura ven inventario.
- **InventoryService**: movimientos con bloqueo de fila, sin stock negativo, ajustes con motivo obligatorio, traspasos entre bodegas, bodegas inactivas rechazadas, sugerencia de reposición (hasta el máximo cuando se baja del mínimo).
- **Recepción → stock**: `POST /purchase-orders/{id}/receipts` acepta `warehouse_public_id` (exige `inventory.manage`); lo recibido conforme de ítems con producto del catálogo entra a la bodega con costo unitario en CLP, en la misma transacción que la recepción.
- **ShipmentService**: estados PLANNED → IN_TRANSIT → DELIVERED, con FAILED, RETURNED y CANCELLED; la entrega solo por "confirmar entrega" (quién recibe, observaciones y comprobante PDF/JPG/PNG en disco privado); un despacho cerrado solo admite costo real y notas; incidencias abiertas y resueltas; enlace de seguimiento desde una plantilla `https://…{code}` del transportista.
- **API**: `/warehouses`, `/inventory/stock` (filtros, `low_only`, conteo de bajo mínimo), `/inventory/movements`, `/inventory/stock/{bodega}/{producto}` (niveles), `/carriers`, `/shipments` (+ status, events, incidents, resolve, deliver, evidence; conteos por estado y filtro de atrasados).
- Prueba: `tests/Feature/Logistics/InventoryAndLogisticsTest.php`.

**Frontend** (módulos activos en el menú):
- `/inventario`: pestañas Stock (aviso de bajo mínimo, sugerencia de reposición, niveles por bodega), Movimientos (historial inmutable) y Bodegas (crear, activar/desactivar). Diálogo de movimiento: entrada con costo/lote/vencimiento, salida, traspaso y ajustes con motivo obligatorio.
- `/logistica`: pestañas Despachos (conteos por estado, atrasados, incidencias abiertas; ficha con datos, cambio de estado, novedades, incidencias, confirmación de entrega con comprobante, costo real) y Transportistas (servicios, cobertura regional, enlace de seguimiento).
- Registrar recepción de una orden: selector "Ingresar a bodega" cuando la persona tiene `inventory.manage`.
- **Pruebas**: frontend **23 OK** (nuevas: aviso de stock bajo, ajuste con motivo, entrega desde la ficha); `tsc`, lint (0 errores, 1 advertencia previa) y build OK. En el navegador los módulos cargan sin errores; las pantallas con sesión no se revisaron en el panel.

**Pendiente:** reservas de stock, alertas de reposición por correo, valorización de inventario (costo promedio), rutas y seguimiento automático con APIs de transportistas.

## 12. Fase 6 — agentes IA (17-11-2026)

**Modelo:** Claude API (elección del propietario), mediante el SDK oficial `anthropic-ai/sdk` ^1.9 (nueva dependencia de Composer). Modelo por defecto `claude-opus-5-5`, esfuerzo `medium`, salida estructurada con JSON Schema estricto, reintento del lado del servidor si el modelo declina (`fallbacks: "default"`). Sin herramientas: el modelo no consulta la base de datos, internet ni Mercado Público.

**Backend** (`php artisan test`: **174 OK, 925 aserciones**):
- **Migración** `2026_11_20_100000_create_ai_agent_runs_table` (reversible): cada ejecución guarda agente, versión del prompt, caso, modo (`deterministic` | `llm`), estado (`COMPLETED` | `PARTIAL`), proveedor, modelo, tokens, latencia, hash y copia de la entrada, hechos calculados, análisis del modelo, error sin datos sensibles, usuario y revisión humana. El resultado es inmutable; solo se registra la revisión, una vez.
- **Permisos** `agents.read`, `agents.run`, `agents.review`. Además, cada agente exige los permisos del caso (Compra Ágil: `public_procurement.read`; cotizaciones: `purchase_requests.read` y `quotations.read`).
- **Capa IA** (`app/Services/Ai/`): contrato `LlmClient` (desacoplado del proveedor), `AnthropicLlmClient`, registro cerrado de agentes y `AgentRunner` (límite diario por empresa, auditoría, ejecución PARTIAL si el modelo falla).
- **Agentes**:
  - `tender_intelligence_agent` v1.0.0: sobre una Compra Ágil ya sincronizada (no consume cuota de Mercado Público). Hechos: abierta/cerrada, horas al cierre, productos y documentos, monto, alertas, checklist preliminar y coincidencias por palabras con el catálogo de la empresa. Modelo: resumen, requisitos observables con su fuente, riesgos, preguntas al organismo, pasos extra, calce con el catálogo y límites.
  - `cost_intelligence_agent` v1.0.0: sobre una solicitud con cotizaciones. Hechos: comparables, menor total, diferencia mayor/menor, menor vs estimado, estado de la última comparación, alertas (vencidas, incompletas, sin plazo). Modelo: notas por proveedor, riesgos, preguntas, puntos de negociación y datos faltantes. No elige ni aprueba.
- **API**: `GET /agents` (catálogo y estado del modelo), `GET /agent-runs`, `GET /agent-runs/{id}`, `POST /agents/{agente}/runs` (`subject_ref`, `use_model`; límite 6/min por usuario), `POST /agent-runs/{id}/review`.
- Prueba: `tests/Feature/Agents/AgentsTest.php` (modelo falso, sin llamadas a internet). Prueba de humo del cliente real contra un puerto local cerrado: arma la solicitud y traduce el error de conexión.

**Frontend**:
- `/agentes` (activo en el menú): estado del modelo y cupo diario, qué usa y qué no hace cada agente, historial con filtro por agente y revisión, ficha del análisis.
- Ficha de Compra Ágil y pestaña Comparación de una solicitud: bloque "Análisis con agente IA" con el último análisis del caso, botón para analizar (con o sin IA) y revisión "Marcar como útil" / "Descartar" con motivo. Los datos calculados se muestran separados del texto del modelo, etiquetado "Estimación IA".
- **Pruebas**: frontend **25 OK** (nuevas: página de agentes, análisis y revisión); `tsc`, lint (0 errores, 1 advertencia previa) y build OK. En el navegador los módulos cargan y `/api/v1/agents` exige sesión; las pantallas con sesión no se revisaron en el panel.

**Para activar el modelo** (solo en `backend/.env`, nunca en el chat): `AI_ENABLED=true` y `ANTHROPIC_API_KEY=...`; opcionales `AI_MODEL`, `AI_EFFORT`, `AI_DAILY_RUNS_PER_TENANT`. Sin esto, los agentes funcionan sin modelo. **No se probó una llamada real a la API de Claude** (no hay clave configurada en este entorno).

**Pendiente:** orquestador (`orchestrator_agent`) y agentes de proveedores, logística y contratos; ejecución en cola para análisis largos; evaluación de calidad con casos reales anonimizados; análisis de documentos adjuntos que el usuario suba.

## 13. Búsquedas guardadas, matching y alertas de Mercado Público (17-11-2026)

Se avanzó sin activar el modelo de IA (decisión del propietario). Todo trabaja sobre las Compras Ágiles **ya sincronizadas** en la base local: no consulta Mercado Público ni consume cuota.

**Backend** (`php artisan test`: **177 OK, 973 aserciones**):
- **Migración** `2026_11_21_100000_create_saved_searches_matches_and_alerts` (reversible, aplicada en MariaDB; probada la reversión): `saved_public_searches`, `public_opportunity_matches` (única por búsqueda y oportunidad, con motivo y estado NEW/SEEN/DISMISSED), `public_alerts` (única por empresa y clave anti-duplicado) y `public_alert_reads` (lectura por usuario).
- **Criterios**: palabras (todas deben aparecer en nombre, descripción, organismo o productos), regiones, rango de monto disponible en CLP, RUT del organismo, solo abiertas (si no, últimos 60 días) y "calza con mi catálogo". Se exige al menos un criterio; máximo 20 búsquedas activas por empresa (configurable).
- **Matching** (`OpportunityMatcher`): explica cada coincidencia (palabras, productos del catálogo, región, monto). El calce por palabras se movió a `App\Support\WordMatcher`, compartido con el agente de Compra Ágil.
- **Alertas**: comando `php artisan public-procurement:match`, programado cada 15 minutos (`MERCADO_PUBLICO_MATCHING_ENABLED`). Genera "N oportunidades nuevas" por búsqueda y "Cierra pronto" (menos de 24 h) para coincidencias vigentes, sin repetir. Al crear o ejecutar una búsqueda a mano no se generan alertas (la persona ya ve el resultado). Solo alertas dentro de TIARE; no se envían correos.
- **API** (permiso `public_procurement.read`, aislado por empresa): `GET/POST /public-procurement/saved-searches`, `PATCH /saved-searches/{id}`, `POST /saved-searches/{id}/run`, `GET /matches`, `PATCH /matches/{id}`, `GET /alerts`, `POST /alerts/read`.
- Prueba: `tests/Feature/MercadoPublico/SavedSearchesTest.php` (bloquea cualquier llamada HTTP externa).

**Frontend**:
- Mercado Público, pestaña **Mis búsquedas** (`/mercado-publico/busquedas`): tarjetas por búsqueda (criterios, nuevas, alertas on/off, buscar ahora, editar), coincidencias con motivo, cierre y monto; "Ver ficha" abre la ficha oficial (con el análisis del agente) y la marca como vista; descartar y restaurar.
- **Campana de alertas** en el encabezado: contador sin leer (se actualiza cada 2 min), lista, marcar todas como leídas; abre la búsqueda o la ficha que cierra pronto.
- **Pruebas**: frontend **28 OK**; `tsc`, lint (0 errores, 1 advertencia previa) y build OK. En el navegador los módulos cargan y las rutas nuevas exigen sesión; las pantallas con sesión no se revisaron en el panel.

**Requisito para alertas automáticas:** ejecutar el programador (`php artisan schedule:work` o cron). Sin sincronización activa (`MERCADO_PUBLICO_SYNC_ENABLED`), solo se comparan las Compras Ágiles que ya estén en la base.

**Pendiente:** alertas por correo, matching de licitaciones V1 (después de validar V1, PEND-T10), cambios de estado de oportunidades seguidas.

## 14. Validación real de la API V1 (17-11-2026)

- **PEND-T10 cerrada**: todas las rutas V1 responden con el ticket del propietario; normalización sin fallas de 1.921 licitaciones y 20.818 órdenes de compra reales. Detalle en MERCADO_PUBLICO_API.md §3.6.
- **Correcciones**: estados de licitación 15/16 (Revocada/Suspendida) agregados; 429 por "peticiones simultáneas" ahora se reintenta tras una pausa y ya no bloquea V1 por cuota; 500 "parámetros no válidos" se informa como error de filtro sin reintentos; búsqueda por RUT exige resultado único; filtros de OC con grafía inválida retirados.
- **Pruebas**: backend **185 OK (1004 aserciones)**, con fixtures anonimizados de estructura real (`tests/Fixtures/mercado_publico/real_*.json`). Consulta real de punta a punta con el adaptador de TIARE: OK.
- Se usaron ~20 solicitudes reales del ticket V1 (fuera del contador de cuota de TIARE, por ser un script de diagnóstico).

## 15. Alertas de licitaciones (17-11-2026)

- **Búsquedas por tipo de proceso**: cada búsqueda guardada elige Compra Ágil, Licitaciones o ambas (`criteria.source_types`). Las búsquedas anteriores siguen siendo solo de Compra Ágil.
- **Seguimiento de licitaciones** (`TenderWatchService`, comando `php artisan public-procurement:watch-tenders`, programado cada 3 horas):
  1. una consulta `licitaciones.json?estado=activas` (código, nombre, estado y cierre de todas las abiertas);
  2. detalle (organismo, región, monto, productos) solo de las candidatas: primero las que calzan por nombre con las palabras o el catálogo de alguna búsqueda, luego (si hay búsquedas solo por región, monto u organismo) las más recientes. Tope de 40 detalles por ejecución (`MERCADO_PUBLICO_TENDER_DETAILS_PER_RUN`). Una licitación con detalle no se vuelve a pedir.
  3. No consulta nada si ninguna búsqueda activa incluye licitaciones. Se detiene si la cuota se agota o el ticket es rechazado.
- **Matching y alertas**: el mismo comando de cada 15 minutos ahora considera licitaciones abiertas (estado 5) y genera alertas de coincidencias nuevas y de cierre en menos de 24 horas. Las alertas de cierre llevan el tipo de proceso para abrir la ficha correcta.
- **Frontend**: selector de tipo en el diálogo de búsqueda (al menos uno), etiqueta "Licitación"/"Compra Ágil" en cada coincidencia, ficha de licitación al hacer clic, aviso cuando aún falta el detalle; la campana abre la ficha de licitación o de Compra Ágil según corresponda.
- **Prueba real**: `estado=activas` devolvió 4.866 licitaciones en 13,6 s (1 solicitud); 2 detalles con región, organismo y productos. Uso V1 del día: 10 de 9.000.
- **Pruebas**: backend **189 OK (1039 aserciones)**, nuevas en `tests/Feature/MercadoPublico/TenderAlertsTest.php` (incluida la verificación de que no se gasta cuota sin búsquedas de licitaciones y de que solo se pide el detalle de candidatas); frontend **29 OK**; `tsc`, lint y build OK.
- **Costo de cuota estimado**: con el programador activo, como máximo 8 ejecuciones al día × (1 + 40) ≈ 330 solicitudes V1 diarias, muy por debajo del presupuesto de 9.000.

## 16. Perfiles, planes y tokens API (17-11-2026, TD-024)

- **Login**: imagen `imagenes/tiare.jpg` (copiada a `frontend/src/assets/`) en el panel izquierdo, sobre los códigos de ejemplo.
- **Migración** `2026_11_22_100000_create_plans_subscriptions_and_token_tenant` (reversible; `--pretend` de rollback revisado): `plans`, `tenant_subscriptions`, `personal_access_tokens.tenant_id`. **Ejecutada en MariaDB** + `PlansSeeder` + permisos nuevos (`platform.tenants.manage`, `api_tokens.manage`). La empresa existente quedó con plan SaaS.
- **Backend**: permisos efectivos = rol ∩ plan (`PlanModules`); límite de personas por plan en invitar/reactivar; `GET /company` y `auth/me` informan el plan; `/api/v1/admin/{plans,tenants,…}`; `/api/v1/auth/tokens` (crear, listar, revocar); el tenant de una request con token sale del token.
- **Frontend**: menú filtrado por permisos efectivos (rol + plan); chip del plan en la barra lateral; Configuración → "Plan contratado" y "Tokens de acceso API" (valor visible una sola vez); página **Administración TIARE** (`/admin`): listado, alta de empresa con plan y administrador, ficha con cambio de plan, suspensión, personas e historial.
- **Pruebas**: backend **200 OK (1101 aserciones)**, 11 nuevas en `tests/Feature/Auth/PlansAdminAndTokensTest.php`; frontend **34 OK** (5 nuevas en `features/admin/AdminAndPlan.test.tsx`); `tsc`/build y lint OK (1 advertencia previa).
- **Verificación real** (servidor local + MariaDB, con usuarios QA temporales ya borrados): token Bearer → 200 en lo permitido, 403 fuera de sus permisos/plan y en `/auth/tokens` y `/admin`, 401 sin token o con token falso; admin TIARE creó una empresa desde la UI; usuario de empresa con plan Public vio solo sus módulos, su plan y su token.
- **Pendiente**: definir el catálogo real de planes (PEND-T12); `php artisan serve` atiende una petición a la vez (crear empresa tardó ~10 s con otras peticiones en curso).

## 17. Documentos MP, eliminar clientes/proveedores y mantenedor de cuentas y roles (17-11-2026, TD-025)

- **Documentación adjunta** en las fichas de Compra Ágil y licitación: adjuntos oficiales (la API solo da el nombre; confirmado en la guía V2 v3.0 y en respuestas reales), botón al portal, copiar código, y **copias subidas por la empresa** con descarga desde TIARE. API `GET|POST /public-procurement/files`, `GET files/{id}/download`, `DELETE files/{id}`.
- **Eliminar** clientes y proveedores desde su ficha, con confirmación; bloqueado (409) si hay historial de compras.
- **Administración TIARE** con pestañas Empresas / **Cuentas** / **Roles y permisos**. API `/api/v1/admin/{account-references,users,users/{id}/memberships/{tenant},users/{id}/password-link,roles,roles/{key}/reset}`.
- **Migración** `2026_11_23_100000_create_public_opportunity_files_and_role_customization` (reversible) **ejecutada en MariaDB**; permisos nuevos resembrados (`public_procurement.documents`, `customers.delete`, `suppliers.delete`, `platform.users.manage`, `platform.roles.manage`).
- **Pruebas**: backend **214 OK (1191 aserciones)**, nuevas en `DeleteAndDocumentsTest` (6) y `AccountsAndRolesAdminTest` (8); frontend **39 OK** (5 nuevas); `tsc`/build y lint OK (1 advertencia previa).
- **Verificación real** (QA temporal, ya borrado): subida de copia por API con token → 201, descarga 200 `application/pdf`, sin auth 401, `.exe` 422; en la UI la ficha mostró el adjunto oficial "Copia en TIARE"; proveedor eliminado desde la ficha; cuenta creada desde el mantenedor; roles con datos reales.
- **Observación**: con `php artisan serve` (una petición a la vez en Windows) un alta desde la UI tardó ~25 s detrás de otras consultas; el servicio en sí tarda 0,5 s. Para desarrollo fluido conviene un servidor con varios workers (p. ej. Laragon/Apache) — pendiente de decisión.

## 18. Contraseña de inicio, Mi cuenta y oferta de proveedores (17-11-2026, TD-026)

- **Contraseña de inicio**: en Administración → Cuentas, al crear ("Generar contraseña de inicio", opción por defecto) o desde la ficha de la cuenta. Se muestra una vez; la cuenta debe cambiarla al ingresar (chip "Contraseña de inicio" en el listado). API `POST /admin/users` con `credential=password` y `POST /admin/users/{id}/initial-password`.
- **Mi cuenta** (menú de usuario → "Mi cuenta y contraseña"): datos de la cuenta y cambio de contraseña con requisitos visibles. API `PUT /auth/password`.
- **Proveedores → pestaña "Oferta y capacidad"**: insumos y servicios con capacidad por período, pedido mínimo, plazo y confirmación; contador en el listado y búsqueda por insumo/servicio. API `GET|POST /suppliers/{id}/offerings`, `PATCH|DELETE /suppliers/{id}/offerings/{offering}`.
- **Migración** `2026_11_24_100000_add_password_lifecycle_and_supplier_offerings` (reversible) **ejecutada en MariaDB**.
- **Pruebas**: backend **222 OK (1405 aserciones)** (14 nuevas); frontend **42 OK** (3 nuevas); `tsc`/build y lint OK (1 advertencia previa).
- **Verificación real** (QA temporal, ya borrado): cuenta creada con contraseña de inicio desde la UI → ingreso con ella → redirigido a Mi cuenta y bloqueado en Proveedores → cambio de contraseña → acceso normal; insumo con capacidad agregado y encontrado por la búsqueda "nitrilo".
- **Corrección durante la prueba**: la regla "capacidad requiere período" no se aplicaba si el período faltaba (`sometimes` omitía la validación); ahora se valida explícitamente, también en ediciones parciales.

## 19. Contraseña de inicio desde la empresa (17-11-2026, TD-027)

- **Configuración → Personas**: "Invitar persona" ofrece "Generar contraseña de inicio" (por defecto) o enlace por correo; ícono de llave por persona para regenerarla (deshabilitado con el motivo cuando no corresponde); chip "Contraseña de inicio" mientras esté pendiente de cambio.
- API: `POST /company/users` con `credential=password`; `POST /company/users/{id}/initial-password`. Servicio común `InitialPasswordService`.
- **Pruebas**: backend **225 OK (1428 aserciones)**, 3 nuevas en `CompanyInitialPasswordTest`; frontend **42 OK**; build y lint OK.
- **Verificación real** (QA temporal, ya borrado): un administrador de empresa agregó a una persona con contraseña de inicio (diálogo de una sola vez); la llave quedó habilitada para la persona y deshabilitada para sí mismo.

## 20. Superadministrador y portal de clientes y proveedores (18-11-2026, TD-028)

- **Comando** `php artisan tiare:superadmin correo@dominio --name="Nombre"`: crea el superadministrador y muestra su contraseña de inicio solo en la terminal. **Hecho (18-11-2026)**: la cuenta de Tiare Vidal Sepúlveda ya existía (creada por el propietario desde el mantenedor, con contraseña propia) y se promovió a `platform_super_admin` con el comando, sin cambiar su contraseña.
- **Portal** (`/portal`, menú "Mi perfil"): vista propia de cliente o proveedor, solo lectura. API `GET /portal/profile`.
- **Alta** de cliente/proveedor con "Crear usuario y contraseña de acceso al portal" (activado por defecto) → diálogo con la contraseña de inicio.
- **Fichas**: sección "Acceso al portal del cliente" y pestaña "Acceso al portal" del proveedor (crear, regenerar, activar/desactivar, revocar).
- **Migración** `2026_11_25_100000_create_portal_accesses_table` **ejecutada en MariaDB**; roles `customer_portal` y `supplier_portal` sembrados.
- **Pruebas**: backend **233 OK (1502 aserciones)**, 8 nuevas; frontend **46 OK** (4 nuevas); build y lint OK. `testTimeout` de Vitest subido a 15 s (las páginas MUI superaban 5 s en paralelo).
- **Verificación real** (HTTP con sesión de cookies, QA temporal ya borrado): empresa crea proveedor con portal → 201 y contraseña de inicio; el portal entra, queda bloqueado (403) hasta cambiarla, luego ve su perfil con su insumo y capacidad, sin evaluaciones, y recibe 403 en proveedores, clientes, solicitudes, personas y evaluaciones. La UI no se pudo revisar en el navegador integrado: con el panel oculto, Vite (~500 módulos sin empaquetar) agota recursos (`ERR_INSUFFICIENT_RESOURCES`).

## 21. Optimización de cuota de Mercado Público (18-11-2026, TD-029)

- Sincronización por **publicaciones nuevas** con **reanudación**, **reserva de 70 solicitudes** para personas, **espera de 10 min** en el botón manual y **caché de fichas** según estado. Comando: `php artisan tiare:mp:sync-compra-agil --new [--max-pages=N]`.
- Franja de estado: modo, "publicaciones conocidas hasta", solicitudes de la última ejecución y "automático hasta X · Y reservadas para ti"; una ejecución parcial se muestra como información, no como error.
- **Pruebas**: backend **239 OK (1533 aserciones)**, 6 nuevas en `QuotaOptimizationTest`; frontend **46 OK**; build y lint OK.
- **Prueba real**: 1 solicitud para confirmar el orden por publicación; 2 ejecuciones de 5 páginas: 303 Compras Ágiles nuevas, la segunda retomó en la página 5. Consumo del día tras las pruebas: 35/250.
- **Activación (18-11-2026)**: el propietario dejó en `.env` la sincronización activada y la caché en 60 min (intervalo aún en 60 min; pidió 30 → falta `MERCADO_PUBLICO_SYNC_INTERVAL_MINUTES=30`). El `queue:work` existente tenía cargado el código anterior: se reinició (`queue:restart`) y se lanzó uno nuevo. El `schedule:work` existente no disparó la ejecución de las 16:00; se lanzó otro en paralelo (no duplica trabajo: lock + job único). Ejecución programada de prueba (`schedule:test`): modo publicaciones, retomó en la página 9, 10 solicitudes, 112 nuevas.

## 22. Anexos de Mercado Público: subida múltiple y "Descargar todo" (18-11-2026, TD-030)

- No se descarga desde el portal (condiciones de ChileCompra: solo mecanismos de la API; bots → bloqueo indefinido). En su lugar: arrastrar y soltar varios anexos a la vez, asociación automática con cada adjunto oficial, sin duplicados, aviso de adjuntos faltantes y "Descargar todo" en ZIP.
- API: `POST /public-procurement/files` ahora asocia solo y responde `matched` y `duplicate`; nuevo `GET /public-procurement/files-archive`.
- **Pruebas**: backend **243 OK (1560 aserciones)**, 4 nuevas en `OpportunityFilesBulkTest`; frontend **46 OK**; build y lint OK.
- **Prueba real** con la Compra Ágil 3100-41-COT26 (QA temporal, ya borrado): asociación automática (puntaje 92), duplicado detectado, ZIP con 2 archivos.

## 23. Eliminar personas, roles, órdenes y cotizaciones (18-11-2026, TD-031)

- **Administración TIARE**: "Eliminar cuenta" en la ficha de cada cuenta; ícono de eliminar en cada rol no protegido, con traslado obligatorio de sus cuentas a otro rol si está en uso.
- **Configuración**: botón "Quitar de la empresa" por persona (si no le queda otro acceso, su cuenta se elimina).
- **Órdenes**: "Eliminar orden" en el detalle, solo sin recepciones ni facturas. **Cotizaciones**: "Eliminar" en cada cotización mientras la solicitud está en cotización.
- Historial intacto: borrado lógico de cuentas y roles del sistema; nombre conservado en aprobaciones, órdenes y auditoría; cada eliminación queda auditada.
- **Migración ejecutada**: `2026_11_26_100000_add_soft_deletes_to_users_and_roles` (reversible). Seeder de permisos ejecutado (`purchase_orders.delete`, `quotations.delete`).
- **Pruebas**: backend **257 OK (1671 aserciones)**, 14 nuevas; frontend **48 OK**; build OK; lint sin errores (1 advertencia previa).
- **Pendiente de verificar en la app con una cuenta real**: no se probó haciendo clic en el navegador (no se usan credenciales del propietario).

## 24. Limpieza de datos locales para pruebas desde cero (19-11-2026)

- A pedido del propietario se eliminaron en la base local todas las empresas, cuentas (salvo el superadministrador), clientes, proveedores, catálogo, solicitudes, cotizaciones, órdenes, recepciones, búsquedas guardadas, alertas y análisis IA. Se conservaron los datos oficiales de Mercado Público, planes, roles, permisos, unidades y la auditoría (con la acción `platform.data_reset`).
- Respaldo previo completo: `backend/storage/app/backups/tiare-antes-de-limpieza-20261119-193430.sql` (no versionado).

## 25. Siguiente hito
1. Fase 7 — producción (hardening, monitoreo, respaldos, staging).
2. Alertas por correo (cuando se defina el proveedor de envío).
3. Activar la clave de IA y probar los agentes con casos reales (cuando el propietario lo decida).
