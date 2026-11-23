# TIARE — Registro de decisiones

> Registro propio de TIARE. **No confundir** con `DECISIONS.md` de la raíz, que pertenece al proyecto INGENERIA JARVIS (Ergo SaniTas SpA).
> Formato: ID · fecha · contexto · opciones · decisión · consecuencias.

---

## TD-001 · 2026-11-14 · Los documentos de especificación de la raíz son de otro proyecto

**Contexto.** `CLAUDE.md` §2.2 pide leer `PRODUCT_REQUIREMENTS.md`, `DATABASE_SPEC.md`, `API_SPEC.md`, `LARAVEL_DATABASE_IMPLEMENTATION.md` y `DECISIONS.md`. Todos existen, pero su encabezado dice **INGENERIA JARVIS / Ergo SaniTas SpA**: un sistema de una sola empresa (DEC-001 de ese proyecto) para salud y medicina deportiva (módulos clínicos, agenda, órdenes de servicio). `INGENERIA_JARVIS_MASTER_SPEC.md` y `skill-ingeneria-administracion.md` tampoco son de TIARE.

No existen: `PLAN_NEGOCIOS_TIARE_SPA.md`, `AUTHORIZATION_MATRIX.md`, `TIARE_PROTOTIPO_HTML_CSS_JS.zip` (el prototipo está descomprimido en `TIARE_PROTOTIPO_HTML_CSS_JS/tiare-prototype/`).

**Opciones.** (a) Adoptar esos documentos como especificación de TIARE; (b) ignorarlos; (c) reutilizar solo sus convenciones técnicas transversales y tomar el dominio de `CLAUDE.md`.

**Decisión.** (c). Se reutilizan convenciones genéricas compatibles con `CLAUDE.md`:
- `public_id` ULID `CHAR(26)` en entidades expuestas por API; PK interna `BIGINT`.
- Envoltorio de respuesta `{data, meta:{request_id, pagination}}` y formato de error `{error:{code,message,details}, meta}` (API_SPEC §4.4, §7).
- `DECIMAL(14,2)` para importes, `currency CHAR(3)`; nunca `FLOAT`.
- Estados `VARCHAR` validados con enums PHP; transiciones por acciones de dominio.
- Allowlist para `sort` y filtros; `per_page` máx. 100.
- Sanctum en modo cookie/sesión para la SPA; sin tokens en `localStorage`.

**No se adopta:** el alcance monoempresa (TIARE es multiempresa, `CLAUDE.md` §5.1), los módulos clínicos/deportivos, el catálogo de permisos de JARVIS ni la regla de "solo Ollama".

**Consecuencias.** Falta una especificación funcional propia de TIARE. Pendiente PEND-T01.

## TD-002 · 2026-11-14 · Motor de base de datos: MariaDB 10.4 (XAMPP) en desarrollo

**Contexto.** `CLAUDE.md` pide MySQL 8. El equipo tiene XAMPP con **MariaDB 10.4.32** (no MySQL 8). Al momento de la auditoría el proceso `mysqld` escuchaba en 3306 pero no completaba el handshake (el log muestra dos arranques sin "ready for connections"). *Actualización: resuelto en TD-010; el desarrollo ya usa MariaDB.*

**Decisión.** Las migraciones se escriben con el Schema Builder de Laravel, compatible con MySQL 8 y MariaDB ≥ 10.3, sin funciones exclusivas de una u otra (sin `CHECK` dependiente de versión, JSON usado solo como almacenamiento). Desarrollo local: `DB_CONNECTION=mariadb`. Pruebas automatizadas: SQLite en memoria (no requieren servidor). Producción: MySQL 8 (InnoDB, utf8mb4).

**Consecuencias.** Antes de producción se debe ejecutar la batería de migraciones contra MySQL 8 real. El servidor local debe reiniciarse desde el panel de XAMPP para ejecutar `php artisan migrate` contra MariaDB.

## TD-003 · 2026-11-14 · Versión de Laravel

**Contexto.** PHP disponible: 8.2.12. Laravel 13 exige PHP ≥ 8.3.
**Decisión.** Laravel 12.x (soportado, compatible con PHP 8.2). No se actualiza PHP por iniciativa propia.

## TD-004 · 2026-11-14 · Diferencias entre la guía oficial V2 y la respuesta real

Validado con consultas reales el 14-11-2026 (ver `docs/MERCADO_PUBLICO_API.md` §2.8). El conector se ajusta a la respuesta real y conserva compatibilidad con lo documentado:

| Tema | Guía v3.0 (mayo 2026) | Respuesta real observada | Decisión |
|---|---|---|---|
| `tamano_pagina` | default 15, máx 50 | Rechaza < 10: `"tamano_pagina debe estar entre 10 y 50"` | Validar rango 10–50 en backend |
| Ticket inválido | 403 | 401 con mensaje "El ticket no existe, es inválido o no tiene permisos." | Tratar 401 y 403 como error de credencial, sin reintento |
| `id_orden_compra` | dentro de `orden_compra` | En la raíz del `payload` del detalle | Leer ambas ubicaciones |
| `documentos[].id` | string UUID | entero | Guardar como string |
| Fechas | ISO-8601 | Mezcla: `"2026-11-13 21:59"` (sin zona) y `"2026-11-16T11:00:00Z"` | Ver TD-005 |
| Listado | `montos.*` | `montos.*` (detalle usa `presupuesto.*`) | Normalizar ambos |

## TD-005 · 2026-11-14 · Zona horaria de las fechas de Compra Ágil V2

**Contexto.** En el mismo registro, `fechas.fecha_cierre = "2026-11-16 11:00"` y `fechas.fecha_cierre_primer_llamado = "2026-11-16T11:00:00Z"`. Si la `Z` fuera UTC real, el cierre sería 08:00 en Chile, mientras que la fecha sin zona indica 11:00. La evidencia sugiere que la API rotula con `Z` horas que en realidad son locales de Chile, pero **no está confirmado oficialmente**.

**Decisión.**
- Fechas sin zona → se interpretan como `America/Santiago` y se guardan en UTC.
- Fechas con `Z` → configurable con `MERCADO_PUBLICO_V2_Z_IS_LOCAL` (por defecto `true`, según la evidencia). Se conserva siempre el texto original en el payload crudo.
- La sincronización incremental usa `ttl_cambio_ms` (ventana relativa, inmune a la ambigüedad de zona) más un solapamiento configurable, en lugar de `cambio_desde/cambio_hasta`.

**Consecuencias.** Revalidar con ChileCompra; si confirman UTC real, cambiar la variable a `false` y re-sincronizar.

## TD-006 · 2026-11-14 · Alcance de la primera fase

**Contexto.** `CLAUDE.md` §15 ordena primero esqueleto + auth + CRM + compras privadas, y luego Mercado Público. El propietario indicó en su instrucción más reciente que **la prioridad principal es Mercado Público, comenzando por Compra Ágil V2** (jerarquía §2.3: prevalece la instrucción explícita más reciente).

**Decisión.** Fase 1 = esqueleto backend/frontend + autenticación Sanctum + tenants/roles mínimos + conector Compra Ágil V2 completo (cliente, normalizador, persistencia, sincronización incremental, cuota, API interna, monitor React). CRM, proveedores y compras privadas pasan a fases siguientes.

## TD-007 · 2026-11-14 · Ticket expuesto en el chat

El ticket se compartió en una conversación (canal adicional). Se configuró únicamente en `backend/.env` (ignorado por Git) y no se escribe en código, tests, docs ni logs. **Recomendación al titular:** consultar con ChileCompra el procedimiento para reemplazarlo y actualizar `backend/.env` con el nuevo valor.

## TD-008 · 2026-11-14 · Carga inicial de Compras Ágiles vigentes

**Contexto.** La sincronización incremental solo trae registros con cambios recientes; una Compra Ágil publicada hace días sin cambios no aparece. La API acepta listar por `estado` sin ventana de cambios.
**Decisión.** Opción `--full` del comando: recorre por estado/región sin `ttl_cambio_ms` (exige al menos un filtro), no avanza la marca incremental y debe ejecutarse en el horario de carga masiva recomendado por ChileCompra (22:00–07:00). No se programa automáticamente.

## TD-009 · 2026-11-14 · Enlace a la ficha oficial

No se encontró un enlace profundo verificable a la ficha pública de una Compra Ágil (las URL probadas devolvieron 403, redirección a login o una SPA que responde 200 a cualquier ruta). Se enlaza al portal `https://www.mercadopublico.cl/` y se muestra el código para buscarlo. Pendiente PEND-T07.

## TD-010 · 2026-11-14 · Recuperación de MariaDB y cambio del desarrollo a MariaDB

**Contexto.** El disco C: estaba lleno (<100 MB libres). MariaDB quedó colgada en el arranque y la tabla de privilegios `mysql.db` resultó con el encabezado del índice (`db.MAI`) en ceros: `Incorrect file format 'db'`.

**Acciones** (con autorización del propietario, después de que liberó espacio):
1. Respaldo completo del directorio de datos en `C:\xampp\mysql\data_backup_20261114` (96 MB, verificado con `diff`).
2. Se detuvo el proceso colgado y se revisaron los encabezados de todos los índices Aria del esquema `mysql`: solo `db.MAI` estaba dañado.
3. El índice dañado se conservó como `mysql/db.MAI.corrupt_20261114`, se usó como plantilla el `db.MAI` limpio de `C:\xampp\mysql\backup\mysql` y se reconstruyó con `aria_chk -r` desde los datos actuales (`db.MAD`): 3 registros recuperados y verificación `aria_chk -e` sin errores.
4. MariaDB arrancó ("ready for connections"). `aula_movil`: 22 tablas, `CHECK TABLE` sin problemas.

**Decisión.** El desarrollo usa MariaDB (`DB_CONNECTION=mariadb`, base `tiare`, utf8mb4_unicode_ci, InnoDB). La suite puede correr contra MariaDB con la base `tiare_test`. El archivo SQLite anterior se conserva sin uso.

**Consecuencias.** Se pueden borrar el respaldo y `db.MAI.corrupt_20261114` cuando el propietario confirme que todo funciona. Conviene vigilar el espacio en disco porque MariaDB vuelve a fallar si se llena.

## TD-011 · 2026-11-14 · Cuota real del ticket V2 ≈ 275 solicitudes/día

**Contexto.** Durante la recarga en MariaDB, la API devolvió 429 en la página 63/189, con unas 275 solicitudes acumuladas en el día. La guía no publica el valor por tipo de ticket.
**Decisión.** `MERCADO_PUBLICO_V2_DAILY_BUDGET=250`; sincronización programada cada 60 min con máximo 10 páginas; nuevo `--from-page` para retomar cargas `--full` en noches siguientes.
**Consecuencias.** El contador local vive en la base de datos: al cambiar de base se pierde el consumo previo del día (hoy la base MariaDB registra 64, no 272). Para operar con más volumen se debe solicitar a ChileCompra un ticket de mayor cuota.

## TD-012 · 2026-11-14 · Modelo de datos privados: todo pertenece a un tenant

**Contexto.** CLAUDE.md exige `tenant_id` en todo dato privado. El prototipo mezcla el CRM de TIARE (sus clientes) con la red de proveedores.
**Decisión.**
- Organizaciones, contactos, clientes, proveedores, categorías y productos llevan `tenant_id`. TIARE opera su propio CRM y red como un tenant más (por crear cuando se definan los datos reales; PEND-T09). Las unidades de medida son un catálogo global de referencia.
- Aislamiento en tres capas: (1) global scope `BelongsToTenant`, que falla cerrado sin tenant activo y asigna `tenant_id` solo desde el backend; (2) Policies por recurso, que verifican permiso y pertenencia; (3) reglas `exists`/`unique` filtradas por tenant para las referencias que llegan en el payload.
- `ResolveCurrentTenant` se ejecuta antes del route-model binding, de modo que un `public_id` de otro tenant devuelve 404.
- Los Form Requests autorizan antes de validar: sin permiso se responde 403, sin revelar detalles de validación.
- Una organización (RUT único por tenant) puede ser cliente y proveedor a la vez.
- Estados de proveedor: Registrado, En revisión, Habilitado para cotizar y Suspendido. No existe "validado" (CLAUDE.md §5.3; criterios pendientes en PEND-T08).
- Los datos demo se cargan solo en el tenant con `is_demo = true`, sin RUT y con correos `.test`, y sin métricas inventadas del prototipo.

## TD-013 · 2026-11-17 · Instrucción del propietario: API completa de Mercado Público y rediseño de la interfaz

**Contexto.** El propietario pidió que Compra Ágil y todas las funciones de la API de Mercado Público estén disponibles y que se rediseñe la interfaz, indicando expresamente que esta instrucción modifica CLAUDE.md (jerarquía §2.3, punto 1).
**Decisión.** (1) Se adelanta la parte de Mercado Público de la Fase 4 (licitaciones y OC V1, organismos, proveedor por RUT) antes de la Fase 2b. (2) Se deja de conservar el aspecto visual del prototipo (§2.1 y §12 pedían usarlo como referencia): se reemplaza por un sistema visual propio (TD-015). El prototipo original sigue intacto en `TIARE_PROTOTIPO_HTML_CSS_JS/`; solo se eliminaron las copias `frontend/src/styles/prototype.css` y `app.css`.
**Consecuencias.** Las búsquedas guardadas, el matching por cliente y las alertas (Fase 4, puntos 9 y 10) siguen pendientes: son funciones de TIARE, no de la API.

## TD-014 · 2026-11-17 · Conector V1 (licitaciones, órdenes de compra, organismos, proveedores)

**Fuentes.** chilecompra.cl/api (revisada 17-11-2026) y los diccionarios oficiales `Documentacion-API-Mercado-Publico-Licitaciones.pdf` y `Documentacion-API-Mercado-Publico-oc.pdf` (marzo 2026), leídos completos. Las claves JSON de BuscarProveedor/BuscarComprador y los valores del filtro `estado` de OC no aparecen textualmente en lo que se pudo leer (PEND-T10, PEND-T11).
**Decisiones.**
- Cliente propio `MercadoPublicoV1Client`: ticket en query (como indican los ejemplos oficiales), sin registrar URL, descartando mensajes de excepción del cliente HTTP (incluyen la URL) y redactando texto externo. Solicitudes serializadas con un lock de caché. Cuota local separada (`v1`).
- Errores: HTTP 4xx/5xx, cuerpos HTML (páginas de error ASP.NET) y JSON `{Codigo, Mensaje}` sin `Listado` se convierten en errores tipados; 5xx y conexión con reintento limitado; 429 bloquea hasta el día siguiente.
- Los listados V1 llegan completos en una sola solicitud y solo traen código, nombre, estado y cierre. Se guardan (upsert idempotente por código) y la lista de códigos de cada consulta se cachea 30 min (`MERCADO_PUBLICO_V1_LIST_CACHE_MINUTES`): paginar y filtrar no consume cuota. El detalle se obtiene por código, se cachea `MERCADO_PUBLICO_CACHE_MINUTES` y ante falla se entrega la copia local marcada como desactualizada.
- Licitaciones en `public_procurement_opportunities` (`source_type = licitacion`) con columnas nuevas `procedure_type`, `buyer_code`, `awarded_at` y `details` (JSON); ítems con adjudicación por línea. OC en `public_purchase_orders` (ítems como JSON). Organismos en `public_buyers` (refresco diario) y proveedores en `public_suppliers` (24 h).
- Minimización: no se guardan nombres, RUT, correos ni teléfonos de funcionarios o contactos (`RutUsuario`, `NombreUsuario`, `MailContacto`, responsables de pago y contrato, etc.), tampoco en el payload crudo.
- Filtros por organismo o proveedor se envían con fecha (hoy si no se indica), como en los ejemplos oficiales; `estado=activas` se consulta sola.
- Montos en moneda de origen; `available_amount_clp` solo se llena si la moneda es CLP (no se convierten UF/UTM).
**Validación real.** (Actualizado: V1 validada el 17-11-2026, ver MERCADO_PUBLICO_API.md §3.6.) Inicialmente, dos consultas reales a `licitaciones.json` (`estado=activas` y `fecha=14112026`) con el ticket del propietario respondieron **HTTP 400 con una página HTML "Runtime Error"** sin detalle. No se pudo determinar si el ticket V2 sirve para V1 ni si fue un problema temporal; el entorno no permitió más sondeos. Todo V1 está probado solo con fixtures ficticios basados en los diccionarios (PEND-T10).

## TD-015 · 2026-11-17 · Sistema visual "expediente"

Ver `docs/DESIGN.md`. Una familia tipográfica (Archivo, eje de ancho), papel frío, lapislázuli como color de acción y cobre reservado a cierres de menos de 24 h. Listas como registro de filas, fichas en panel lateral, resumen encabezado por la línea de cierres de 72 h. Se retiraron las migas en mayúsculas y la tarjeta por oportunidad del prototipo. El banner "DATOS DEMOSTRATIVOS" (§12) se mantiene con el texto en minúsculas iniciales ("Datos demostrativos.").

## TD-016 · 2026-11-17 · Rediseño Material rosado con MUI

**Contexto.** El propietario pidió una interfaz más intuitiva y actual, con paleta rosada, usando Angular Material. El frontend es React (CLAUDE.md §4.1); Angular Material no funciona en React.
**Opciones.** (a) MUI, implementación de Material Design para React; (b) migrar todo el frontend a Angular; (c) imitar Material con CSS propio.
**Decisión.** El propietario eligió (a). Dependencias nuevas: `@mui/material`, `@mui/icons-material`, `@emotion/react`, `@emotion/styled` (requeridas por MUI). Tema Material 3 rosado en `src/app/theme.ts`; ver `docs/DESIGN.md`. Reemplaza TD-015.
**Consecuencias.** Toda la interfaz usa MUI (Clientes, Proveedores y Catálogo se migraron el mismo día). Se eliminaron `Modal`, `Icon` e `iconPaths` (íconos del prototipo) y casi todo `tiare.css`. El bundle se separa en chunks `react` y `mui` (≈116 kB gzip para MUI).

## TD-017 · 2026-11-17 · Fase 2b: invitaciones, evaluación y documentos de proveedores

- **Invitaciones**: no se crean contraseñas por el administrador ni se muestran en pantalla. Una cuenta nueva recibe una contraseña aleatoria desconocida y el enlace de restablecimiento del broker de Laravel. Si la persona ya tiene cuenta, solo se agrega la membresía (no se cambia su nombre ni se envía correo). El token viaja en el fragmento de la URL.
- **Resguardos de roles**: nadie modifica su propia membresía; la empresa siempre conserva al menos un `tenant_admin` activo. Desactivar es quitar la membresía activa, no borrar al usuario.
- **Evaluación**: cinco criterios de CLAUDE.md §5.3 (precio, calidad, plazo, cumplimiento, atención/reclamos) en escala 1–5; global = promedio simple; inmutables. Un incidente exige comentario. Sin ponderaciones hasta que el propietario las defina.
- **Documentos**: tipos operativos iniciales (certificación, tributario, legal, seguro, permiso, bancario, lista de precios, otro); "por vencer" = 30 días o menos. Archivar es reversible y conserva el archivo. Sin borrado físico desde la UI. Antimalware pendiente.

## TD-018 · 2026-11-18 · Fase 3: compras privadas

- **Flujo**: DRAFT → SUBMITTED → QUOTING → PENDING_APPROVAL → APPROVED → ORDERED → CLOSED. La aprobación (§5.4 paso 7) se hace sobre la compra propuesta (cotización elegida), no sobre la necesidad. Un rechazo devuelve a cotización con la decisión registrada. Anular la orden anula la solicitud (una solicitud, una orden).
- **Separación de funciones**: quien propone la compra no la aprueba (CLAUDE.md §9, aplicado también a personas). Con un solo usuario no se puede aprobar: hay que invitar a otra persona desde Configuración.
- **Límite de aprobación** por persona y empresa (`tenant_user.approval_limit_clp`); vacío = sin límite.
- **Comparador**: puntaje = 60 % precio (costo total CLP con IVA y despacho) + 25 % plazo + 15 % evaluación del proveedor (neutral 0,6 sin evaluaciones); pesos ajustables y guardados en cada comparación; excluye vencidas, descartadas e incompletas. Garantía y condiciones de pago se muestran, no se puntúan. Elegir una cotización distinta de la recomendada exige justificación.
- **Montos**: bcmath, redondeo half-up a 2 decimales. Moneda extranjera exige tipo de cambio y fuente; no se consultan tipos de cambio externos.
- **Permisos**: nuevo `purchase_orders.receive`. `tenant_admin` recibe `quotations.*` y `purchase_orders.*` para que una empresa complete el flujo sin personal de TIARE; `tenant_procurement_user` puede ver órdenes y registrar recepciones.
- **TIARE no envía correos al proveedor** en esta fase: el pedido de cotización y el envío de la orden se registran; la comunicación se hace por el canal habitual.

## TD-019 · 2026-11-18 · Facturas e indicadores de compras

- **Facturas**: registro administrativo ligado a la orden (§7.2); no reemplaza contabilidad ni SII. Misma moneda que la orden. IVA sugerido 19 % en la UI, el backend guarda lo que se informa. Las diferencias contra la orden se muestran, no bloquean (puede haber notas de crédito o ajustes).
- **Pago informado**: TIARE no paga ni verifica pagos; registra quién lo informa, fecha y referencia, con auditoría. Notas de crédito no se "pagan": restan en la conciliación.
- **Indicadores**: cada cifra declara fórmula, tamaño de muestra, supuestos, período, moneda y hora de cálculo (§1.2 #7); sin datos el valor es null. Tiempos con mediana (no promedio) para evitar distorsión por casos extremos. El "ahorro vs referencia" usa el precio que informa el solicitante y se compara en neto; se declara que no es un precio de mercado verificado.

## TD-020 · 2026-11-17 · Inventario y logística (Fase 5)

- **Movimientos inmutables**: el stock solo cambia con movimientos (entrada, salida, ajuste, traspaso); un error se corrige con un ajuste con motivo, nunca editando (§7.5). No se permite stock negativo.
- **Recepción e inventario**: ingresar a bodega es opcional al recibir una orden y requiere `inventory.manage`; solo entran ítems con producto del catálogo (los de texto libre y servicios no se inventarían). Costo unitario en CLP según el tipo de cambio de la orden.
- **Logística con aliados** (§5.6): TIARE coordina y registra; no integra APIs de transportistas en esta fase. El enlace de seguimiento se arma desde una plantilla https con `{code}`, validada en backend.
- **Entrega**: solo por la acción "confirmar entrega" (permiso `shipments.confirm_delivery`), con nombre de quien recibe y comprobante opcional en disco privado, descargable solo por usuarios con `shipments.read` del mismo tenant.

## TD-021 · 2026-11-17 · Agentes IA (Fase 6)

- **Proveedor**: Claude API, elegido por el propietario. Se usa el SDK oficial de PHP (`anthropic-ai/sdk`) en vez de llamadas HTTP propias: tipos de error por estado, reintentos y salida estructurada ya resueltos. Queda detrás del contrato `LlmClient` para poder sumar otro proveedor (por ejemplo, un modelo local) sin tocar los agentes.
- **Hechos primero**: cada agente calcula por código los datos verificables (fechas, montos, diferencias, alertas, checklist). El modelo solo agrega análisis redactado, que la interfaz etiqueta como "Estimación IA". Si el modelo no está configurado o falla, el análisis sigue siendo útil (modo determinista o ejecución PARTIAL).
- **Una llamada, sin herramientas**: la tarea es de extracción y análisis acotado; no requiere un agente autónomo. El modelo no accede a la base de datos, a internet ni a Mercado Público (§10.3). Los textos de terceros se entregan marcados como datos, no instrucciones.
- **Datos enviados**: solo los campos del caso seleccionados por el backend (datos públicos de ChileCompra y, en compras privadas, la solicitud y sus cotizaciones con nombres de proveedores); nunca credenciales, tickets ni datos de usuarios. Se guarda copia de lo enviado para trazabilidad.
- **Costo y cuota**: límite diario por empresa (`AI_DAILY_RUNS_PER_TENANT`, 30 por defecto) y 6 ejecuciones por minuto por usuario. Esfuerzo `medium` por defecto (configurable).
- **Revisión humana**: "Útil" o "Descartado" (con motivo), una vez, registrada con usuario y fecha. No cambia ningún dato de negocio; sirve para evaluar y mejorar los prompts (versionados).
- **Ejecución síncrona**: el análisis responde en la misma solicitud (hasta ~2 min). Si los tiempos crecen, pasar a la cola de base de datos.

## TD-022 · 2026-11-17 · Búsquedas guardadas, matching y alertas

- **Solo datos locales**: el matching compara contra las oportunidades ya sincronizadas; nunca consulta Mercado Público. Así no consume cuota (≈275 consultas/día en V2) y escala con el número de empresas.
- **Matching explicable**: palabras y catálogo por coincidencia de palabras (`WordMatcher`), sin IA. Cada coincidencia guarda su motivo. Es preliminar: no afirma que el producto cumple lo pedido.
- **Alertas internas, no correo**: se muestran en TIARE con lectura por usuario. El correo queda para cuando se defina el proveedor de envío y las preferencias de notificación.
- **Sin duplicados**: clave única por empresa (`new:<búsqueda>:<hash de códigos>`, `closing:<oportunidad>`); repetir el comando no crea alertas nuevas.
- **Permiso**: se reutiliza `public_procurement.read`; búsquedas y alertas son privadas por empresa (global scope del tenant) y no modifican datos oficiales.
- **Compatibilidad**: columnas `timestamp` no nulas usan `useCurrent()` porque MariaDB 10.4 las rechaza sin valor por defecto (las pruebas usan SQLite y no lo detectan).

## TD-023 · 2026-11-17 · Alertas de licitaciones

- **Listado + detalle selectivo**: `estado=activas` trae en una sola solicitud todas las licitaciones abiertas, pero solo con código, nombre, estado y cierre. El detalle (región, monto, productos) cuesta una solicitud por licitación: se pide solo para candidatas de alguna búsqueda, con tope por ejecución, y una sola vez por licitación.
- **Sin búsquedas, sin consultas**: el seguimiento no corre si ninguna búsqueda activa incluye licitaciones.
- **Frecuencia**: cada 3 horas (configurable). Una licitación dura días o semanas abierta; no se justifica más frecuencia ni carga masiva.
- **Calce previo por nombre**: antes del detalle solo se conoce el nombre, así que las palabras deben aparecer en él. Tras el detalle, el matching normal usa además descripción, organismo y productos.
- **Compatibilidad**: búsquedas guardadas sin `source_types` se tratan como solo Compra Ágil.

## TD-024 · 2026-11-17 · Perfiles: administrador TIARE, empresa cliente con plan y tokens API

**Contexto.** El propietario pidió un rol administrador y un rol "empresa que contrata servicios" que ve solo su empresa y su plan contratado, con autenticación y tokens de seguridad. Ya existían 11 roles (plataforma/empresa), aislamiento por tenant y login SPA de Sanctum (cookie de sesión + CSRF).

**Decisión.**
- **Rol administrador** = roles de plataforma `tiare_admin`/`platform_super_admin`, con el nuevo permiso `platform.tenants.manage` (ningún rol de empresa lo tiene). Panel `/admin` y API `/api/v1/admin/*`: alta de empresa + plan + invitación del administrador de la empresa, cambio de plan, suspensión. El admin TIARE **no** queda como miembro de las empresas que crea.
- **Rol empresa** = roles de alcance `tenant` existentes (`tenant_admin`, etc.). Ya veían solo su empresa (tenant resuelto en backend); ahora también ven su plan (`GET /company` → `subscription`, `seats`) y nada de otros clientes.
- **Planes** (`plans`, `tenant_subscriptions`): el plan habilita módulos y limita personas activas. **Permisos efectivos = permisos del rol ∩ módulos del plan vigente** (`App\Support\PlanModules`), aplicado en `User::permissionsFor`. Así el middleware `permission:` y las Policies existentes aplican el plan sin tocar cada ruta. Permisos CORE (`company.*`, `users.*`, `api_tokens.*`) no dependen del plan. Sin plan vigente → sin módulos. Los roles de plataforma no se filtran por plan (son personal TIARE).
- **Catálogo de planes PROPUESTO** (Start, Growth, Managed, Public, SaaS, nombres tomados del CRM): módulos y cupos son un supuesto de desarrollo hasta que el área comercial los defina (PEND-T12). No hay cobros (CLAUDE.md §5.1).
- **Tokens API** (Sanctum personal access tokens, `Authorization: Bearer`): para integraciones. Cada token queda fijado a una empresa (`personal_access_tokens.tenant_id`), lleva solo abilities que su emisor tiene (nunca `users.*`, `company.*`, `api_tokens.*`, `platform.*`), vence siempre (7–365 días), se muestra una sola vez, se guarda como hash, prefijo `tiare_` para escáneres de secretos. Solo se emiten/revocan desde la sesión web. Si la persona pierde acceso a la empresa, el token deja de servir (403). El navegador sigue usando cookie de sesión, no tokens.

**Consecuencias (TD-024).** Al bajar de plan, las personas por sobre el cupo conservan su acceso; solo se bloquean nuevas invitaciones o reactivaciones. La empresa demostrativa recibe el plan SaaS. Pruebas: `tests/Feature/Auth/PlansAdminAndTokensTest.php`.

## TD-025 · 2026-11-17 · Documentos de Mercado Público, eliminar clientes/proveedores y mantenedor de cuentas y roles

**Documentos adjuntos de Mercado Público.** La "Guía de uso API Compra Ágil v2" (v3.0, mayo 2026, texto extraído del PDF oficial) documenta solo `GET /v2/compra-agil` y `GET /v2/compra-agil/{codigo}`; cada elemento de `documentos[]` trae `id` (UUID en la guía; entero en respuestas reales) y `nombre`, **sin URL ni endpoint de descarga**. Las respuestas reales guardadas confirman solo `id` y `nombre`. Por CLAUDE.md §1.2.9 y §5.5 no se inventa una ruta ni se descarga desde el portal. Decisión: la ficha (Compra Ágil y licitación) muestra los adjuntos oficiales y el acceso al portal, y la empresa puede **subir la copia que descargó** (`public_opportunity_files`, privada por empresa, PDF/Word/Excel/JPG/PNG ≤ 20 MB, tipo verificado por contenido, descarga autenticada con `nosniff`), opcionalmente ligada al adjunto oficial. Permiso nuevo `public_procurement.documents` (subir/eliminar); leer y descargar requieren `public_procurement.read`. Si ChileCompra publica un endpoint de descarga, se agregará un adaptador (revisar en cada actualización de la guía).

**Eliminar clientes y proveedores.** Permisos nuevos `customers.delete`/`suppliers.delete` (incluidos en roles con `customers.*`/`suppliers.*`). Se elimina solo si no hay historial de compras (solicitudes de cotización, cotizaciones, órdenes, facturas para proveedores; solicitudes de compra para clientes). Con historial → HTTP 409 `HAS_HISTORY` y se sugiere Inactivo/Suspendido, para no romper la trazabilidad (CLAUDE.md §7.5). Junto al proveedor se eliminan evaluaciones, documentos (y sus archivos) y la organización si no es también cliente (y viceversa). Auditoría con nombre y RUT del registro eliminado.

**Mantenedor de cuentas y roles** (Administración TIARE → Cuentas / Roles y permisos; permisos `platform.users.manage` y `platform.roles.manage`):
- Cuentas: alta (rol de plataforma y/o empresas con rol; enlace para definir contraseña, TIARE no la ve), edición de nombre/correo, activar/desactivar (cierra sesiones y revoca tokens), rol de plataforma, alta/cambio/baja de acceso por empresa, reenvío de enlace.
- Roles: crear roles propios (`custom_*`) de plataforma o de empresa, editar nombre/descripción/permisos de cualquier rol salvo `platform_super_admin` y `tiare_admin` (bloqueados para no perder la administración), restaurar permisos de fábrica, eliminar roles propios sin cuentas asignadas. Un rol de empresa no puede recibir permisos `platform.*` ni `public_procurement.sync`.
- Salvaguardas: nadie se desactiva ni cambia su rol de plataforma; siempre queda ≥ 1 administrador TIARE activo; solo un superadministrador asigna o edita superadministradores; cada empresa conserva ≥ 1 administrador activo; se respeta el cupo del plan.
- Roles del sistema editados quedan marcados (`roles.permissions_customized_at`) y el seeder ya no los sobrescribe.

Pruebas: `tests/Feature/Crm/DeleteAndDocumentsTest.php`, `tests/Feature/Auth/AccountsAndRolesAdminTest.php`, `frontend/src/features/admin/AccountsRolesDocs.test.tsx`.

## TD-026 · 2026-11-17 · Contraseña de inicio, "Mi cuenta" y oferta/capacidad de proveedores

**Contraseña de inicio.** El propietario pidió poder generar una contraseña de inicio para cada perfil. Se revisa el criterio anterior de TD-025 ("TIARE nunca define contraseñas"): ahora el administrador TIARE puede elegir, al crear una cuenta o después desde su ficha, entre enviar el enlace por correo o **generar una contraseña de inicio**. Reglas:
- Generada en el servidor (`InitialPasswordGenerator`: 4 bloques de 4 caracteres sin ambiguos + 2 dígitos, ~90 bits), se entrega **una sola vez** en la respuesta, se guarda solo como hash y nunca se registra en auditoría ni logs.
- La cuenta queda con `must_change_password = true`: el backend responde `403 PASSWORD_CHANGE_REQUIRED` a todo salvo `auth/me`, `auth/logout` y `auth/password`; la SPA lleva a "Mi cuenta".
- Generarla cierra las sesiones y revoca los tokens de la cuenta. No aplica a la propia cuenta, ni a cuentas inactivas; un superadministrador solo lo genera otro superadministrador.
- El enlace por correo sigue disponible; restablecer por enlace también libera el cambio obligatorio.
- ~~Pendiente: la empresa cliente sigue invitando solo por enlace.~~ Resuelto en TD-027.

**Mi cuenta** (`/mi-cuenta`, `PUT /api/v1/auth/password`): exige la contraseña actual; política mínimo 10, letras y números, distinta de la actual; límite 5 intentos/min por cuenta; cierra las demás sesiones; no permitido con token API; auditoría `auth.password_changed`.

**Oferta y capacidad de proveedores** (`supplier_offerings`): insumos/productos y servicios que entrega cada proveedor, con categoría, unidad, vínculo opcional al catálogo, **capacidad declarada** (cantidad + período día/semana/mes/año), pedido mínimo, plazo y fecha de confirmación. La capacidad se marca como `supplier_declared` (no verificada por TIARE). Una capacidad sin período se rechaza. La búsqueda de proveedores también encuentra por nombre de insumo/servicio. Lectura con `suppliers.read`; cambios con `suppliers.update`.

Pruebas: `tests/Feature/Auth/InitialPasswordAndMyAccountTest.php`, `tests/Feature/Crm/SupplierOfferingTest.php`, `frontend/src/features/account/PasswordAndOfferings.test.tsx`.

## TD-027 · 2026-11-17 · Contraseña de inicio desde la empresa cliente

**Decisión.** El administrador de una empresa (permiso `users.manage`) puede usar la contraseña de inicio al invitar (opción por defecto) y generarla después para una persona de su equipo, con el mismo núcleo que el administrador TIARE (`App\Services\Auth\InitialPasswordService`: una sola vez, hash, cambio obligatorio, cierra sesiones y revoca tokens).

**Límite de alcance** (para que una empresa no pueda tomar control de cuentas ajenas):
- Al invitar, solo las **cuentas nuevas** reciben contraseña de inicio; una cuenta existente conserva su contraseña.
- Regenerar solo se permite si la persona pertenece **únicamente a esa empresa**, no es personal TIARE (sin rol de plataforma), está activa y no es uno mismo. Si no, la API responde 422 con el motivo y la UI deshabilita el botón mostrando ese motivo (`initial_password_blocker`).
- Límite `initial-password`: 10 por minuto por administrador (también para el endpoint del administrador TIARE).

Pruebas: `tests/Feature/Company/CompanyInitialPasswordTest.php`, `frontend/src/features/settings/SettingsPage.test.tsx`.

## TD-028 · 2026-11-18 · Superadministrador por consola y portal de clientes y proveedores

**Superadministrador.** Comando `php artisan tiare:superadmin {email} --name="..."` (y `--reset-password` para una cuenta existente). Crea o promueve la cuenta a `platform_super_admin` y genera una contraseña de inicio que se imprime **solo en la terminal de quien lo ejecuta** (no pasa por la web, el chat, logs ni auditoría); se cambia en el primer ingreso. Auditoría `admin.super_admin_created|promoted` con `via=console`.

**Portal.** Cada cliente y cada proveedor puede tener **una** cuenta de portal (`portal_accesses`), con rol de empresa `customer_portal` o `supplier_portal` (permisos `portal.customer` / `portal.supplier`, ligados a los módulos CRM / Proveedores del plan). La cuenta queda atada a su registro y solo ve, en modo lectura:
- **Cliente**: datos de su organización y contactos, estado y plan, y sus solicitudes de compra.
- **Proveedor**: organización y contactos, estado, rubros, cobertura, condiciones de pago, insumos/servicios con capacidad, documentos con vigencia, solicitudes de cotización dirigidas a él y sus órdenes de compra.
- Nunca: evaluaciones, notas internas, datos de otros clientes/proveedores ni de la empresa (todas las demás API responden 403).

**Alta.** Al crear un cliente o proveedor se crea su usuario con contraseña de inicio (opción activada por defecto; correo propuesto desde el contacto u organización). Cliente/proveedor y acceso se crean en la misma transacción. El correo no puede ser de una persona del equipo de la empresa ni del personal TIARE; una cuenta existente de otra empresa conserva su contraseña.

**Gestión** desde la ficha (`/customers|suppliers/{id}/portal-access`): ver, crear, regenerar contraseña (solo si la cuenta pertenece únicamente a esta empresa), activar/desactivar y revocar (desactiva la cuenta si queda sin uso). Eliminar el cliente/proveedor revoca su acceso. Los roles de portal no ocupan cupo del plan, no aparecen en "Personas", no se asignan desde los mantenedores y sus permisos no se editan.

Pruebas: `tests/Feature/Crm/PortalAccessTest.php` (incluye el comando), `frontend/src/features/portal/Portal.test.tsx`.

## TD-029 · 2026-11-18 · Optimización del consumo de cuota de Compra Ágil V2

**Diagnóstico (datos reales del 18-11-2026).** La cuota del ticket V2 es de ~250–275 solicitudes/día. La sincronización seguía *todos los cambios* del país en las últimas 24 h (`ttl_cambio_ms`): ~10.000 registros = 200 páginas ≈ 200 solicitudes, casi toda la cuota. Con el tope de 10 páginas por ejecución nunca terminaba; y como una ejecución parcial no avanzaba la marca, **cada clic en "Sincronizar" volvía a pedir las mismas 10 primeras páginas** (el segundo clic del día: 10 solicitudes para 43 registros nuevos).

**Decisiones.**
1. **Modo por defecto "publicaciones nuevas"** (`MERCADO_PUBLICO_SYNC_MODE=publications`): `estado=publicada` + `ordenar_por=FechaPublicacion` (verificado con 1 solicitud real: más reciente primero). Se detiene en la primera página que llega a lo ya conocido (cursor − solapamiento). Costo por ejecución ≈ publicaciones nuevas / 50 + 1 (1 solicitud si no hay nada nuevo). No depende de `publicado_desde` ni de su zona horaria (TD-005).
2. **Reanudación**: si se corta por máximo de páginas o cuota, guarda página, objetivo y tope del recorrido; la siguiente ejecución continúa (una página antes, por si la lista se corrió). Verificado con la API real (página 5 → retoma en 5, luego 10).
3. **Reserva para personas**: las ejecuciones programadas corren como proceso automático y se detienen en `presupuesto − reserva` (`MERCADO_PUBLICO_V2_INTERACTIVE_RESERVE=70`, es decir 180 de 250). Ese tope no bloquea el día; solo un 429 real de ChileCompra lo hace.
4. **Espera mínima de la sincronización manual**: 10 min (`MERCADO_PUBLICO_SYNC_MANUAL_COOLDOWN_MINUTES`); antes de eso responde "se sincronizó hace X min" sin consultar la API.
5. **Caché de fichas por estado**: abiertas 60 min (`MERCADO_PUBLICO_CACHE_MINUTES`), cerradas/finalizadas o con cierre de hace más de 1 día 24 h (`MERCADO_PUBLICO_CLOSED_CACHE_HOURS`).
6. **Cambios de estado**: ya no se siguen para todo el país. Un proceso con cierre vencido se trata como cerrado por fecha; la ficha actualiza el estado al abrirla. El modo "changes" queda para uso puntual nocturno (`tiare:mp:sync-compra-agil` sin `--new`).

**Consecuencia (TD-029).** La primera puesta al día de 24 h toma varias ejecuciones (≈ publicaciones del día / 500 por ejecución de 10 páginas); después, cada ejecución cuesta pocas solicitudes. Estimación con ~3.000 publicaciones diarias y ejecución cada 30–60 min: 70–110 solicitudes/día, dentro del tope automático de 180. Pruebas: `tests/Feature/MercadoPublico/QuotaOptimizationTest.php`.

---

## Pendientes (no inventar valores)

| ID | Tema | Responsable |
|---|---|---|
| PEND-T01 | Especificación funcional propia de TIARE (PRD, modelo de datos, matriz de autorización) | Propietario |
| PEND-T02 | Razón social definitiva (el prototipo muestra un nombre provisional) | Propietario |
| PEND-T03 | Confirmar con ChileCompra zona horaria de fechas V2 y cuota del tipo de ticket | Propietario / Desarrollo |
| PEND-T04 | Reemplazo del ticket expuesto | Titular del ticket |
| PEND-T05 | Política de retención de `public_procurement_raw_payloads` | Legal |
| ~~PEND-T06~~ | **Resuelto 14-11-2026** (ver TD-010) | — |
| PEND-T07 | Formato oficial de enlace profundo a la ficha de Compra Ágil | Desarrollo / ChileCompra |
| PEND-T08 | Criterios y evidencia para considerar "validado" a un proveedor (documentos, vigencias, evaluación) | Propietario |
| PEND-T09 | Crear el tenant real de TIARE y sus usuarios; definir la matriz de autorización real | Propietario |
| PEND-T10 | ~~Validar V1 con respuesta real~~ **Cerrada 17-11-2026**: V1 responde; normalizadores validados con datos reales (MERCADO_PUBLICO_API.md §3.6) | — |
| PEND-T11 | Filtro `estado` de OC: 5 valores validados; falta la grafía de "recepcionada parcialmente" y "recepción conforme incompleta" (las publicadas responden 500) | Desarrollo |
| PEND-T12 | Definir módulos, cupo de personas y condiciones comerciales de cada plan (el catálogo actual es una propuesta de desarrollo, TD-024) | Propietario / Comercial |

## TD-030 · 2026-11-18 · Anexos de Mercado Público: subida múltiple y "Descargar todo" (sin descarga automática del portal)

**Pedido.** Descargar los documentos anexos de una Compra Ágil desde TIARE.

**Revisión.** La API V2 solo informa id y nombre de cada adjunto (TD-025). Las condiciones de la API indican que el acceso "debe realizarse exclusivamente a través de los mecanismos descritos en api.mercadopublico.cl"; las del portal listan como causa de bloqueo indefinido de un proveedor "si se realizan operaciones mediante BOT en el sistema". El buscador público responde 403 a accesos automáticos. Descargar desde el portal con un robot arriesgaría el bloqueo de cuentas de clientes y del ticket, y contradice CLAUDE.md §1.2.9, §5.5 y §6.5.

**Decisión (aprobada por el propietario).** No se descarga desde el portal. Se reduce el trabajo manual a segundos:
- **Subida múltiple con arrastrar y soltar** en la ficha. Cada archivo se asocia automáticamente con su adjunto oficial comparando nombres (`App\Support\DocumentNameMatcher`: sin tildes ni mayúsculas, sin extensión, tolera nombres truncados y sufijos como "(1)"; umbral 80/100; en empate prefiere un adjunto sin copia).
- **Sin duplicados**: el mismo archivo (SHA-256) para el mismo proceso no se guarda dos veces.
- **"Descargar todo"**: ZIP con las copias de la empresa (`GET /public-procurement/files-archive`), nombres seguros y únicos, privado por empresa, auditado.
- Aviso de cuántos adjuntos oficiales faltan por guardar.

Si ChileCompra publica un mecanismo oficial de descarga, se agrega un adaptador. Prueba real (QA temporal, ya borrado): un archivo con el nombre del adjunto oficial en minúsculas y con "(1)" se asoció solo (puntaje 92); otro sin relación quedó como "otro documento"; resubir no duplicó; el ZIP trajo 2 archivos. Pruebas: `tests/Feature/Crm/OpportunityFilesBulkTest.php`.

## TD-031 · 2026-11-18 · Eliminar personas, roles, órdenes y cotizaciones sin perder el historial

**Pedido.** En Administración TIARE y en Configuración no se podían eliminar roles ni personas; tampoco órdenes de compra ni cotizaciones.

**Tensión.** CLAUDE.md exige auditoría y no borrar datos sin respaldo; toda aprobación debe registrar al usuario. Un borrado físico de cuentas dejaría aprobaciones y órdenes sin autor (las FK son `nullOnDelete`).

**Decisión.**
- **Cuentas** (Administración → Cuentas → ficha → "Eliminar cuenta"): borrado lógico (`users.deleted_at`). Se quitan sus empresas, se cierran sesiones y tokens, el correo se libera (se reemplaza por una dirección `.invalid`) y el nombre se conserva para el historial (las relaciones a `User` usan `withTrashed`). No se elimina la propia cuenta, el último administrador TIARE activo, el último administrador de una empresa, ni un superadministrador por un no superadministrador. Los accesos de portal se eliminan desde la ficha del cliente/proveedor.
- **Personas de una empresa** (Configuración → Personas → "Quitar de la empresa"): se quita la membresía y sus tokens de esa empresa. Si la cuenta no tiene otra empresa ni rol TIARE, se elimina como arriba.
- **Roles**: se pueden eliminar los propios y también los del sistema, salvo los protegidos (`Role::UNDELETABLE_KEYS`: superadministrador, administrador TIARE, portales y administrador de empresa). Si el rol tiene cuentas, se exige un rol de reemplazo del mismo alcance y las cuentas se mueven antes de eliminarlo. Los roles del sistema quedan con borrado lógico para que `RolesAndPermissionsSeeder` no los recree.
- **Órdenes internas**: permiso nuevo `purchase_orders.delete`. Solo sin recepciones ni facturas (con ellas corresponde anular). Si estaba vigente, la solicitud vuelve a "Aprobada" para emitir otra; si estaba anulada, la solicitud sigue anulada. El número no se reutiliza. Auditoría con copia de número, proveedor, total y estado.
- **Cotizaciones**: permiso nuevo `quotations.delete`. Solo mientras la solicitud está en cotización y si no es la propuesta. Las comparaciones ya generadas no cambian (instantáneas); si la recomendada se eliminó, proponer otra ya no exige justificación. Si era la única respuesta a un pedido de cotización, el pedido vuelve a "pedida".

**Consecuencias.** Los permisos nuevos llegan con el seeder a los roles con `purchase_orders.*` / `quotations.*` no personalizados (administrador de empresa, gestor de compras y administradores TIARE); a un rol personalizado se le agregan desde el mantenedor. Restaurar un rol del sistema eliminado requiere hoy intervención técnica (`deleted_at = null`). Pruebas: `AccountsAndRolesAdminTest`, `CompanyRemoveUserTest`, `ProcurementDeletionTest`.
