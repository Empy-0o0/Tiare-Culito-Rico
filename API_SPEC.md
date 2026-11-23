# INGENERIA JARVIS — API SPECIFICATION

**Proyecto:** INGENERIA JARVIS / ERGO JARVIS  
**Organización inicial:** Ergo SaniTas SpA  
**Documento:** Contrato de API para React + Vite + TypeScript, Laravel, MySQL y agentes IA  
**Versión:** 1.1.0  
**Estado:** Especificación propuesta; debe validarse contra las versiones y el código reales antes de implementar  
**Fecha:** 11 de octubre de 2026 (actualizada 15 de octubre de 2026)  
**Decisiones vinculantes:** `DECISIONS.md` (enums, nombres de campos, permisos y riesgos; prevalece ante contradicciones). Los campos JSON usan los mismos nombres que la base de datos; las FK se exponen como `<fk_sin_id>_public_id`.  
**Idioma de negocio:** Español  
**Zona horaria de negocio:** `America/Santiago`  
**Moneda predeterminada:** `CLP`  
**Documentos base:** `INGENERIA_JARVIS_MASTER_SPEC.md`, `PRODUCT_REQUIREMENTS.md`, `DATABASE_SPEC.md`, `LARAVEL_DATABASE_IMPLEMENTATION.md`

> Este documento define contratos de HTTP, no afirma que los endpoints estén implementados. Los recursos de fases futuras deben permanecer deshabilitados hasta completar su diseño, autorización, pruebas y aprobación. Los ejemplos utilizan datos ficticios.

---

## 1. Propósito

Definir un contrato versionado, seguro, consistente y comprobable entre el frontend y el backend de INGENERIA JARVIS. La API será la vía controlada para consultar y operar datos empresariales, y para solicitar tareas a los agentes de inteligencia artificial.

La API debe:

- separar presentación, reglas de negocio y persistencia;
- aplicar autenticación y autorización en cada operación protegida;
- mantener un alcance de compañía explícito, sin confiar en `company_id` enviado por el navegador;
- ofrecer respuestas, errores, paginación y validaciones consistentes;
- proteger datos personales, financieros, laborales y sanitarios;
- preservar la trazabilidad de operaciones relevantes;
- evitar duplicados causados por reintentos y manejar la concurrencia;
- permitir evolución mediante versiones compatibles;
- ofrecer contratos que puedan convertirse en documentación OpenAPI y pruebas automatizadas.

## 2. Alcance por fases

| Fase | Dominios habilitados | Regla |
|---|---|---|
| Fase 0 — MVP | MVP-1/2: identidad, empresa activa, dashboard básico, CRM, catálogo de servicios, oportunidades, cotizaciones, agenda, órdenes de servicio, documentos administrativos básicos, auditoría y aprobaciones. MVP-3: agentes, conversaciones, ejecuciones, pasos, herramientas R0–R2 y automatizaciones internas (§11) | Prioridad inicial de implementación; IA con Ollama local |
| Fase 1 — operación ampliada | Finanzas administrativas, cuentas por cobrar, proveedores, compras, inventario, equipos y reportes ampliados | Habilitar luego de validar el flujo comercial-operativo |
| Fase 2 — IA ampliada | Base de conocimiento (RAG), nuevos agentes y herramientas | Herramientas mediante backend; sin SQL arbitrario |
| Fase 3 — personas y cumplimiento | Perfiles de personal, turnos, capacitación, obligaciones, riesgos y evidencias | Permisos específicos por categoría de información |
| Fase 4 — clínica y rendimiento | Registros clínicos, documentación sanitaria, evaluaciones deportivas y mediciones | Deshabilitado hasta aprobar permisos, privacidad, retención, revisión profesional y protocolos |
| Fase 5 — integraciones y producción | Correo, calendarios, facturación electrónica, equipos, mensajería, proveedores externos y monitoreo ampliado | Solo después de probar proveedor, credenciales, tratamiento de errores y datos compartidos |

La presencia de una ruta en una lista de futuras capacidades no autoriza su disponibilidad inmediata. La API debe devolver un error controlado para módulos aún no habilitados y no filtrar si existen registros protegidos.

## 3. Arquitectura y límites

```text
React + Vite + TypeScript
        |
        | HTTPS / JSON (multipart solo para archivos)
        v
Laravel API /api/v1
  ├── Authentication & Authorization
  ├── Form Requests / Validation
  ├── Policies / Company Scope
  ├── Controllers / API Resources
  ├── Domain Services / Transactions
  ├── Audit Logger / Approval Workflow
  ├── Queue / Notifications / Scheduled Jobs
  └── AI Orchestrator / Typed Tool Registry
        |
        v
MySQL (InnoDB) + private object/file storage
        |
        v
Ollama/Qwen u otros proveedores aprobados mediante una capa de modelos
```

**Reglas arquitectónicas:**

1. El frontend no se conecta directamente a MySQL.
2. Los modelos de IA no reciben credenciales de base de datos ni ejecutan SQL libre.
3. Cada herramienta de agente llama un servicio Laravel tipado y con autorización propia.
4. Un controlador no debe contener cálculos complejos ni transiciones de negocio; delega en Services/Actions.
5. Todos los recursos devueltos se filtran por compañía y permisos a nivel de objeto.
6. El backend calcula los totales, precios finales, permisos y estados; no confía en los valores calculados por el navegador o por la IA.
7. La API nunca considera un ULID/UUID como prueba suficiente de autorización.

## 4. Convenciones HTTP

### 4.1 Base URL y versionado

- Base local de ejemplo: `http://localhost:8000/api/v1`.
- Producción: una URL HTTPS del dominio aprobado, por ejemplo `https://<dominio-aprobado>/api/v1`; no fijar un dominio de producción en el código hasta confirmar infraestructura.
- Todas las rutas de aplicación comienzan en `/api/v1`.
- Cambios incompatibles requieren una nueva versión mayor (`/api/v2`) y un plan de migración.
- Añadir campos opcionales y endpoints nuevos puede ser compatible si no cambia el significado de datos existentes.
- No devolver detalles del servidor, rutas locales, stack traces ni secretos en producción.

### 4.2 Métodos

| Método | Uso |
|---|---|
| `GET` | Lectura sin efectos secundarios |
| `POST` | Creación o comando/acción de dominio claramente identificado |
| `PUT` | Reemplazo completo solo cuando el contrato lo defina explícitamente |
| `PATCH` | Actualización parcial de campos permitidos |
| `DELETE` | Solicitud de eliminación/desactivación solo en recursos cuyo ciclo de vida lo permita; no usar para borrar auditoría ni registros con obligaciones de conservación |

Para acciones de dominio se prefieren rutas explícitas como `/submit-for-approval`, `/convert-to-order`, `/cancel` o `/transition`, en vez de permitir que el cliente cambie directamente un campo `status` sensible.

### 4.3 Encabezados

Solicitud habitual:

```http
Accept: application/json
Content-Type: application/json
X-Request-ID: 01J...        # opcional; el servidor también puede generarlo
Idempotency-Key: <uuid>     # obligatorio en comandos que puedan duplicar una operación
```

Respuestas de servidor:

```http
Content-Type: application/json
X-Request-ID: 01J...
Cache-Control: no-store     # identidad, datos personales, clínicos y respuestas sensibles
```

No confiar ciegamente en un `X-Request-ID` suministrado por el cliente: validar formato/longitud o reemplazarlo. Nunca colocar tokens, contraseñas o datos clínicos en encabezados de trazabilidad.

### 4.4 Formato de éxito

Respuesta de recurso individual:

```json
{
  "data": {
    "public_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M9",
    "display_name": "Cliente de ejemplo",
    "created_at": "2026-10-11T12:00:00Z",
    "updated_at": "2026-10-11T12:00:00Z"
  },
  "meta": {
    "request_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M9"
  }
}
```

Este envoltorio es el contrato recomendado de aplicación. Si se decide utilizar directamente la serialización estándar de Laravel, hacerlo de manera uniforme en todos los recursos y documentar el cambio antes de implementar el frontend.

### 4.5 Colecciones y paginación

```json
{
  "data": [
    { "public_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M9", "display_name": "Cliente de ejemplo" }
  ],
  "meta": {
    "request_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M9",
    "pagination": {
      "page": 1,
      "per_page": 25,
      "total": 1,
      "last_page": 1
    }
  },
  "links": {
    "first": "?page=1&per_page=25",
    "last": "?page=1&per_page=25",
    "prev": null,
    "next": null
  }
}
```

Reglas:

- `per_page` predeterminado: 25; máximo general: 100. Algunos listados sensibles o costosos tendrán un máximo inferior.
- Validar `page`, `per_page`, filtros y ordenamiento.
- `sort` debe compararse con una allowlist por endpoint; nunca pasar directamente nombres de columnas recibidos a SQL.
- En historiales voluminosos/eventos, preferir cursor pagination con orden determinista.
- El `total` puede omitirse si la consulta es costosa y el contrato de cursor así lo define.
- Los filtros no deben permitir ampliar el alcance de compañía.

### 4.6 Fechas, hora y moneda

- Las marcas de tiempo de API se representan en ISO 8601/RFC 3339 con zona u offset explícito.
- Al almacenar eventos técnicos, usar UTC; presentar tiempos con la zona de negocio `America/Santiago`.
- Para agenda, exigir timestamp con offset o un par fecha/hora/zona validado; nunca interpretar una fecha-hora local ambigua sin una zona definida.
- Las fechas sin hora usan `YYYY-MM-DD`.
- Importe monetario en CLP: número decimal JSON y código de moneda explícito cuando el contexto lo requiera. No usar coma decimal.
- La persistencia usa `DECIMAL`, y el backend debe definir redondeo y serialización estables.
- Los precios vigentes y las cotizaciones aceptadas conservan una instantánea de los importes aplicables en el momento correspondiente.

## 5. Autenticación y sesión

### 5.1 Estrategia recomendada para la SPA

Para React SPA servida en un dominio compatible con Laravel, utilizar Laravel Sanctum en modo cookie/sesión, protección CSRF y cookies seguras. Confirmar el despliegue final (dominio/subdominio, CORS, HTTPS y configuración `stateful`) antes de habilitar producción.

- La SPA solicita el endpoint CSRF de Sanctum que corresponda a la instalación (`/sanctum/csrf-cookie`) antes del login cuando el flujo lo requiera.
- El login se realiza contra el endpoint definido en esta especificación.
- El frontend no guarda tokens de sesión en `localStorage`.
- Enviar cookies con configuración segura y credenciales habilitadas únicamente al origen permitido.
- Verificar versión de Laravel/Sanctum y adaptar los middlewares a la instalación existente.

Para integraciones servidor-servidor, considerar tokens personales de Sanctum con abilities limitadas, propietario identificable, expiración y revocación. No mezclar sin necesidad el token bearer de integración con la sesión normal del navegador.

### 5.2 Endpoints de autenticación

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `POST` | `/auth/login` | Iniciar sesión | Público con rate limit |
| `POST` | `/auth/logout` | Cerrar sesión/revocar credencial actual | Autenticado |
| `GET` | `/auth/me` | Obtener identidad y permisos de la sesión | Autenticado |
| `POST` | `/auth/forgot-password` | Solicitar restablecimiento | Público con rate limit y respuesta no-enumerable |
| `POST` | `/auth/reset-password` | Restablecer contraseña con token válido | Público con rate limit |
| `POST` | `/auth/verify-email` | Confirmar correo según estrategia seleccionada | Autenticado o token de verificación |
| `POST` | `/auth/reauthenticate` | Revalidar identidad para una operación sensible | Autenticado; si se implementa |

Petición de login:

```json
{
  "email": "usuario@ejemplo.cl",
  "password": "<no incluir en logs>"
}
```

Respuesta: identidad mínima de usuario, compañía/contexto permitido y capacidades visibles. Nunca devolver el hash de contraseña, secretos, tokens CSRF ni atributos internos.

Ejemplo de `/auth/me`:

```json
{
  "data": {
    "public_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M9",
    "name": "Usuario de ejemplo",
    "email": "usuario@ejemplo.cl",
    "status": "ACTIVE",
    "company": {
      "public_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M8",
      "display_name": "Ergo SaniTas SpA"
    },
    "roles": ["ADMINISTRACION"],
    "permissions": ["crm.customers.read", "crm.customers.create"],
    "timezone": "America/Santiago",
    "locale": "es-CL"
  },
  "meta": { "request_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M9" }
}
```

Los nombres de roles/permisos son ejemplos. El backend debe devolver solo roles y permisos efectivos de la compañía/contexto actual.

### 5.3 Seguridad de autenticación

- Aplicar rate limit configurable por IP y por identidad de manera que no facilite ataques de enumeración.
- Responder con un mensaje genérico para correo no registrado en recuperación de contraseña.
- Invalidar/revocar sesiones cuando la cuenta se deshabilite o se sospeche compromiso.
- Requerir reautenticación/MFA para acciones de elevado impacto según política aprobada.
- Registrar resultado y contexto mínimo en bitácora sin guardar contraseña, token o cookie.
- La respuesta `401` no debe revelar si el correo existe.

## 6. Autorización y contexto de compañía

### 6.1 Reglas generales

1. Laravel autentica al actor y resuelve el contexto de compañía desde la sesión/token y las relaciones autorizadas.
2. `company_id`, `actor_user_id`, `approved_by`, `created_by`, `owner` y atributos equivalentes no son confiables por venir del body. El backend los establece o valida expresamente.
3. Toda operación aplica permiso de acción y Policy del recurso concreto.
4. Para relaciones, validar que tanto el recurso raíz como los recursos vinculados pertenecen al alcance autorizado.
5. Responder `404` cuando sea preferible no revelar la existencia de un objeto fuera del alcance; mantener una política consistente.
6. `SUPER_ADMIN` no debe implicar acceso clínico ilimitado sin necesidad, permiso, finalidad y auditoría adecuados.
7. Las cuentas de servicio/agentes deben usar permisos mínimos y herramientas explícitamente asignadas.

### 6.2 Convención de permisos

Formato `dominio.recurso.accion`. El catálogo canónico del MVP está en `DECISIONS.md` DEC-022:

- `users.read`, `users.manage`, `roles.read`, `roles.manage`
- `dashboard.read`
- `crm.customers.read`, `crm.customers.create`, `crm.customers.update`, `crm.customers.archive`
- `crm.interactions.read`, `crm.interactions.create`
- `catalog.services.read`, `catalog.services.manage`, `catalog.prices.manage`
- `sales.opportunities.read`, `sales.opportunities.create`, `sales.opportunities.update`
- `sales.quotes.read`, `sales.quotes.create`, `sales.quotes.update_draft`, `sales.quotes.submit`, `sales.quotes.issue`, `sales.quotes.approve`, `sales.quotes.send`, `sales.quotes.accept`, `sales.quotes.cancel`
- `operations.orders.read`, `operations.orders.create`, `operations.orders.update`, `operations.orders.transition`, `operations.orders.cancel`, `operations.appointments.read`, `operations.appointments.manage`
- `tasks.read`, `tasks.create`, `tasks.update`
- `documents.read`, `documents.upload`, `documents.download`, `documents.manage`
- `approvals.read`, `approvals.decide`
- `ai.agents.read`, `ai.conversations.read`, `ai.conversations.create`, `ai.runs.read`, `ai.runs.create`, `ai.runs.cancel`, `ai.knowledge.read`, `ai.knowledge.manage`, `ai.automations.manage`
- `audit.read`, `security.events.read`

Fases posteriores (se siembran con su fase):

- `finance.receivables.read`, `finance.transactions.read`, `finance.transactions.create`, `finance.reports.read`, `finance.reports.export`
- `procurement.*`, `inventory.items.read`, `inventory.items.manage`, `inventory.stock.read`, `inventory.movements.read`, `inventory.movements.create`, `inventory.adjustments.create`, `inventory.adjustments.approve`, `equipment.read`, `equipment.maintenance.manage`
- `hr.staff.read`, `hr.staff.manage`
- `clinical.records.read`, `clinical.records.create_draft`, `clinical.records.finalize`, `clinical.records.amend`
- `sports.assessments.read`, `sports.assessments.create`, `sports.assessments.finalize`

La existencia de permisos de fases futuras no habilita sus endpoints automáticamente.

## 7. Formato de error

Usar un formato de error común, inspirado en Problem Details, con códigos internos estables y mensajes seguros.

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Revisa los campos indicados.",
    "details": {
      "email": ["Ingresa un correo válido."]
    }
  },
  "meta": {
    "request_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M9"
  }
}
```

| HTTP | Código de ejemplo | Uso |
|---:|---|---|
| `400` | `BAD_REQUEST` | Petición malformada o comando inválido |
| `401` | `UNAUTHENTICATED` | Falta autenticación o sesión no válida |
| `403` | `FORBIDDEN` | Actor autenticado sin permiso |
| `404` | `RESOURCE_NOT_FOUND` | Recurso no encontrado o no visible en el alcance |
| `409` | `STATE_CONFLICT` | Conflicto de estado, versión o concurrencia |
| `409` | `IDEMPOTENCY_KEY_REUSED` | Misma clave de idempotencia usada con payload distinto |
| `413` | `PAYLOAD_TOO_LARGE` | Cuerpo o archivo excede límites |
| `415` | `UNSUPPORTED_MEDIA_TYPE` | Tipo de contenido no admitido |
| `419` | `CSRF_TOKEN_MISMATCH` | Token CSRF inválido cuando aplique la estrategia Laravel |
| `422` | `VALIDATION_FAILED` | Validación de campo o regla de dominio |
| `423` | `RESOURCE_LOCKED` | Recurso bloqueado por una operación o revisión, si se implementa |
| `429` | `RATE_LIMITED` | Límite de frecuencia excedido |
| `500` | `INTERNAL_ERROR` | Error inesperado; sin detalles internos |
| `503` | `SERVICE_UNAVAILABLE` | Dependencia temporalmente no disponible |

No exponer mensajes de SQL, paths internos, stack traces, variables de entorno ni prompts internos de agentes. Para solicitudes de negocio válidas pero no permitidas por transición, preferir `409` o `422` según si el problema es el estado actual o el contenido de la solicitud; documentar la elección por endpoint.

## 8. Filtros, búsquedas y ordenamiento

Parámetros comunes permitidos si el endpoint los soporta:

- `page`, `per_page`
- `q` para búsqueda de texto sanitizada y limitada
- `status`, `stage`, `type` mediante listas explícitas
- `created_from`, `created_to` con fechas ISO
- `sort`, `direction=asc|desc`
- filtros propios del módulo documentados por endpoint

Reglas:

- longitud máxima para `q` (por ejemplo, 120 caracteres);
- allowlist de campos filtrables y de ordenamiento;
- máximo de rango temporal de acuerdo con coste/permiso;
- no permitir filtros genéricos contra columnas sensibles;
- no revelar detalles de búsqueda clínica desde una búsqueda global;
- exportaciones masivas requieren permiso explícito, límites, auditoría y, cuando corresponda, aprobación.

## 9. Catálogo de endpoints — Fase 0 (MVP)

Los endpoints siguientes son el contrato objetivo propuesto. Las acciones no deben habilitarse hasta implementar Policies, Form Requests, Services, auditoría y pruebas asociadas.

### 9.1 Dashboard

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/dashboard/summary` | Resumen de ventas/operación permitido al usuario | `dashboard.read` |
| `GET` | `/dashboard/activity` | Actividad reciente filtrada por alcance y permisos | `dashboard.read` |
| `GET` | `/dashboard/tasks` | Tareas y pendientes del usuario | `dashboard.read` |

El dashboard debe informar fecha de corte, zona horaria y definición de KPI. No debe mezclar facturación efectiva con cotizaciones, cobros recibidos o proyecciones.

### 9.2 CRM: partes, clientes, organizaciones y contactos

La entidad `parties` es la referencia central para personas y organizaciones. La API puede usar el término amigable `customers` en la interfaz, pero el modelo y los recursos deben seguir un vocabulario estable y evitar entidades duplicadas para un mismo cliente.

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/parties` | Listar partes autorizadas | `crm.customers.read` |
| `POST` | `/parties` | Crear persona u organización | `crm.customers.create` |
| `GET` | `/parties/{public_id}` | Detalle visible de una parte | `crm.customers.read` |
| `PATCH` | `/parties/{public_id}` | Actualizar campos permitidos | `crm.customers.update` |
| `POST` | `/parties/{public_id}/archive` | Archivar sin borrar historia | `crm.customers.archive` |
| `GET` | `/parties/{public_id}/addresses` | Direcciones autorizadas | `crm.customers.read` |
| `POST` | `/parties/{public_id}/addresses` | Crear dirección | `crm.customers.update` |
| `PATCH` | `/parties/{public_id}/addresses/{address_id}` | Actualizar dirección permitida | `crm.customers.update` |
| `GET` | `/parties/{public_id}/interactions` | Historial comercial permitido | `crm.interactions.read` |
| `POST` | `/parties/{public_id}/interactions` | Registrar interacción | `crm.interactions.create` |

Ejemplo de creación:

```json
{
  "party_type": "ORGANIZATION",
  "display_name": "Club Deportivo Ejemplo",
  "legal_name": "Club Deportivo Ejemplo SpA",
  "email": "contacto@ejemplo.cl",
  "phone": "+56 9 0000 0000",
  "website": "https://ejemplo.cl",
  "notes": "Contacto comercial inicial"
}
```

Reglas:

- `party_type` debe pertenecer al enum implementado, por ejemplo `PERSON` u `ORGANIZATION`.
- `company_id`, claves internas, campos de hash/cifrado y campos de auditoría no se aceptan desde el cuerpo público.
- `notes` debe tener límites, no debe utilizarse como depósito informal de datos clínicos ni secretos.
- El RUT/identificador tributario, cuando se gestione, tendrá campos y permisos específicos; no incluirlo por defecto en listados.
- El archivo/descarga de documentos se gestiona por endpoints privados de documentos, no por una URL pública libre.
- Evitar hard delete de clientes con operaciones relacionadas; utilizar archivo o un flujo de eliminación/restricción aprobado.

### 9.3 Catálogo de servicios, categorías y precios

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/service-categories` | Listar categorías activas | `catalog.services.read` |
| `POST` | `/service-categories` | Crear categoría | `catalog.services.manage` |
| `PATCH` | `/service-categories/{public_id}` | Modificar categoría | `catalog.services.manage` |
| `GET` | `/services` | Listar servicios y filtros permitidos | `catalog.services.read` |
| `POST` | `/services` | Crear servicio como borrador/inactivo | `catalog.services.manage` |
| `GET` | `/services/{public_id}` | Detalle de servicio | `catalog.services.read` |
| `PATCH` | `/services/{public_id}` | Modificar campos habilitados | `catalog.services.manage` |
| `POST` | `/services/{public_id}/activate` | Activar tras validaciones | `catalog.services.manage` |
| `POST` | `/services/{public_id}/deactivate` | Desactivar evitando nuevas ventas | `catalog.services.manage` |
| `GET` | `/price-lists` | Consultar listas de precio autorizadas | `catalog.services.read` |
| `POST` | `/price-lists` | Crear lista de precios | `catalog.prices.manage` |
| `GET` | `/price-lists/{public_id}/prices` | Listar precios de la lista | `catalog.services.read` |
| `PUT` | `/price-lists/{public_id}/prices/{service_public_id}` | Crear nueva vigencia o actualizar según política | `catalog.prices.manage` |

Ejemplo de servicio:

```json
{
  "code": "EVAL-CARD-DEP",
  "name": "Chequeo cardiovascular deportivo",
  "description": "Evaluación de acuerdo con el protocolo aprobado.",
  "service_category_public_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M1",
  "delivery_mode": "HOME",
  "billing_unit": "PERSON",
  "default_duration_minutes": 45,
  "requires_professional": true,
  "requires_clinical_workflow": false
}
```

El servicio se crea siempre en `DRAFT`; `status` no se acepta en el cuerpo (§20.1) y se cambia con `/activate` o `/deactivate`.

Ejemplo de precio:

```json
{
  "unit_price": "25000",
  "tax_treatment": "EXEMPT",
  "tax_rate": null,
  "valid_from": "2026-10-11",
  "valid_until": null
}
```

La moneda la define la lista de precios. Los importes se serializan como strings decimales en toda la API (`DECISIONS.md` DEC-005); en CLP los totales calculados no tienen decimales. `tax_treatment` debe estar validado por el contador; un servicio con `NOT_DEFINED` no puede activarse.

### 9.4 Oportunidades comerciales

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/opportunities` | Listar oportunidades | `sales.opportunities.read` |
| `POST` | `/opportunities` | Crear oportunidad | `sales.opportunities.create` |
| `GET` | `/opportunities/{public_id}` | Ver oportunidad | `sales.opportunities.read` |
| `PATCH` | `/opportunities/{public_id}` | Editar campos permitidos | `sales.opportunities.update` |
| `POST` | `/opportunities/{public_id}/activities` | Registrar llamada, reunión o seguimiento | `crm.interactions.create` o permiso específico |
| `POST` | `/opportunities/{public_id}/stage-transitions` | Solicitar transición validada de etapa | `sales.opportunities.update` |
| `POST` | `/opportunities/{public_id}/close` | Cerrar ganada/perdida con razón | `sales.opportunities.update` |

Ejemplo de creación:

```json
{
  "customer_party_public_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M2",
  "title": "Programa de chequeos para empresa",
  "description": "Solicitud de cotización para trabajadores",
  "source": "REFERRAL",
  "estimated_value": "450000",
  "currency": "CLP",
  "probability_percent": "40.00",
  "expected_close_date": "2026-11-15",
  "next_action_at": "2026-10-15T13:00:00-03:00",
  "next_action_summary": "Llamar a RR. HH. para confirmar dotación"
}
```

La oportunidad se crea en etapa `NEW` (`DECISIONS.md` DEC-010). El servidor determinará propietario y compañía desde la identidad o validará una asignación autorizada. La etapa solo cambia mediante `/stage-transitions` o `/close`; `LOST` y `ON_HOLD` exigen `lost_reason`. Una oportunidad cerrada no se reabre mediante un `PATCH` genérico; requerirá una transición autorizada con trazabilidad.

### 9.5 Cotizaciones

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/quotes` | Listar cotizaciones | `sales.quotes.read` |
| `POST` | `/quotes` | Crear borrador | `sales.quotes.create` |
| `GET` | `/quotes/{public_id}` | Ver cotización y versión visible | `sales.quotes.read` |
| `PATCH` | `/quotes/{public_id}` | Modificar metadatos del borrador | `sales.quotes.update_draft` |
| `POST` | `/quotes/{public_id}/versions` | Crear nueva versión de borrador | `sales.quotes.update_draft` |
| `POST` | `/quotes/{public_id}/submit-for-approval` | `DRAFT` → `PENDING_APPROVAL` | `sales.quotes.submit` |
| `POST` | `/quotes/{public_id}/issue` | `DRAFT` → `ISSUED`, solo si no requiere aprobación (si la requiere: `409`) | `sales.quotes.issue` |
| `POST` | `/quotes/{public_id}/approve` | `PENDING_APPROVAL` → `ISSUED` (aprueba y emite la versión; el aprobador no puede ser quien la preparó) | `sales.quotes.approve` |
| `POST` | `/quotes/{public_id}/reject` | Rechazo interno con motivo: `PENDING_APPROVAL` → `DRAFT` | `sales.quotes.approve` |
| `POST` | `/quotes/{public_id}/mark-sent` | `ISSUED` → `SENT`, envío confirmado por usuario/sistema | `sales.quotes.send` |
| `POST` | `/quotes/{public_id}/record-customer-response` | `ISSUED`/`SENT` → `ACCEPTED` o `REJECTED` | `sales.quotes.accept` |
| `POST` | `/quotes/{public_id}/cancel` | Cancelar con motivo | `sales.quotes.cancel` |
| `POST` | `/quotes/{public_id}/convert-to-order` | Convertir versión aceptada en orden de servicio | `operations.orders.create` |

Estados y transiciones: `DECISIONS.md` DEC-011. `POST /versions` sobre una cotización `ISSUED`, `SENT`, `REJECTED` o `EXPIRED` crea una nueva versión y la devuelve a `DRAFT`.

Ejemplo de borrador:

```json
{
  "customer_party_public_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M2",
  "opportunity_public_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M3",
  "currency": "CLP",
  "valid_until": "2026-11-10",
  "notes_for_customer": "Propuesta sujeta a confirmación de disponibilidad.",
  "items": [
    {
      "service_public_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M4",
      "description": "Chequeo cardiovascular deportivo",
      "quantity": "10.000",
      "discount_percent": "5.0000"
    }
  ]
}
```

`unit_price` no se envía: el backend lo toma de la lista de precios vigente. `discount_percent` (0–100) es la entrada; el backend calcula `discount_amount`, `line_total`, `tax_total` y `grand_total` según DEC-005 y DEC-006, y exige aprobación si el descuento supera el límite configurado.

Reglas obligatorias:

- El backend verifica precio, descuento máximo, moneda, impuestos aplicables y permisos.
- Si un cliente envía `unit_price` u otros importes, el servidor responde `422`: los precios salen del catálogo.
- Los totales se calculan en servidor y se almacenan con una versión inmutable una vez emitida/aceptada según reglas del dominio.
- La aprobación debe vincularse a una versión concreta y a su `payload_hash`; cambios posteriores requieren nueva versión y nueva aprobación cuando corresponda.
- El folio se genera mediante servicio concurrentemente seguro, nunca con `MAX(id) + 1`.
- `convert-to-order` requiere aceptación válida, autorización y `Idempotency-Key`; los reintentos no deben producir dos órdenes.
- No afirmar que se envió un correo si no hay integración probada o confirmación real de envío.

### 9.6 Órdenes de servicio

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/service-orders` | Listar órdenes | `operations.orders.read` |
| `POST` | `/service-orders` | Crear orden autorizada, según reglas comerciales | `operations.orders.create` |
| `GET` | `/service-orders/{public_id}` | Ver orden | `operations.orders.read` |
| `PATCH` | `/service-orders/{public_id}` | Actualizar campos editables en estado permitido | `operations.orders.update` |
| `POST` | `/service-orders/{public_id}/transitions` | Cambiar estado con transición validada | `operations.orders.transition` |
| `GET` | `/service-orders/{public_id}/events` | Consultar eventos operativos permitidos | `operations.orders.read` |
| `POST` | `/service-orders/{public_id}/events` | Registrar un evento/incidencia | `operations.orders.update` |
| `POST` | `/service-orders/{public_id}/cancel` | Solicitar/registrar cancelación autorizada | `operations.orders.cancel` |

Ejemplo de transición:

```json
{
  "to_status": "CONFIRMED",
  "reason": "Agenda confirmada con el cliente",
  "expected_updated_at": "2026-10-11T12:00:00Z"
}
```

`to_status` debe ser un destino permitido desde el estado actual según `DECISIONS.md` DEC-012; `reason` es obligatorio para `CANCELLED`, `NOT_PERFORMED` y `ON_HOLD`. `expected_updated_at` o un ETag/versión equivalente puede utilizarse para detectar modificaciones concurrentes. No se permite cambiar cualquier estado escribiendo `status` directamente.

### 9.7 Agenda y disponibilidad

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/appointments` | Listar citas autorizadas por periodo y filtros | `operations.appointments.read` |
| `POST` | `/appointments` | Reservar una cita | `operations.appointments.manage` |
| `GET` | `/appointments/{public_id}` | Detalle de cita | `operations.appointments.read` |
| `PATCH` | `/appointments/{public_id}` | Actualizar campos permitidos | `operations.appointments.manage` |
| `POST` | `/appointments/{public_id}/reschedule` | Reprogramar cita con validación de conflicto | `operations.appointments.manage` |
| `POST` | `/appointments/{public_id}/cancel` | Cancelar cita con razón | `operations.appointments.manage` |
| `GET` | `/availability` | Consultar disponibilidad dentro del alcance autorizado | `operations.appointments.read` |

Consulta de disponibilidad:

```http
GET /api/v1/availability?start=2026-10-16T09:00:00-03:00&end=2026-10-16T18:00:00-03:00&service_public_id=01JAZ1Q9K3A3W6N0P2F6D7H8M4
```

Creación de cita:

```json
{
  "service_order_public_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M5",
  "start_at": "2026-10-16T10:00:00-03:00",
  "end_at": "2026-10-16T10:45:00-03:00",
  "location_type": "HOME",
  "party_address_public_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M6",
  "staff_profile_public_ids": ["01JAZ1Q9K3A3W6N0P2F6D7H8MA"]
}
```

La cita se crea en `SCHEDULED` (estados en `DECISIONS.md` DEC-014).

Reglas:

- El servidor recalcula duración esperada a partir del servicio/configuración vigente cuando aplique.
- La disponibilidad se revalida durante la escritura; una consulta anterior no garantiza una reserva.
- Aplicar transacción y estrategia contra colisiones/concurrencia.
- La zona horaria del usuario debe ser explícita y transformada adecuadamente a UTC para persistencia.
- Las vistas logísticas mostrarán solo los datos del domicilio necesarios para ejecutar la visita; no exponer datos clínicos en la agenda general.

### 9.8 Tareas, aprobaciones y notificaciones

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/tasks` | Listar tareas asignadas/visibles | `tasks.read` o permiso equivalente |
| `POST` | `/tasks` | Crear tarea | `tasks.create` |
| `GET` | `/tasks/{public_id}` | Ver tarea | `tasks.read` |
| `PATCH` | `/tasks/{public_id}` | Actualizar campos autorizados | `tasks.update` |
| `POST` | `/tasks/{public_id}/complete` | Completar tarea | `tasks.update` |
| `GET` | `/approvals` | Listar aprobaciones dentro del alcance | `approvals.read` |
| `GET` | `/approvals/{public_id}` | Ver detalle y resumen de la acción propuesta | `approvals.read` |
| `POST` | `/approvals/{public_id}/approve` | Aprobar un payload exacto | `approvals.decide` |
| `POST` | `/approvals/{public_id}/reject` | Rechazar con motivo | `approvals.decide` |
| `GET` | `/notifications` | Listar notificaciones del usuario | Usuario autenticado |
| `POST` | `/notifications/{public_id}/read` | Marcar notificación como leída | Usuario destinatario |

Ejemplo de decisión:

```json
{
  "decision_reason": "Revisado y autorizado según el procedimiento interno."
}
```

Una aprobación no es una autorización universal ni indefinida. Debe expirar según política, vincularse al hash del payload revisado, comprobar el estado del recurso en ejecución y rechazar si el contenido cambió. La acción aprobada se ejecuta mediante un servicio de dominio separado y auditable.

### 9.9 Documentos

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/documents` | Listar metadatos de documentos permitidos | `documents.read` |
| `POST` | `/documents` | Subir archivo por `multipart/form-data` | `documents.upload` |
| `GET` | `/documents/{public_id}` | Consultar metadatos | `documents.read` |
| `GET` | `/documents/{public_id}/download` | Descargar archivo privado autorizado | `documents.download` |
| `POST` | `/documents/{public_id}/versions` | Cargar nueva versión | `documents.manage` |
| `POST` | `/documents/{public_id}/archive` | Archivar según política de retención | `documents.manage` |

Metadatos mínimos: `public_id`, `name`, `category`, `mime_type`, `size_bytes`, `version`, `uploaded_at`, `uploaded_by` visible según permiso. No retornar la ruta física de almacenamiento ni una URL pública permanente.

Controles de archivo:

- allowlist de extensiones y MIME; verificar contenido real, no solo extensión;
- límite de tamaño por tipo y endpoint;
- nombre generado por servidor;
- almacenamiento privado, autorización de descarga y antivirus/escaneo si está disponible;
- rechazar ejecutables, scripts y formatos no autorizados;
- no insertar archivos o su texto en un agente sin validar clasificación y acceso;
- auditar las descargas sensibles.

## 10. Endpoints — Fase 1: finanzas, compras e inventario

Estos endpoints quedan sujetos a la aprobación del diseño financiero, contable y de inventario correspondiente.

### 10.1 Finanzas administrativas

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/finance/receivables` | Listar cuentas por cobrar | `finance.receivables.read` |
| `GET` | `/finance/receivables/{public_id}` | Detalle de cuenta por cobrar | `finance.receivables.read` |
| `POST` | `/finance/receivables/{public_id}/record-payment` | Registrar pago recibido, sujeto al procedimiento aprobado | `finance.transactions.create` |
| `GET` | `/finance/transactions` | Consultar movimientos autorizados | `finance.transactions.read` |
| `POST` | `/finance/transactions` | Registrar movimiento administrativo controlado | `finance.transactions.create` |
| `GET` | `/finance/reports/cash-flow` | Reporte de flujo de caja, con fecha de corte | `finance.reports.read` |
| `GET` | `/finance/reports/service-margin` | Margen por servicio según costos disponibles | `finance.reports.read` |

Reglas: separar transacción registrada, factura emitida, pago confirmado y proyección. Registrar un pago no llama automáticamente al banco. Una reversa debe generar un asiento/evento correctivo trazable; no borrar el movimiento original. Validar el modelo con el responsable contable antes de uso real. No declarar integración con SII ni emisión de DTE hasta completar una integración real y pruebas autorizadas.

### 10.2 Proveedores y compras

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/suppliers` | Listar proveedores | `procurement.suppliers.read` |
| `POST` | `/suppliers` | Crear proveedor | `procurement.suppliers.manage` |
| `GET` | `/purchase-requests` | Listar solicitudes de compra | `procurement.requests.read` |
| `POST` | `/purchase-requests` | Crear solicitud de compra | `procurement.requests.create` |
| `POST` | `/purchase-requests/{public_id}/submit` | Enviar solicitud a aprobación | `procurement.requests.submit` |
| `POST` | `/purchase-requests/{public_id}/approve` | Aprobar solicitud, sin que esto implique pago | `procurement.requests.approve` |
| `GET` | `/purchase-orders` | Listar órdenes de compra internas | `procurement.orders.read` |
| `POST` | `/purchase-orders` | Crear orden de compra conforme al flujo autorizado | `procurement.orders.create` |

No confundir una orden interna con una orden emitida en Mercado Público ni afirmar que un contrato se ha perfeccionado sin el procedimiento y formalidades correspondientes. Si se integra con plataformas públicas, utilizar exclusivamente los procedimientos y APIs autorizados y documentar la fuente de verdad de cada estado.

### 10.3 Inventario y equipos

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/inventory/items` | Consultar catálogo de insumos | `inventory.items.read` |
| `POST` | `/inventory/items` | Crear ítem | `inventory.items.manage` |
| `GET` | `/inventory/stock` | Consultar stock por ubicación/lote | `inventory.stock.read` |
| `GET` | `/inventory/movements` | Consultar movimientos | `inventory.movements.read` |
| `POST` | `/inventory/movements` | Registrar movimiento validado | `inventory.movements.create` |
| `POST` | `/inventory/adjustments` | Solicitar ajuste de inventario | `inventory.adjustments.create` |
| `POST` | `/inventory/adjustments/{public_id}/approve` | Aprobar ajuste separado del solicitante cuando la política lo requiera | `inventory.adjustments.approve` |
| `GET` | `/equipment` | Consultar activos/equipos | `equipment.read` |
| `POST` | `/equipment/{public_id}/maintenance-events` | Registrar mantención | `equipment.maintenance.manage` |

No aceptar edición directa de `stock_quantity` como operación ordinaria. El stock es la proyección de movimientos autorizados; movimientos y ajustes deben estar trazados y protegidos contra duplicación.

## 11. Endpoints — Fase 0 (MVP-3): agentes y orquestación IA

### 11.1 Principios de diseño

- En el MVP solo existen los agentes de `DECISIONS.md` DEC-002, el proveedor es Ollama local y las herramientas ejecutables son R0–R2. Una herramienta R3 produce una aprobación (`approvals`) y una tarea para que una persona ejecute la acción; nunca un efecto externo automático.
- `/ai/knowledge-sources` pertenece a la Fase 2 y responde `FEATURE_NOT_ENABLED` en el MVP.
- La interfaz usa endpoints de conversación/tarea; las herramientas de agente son invocaciones internas autorizadas.
- El navegador no puede indicar libremente cualquier agente, modelo, proveedor, herramienta o rol. Solo puede seleccionar opciones publicadas y permitidas para ese usuario.
- Cada ejecución mantiene usuario solicitante, compañía, objetivo, agentes invocados, estado, consumos/métricas disponibles y eventos de auditoría sin guardar secretos ni contenido clínico innecesario.
- El backend construye contexto con los datos mínimos autorizados y aplica controles contra instrucciones maliciosas presentes en documentos o entradas externas.
- Se recomienda inicialmente polling controlado; SSE/WebSocket es una mejora opcional posterior.

### 11.2 Endpoints

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/ai/agents` | Listar agentes habilitados para el usuario | `ai.agents.read` |
| `GET` | `/ai/agents/{agent_code}` | Ver descripción/capacidades publicadas | `ai.agents.read` |
| `GET` | `/ai/conversations` | Listar conversaciones propias/compartidas autorizadas | `ai.conversations.read` |
| `POST` | `/ai/conversations` | Crear conversación | `ai.conversations.create` |
| `GET` | `/ai/conversations/{public_id}` | Consultar conversación | `ai.conversations.read` |
| `GET` | `/ai/conversations/{public_id}/messages` | Consultar mensajes visibles | `ai.conversations.read` |
| `POST` | `/ai/conversations/{public_id}/messages` | Enviar solicitud al orquestador | `ai.runs.create` |
| `POST` | `/ai/runs` | Crear ejecución sin conversación persistente, si el producto la permite | `ai.runs.create` |
| `GET` | `/ai/runs/{public_id}` | Consultar estado y resumen de ejecución | `ai.runs.read` |
| `GET` | `/ai/runs/{public_id}/steps` | Consultar pasos permitidos de ejecución | `ai.runs.read` |
| `POST` | `/ai/runs/{public_id}/cancel` | Solicitar cancelación si el estado permite | `ai.runs.cancel` |
| `GET` | `/ai/runs/{public_id}/approvals` | Ver aprobaciones asociadas | `approvals.read` |
| `GET` | `/ai/automations` | Listar automatizaciones internas y su último resultado | `ai.automations.manage` |
| `POST` | `/ai/automations` | Crear automatización en `DRAFT` (acciones R0–R2 del catálogo) | `ai.automations.manage` |
| `POST` | `/ai/automations/{public_id}/activate` | Activar versión aprobada | `ai.automations.manage` |
| `POST` | `/ai/automations/{public_id}/pause` | Pausar sin desplegar código (FR-AUTO-002) | `ai.automations.manage` |
| `GET` | `/ai/automations/{public_id}/runs` | Historial de ejecuciones | `ai.automations.manage` |
| `GET` | `/ai/knowledge-sources` | Listar fuentes accesibles (Fase 2) | `ai.knowledge.read` |
| `POST` | `/ai/knowledge-sources` | Registrar fuente para ingestión controlada (Fase 2) | `ai.knowledge.manage` |

### 11.3 Crear conversación

```json
{
  "title": "Análisis de ventas del mes",
  "agent_code": "ORCHESTRATOR",
  "context": {
    "module": "DASHBOARD",
    "date_from": "2026-10-01",
    "date_to": "2026-10-31"
  }
}
```

El servidor valida que el agente está habilitado y deriva el contexto permitido. No aceptar desde el cliente `system_prompt`, lista arbitraria de herramientas, credenciales, contexto privado de otro usuario o `company_id` libre.

### 11.4 Enviar mensaje/crear ejecución

```json
{
  "content": "Compara las cotizaciones creadas este mes y resume los principales pendientes.",
  "attachments": [],
  "response_mode": "POLLING"
}
```

Respuesta inicial sugerida: `202 Accepted` con identificador de ejecución y estado `QUEUED` o `RUNNING`.

```json
{
  "data": {
    "run_public_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M7",
    "status": "QUEUED",
    "status_url": "/api/v1/ai/runs/01JAZ1Q9K3A3W6N0P2F6D7H8M7"
  },
  "meta": { "request_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M9" }
}
```

Estados de la ejecución (`DECISIONS.md` DEC-016): `QUEUED`, `RUNNING`, `WAITING_APPROVAL`, `SUCCEEDED`, `PARTIAL`, `FAILED`, `CANCEL_REQUESTED`, `CANCELLED`, `TIMED_OUT`.

### 11.5 Formato de salida de agente

Contrato alineado con `PRODUCT_REQUIREMENTS.md` §7.4; `status` usa `AgentOutputStatus` (DEC-017), distinto del estado de la ejecución:

```json
{
  "agent_id": "SALES_CRM",
  "run_id": "01JAZ1Q9K3A3W6N0P2F6D7H8M7",
  "status": "COMPLETED",
  "summary": "Se identificaron 8 cotizaciones nuevas y 3 pendientes de seguimiento.",
  "result": {
    "findings": [
      {
        "type": "OBSERVATION",
        "text": "Tres oportunidades no tienen próxima acción registrada.",
        "source_refs": ["01JAZ1Q9K3A3W6N0P2F6D7H8M3"]
      }
    ]
  },
  "sources": ["01JAZ1Q9K3A3W6N0P2F6D7H8M3"],
  "assumptions": ["Periodo: 1 al 15 de octubre de 2026, zona America/Santiago."],
  "warnings": [],
  "proposed_actions": [
    {
      "tool": "tasks.create",
      "risk_level": "R2",
      "requires_approval": false,
      "description": "Crear 3 tareas de seguimiento para el responsable comercial"
    }
  ],
  "approval_required": false
}
```

No se garantiza que todo agente emita exactamente esta estructura hasta que se implemente un validador de salida; este contrato sirve como objetivo. Validar cada salida del modelo en backend antes de presentarla como dato estructurado o convertirla en una acción.

### 11.6 Invocaciones de herramientas

No exponer un endpoint público general como `POST /ai/tools/execute` que acepte cualquier nombre de herramienta y argumentos. Las invocaciones de herramienta deben salir del orquestador interno, que valida:

1. agente y versión habilitados;
2. usuario y compañía dueños de la ejecución;
3. herramienta incluida en allowlist;
4. permiso del usuario para el dominio/acción;
5. autorización de los recursos concretos;
6. esquema JSON y límites de argumentos;
7. necesidad y vigencia de aprobación humana;
8. idempotencia cuando la operación lo requiere;
9. registro de solicitud, resultado y redacción de datos sensibles.

Los agentes no podrán ejecutar shell, SQL libre, comandos de despliegue, pagos, envío masivo, eliminación irreversible o modificaciones clínicas finales sin mecanismos adicionales de seguridad y aprobación.

## 12. Endpoints — Fase 3: personas y cumplimiento

Deshabilitados hasta aprobar el modelo de permisos y confidencialidad de cada tipo de documento.

| Método | Ruta | Descripción prevista | Permiso sugerido |
|---|---|---|---|
| `GET` | `/hr/staff` | Consultar perfiles de personal | `hr.staff.read` |
| `POST` | `/hr/staff` | Crear perfil | `hr.staff.manage` |
| `GET` | `/hr/availability` | Consultar disponibilidad autorizada | `hr.availability.read` |
| `POST` | `/hr/training-records` | Registrar capacitación | `hr.training.manage` |
| `GET` | `/compliance/obligations` | Consultar obligaciones | `compliance.obligations.read` |
| `POST` | `/compliance/obligations` | Registrar obligación/evidencia | `compliance.obligations.manage` |
| `GET` | `/compliance/risks` | Consultar matriz de riesgos | `compliance.risks.read` |
| `POST` | `/compliance/risks` | Registrar/actualizar riesgo | `compliance.risks.manage` |

Estos endpoints no sustituyen las autorizaciones legales ni profesionales necesarias. Evitar exponer antecedentes de salud ocupacional a roles que solo necesiten información administrativa.

## 13. Endpoints — Fase 4: gestión clínica y medicina deportiva

**Estado predeterminado: deshabilitado.** Antes de activar este dominio se requiere revisión específica de acceso, base legal/finalidad, consentimiento cuando aplique, roles profesionales, retención, trazabilidad, protocolos, gestión de incidentes y límites de uso de IA.

Rutas conceptuales reservadas:

| Método | Ruta prevista | Uso limitado |
|---|---|---|
| `GET` | `/clinical/records` | Consultar registros dentro de una relación/perfil autorizado |
| `POST` | `/clinical/records` | Crear borrador clínico, no final |
| `GET` | `/clinical/records/{public_id}` | Consultar registro autorizado |
| `PATCH` | `/clinical/records/{public_id}` | Modificar borrador permitido |
| `POST` | `/clinical/records/{public_id}/finalize` | Finalización profesional autenticada y auditada |
| `POST` | `/clinical/records/{public_id}/amendments` | Enmienda trazable; no sobrescribir historia finalizada |
| `GET` | `/sports/athletes` | Consultar atletas autorizados |
| `POST` | `/sports/assessment-sessions` | Crear sesión de evaluación |
| `POST` | `/sports/assessment-sessions/{public_id}/measurements` | Registrar mediciones validadas |
| `POST` | `/sports/assessment-sessions/{public_id}/finalize` | Finalizar con revisión profesional según protocolo |

Mientras esté deshabilitado, responder con error controlado como `FEATURE_NOT_ENABLED` o no exponer la ruta, de acuerdo con la política de versionado. No mostrar información que permita inferir la existencia de un registro clínico. El agente `CLINICAL_DOCUMENTATION` solo puede preparar borradores o revisar integridad; no diagnosticar, prescribir ni emitir informes clínicos finales de forma autónoma.

## 14. Administración, usuarios, roles y auditoría

| Método | Ruta | Descripción | Permiso |
|---|---|---|---|
| `GET` | `/users` | Listar usuarios visibles de la compañía | `users.read` |
| `POST` | `/users` | Invitar/crear usuario | `users.manage` |
| `GET` | `/users/{public_id}` | Consultar perfil administrativo mínimo | `users.read` |
| `PATCH` | `/users/{public_id}` | Actualizar campos autorizados | `users.manage` |
| `POST` | `/users/{public_id}/suspend` | Suspender cuenta | `users.manage` |
| `POST` | `/users/{public_id}/restore` | Restaurar cuenta, con controles | `users.manage` |
| `GET` | `/roles` | Listar roles visibles | `roles.read` |
| `GET` | `/permissions` | Consultar catálogo de permisos | `roles.read` |
| `PUT` | `/users/{public_id}/roles` | Asignar roles en contexto autorizado | `roles.manage` |
| `GET` | `/audit-logs` | Consultar bitácora filtrada | `audit.read` |
| `GET` | `/security-events` | Consultar eventos de seguridad | `security.events.read` |

Seguridad:

- impedir que un usuario se autoasigne permisos privilegiados;
- exigir reautenticación/aprobación independiente para cambios críticos cuando la política lo defina;
- registrar quién asignó o revocó cada rol;
- conservar la separación entre roles y permisos efectivos;
- proteger los endpoints contra elevación horizontal/vertical de privilegios;
- filtrar los campos de perfil mediante Resource explícito.

## 15. Integraciones externas

Estas interfaces se definirán individualmente cuando se elijan proveedores y credenciales. No se deben simular como integraciones activas.

| Integración posible | Contrato/API futuro | Controles necesarios |
|---|---|---|
| Correo transaccional | Servicio interno de envío y callbacks verificados | Consentimiento/base de contacto, límites, reintentos, idempotencia, estado de entrega |
| Calendario | Adaptador de lectura/escritura de eventos | OAuth/secretos, zona horaria, sincronización, cancelaciones y conflictos |
| Facturación electrónica/DTE | Adaptador del proveedor validado | Cumplimiento tributario, folios, estados, reversas y pruebas autorizadas |
| Mercado Público/ChileCompra | Adaptador solo si la API/acceso y finalidad lo permiten | Credenciales, reglas aplicables y trazabilidad; distinguir gestión interna de actos oficiales |
| WhatsApp/SMS | Adaptador oficial del proveedor elegido | Consentimiento, plantillas/canales autorizados, bajas y límites de envío |
| Equipos clínicos | Adaptador por dispositivo/protocolo documentado | Validación de unidades, calibración, integridad y asociación correcta al paciente |
| Ollama/proveedor externo de IA | `ModelProvider` interno | Timeout, límites, privacidad, redacción de datos, costes y modo degradado |

Cada integración define: propietario funcional, ambiente, autenticación, scopes, timeout, reintentos, idempotencia, formato de errores, observabilidad, origen de verdad, datos compartidos y plan de desconexión.

## 16. Idempotencia y concurrencia

### 16.1 Idempotencia

Usar `Idempotency-Key` en operaciones potencialmente duplicables, como crear una orden, convertir una cotización, registrar un pago, crear movimiento de inventario o lanzar una acción externa. La clave se vincula al usuario/compañía, endpoint y hash del payload.

- Si llega otra vez la misma clave con el mismo payload, devolver la respuesta de la operación original cuando la política lo permita.
- Si se reutiliza la clave con otro payload, responder `409 IDEMPOTENCY_KEY_REUSED`.
- La solución debe persistir el estado de la clave con protección contra concurrencia; un cache efímero por sí solo puede no ser suficiente para comandos financieros.
- No considerar idempotencia de transporte como sustituto de una transacción consistente.

### 16.2 Control de versiones

Para editar recursos compartidos, utilizar `updated_at`, un campo `version` o `ETag`/`If-Match`. Si el estado cambió desde que el cliente lo leyó, responder `409 STATE_CONFLICT` con instrucciones seguras para refrescar. No sobrescribir silenciosamente una cotización aprobada o una cita modificada por otro usuario.

### 16.3 Transacciones

Los servicios Laravel deben coordinar dentro de transacciones:

- crear cotización, versión e ítems;
- aceptar cotización y convertirla en orden;
- reservar/reprogramar cita y validar la disponibilidad;
- registrar movimiento de inventario y actualizar su proyección;
- registrar pago y actualización relacionada de una cuenta por cobrar;
- ejecutar una acción aprobada y marcar la aprobación usada, cuando aplique.

Las llamadas externas no pueden garantizar atomicidad con MySQL: utilizar outbox/cola y estados de procesamiento donde corresponda.

## 17. Límites de uso, carga y protección contra abuso

Definir límites configurables por usuario, endpoint, tamaño de payload y costo de operación. Valores iniciales que deben medirse y ajustarse:

- listados: `per_page` máximo 100;
- búsqueda `q`: máximo 120 caracteres;
- JSON común: máximo predeterminado 1 MiB;
- adjuntos: límite por endpoint definido por tipo de documento y almacenamiento;
- solicitudes de IA: límites de longitud, frecuencia, contexto, tiempo, concurrencia y consumo;
- exportaciones: límites de filas/tamaño, jobs en segundo plano y permisos separados;
- recuperación/login: rate limit más estricto;
- polling de ejecución IA: intervalo mínimo recomendado y límite de frecuencia.

No hacer que el frontend pueda elegir arbitrariamente parámetros que causen consultas no acotadas o ejecución de modelos demasiado costosa.

## 18. Seguridad, privacidad y auditoría

Requisitos obligatorios:

- HTTPS en toda conexión pública.
- `APP_DEBUG=false` en producción y manejo uniforme de excepciones.
- secretos fuera de Git y de los logs; rotación documentada.
- CORS allowlist y cookies de sesión seguras.
- CSRF conforme al mecanismo de autenticación.
- Validación por Form Request y autorización mediante Policy/servicio.
- Rate limiting, límites de payload y protección de archivos.
- acceso mínimo a base de datos; la cuenta runtime no debe administrar el esquema.
- registros append-only de acciones sensibles, con redacción de datos privados.
- almacenamiento privado de archivos y descargas autorizadas.
- exportaciones y reportes con controles equivalentes al dato fuente.
- minimización y separación de datos empresariales, laborales y sanitarios.
- copias de seguridad cifradas y simulacros de restauración.
- gestión de incidentes y revocación de sesiones/credenciales.
- pruebas negativas de acceso horizontal/vertical entre usuarios y recursos.

### 18.1 Auditoría mínima

Registrar cuando proceda: actor (`HUMAN`, `AGENT`, `SYSTEM`, `INTEGRATION`), compañía, acción normalizada, recurso, resultado, `request_id`, momento UTC y resumen redactado. Para agentes, registrar código/versión, herramienta, resultado y aprobación asociada sin volcar el prompt completo ni datos clínicos innecesarios.

No registrar: contraseñas, cookies, tokens, secretos de API, URLs firmadas de larga duración, resultados clínicos completos o texto libre sensible no necesario.

### 18.2 Protección de la información sanitaria

- El módulo clínico debe estar apagado por defecto hasta aprobar el diseño de acceso y las obligaciones aplicables.
- Las autorizaciones consideran profesional, función, relación con el caso y finalidad, no solo un rol general.
- Los datos clínicos no se incluyen en resúmenes comerciales, eventos generales ni prompts globales.
- Para IA externa, no transmitir datos identificables sin revisión específica, autorización y controles apropiados.
- Un informe o registro final requiere acción autenticada del profesional responsable y trazabilidad de enmiendas.

### 18.3 Cumplimiento chileno

Antes del despliegue, comprobar con fuentes oficiales y asesoría competente las obligaciones aplicables a servicios sanitarios, datos personales/sensibles, documentación, relaciones laborales, contabilidad y tributación. La API habilita controles técnicos, pero no certifica por sí sola el cumplimiento jurídico.

## 19. Observabilidad y salud del servicio

Rutas recomendadas para infraestructura:

- `GET /up`: endpoint básico de vida del framework si está habilitado por la versión de Laravel.
- Endpoint de readiness interno/protegido, si se implementa, para verificar dependencias necesarias sin exponer hostnames, versiones, rutas o credenciales.
- No hacer públicos los detalles de configuración MySQL, Ollama, cola, proveedor de correo o excepciones.

Registrar métricas agregadas: latencia por ruta, tasa de error por código, volumen, saturación de workers/cola, tiempos de IA y fallos de integración. Agregar alertas sin incluir datos personales en las etiquetas o nombres de métricas.

Cada solicitud debe tener un `request_id` correlacionable entre logs HTTP, Jobs, acciones de servicios y ejecución de agentes.

## 20. Contratos de validación y recursos Laravel

Implementación esperada por endpoint:

1. `FormRequest`: tipos, formatos, longitudes, listas válidas y reglas de entrada.
2. `Policy`: permiso de acción y acceso al recurso.
3. `Controller`: transporte HTTP delgado.
4. `Service`/`Action`: reglas de dominio y transacciones.
5. `API Resource`: lista explícita de campos que pueden exponerse.
6. `AuditLogger`: evento de resultado relevante con redacción.
7. Pruebas: éxito, validación, permiso denegado, acceso a otro tenant, concurrencia y regresión.

Nunca retornar directamente un modelo Eloquent sin Resource cuando contenga columnas internas o campos potencialmente sensibles.

### 20.1 Campos no aceptados de forma directa en solicitudes comunes

Salvo endpoints administrativos específicamente autorizados, rechazar o ignorar de forma documentada:

- `id`, `company_id` y claves foráneas internas;
- `created_by`, `updated_by`, `approved_by` y actor efectivo;
- permisos, roles o alcance de compañía;
- folios/números de cotización u orden generados por el backend;
- `status` de recursos con máquina de estados;
- totales, impuestos, margen/costo calculado y montos derivados;
- hash, campos cifrados, marcas de auditoría y `deleted_at`;
- resultados de auditoría o salidas supuestamente validadas de IA.

La lista puede variar por endpoint si se documenta explícitamente; nunca aplicar un mass assignment abierto (`Model::create($request->all())`).

## 21. OpenAPI y generación de cliente

Mantener una definición OpenAPI como artefacto versionado una vez se inicie el código. La especificación debe describir:

- servidores local, test y producción sin secretos;
- esquemas reutilizables de éxito, paginación, error, usuario, compañía y recursos;
- parámetros, ejemplos, permisos requeridos, códigos de error y comportamientos de idempotencia;
- endpoints por fase y estados de disponibilidad;
- seguridad de sesión/cookie y tokens de integración si corresponde;
- restricciones de campos sensibles y datos no retornados.

Estructura de archivos sugerida:

```text
docs/
  api/
    openapi.yaml
    schemas/
      common.yaml
      errors.yaml
      crm.yaml
      catalog.yaml
      sales.yaml
      operations.yaml
      ai.yaml
    examples/
      login.json
      party-create.json
      quote-create.json
```

No mantener a mano dos contratos contradictorios. La API documentada, los tests del backend y los tipos TypeScript del frontend deben derivarse de la misma definición o verificarse automáticamente entre sí.

## 22. Pruebas y criterios de aceptación

### 22.1 Pruebas funcionales

- autenticación correcta/incorrecta y recuperación de acceso;
- CRUD autorizado de clientes y catálogo;
- paginación, filtros y ordenamiento allowlist;
- cálculo de cotización en servidor;
- versiones de cotización y aprobaciones ligadas a payload;
- conversión idempotente de cotización aceptada a orden;
- transición de órdenes con estados permitidos;
- creación/reprogramación de cita con prueba de colisión concurrente;
- permisos correctos para cada rol inicial;
- uso consistente de formato de éxito y error.

### 22.2 Pruebas negativas de seguridad

- usuario de compañía A intenta consultar/modificar un recurso de compañía B;
- usuario intenta cambiar `company_id`, `owner_user_id`, `created_by`, rol o aprobación desde el body;
- usuario comercial intenta consultar registros financieros o clínicos no autorizados;
- usuario no autorizado intenta aprobar su propia acción cuando la segregación de funciones lo prohíbe;
- agente intenta llamar herramienta que no está en allowlist o ampliar argumentos;
- descarga de documento privado usando ULID conocido sin permiso;
- payload demasiado grande, tipo de archivo falso, rate limit y token/sesión revocados;
- errores de backend no filtran stack traces, SQL ni datos confidenciales.

### 22.3 Pruebas de integridad

- clave foránea de otro recurso/compañía se rechaza;
- los importes y totales se calculan con precisión definida;
- folios únicos bajo concurrencia;
- dos reintentos con la misma `Idempotency-Key` no duplican operaciones;
- una clave de idempotencia con payload diferente devuelve conflicto;
- un objeto aprobado que cambia después exige nueva aprobación cuando corresponda;
- auditoría registra las acciones sensibles y no contiene datos secretos.

### 22.4 Pruebas de contrato frontend/backend

- los nombres de campos de TypeScript coinciden con los Resources de Laravel;
- estados/enums son idénticos entre contrato, validación y frontend;
- manejo uniforme de `401`, `403`, `404`, `409`, `422` y `429`;
- fechas se muestran en `America/Santiago` y no cambian accidentalmente de día;
- pantallas no asumen permisos que el backend no haya confirmado;
- componentes muestran estados de carga, vacío, error y reintento sin duplicar comandos no idempotentes.

## 23. Matriz inicial de recursos y autorización

Matriz orientativa; aprobar y desarrollar con `AUTHORIZATION_MATRIX.md` antes de dar acceso real.

| Dominio | Lectura | Crear | Modificar | Acción crítica |
|---|---|---|---|---|
| Dashboard | Según rol y agregación | — | — | Exportar según permiso separado |
| CRM | Comercial/administración autorizada | Comercial/administración | Responsable autorizado | Archivar con validación |
| Catálogo | Roles autorizados | Administración habilitada | Administración habilitada | Activar precios/servicios según aprobación |
| Oportunidades | Comercial/dirección según alcance | Comercial | Comercial propietario o rol elevado | Cierre con razón |
| Cotizaciones | Comercial/administración autorizados | Comercial | Borrador solamente | Aprobar/enviar/aceptar/conversión con permisos y trazabilidad |
| Agenda/órdenes | Operaciones y roles autorizados | Operaciones | Campos según estado | Reprogramar/cancelar/cerrar con validación |
| Finanzas | Finanzas/dirección según necesidad | Rol financiero específico | Estados financieros protegidos | Pago/reversa bajo control reforzado |
| Inventario | Inventario/operaciones autorizadas | Inventario | Movimientos controlados | Ajuste aprobado según segregación |
| RR. HH. | RR. HH. y acceso mínimo | RR. HH. | Campos según confidencialidad | Decisiones de personal siempre humanas |
| Clínica | Profesional autorizado por finalidad/relación | Borrador autorizado | Borrador o enmienda trazable | Finalización por profesional responsable |
| IA | Dueño/compartidos explícitos | Usuarios autorizados | Propietario o gestor autorizado | Herramienta según riesgo y aprobación |
| Auditoría | Auditor/seguridad autorizado | Generada por servicios | Sin edición ordinaria | Retención/consulta restringidas |

## 24. Secuencia de implementación recomendada

1. Verificar versiones y autenticación elegida en el repositorio Laravel real.
2. Crear `API_SPEC.md` versionado en el proyecto y confirmar nombres de tablas/campos con `DATABASE_SPEC.md` y migraciones.
3. Implementar contrato común: respuesta, errores, request ID, paginación, validación y rate limits.
4. Implementar autenticación, `/auth/me`, permisos y políticas de compañía.
5. Implementar recursos de CRM, catálogo, oportunidades y cotizaciones con pruebas.
6. Implementar órdenes, transiciones, disponibilidad y agenda con transacciones e idempotencia.
7. Incorporar aprobaciones y documentos privados.
8. Implementar OpenAPI y validar el contrato con frontend y tests.
9. Añadir el orquestador y los agentes del MVP-3 con herramientas tipadas R0–R2, aprobación para R3 y límites.
10. Habilitar finanzas e inventario solo después de aprobar modelos y controles del dominio.
11. Mantener clínica y medicina deportiva deshabilitadas hasta revisión específica.
12. Ejecutar auditoría de seguridad, pruebas de recuperación y aceptación de usuarios antes de producción.

## 25. Checklist de salida del MVP

- [ ] Rutas bajo `/api/v1` y contrato de errores uniforme.
- [ ] Autenticación y CSRF probados con la arquitectura SPA real.
- [ ] Permisos y Policies en backend, no solo ocultamiento de UI.
- [ ] Aislamiento entre compañías y recursos verificado con pruebas negativas.
- [ ] CRM, catálogo, oportunidades, cotizaciones, agenda y órdenes alineados con la base de datos.
- [ ] Totales/precios/folios generados y validados en backend.
- [ ] Estados de dominio modificables solo a través de comandos permitidos.
- [ ] Idempotencia y concurrencia probadas para operaciones que duplican datos.
- [ ] Archivos almacenados de forma privada y descargas autorizadas.
- [ ] Auditoría útil sin secretos ni datos sensibles innecesarios.
- [ ] Endpoints de IA del MVP-3 limitados a herramientas R0–R2 y a Ollama local; clínica, finanzas, inventario y base de conocimiento no habilitados antes de su fase aprobada.
- [ ] OpenAPI, pruebas y tipos frontend actualizados.
- [ ] Variables de entorno y secretos fuera del repositorio.
- [ ] Respaldos y restauración comprobados antes de producción.
- [ ] Revisión funcional y de seguridad completada con datos sintéticos.

---

## 26. Decisiones pendientes antes de implementación

Registrar cada decisión con responsable, fecha y justificación. No resolver silenciosamente estos puntos:

1. Versión de Laravel/PHP y autenticación existente en el proyecto real.
2. Si Sanctum SPA es compatible con el dominio/subdominio donde vivirá React.
3. Política de email único global o por compañía y futura gestión de múltiples empresas.
4. ~~Enums definitivos y máquina de estados~~ — resuelto en `DECISIONS.md` DEC-010 a DEC-018.
5. ~~Política de CLP~~ — resuelto en DEC-005 (`DECIMAL`, CLP sin decimales, half-up); falta validación contable del IVA (PEND-002).
6. Reglas de descuentos, aprobación comercial y vigencia de precios.
7. Política de idempotencia y almacenamiento persistente de claves.
8. Catálogo de permisos y segregación de funciones en `AUTHORIZATION_MATRIX.md`.
9. Proveedor de correo/calendario y si se necesita integración en el MVP.
10. Modelo financiero/tributario revisado por responsable competente.
11. Política de documentos, retención y almacenamiento privado.
12. Revisión de privacidad y protocolos antes de habilitar módulos clínicos/IA con datos sensibles.

**Regla de cierre:** esta especificación es la fuente propuesta del contrato HTTP. Cualquier cambio debe actualizar este documento, los Requests/Resources/Policies de Laravel, los tipos TypeScript, el OpenAPI y las pruebas de contrato dentro de la misma tarea de desarrollo.
