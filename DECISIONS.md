# INGENERIA JARVIS — REGISTRO DE DECISIONES

**Proyecto:** INGENERIA JARVIS / ERGO JARVIS  
**Organización:** Ergo SaniTas SpA  
**Versión:** 1.0.0  
**Fecha:** 2026-10-15  
**Estado:** Vigente. Este documento es la **fuente única de verdad** para estados (enums), nombres de campos compartidos, permisos, niveles de riesgo y alcance del MVP.

> Regla de precedencia: si `INGENERIA_JARVIS_MASTER_SPEC.md`, `PRODUCT_REQUIREMENTS.md`, `DATABASE_SPEC.md`, `LARAVEL_DATABASE_IMPLEMENTATION.md` o `API_SPEC.md` contradicen este archivo, **prevalece este archivo**. Toda nueva decisión se agrega aquí con ID, fecha y justificación, y luego se propaga a los documentos afectados en la misma tarea.

---

## DEC-001 — Alcance del producto: una sola empresa

**Decisión:** el producto se construye para **Ergo SaniTas SpA**. No es una plataforma multiempresa (SaaS).

- Se conserva `company_id` en las tablas como preparación técnica y para filtrar consultas, pero **no se diseña ni se promete multitenencia**.
- Catálogos, roles y agentes pueden ser específicos de salud y medicina deportiva.
- Una eventual apertura a otras empresas requerirá una decisión nueva y una revisión de arquitectura.

**Justificación:** reduce complejidad y permite entregar valor antes.

## DEC-002 — La IA forma parte del MVP

**Decisión:** el MVP incluye el orquestador y agentes de bajo riesgo para aumentar automatización y productividad desde la primera versión.

El MVP se entrega en tres incrementos, en este orden:

| Incremento | Contenido | Criterio de salida |
|---|---|---|
| **MVP-1 Núcleo** | Login, roles/permisos, auditoría, configuración de empresa, folios, idempotencia, aprobaciones | Pruebas negativas de permisos pasan |
| **MVP-2 Comercial-operativo** | CRM, catálogo, oportunidades, cotizaciones versionadas, órdenes, agenda, perfiles de personal y disponibilidad, asignaciones de citas, tareas, documentos administrativos (PDF de cotizaciones) | Flujo cliente → cotización → orden → cita asignada sin duplicados ni choques |
| **MVP-3 IA y automatización** | Orquestador, agentes del MVP, herramientas R0–R2, automatizaciones internas | AC-AI-001 a AC-AI-008 cumplidos |

**Agentes incluidos en el MVP:**

| Agente | Uso en el MVP | Riesgo máximo de sus herramientas |
|---|---|---|
| `ORCHESTRATOR` | Clasificar, planificar, delegar y consolidar | — |
| `SALES_CRM` | Detectar oportunidades sin próxima acción, redactar seguimientos, preparar borradores de cotización | R2 |
| `OPERATIONS_COORDINATOR` | Detectar conflictos de agenda, proponer asignaciones, listas de preparación | R2 |
| `BUSINESS_INTELLIGENCE` | Explicar KPI comerciales y operativos calculados por backend | R0 |
| `BUSINESS_MANAGER` | Resúmenes ejecutivos, planes de acción y priorización | R1 |
| `AUTOMATION_COORDINATOR` | Recordatorios internos, tareas, reportes programados | R2 |

**Límites del MVP:**

- Solo herramientas de nivel **R0 a R2** (ver DEC-020). Las acciones **R3** (efecto externo) quedan como propuestas que requieren aprobación y ejecución manual, porque el MVP no tiene integraciones externas de envío. **R4** está bloqueado.
- Solo proveedor **local (Ollama)**. Proveedores externos deshabilitados hasta aprobar transferencia de datos (DEC-021).
- `FINANCE_ANALYST` e `INVENTORY_EQUIPMENT` se activan con la Fase 1. `CLINICAL_DOCUMENTATION` y `SPORTS_PERFORMANCE` con la Fase 4.

**Justificación:** la empresa prioriza automatización. El riesgo se controla limitando el nivel de las herramientas, no posponiendo la IA.

## DEC-003 — Convención de idioma

- Códigos persistidos, columnas, enums y nombres técnicos: **inglés**, en `UPPER_SNAKE_CASE` para valores de enum.
- Interfaz, mensajes y etiquetas: **español**. Cada enum tiene su etiqueta en español definida en este archivo.
- Todos los valores de enum se escriben en mayúsculas, también en los contratos JSON de IA.

## DEC-004 — Fechas de vigencia

Todos los rangos de vigencia usan **`valid_from` / `valid_until`** (no `valid_to`, no `effective_to` en tablas nuevas). `valid_until` es inclusiva y `NULL` significa sin término.

## DEC-005 — Dinero, moneda, IVA y redondeo

- Almacenamiento: `DECIMAL(14,2)` para importes y `DECIMAL(14,4)` para precios unitarios y costos unitarios. Nunca `FLOAT`/`DOUBLE`.
- Moneda inicial: `CLP`. Escala de presentación y redondeo por moneda: **CLP = 0 decimales** (pesos enteros), **CLF (UF) = 4 decimales**, otras = 2.
- Redondeo: **half-up** (0,5 sube) a la escala de la moneda, aplicado mediante un servicio `Money` probado. No usar `bcadd(..., 2)` como redondeo, porque trunca.
- Totales de línea (`line_total`) se redondean a la escala de la moneda.
- **IVA:** el impuesto autoritativo es `quote_versions.tax_total = round(Σ neto de líneas gravadas × tasa)`, calculado a nivel de documento. `quote_items.tax_amount` es una asignación informativa por línea.
- `tax_rate` se guarda como **porcentaje** en `DECIMAL(7,4)` (19 % = `19.0000`).
- El tratamiento tributario de cada servicio (`TAXABLE`, `EXEMPT`, `NOT_DEFINED`) se configura en `service_prices.tax_treatment` y **debe validarlo el contador** antes de producción. Ningún servicio se activa con `NOT_DEFINED`.
- La API serializa importes como **strings** decimales (`"25000"`, `"25000.00"`).

## DEC-006 — Descuentos

- La API recibe `discount_percent` (0–100, `DECIMAL(7,4)`) por línea.
- El backend calcula y guarda `discount_amount`. Ambas columnas existen en `quote_items`.
- El límite de descuento sin aprobación es un parámetro de `companies.settings` (`quote.max_discount_percent_without_approval`). Pendiente de valor por Dirección.

## DEC-007 — Probabilidad de oportunidad

`probability_percent DECIMAL(5,2)` en escala 0–100.

---

## Enums canónicos

### DEC-010 — `OpportunityStage` (`opportunities.stage`)

| Código | Etiqueta | Terminal |
|---|---|---|
| `NEW` | Nueva | |
| `CONTACTED` | Contactada | |
| `QUALIFIED` | Calificada | |
| `QUOTE_IN_PREPARATION` | Cotización en preparación | |
| `QUOTED` | Cotizada | |
| `NEGOTIATION` | Negociación | |
| `WON` | Ganada | Sí |
| `LOST` | Perdida | Sí |
| `ON_HOLD` | Pausada | |

Valor por defecto: `NEW`. `LOST` y `ON_HOLD` exigen motivo. Reabrir `WON`/`LOST` requiere la acción explícita `reopen` con permiso y auditoría. Pasar a `WON` no crea orden automáticamente.

### DEC-011 — `QuoteStatus` (`quotes.status`)

| Código | Etiqueta |
|---|---|
| `DRAFT` | Borrador |
| `PENDING_APPROVAL` | Pendiente de revisión |
| `ISSUED` | Emitida |
| `SENT` | Enviada |
| `ACCEPTED` | Aceptada |
| `REJECTED` | Rechazada por el cliente |
| `EXPIRED` | Vencida |
| `CANCELLED` | Cancelada |

Transiciones:

| Desde | Acción (endpoint) | Hacia |
|---|---|---|
| `DRAFT` | `submit-for-approval` | `PENDING_APPROVAL` |
| `DRAFT` | `issue` (solo si no requiere aprobación) | `ISSUED` |
| `PENDING_APPROVAL` | `approve` (aprueba y emite la versión) | `ISSUED` |
| `PENDING_APPROVAL` | `reject` (rechazo interno, con motivo) | `DRAFT` |
| `ISSUED` | `mark-sent` | `SENT` |
| `ISSUED`, `SENT` | `record-customer-response` | `ACCEPTED` o `REJECTED` |
| `ISSUED`, `SENT` | proceso programado al pasar `valid_until` | `EXPIRED` |
| `ISSUED`, `SENT`, `REJECTED`, `EXPIRED` | `versions` (nueva versión) | `DRAFT` |
| `DRAFT`, `PENDING_APPROVAL`, `ISSUED`, `SENT` | `cancel` (con motivo) | `CANCELLED` |

- `ACCEPTED` y `CANCELLED` son terminales.
- Se elimina `SUPERSEDED`: las versiones anteriores quedan identificadas por `quotes.current_version_id` y su `issued_at` no nulo las vuelve inmutables.
- Solo una cotización `ACCEPTED` se convierte en orden.

### DEC-012 — `ServiceOrderStatus` (`service_orders.status`)

| Código | Etiqueta | Terminal |
|---|---|---|
| `DRAFT` | Borrador | |
| `PENDING_CONFIRMATION` | Pendiente de confirmación | |
| `CONFIRMED` | Confirmada | |
| `ASSIGNED` | Asignada | |
| `IN_PROGRESS` | En curso | |
| `COMPLETED` | Realizada | |
| `PENDING_DOCUMENTATION` | Pendiente de documentación | |
| `CLOSED` | Cerrada | Sí |
| `CANCELLED` | Cancelada | Sí |
| `NOT_PERFORMED` | No realizada | Sí |
| `ON_HOLD` | En pausa | |

Transiciones permitidas:

- `DRAFT` → `PENDING_CONFIRMATION`, `CANCELLED`
- `PENDING_CONFIRMATION` → `CONFIRMED`, `ON_HOLD`, `CANCELLED`
- `CONFIRMED` → `ASSIGNED`, `ON_HOLD`, `CANCELLED`
- `ASSIGNED` → `IN_PROGRESS`, `CONFIRMED` (liberar asignación), `ON_HOLD`, `CANCELLED`, `NOT_PERFORMED`
- `IN_PROGRESS` → `COMPLETED`, `NOT_PERFORMED`
- `COMPLETED` → `PENDING_DOCUMENTATION`, `CLOSED`
- `PENDING_DOCUMENTATION` → `CLOSED`
- `ON_HOLD` → `PENDING_CONFIRMATION`, `CONFIRMED`, `CANCELLED`

Motivo obligatorio para `CANCELLED`, `NOT_PERFORMED` y `ON_HOLD`. Pasar a `ASSIGNED` exige al menos una asignación válida. `COMPLETED` no implica factura ni pago.

### DEC-013 — `ServiceOrderItemStatus`

`PENDING`, `IN_PROGRESS`, `COMPLETED`, `NOT_PERFORMED`, `CANCELLED`.

### DEC-014 — `AppointmentStatus`

`TENTATIVE`, `SCHEDULED`, `CONFIRMED`, `IN_PROGRESS`, `COMPLETED`, `CANCELLED`, `NO_SHOW`, `RESCHEDULED`.  
Se elimina la columna `appointments.confirmation_status`: la confirmación se expresa con el estado `CONFIRMED` y `confirmed_at`.

### DEC-015 — `ApprovalStatus` (`approvals.status`)

`PENDING`, `APPROVED`, `REJECTED`, `EXPIRED`, `CANCELLED`, `EXECUTED`, `FAILED`.

`approvals` incorpora `risk_level` (DEC-020), `executed_at` y `execution_result_code`.

### DEC-016 — `AiRunStatus` (`ai_runs.status`)

`QUEUED`, `RUNNING`, `WAITING_APPROVAL`, `SUCCEEDED`, `PARTIAL`, `FAILED`, `CANCEL_REQUESTED`, `CANCELLED`, `TIMED_OUT`.

### DEC-017 — `AgentOutputStatus` (campo `status` de la salida de un agente)

`COMPLETED`, `NEEDS_REVIEW`, `BLOCKED`, `FAILED`. Es distinto del estado de la ejecución (`AiRunStatus`).

### DEC-018 — Otros enums compartidos

| Enum | Valores |
|---|---|
| `DeliveryMode` (`services.delivery_mode`) | `HOME` (domicilio), `ON_SITE` (en terreno: empresa, colegio, club), `FACILITY` (sede propia), `REMOTE` (telemedicina), `MIXED` |
| `BillingUnit` (`services.billing_unit`) | `SERVICE`, `PERSON`, `HOUR`, `SESSION`, `PACKAGE`, `OTHER` |
| `ServiceStatus` | `DRAFT`, `ACTIVE`, `INACTIVE`, `ARCHIVED` |
| `PartyType` | `PERSON`, `ORGANIZATION` |
| `PartyStatus` | `ACTIVE`, `INACTIVE`, `ARCHIVED` |
| `UserType` | `HUMAN`, `SERVICE` |
| `UserStatus` | `INVITED`, `ACTIVE`, `SUSPENDED`, `DISABLED` |
| `TaskStatus` | `OPEN`, `IN_PROGRESS`, `BLOCKED`, `DONE`, `CANCELLED` |
| `Priority` | `LOW`, `NORMAL`, `HIGH`, `URGENT` |
| `ActorType` | `HUMAN`, `AGENT`, `SYSTEM`, `INTEGRATION` |
| `AuditResult` | `SUCCESS`, `DENIED`, `FAILED` |

---

## DEC-019 — Nombres de campos compartidos

La base de datos (`DATABASE_SPEC.md`) define los nombres. La API usa **los mismos nombres**; las claves foráneas se exponen como `<nombre_fk_sin_id>_public_id`.

| Entidad | Campo canónico | Reemplaza a |
|---|---|---|
| `services` | `service_category_id` (API: `service_category_public_id`) | `category_id` |
| `services` | `delivery_mode` | `modality` |
| `services` | `billing_unit` | `default_unit` |
| `services` | `requires_professional` | `requires_qualified_professional` |
| `services` | `requires_clinical_workflow` | `is_clinical` |
| `services` | `metadata` | `operational_metadata` |
| `price_lists` | `code`, `customer_segment`, `valid_from`, `valid_until` | `valid_to` |
| `service_prices` | `unit_price DECIMAL(14,4)`, `valid_from`, `valid_until` | `amount`, `valid_to` |
| `opportunities` | `estimated_value`, `probability_percent`, `lost_reason`, `description`, `next_action_at`, `next_action_summary` | `estimated_amount`, `probability`, `loss_reason`, `notes`, `summary` |
| `opportunities`, `quotes` | `customer_party_id` (API: `customer_party_public_id`) | `party_public_id` |
| `quote_items` | `discount_percent` + `discount_amount` | solo uno de los dos |
| `appointments` | `start_at`, `end_at`, `party_address_id` (API: `party_address_public_id`) | `starts_at`, `ends_at`, `location_public_id` |

## DEC-020 — Niveles de riesgo de herramientas y acciones

| Nivel | Definición | Control |
|---|---|---|
| `R0` | Lectura de datos autorizados para el usuario | Permiso del recurso; auditoría según sensibilidad |
| `R1` | Borrador, análisis o cálculo sin modificar registros de negocio | Resultado marcado como borrador |
| `R2` | Cambio interno reversible (crear tarea, borrador de cotización, recordatorio interno, propuesta de asignación) | Permiso, auditoría, idempotencia; confirmación del usuario si la herramienta lo declara |
| `R3` | Efecto externo o compromiso (enviar comunicación, emitir documento comercial, confirmar cita con cliente) | Aprobación explícita persistida en `approvals` |
| `R4` | Alto impacto: pagos, eliminación irreversible, decisión clínica, acto legal, cambio de permisos privilegiados | Bloqueado para ejecución autónoma |

El nivel efectivo es el más restrictivo entre dato, herramienta e impacto. Una herramienta sin nivel declarado se rechaza. Se persiste como `R0`…`R4` en `risk_level`.

## DEC-021 — Proveedor de modelos

MVP: solo **Ollama local** (modelo inicial de la familia Qwen, versión exacta a fijar al instalar según el hardware disponible). Ningún dato sale del servidor de la empresa. Proveedores externos requieren decisión nueva.

## DEC-022 — Catálogo canónico de permisos (MVP)

Formato `dominio.recurso.accion`. Este es el conjunto que siembra `RolesAndPermissionsSeeder` en el MVP:

| Módulo | Permisos |
|---|---|
| Identidad | `users.read`, `users.manage`, `roles.read`, `roles.manage` |
| Dashboard | `dashboard.read` |
| CRM | `crm.customers.read`, `crm.customers.create`, `crm.customers.update`, `crm.customers.archive`, `crm.interactions.read`, `crm.interactions.create` |
| Catálogo | `catalog.services.read`, `catalog.services.manage`, `catalog.prices.manage` |
| Ventas | `sales.opportunities.read`, `sales.opportunities.create`, `sales.opportunities.update`, `sales.quotes.read`, `sales.quotes.create`, `sales.quotes.update_draft`, `sales.quotes.submit`, `sales.quotes.issue`, `sales.quotes.approve`, `sales.quotes.send`, `sales.quotes.accept`, `sales.quotes.cancel` |
| Operaciones | `operations.orders.read`, `operations.orders.create`, `operations.orders.update`, `operations.orders.transition`, `operations.orders.cancel`, `operations.appointments.read`, `operations.appointments.manage` |
| Tareas | `tasks.read`, `tasks.create`, `tasks.update` |
| Aprobaciones | `approvals.read`, `approvals.decide` |
| Documentos | `documents.read`, `documents.upload`, `documents.download`, `documents.manage` |
| IA | `ai.agents.read`, `ai.conversations.read`, `ai.conversations.create`, `ai.runs.read`, `ai.runs.create`, `ai.runs.cancel`, `ai.knowledge.read`, `ai.knowledge.manage`, `ai.automations.manage` |
| Auditoría | `audit.read`, `security.events.read` |

Se eliminan los nombres `sales.opportunities.manage`, `operations.orders.close` y `security.audit.read`. Los permisos de fases posteriores (finanzas, inventario, RR. HH., clínica, deporte) se agregan con su fase usando la lista de `API_SPEC.md` §6.2. `ai.tools.execute` no se asigna a humanos: las herramientas las invoca el orquestador.

## DEC-023 — Idempotencia y folios

- Tabla `idempotency_keys` (MVP-1): `company_id`, `user_id`, `action`, `idempotency_key`, `payload_hash`, `status` (`IN_PROGRESS`, `COMPLETED`, `FAILED`), `response_reference JSON`, `expires_at`, timestamps; `UNIQUE(company_id, action, idempotency_key)`.
- Tabla `document_sequences` (MVP-1) para folios internos, con bloqueo de fila.
- Una versión de cotización aceptada genera **una** orden de servicio en el MVP. Dividir en varias órdenes queda para una decisión posterior.

## DEC-024 — Errores corregidos en el código de ejemplo

| Error | Corrección |
|---|---|
| `CompanySeeder` creaba una empresa nueva en cada ejecución si faltaba `APP_PRIMARY_COMPANY_PUBLIC_ID` | Falla con mensaje claro si falta la variable |
| `QuoteCalculator` truncaba a 2 decimales | Usa servicio `Money` con half-up a la escala de la moneda (DEC-005) |
| `QuotePolicy` comparaba `status` como string | Compara con `UserStatus::Active` |
| `ConvertQuoteToServiceOrder` ignoraba `idempotencyKey` | Usa `idempotency_keys` (DEC-023) |
| Default `'LEAD'` en `opportunities.stage` | Default `NEW` |
| `CompanySeeder` usaba `firstOrCreate` con `public_id`, que no es fillable: se descartaba y se generaba un ULID nuevo | Asigna `public_id` explícitamente |
| Import `Illuminate\Authorization\Access\AuthorizationException` (no existe) | `Illuminate\Auth\Access\AuthorizationException` |
| `staff_profiles` y `appointment_assignments` fuera del MVP, aunque FR-OPS-002/003 exigen asignar profesionales y detectar conflictos | Incluidas en MVP-2 |

---

## Decisiones pendientes (no inventar valores)

| ID | Tema | Responsable |
|---|---|---|
| PEND-001 | Límite de descuento sin aprobación | Dirección |
| PEND-002 | Tratamiento de IVA por servicio (gravado/exento) | Contador |
| PEND-003 | Catálogo vigente de servicios, precios y duraciones | Dirección / Comercial |
| PEND-004 | Usuarios reales, roles y aprobadores (`AUTHORIZATION_MATRIX.md`) | Dirección |
| PEND-005 | Hardware del servidor de IA y modelo exacto | Desarrollo |
| PEND-006 | Plazos de retención por tipo de dato y adecuación a la Ley 21.719 | Asesoría legal |
| PEND-007 | Sistema de facturación electrónica actual y punto de integración | Administración |

---

## Historial

| Versión | Fecha | Cambios |
|---|---|---|
| 1.0.0 | 2026-10-15 | Creación. Unifica enums, campos, permisos y riesgos; fija alcance de una empresa (DEC-001) e IA en el MVP (DEC-002). |
