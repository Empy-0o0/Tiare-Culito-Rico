# Integración Mercado Público (ChileCompra)

**Fuente de verdad:** https://www.chilecompra.cl/api/ (página "Políticas y Condiciones de Uso", última actualización 05-11-2026, revisada el 14-11-2026).
**Guía V2 revisada:** "Guía de uso API Compra Ágil v2", versión 3.0, mayo 2026 (`Documentacion_API_Compra_Agil-3-1.pdf`). Las otras dos variantes publicadas (`-2-1` y sin sufijo) son la versión 2.0 con el mismo contenido técnico.

> Toda la comunicación con Mercado Público ocurre en el backend Laravel. React solo llama a `/api/v1/public-procurement/*`.

---

## 1. Condiciones de uso relevantes (página oficial)

- Cada ticket tiene un **límite diario de 10.000 solicitudes**, no modificable. No exceder ni eludir (sin tickets/IPs múltiples). El abuso puede suspender o bloquear el acceso.
- Procesos de alta demanda o descarga masiva: preferir **22:00–07:00**.
- Quien publique información obtenida desde la API sin modificarla debe indicar claramente que **la fuente es la Dirección ChileCompra**.
- El ticket se solicita con Clave Única de una persona; es personal. No compartir ni publicar.

## 2. API Compra Ágil V2 (implementada en Fase 1)

### 2.1 Base y autenticación
- Base: `https://api2.mercadopublico.cl`
- Header obligatorio: `ticket: <MERCADO_PUBLICO_TICKET>`. **No** se envía en query string.

### 2.2 Endpoints
| Método | Ruta | Uso |
|---|---|---|
| GET | `/v2/compra-agil` | Listado/búsqueda paginada |
| GET | `/v2/compra-agil/{codigo}` | Detalle (productos, proveedores cotizando, presupuesto, OC) |

### 2.3 Parámetros del listado
| Grupo | Parámetro | Regla |
|---|---|---|
| Ventana de cambios | `ttl_cambio_ms` (int ms) **o** `cambio_desde` + `cambio_hasta` (ISO-8601) | No combinar A y B |
| Publicación | `publicado_desde`, `publicado_hasta` | ISO-8601 |
| Estado | `estado` | Coma: `publicada,cerrada,desierta,cancelada,proveedor_seleccionado` (`oc_emitida` existe en el modelo pero no se usa en la práctica) |
| Región | `region` | Enteros 1–16, separados por coma (`region=13,5`) |
| Búsqueda | `id` **o** `q` | Mutuamente excluyentes |
| Paginación | `tamano_pagina`, `numero_pagina` | Guía: default 15, máx 50. **Real: mínimo 10** (ver §2.8). Página desde 1 |
| Orden | `ordenar_por` | `FechaUltimaModificacion` (default, desc) o `FechaPublicacion` (desc) |

**Sin filtro por organismo:** no existe `codigo_organismo`. Filtrar por `region` y luego localmente por `institucion.rut` / `institucion.organismo_comprador`.

### 2.4 Regiones
1 Tarapacá · 2 Antofagasta · 3 Atacama · 4 Coquimbo · 5 Valparaíso · 6 O'Higgins · 7 Maule · 8 Biobío · 9 La Araucanía · 10 Los Lagos · 11 Aysén · 12 Magallanes · 13 Metropolitana · 14 Los Ríos · 15 Arica y Parinacota · 16 Ñuble.

### 2.5 Respuesta
Éxito: `{"success":"OK","trace":null,"payload":{...},"errors":null}`.
Listado: `payload.items[]` + `payload.paginacion{total_paginas, numero_pagina, tamano_pagina, total_resultados}`.
Error: `{"success":"NOK","payload":null,"errors":[{"codigo","mensaje","detalle"}]}`.

Mapeo a `public_procurement_opportunities` (normalizador `CompraAgilNormalizer`):

| Campo API | Columna TIARE |
|---|---|
| `codigo` | `external_code` (unique con `source_system`) |
| `nombre`, `descripcion` | `title`, `description` |
| `estado.codigo` / `estado.glosa` / `estado.id_estado` | `status_code` / `status_label` / `status_source_id` |
| `convocatoria.estado_convocatoria` | `call_number` |
| `fechas.fecha_publicacion` / `fecha_cierre` / `fecha_ultimo_cambio` / `fecha_cancelacion` | `published_at` / `closes_at` / `source_updated_at` / `cancelled_at` (UTC) |
| `institucion.organismo_comprador`, `rut`, `unidad_compra`, `region`, `nombre_region` | `buyer_name`, `buyer_rut`, `buyer_unit`, `region_code`, `region_name` |
| `montos.*` (listado) o `presupuesto.*` (detalle) | `currency`, `budget_type`, `estimated_budget`, `available_amount`, `available_amount_clp` |
| `resumen.total_ofertas_recibidas` | `offers_count` |
| `id_orden_compra` (raíz) u `orden_compra.id_orden_compra` | `purchase_order_source_id`; `has_purchase_order = (valor != null)` |
| `productos_solicitados[]` | `public_opportunity_items` |
| `documentos[]` | `public_opportunity_documents` (solo metadatos) |
| `proveedores_cotizando[]` | `raw payload` (se normaliza en fase posterior) |

**Orden de compra:** `id_orden_compra != null` es el único indicador confiable. `codigo_orden_compra` y `estado_orden_compra` pueden venir `null` aun con OC emitida; `oc_emitida` no se usa como condición.

### 2.6 Errores y política de reintento (implementada en `MercadoPublicoV2Client`)
| HTTP | Significado | Acción |
|---|---|---|
| 400 | Parámetros inválidos | Sin reintento; se devuelve `detalle` saneado |
| 401 / 403 | Falta ticket / ticket inválido, inactivo o bloqueado | Sin reintento; alerta de configuración |
| 404 | Compra Ágil no existe o no es pública | Sin reintento |
| 429 | Cuota diaria agotada | Sin reintento. Respetar `Retry-After` si viene; si no, bloquear hasta el próximo día calendario (America/Santiago) |
| 500 / 503 | Error temporal | Hasta `MERCADO_PUBLICO_MAX_RETRIES` reintentos con backoff exponencial + jitter |

### 2.7 Cuota
La guía V2 indica cuota diaria **según el tipo de ticket** (`-1` = ilimitada), con reinicio al cambiar el día calendario. Se cuenta cada solicitud en `mercado_publico_api_usage` y, al alcanzar `MERCADO_PUBLICO_V2_DAILY_BUDGET`, el conector deja de llamar a la API.

**Cuota real observada (14-11-2026):** la API respondió **429** tras ≈271 solicitudes de TIARE más ≈4 sondeos manuales, es decir, unas **~275 solicitudes diarias** para este ticket. No hubo header `Retry-After`, así que el bloqueo duró hasta las 00:00 de Chile. En consecuencia:
- Presupuesto local por defecto: **250/día**, para dejar margen a los detalles bajo demanda.
- Sincronización automática: cada **60 min** con un máximo de **10 páginas** por ejecución.
- Una carga completa de todas las publicadas (~190 páginas) consume ~70 % de la cuota diaria. Debe partirse en varias noches con `--max-pages` y `--from-page`.
- Para más cuota, ChileCompra indica solicitar un ticket de mayor límite.

### 2.8 Validación real (14-11-2026, ≈8 solicitudes)
| Consulta | Resultado |
|---|---|
| `estado=publicada&region=13&tamano_pagina=2` | **400** `tamano_pagina debe estar entre 10 y 50` |
| `estado=publicada&region=13&tamano_pagina=10` | 200 · 2.152 resultados · 216 páginas · ~2,1 s |
| Detalle del primer resultado | 200 · `id_orden_compra` en raíz · `proveedores_cotizando: []` |
| Código inexistente | 404 |
| Ticket falso / sin ticket | 401 (ambos) |
| Headers de cuota | No se observaron `Retry-After` ni `X-RateLimit-*` en respuestas exitosas |

## 3. API legacy V1 (implementada 17-11-2026; **sin validar con respuesta real**, ver §3.6)

- Base: `https://api.mercadopublico.cl/servicios/v1/publico`. Formatos `.json`, `.jsonp`, `.xml`: TIARE usa JSON.
- Autenticación: query `ticket=` (según ejemplos oficiales). El ticket viaja en la URL: `MercadoPublicoV1Client` nunca registra la URL, descarta los mensajes de excepción del cliente HTTP y redacta el texto externo. Revisar también proxy y APM antes de producción.

### 3.1 Recursos y filtros

| Recurso | Ruta (config `mercado_publico.v1.paths`) | Filtros documentados |
|---|---|---|
| Licitaciones | `/licitaciones.json` | `codigo`; sin filtros (día actual); `fecha` (`ddmmaaaa`); `estado`; `fecha`+`estado`; `fecha`+`CodigoProveedor`; `fecha`+`CodigoOrganismo` |
| Órdenes de compra | `/ordenesdecompra.json` | Mismas combinaciones |
| Proveedor por RUT | `/Empresas/BuscarProveedor` | `rutempresaproveedor` con puntos, guion y DV (ej. `70.017.820-k`) |
| Organismos compradores | `/Empresas/BuscarComprador` | Ninguno (lista completa) |

Valores de `estado` en licitaciones (textuales en chilecompra.cl/api): `activas` (publicadas a la fecha, se consulta sola), `publicada`, `cerrada`, `desierta`, `adjudicada`, `revocada`, `suspendida`, `todos`.
Valores de `estado` en OC (resumen de la página, **sin verificar textualmente**, PEND-T11): `todos`, `enviadaproveedor`, `aceptada`, `cancelada`, `recepcionconforme`, `pendienterecepcion`, `recepcionaceptadacialmente`, `recepecionconformeincompleta` (las erratas se conservan tal como se publican).

Códigos de estado: licitación 5 Publicada, 6 Cerrada, 7 Desierta, 8 Adjudicada, 18 Revocada, 19 Suspendida. OC 4 Enviada a proveedor, 5 En proceso, 6 Aceptada, 9 Cancelada, 12 Recepción conforme, 13 Pendiente de recepcionar, 14 Recepcionada parcialmente, 15 Recepción conforme incompleta.
Tipos de licitación (diccionario §3.1): L1, LE, LP, LQ, LR, LS (públicas), E2, CO, B2, H2, I2 (privadas). Tipos de OC (diccionario §3.1): 8 SE (sin emisión automática), 9 CM (convenio marco).

### 3.2 Respuesta

`{Cantidad, FechaCreacion, Version, Listado[]}`. Por código el elemento es detallado; por fecha/estado solo trae `CodigoExterno`/`Codigo`, `Nombre`, `CodigoEstado` y `FechaCierre`. Fechas sin zona (hora de Chile). Fechas centinela `0001-01-01` se descartan.

Mapeo de licitación (`LicitacionNormalizer` → `public_procurement_opportunities`, `source_type = licitacion`):

| Campo V1 | Columna TIARE |
|---|---|
| `CodigoExterno`, `Nombre`, `Descripcion` | `external_code`, `title`, `description` |
| `CodigoEstado` / `Estado` | `status_code` (texto del número) / `status_label` |
| `Tipo` | `procedure_type` |
| `Fechas.FechaPublicacion`, `Fechas.FechaCierre` (o `FechaCierre`), `Fechas.FechaAdjudicacion` | `published_at`, `closes_at`, `awarded_at` (UTC) |
| `Comprador.CodigoOrganismo`, `NombreOrganismo`, `RutUnidad`, `NombreUnidad`, `RegionUnidad` | `buyer_code`, `buyer_name`, `buyer_rut`, `buyer_unit`, `region_name` (+ `region_code` deducido del nombre) |
| `Moneda`, `MontoEstimado`, `Estimacion` | `currency`, `estimated_budget`, `budget_type`; `available_amount_clp` solo si `Moneda = CLP` |
| `DireccionEntrega` | `delivery_address` |
| Otros (convocatoria, etapas, toma de razón, modalidad de pago, duración, fechas de foro y aperturas, `Adjudicacion.*`) | `details` (JSON) |
| `Items.Listado[]` (`Correlativo`, `CodigoProducto`, `Categoria`, `NombreProducto`, `Descripcion`, `UnidadMedida`, `Cantidad`, `Adjudicacion.*`) | `public_opportunity_items` (incluye proveedor adjudicado y monto unitario por línea) |

Mapeo de OC (`OrdenCompraNormalizer` → `public_purchase_orders`): `Codigo`, `Nombre`, `Descripcion`, `CodigoEstado`/`Estado`, `EstadoProveedor`, `CodigoLicitacion`, `CodigoTipo`/`Tipo`, `TipoMoneda`, `TotalNeto`, `Impuestos`, `PorcentajeIva`, `Total`, `Fechas.*` (creación, envío, aceptación, cancelación, última modificación), `Comprador.*` y `Proveedor.*` (código, nombre, RUT, región), `TipoDespacho` y `FormaPago` (con glosa del diccionario) e `Items.Listado[]` (JSON).

**No se almacenan** datos personales de funcionarios ni contactos: `RutUsuario`, `CodigoUsuario`, `NombreUsuario`, `CargoUsuario`, `NombreContacto`, `CargoContacto`, `FonoContacto`, `MailContacto`, responsables de pago y de contrato. Se quitan también del payload crudo.

BuscarProveedor/BuscarComprador: la página describe "Código Empresa" y "Nombre Empresa" sin nombrar las claves JSON. El adaptador acepta `listaEmpresas`/`ListaEmpresas`/`Listado` con `CodigoEmpresa`/`NombreEmpresa` o `Codigo`/`Nombre` (PEND-T10).

### 3.3 Errores

| Respuesta | Interpretación |
|---|---|
| JSON `{Codigo, Mensaje}` sin `Listado` (aun con HTTP 200) | Según el mensaje: ticket → `MP_TICKET_REJECTED`; "simultáneas" → reintento; cuota/límite → `MP_QUOTA_EXHAUSTED`; "no existe" → `MP_NOT_FOUND`; otro → `MP_INVALID_PARAMETERS` |
| HTTP 400 con HTML ("Runtime Error") | `MP_INVALID_PARAMETERS` con sugerencia de revisar filtros y habilitación del ticket |
| 401/403 | `MP_TICKET_REJECTED` |
| 429 | `MP_QUOTA_EXHAUSTED`; bloqueo local hasta las 00:00 de Chile, sin reintento |
| 5xx / error de conexión | Reintento limitado con backoff y jitter |
| `Listado` vacío al consultar por código | `MP_NOT_FOUND` |

### 3.4 Uso de cuota

- Un listado V1 es **una** solicitud. Los códigos resultantes se guardan 30 min (`MERCADO_PUBLICO_V1_LIST_CACHE_MINUTES`); paginar o filtrar no vuelve a llamar.
- Cada ficha nueva o vencida (más de `MERCADO_PUBLICO_CACHE_MINUTES`) es una solicitud.
- El directorio de organismos se descarga la primera vez y luego como máximo una vez cada 24 h; el proveedor por RUT se guarda 24 h.
- Las solicitudes V1 se hacen de a una (lock de caché) para evitar el rechazo por consultas simultáneas.
- Límite local diario `MERCADO_PUBLICO_V1_DAILY_BUDGET` (9.000 por defecto, bajo el máximo oficial de 10.000).

### 3.5 API interna

| Método | Ruta `/api/v1/public-procurement/…` | Uso |
|---|---|---|
| GET | `tenders` | Licitaciones guardadas. Con `date`, `source_status`, `buyer_code` o `supplier_code` consulta Mercado Público y restringe a ese resultado. Filtros locales: `q`, `status`, `type`, `region`, `sort` (`closes_at`, `published_at`, `estimated_budget`, `last_fetched_at`), `refresh` |
| GET | `tenders/{codigo}` | Ficha (`refresh=1` fuerza consulta) |
| GET | `purchase-orders` | Igual que `tenders`; `sort` (`issued_at`, `total`, `last_fetched_at`) |
| GET | `purchase-orders/{codigo}` | Ficha de OC |
| GET | `buyers` | Directorio de organismos (`q`, `refresh`) |
| GET | `suppliers/lookup?rut=` | Proveedor por RUT (valida DV y formatea) |

Todas requieren `public_procurement.read` y el límite `mp-v1` (40/min por usuario). Las respuestas incluyen `meta.source` (atribución), `meta.search` (consulta, hora, si vino de caché) y `meta.quota`.

### 3.6 Validación real (17-11-2026: PEND-T10 cerrada, PEND-T11 resuelta en parte)

Con el ticket del propietario (leído solo por el backend; nunca impreso) se consultaron, una vez cada una: licitaciones por fecha y por código, órdenes de compra por fecha y por código, `BuscarComprador`, `BuscarProveedor` (un RUT real y uno inexistente), el detalle de licitaciones en estados 15 y 16, y el filtro `estado` de órdenes con 9 valores. Unas 20 solicitudes en total. **Todas las rutas V1 responden JSON con HTTP 200.** El HTTP 400 "Runtime Error" del intento anterior fue temporal.

Resultados:
- **Estructura**: coincide con los diccionarios y con lo que leen los normalizadores. Se normalizaron sin fallas 1.921 licitaciones y 20.818 órdenes de compra de un día.
- **Listados** traen solo `CodigoExterno/Codigo`, `Nombre`, `CodigoEstado` y, en licitaciones, `FechaCierre`. En el **detalle**, `FechaCierre` de primer nivel viene `null` y la fecha real está en `Fechas.FechaCierre` (el normalizador ya usaba esa).
- **Estados de licitación 15 y 16**: aparecen en datos reales con `Estado` = "Revocada" y "Suspendida". El diccionario informa 18 y 19. Se agregaron 15 y 16 al catálogo y se conservan 18 y 19.
- **Fechas** sin zona (`2026-11-16T15:01:00`, a veces con milisegundos): se interpretan como hora de Chile.
- **Región** del comprador viene como texto con espacios sobrantes (`"Región de los Lagos "`); `ChileRegion::codeFromName` la resuelve.
- **`BuscarComprador`** y **`BuscarProveedor`** responden `{Cantidad, FechaCreacion, listaEmpresas: [{CodigoEmpresa, NombreEmpresa}]}`. Un RUT válido devolvió exactamente una empresa; **un RUT inexistente devolvió 2 empresas sin relación**. Desde ahora solo un resultado único cuenta como proveedor encontrado.
- **Filtro `estado` de órdenes** (PEND-T11). Válidos: `enviadaproveedor`, `aceptada`, `cancelada`, `recepcionconforme`, `pendienterecepcion`. Responden **HTTP 500 `{"Mensaje": "Los parámetros no son válidos."}`**: `recepcionaceptadacialmente`, `recepecionconformeincompleta` (grafías publicadas), `recepcionparcial` y `enproceso`. Se quitaron del selector.
- **HTTP 429 `{"Mensaje": "...existen peticiones simultáneas."}`** al encadenar consultas pesadas: **no es cuota**. El cliente ahora lee el mensaje antes que el código HTTP; este caso se reintenta tras una pausa (~3 s) en vez de bloquear V1 hasta el día siguiente.

Fixtures anonimizados con la estructura real: `tests/Fixtures/mercado_publico/real_*.json` (datos de personas y contactos reemplazados por valores ficticios). Prueba: `tests/Feature/MercadoPublico/RealV1ResponsesTest.php`.

Pendiente: la grafía válida del filtro para "recepcionada parcialmente" y "recepción conforme incompleta" (se puede filtrar por fecha y mirar `CodigoEstado` 14 y 15).

## 4. Flujo de Compra Ágil V2

```text
Scheduler (cada N min, si MERCADO_PUBLICO_SYNC_ENABLED)
  └─ SyncCompraAgilJob (ShouldBeUnique: 1 ejecución a la vez)
       └─ MercadoPublicoSyncService::syncCompraAgil()
            1. Lee mercado_publico_sync_state (último éxito)
            2. ttl_cambio_ms = ahora − último éxito + solapamiento (tope: ventana inicial)
            3. Por página (tamano_pagina=50, hasta total_paginas):
                 QuotaService::reserve()  → si no hay cupo: aborta con estado "quota_exhausted"
                 V2Client::get()          → header ticket, timeout, reintentos 5xx
                 Normalizer → DTO → upsert transaccional por lote (unique source_system+external_code)
                 Omite si payload_hash no cambió · checkpoint de página en sync_run
            4. Cierra sync_run (ok/partial/failed, solicitudes usadas, error saneado)

React → GET /api/v1/public-procurement/compra-agil (datos persistidos + last_fetched_at + estado de sync)
React → GET /api/v1/public-procurement/compra-agil/{codigo}
         └─ si el detalle no existe o es más antiguo que MERCADO_PUBLICO_CACHE_MINUTES → V2 detalle → upsert
```

## 4.1 Búsquedas guardadas y alertas (17-11-2026)

El matching de búsquedas guardadas usa solo la tabla local `public_procurement_opportunities` (Compra Ágil ya sincronizada): **no hace solicitudes a la API ni consume cuota**. Comando `php artisan public-procurement:match`, cada 15 minutos (`MERCADO_PUBLICO_MATCHING_ENABLED`). Ver TD-022 en DECISIONS.md.

**Licitaciones** (TD-023): el comando `php artisan public-procurement:watch-tenders` (cada 3 horas) sí usa la API V1. Hace 1 solicitud a `licitaciones.json?estado=activas` y hasta 40 detalles por `codigo`, solo de candidatas de búsquedas activas que incluyan licitaciones. No corre si no hay búsquedas así.

## 5. Variables de entorno (`backend/.env`)

```dotenv
MERCADO_PUBLICO_ENABLED=true
MERCADO_PUBLICO_TICKET=            # solo en backend/.env; nunca en Git
MERCADO_PUBLICO_V2_BASE_URL=https://api2.mercadopublico.cl
MERCADO_PUBLICO_V1_BASE_URL=https://api.mercadopublico.cl/servicios/v1/publico
MERCADO_PUBLICO_V1_DAILY_BUDGET=9000
MERCADO_PUBLICO_V1_LIST_CACHE_MINUTES=30
MERCADO_PUBLICO_V1_BUYERS_REFRESH_HOURS=24
MERCADO_PUBLICO_V1_HTTP_TIMEOUT=45
MERCADO_PUBLICO_V2_DAILY_BUDGET=9000
MERCADO_PUBLICO_V2_Z_IS_LOCAL=true
MERCADO_PUBLICO_SYNC_ENABLED=false
MERCADO_PUBLICO_SYNC_INTERVAL_MINUTES=30
MERCADO_PUBLICO_SYNC_OVERLAP_MINUTES=15
MERCADO_PUBLICO_SYNC_INITIAL_WINDOW_HOURS=24
MERCADO_PUBLICO_SYNC_MAX_PAGES=40
MERCADO_PUBLICO_HTTP_TIMEOUT=20
MERCADO_PUBLICO_HTTP_CONNECT_TIMEOUT=5
MERCADO_PUBLICO_MAX_RETRIES=2
MERCADO_PUBLICO_CACHE_MINUTES=10
```
