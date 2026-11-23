# INGENERIA JARVIS — LARAVEL DATABASE IMPLEMENTATION

**Proyecto:** INGENERIA JARVIS para Ergo SaniTas SpA  
**Versión:** 1.1.0  
**Fecha:** 2026-10-11 (actualizada 2026-10-15)  
**Estado:** Especificación de implementación y base de código inicial; requiere ejecución y pruebas en el repositorio real antes de considerarse implementado.  
**Documentos de referencia:** `DECISIONS.md` (prevalece), `INGENERIA_JARVIS_MASTER_SPEC.md`, `PRODUCT_REQUIREMENTS.md`, `DATABASE_SPEC.md`.

---

## 0. Propósito, alcance y reglas de implementación

Este documento transforma el diseño conceptual de `DATABASE_SPEC.md` en una guía de implementación para Laravel: migraciones, modelos Eloquent, enums, relaciones, Policies, Services/Actions, transacciones, seeders, factories, pruebas y comandos de verificación.

### 0.1 Alcance de esta versión

La primera entrega ejecutable prioriza **Fase 0 / MVP**, en tres incrementos (`DECISIONS.md` DEC-002):

**MVP-1 núcleo**

1. Empresa (`companies`).
2. Usuarios, roles y permisos (`users`, `roles`, `permissions`, `role_user`, `permission_role`).
3. Auditoría (`audit_logs`).
4. Aprobaciones, idempotencia y folios (`approvals`, `idempotency_keys`, `document_sequences`).

**MVP-2 comercial-operativo**

5. CRM base (`parties`, `party_addresses`, `party_roles`, `interactions`).
6. Catálogo de servicios y tarifas (`service_categories`, `services`, `price_lists`, `service_prices`, `service_cost_components`).
7. Oportunidades y actividades comerciales (`opportunities`, `opportunity_activities`).
8. Cotizaciones versionadas (`quotes`, `quote_versions`, `quote_items`).
9. Operación (`staff_profiles`, `staff_availability`, `service_orders`, `service_order_items`, `appointments`, `appointment_assignments`, `service_events`, `tasks`). Sin perfiles y asignaciones no se puede cumplir FR-OPS-002/003 (asignar profesionales y detectar conflictos).
10. Documentos administrativos (`documents`, `document_versions`, `document_relations`) para los PDF de cotizaciones.

**MVP-3 IA y automatización interna**

11. Agentes y ejecución (`ai_model_profiles`, `ai_agents`, `ai_agent_versions`, `ai_conversations`, `ai_messages`, `ai_runs`, `ai_run_steps`, `ai_tool_invocations`, `prompt_templates`).
12. Automatización interna (`automation_workflows`, `automation_runs`, `scheduled_jobs`).

`party_relationships`, `customer_consents`, `company_locations`, `service_requirements`, `staff_skills` y otros módulos se agregan en migraciones siguientes según la secuencia de `DATABASE_SPEC.md`. En esta versión se documenta el patrón de implementación de esos dominios, pero no se debe asumir que ya existen tablas o rutas en la aplicación.

Finanzas, inventario, documentación avanzada, RR. HH., cumplimiento, clínica, medicina deportiva, base de conocimiento (RAG) e integraciones externas son fases posteriores. La especificación completa de sus entidades permanece en `DATABASE_SPEC.md`; su activación funcional debe depender de una decisión de fase, permisos y pruebas de seguridad.

### 0.2 Supuestos técnicos que deben verificarse

Antes de aplicar código, revisar las versiones reales en el proyecto:

```bash
php -v
composer show laravel/framework
composer show laravel/sanctum
php artisan about
php artisan migrate:status
mysql --version
```

Base asumida para los ejemplos: PHP 8.2+, Laravel moderno compatible con enum casts, MySQL 8.0.16+ e InnoDB. **No actualizar versiones ni instalar paquetes a ciegas.** Adaptar sintaxis si el repositorio utiliza otra versión. Elegir explícitamente autenticación (por ejemplo, Sanctum para SPA/API) y no implementar dos estrategias en paralelo sin necesidad.

### 0.3 Principios no negociables

- MySQL con InnoDB y `utf8mb4`.
- PK interna `BIGINT UNSIGNED`; `public_id` ULID (`CHAR(26)`) en recursos expuestos por API.
- Dinero como `DECIMAL`, nunca `FLOAT`/`DOUBLE`.
- Las entradas del navegador o un modelo de IA no son autoridad para importes, estados, compañía o permisos.
- Las operaciones multi-registro usan transacciones.
- Los números de cotización/orden deben ser concurrentemente seguros; nunca generar con `MAX(id) + 1`.
- Prohibido usar SQL concatenado con valores externos.
- Prohibido guardar credenciales, tokens, contenido clínico íntegro o datos sensibles en logs generales.
- Los registros aceptados, órdenes cerradas, pagos, movimientos de inventario y registros clínicos finalizados no deben borrarse silenciosamente.
- Toda lectura o escritura debe autorizarse sobre el recurso concreto, no solo por conocer su ID.
- La IA solo utiliza herramientas de una lista permitida, implementadas por backend.
- Las migraciones deben probarse en entorno local/test. No ejecutar `migrate:fresh` en producción.

---

## 1. Estructura objetivo del backend

Estructura recomendada, adaptable a la arquitectura Laravel existente:

```text
app/
├── Enums/
│   ├── UserStatus.php
│   ├── UserType.php
│   ├── PartyType.php
│   ├── PartyStatus.php
│   ├── ServiceStatus.php
│   ├── OpportunityStage.php
│   ├── QuoteStatus.php
│   ├── ServiceOrderStatus.php
│   ├── AppointmentStatus.php
│   ├── ApprovalStatus.php
│   ├── AiRunStatus.php
│   ├── RiskLevel.php
│   └── AuditResult.php
├── Http/
│   ├── Controllers/Api/V1/
│   ├── Requests/
│   └── Resources/
├── Models/
├── Policies/
├── Services/
│   ├── Audit/AuditLogger.php
│   ├── Sales/QuoteCalculator.php
│   ├── Sales/QuoteService.php
│   ├── Operations/ConvertQuoteToServiceOrder.php
│   ├── Operations/AppointmentService.php
│   ├── Numbers/BusinessNumberGenerator.php
│   ├── Support/Money.php
│   ├── Support/IdempotencyService.php
│   └── AI/                        # Orchestrator, ToolRegistry, ModelProvider (Ollama)
├── Actions/                     # opcional si se prefiere Action classes
├── Jobs/
├── Events/
├── Listeners/
└── Support/
    └── CompanyContext.php

database/
├── factories/
├── migrations/
└── seeders/
tests/
├── Feature/
│   ├── Authorization/
│   ├── Sales/
│   └── Operations/
└── Unit/
```

Mantener la lógica de negocio en Services/Actions. Los controladores deben autenticar, validar Request, autorizar, invocar el servicio y devolver un Resource; no deben contener reglas complejas de estado o cálculos financieros.

---

## 2. Convenciones de migración

### 2.1 Reglas generales

- Una migración cronológica por tabla o cambio lógico pequeño.
- Nombrar con `YYYY_MM_DD_HHMMSS_create_<tabla>_table.php`.
- Definir nombres explícitos para índices y claves únicas cuando su longitud pueda ser problemática.
- Crear primero tablas referenciadas y después tablas dependientes.
- No crear una FK circular en la primera migración: agregarla en una migración posterior cuando ambas tablas existan.
- Agregar `down()` seguro y en orden inverso.
- Para datos comerciales históricos, preferir snapshots inmutables a depender de la descripción/precio del catálogo actual.
- Aplicar `restrictOnDelete()` o `nullOnDelete()` cuidadosamente; no cascadas para datos históricos o financieros.
- No usar `cascadeOnDelete()` en órdenes, cotizaciones emitidas, eventos, auditoría, pagos o datos clínicos.

### 2.2 Tipos y campos

- `public_id`: ULID generado desde la aplicación o evento de modelo probado. Evitar exponer `id` cuando el recurso sea público.
- `created_by`/`updated_by`: FK opcional a `users` en entidades donde la autoría sea necesaria. Manejar borrado/retirada de una cuenta sin destruir historial.
- `created_at`/`updated_at`: `timestamps()` de Laravel, salvo tablas append-only donde se usa timestamp de creación.
- Instantes de negocio: almacenar con una política UTC uniforme; convertir a `America/Santiago` al presentar y al interpretar entradas locales.
- `json`: solo para snapshots/configuración definida y validada; no debe convertirse en un cajón de sastre.

### 2.3 ULID

En Eloquent moderno, usar el trait `HasUlids` solo si la PK real de la tabla es ULID. En este diseño la PK es `BIGINT`, por tanto `public_id` es una columna independiente y se genera así:

```php
use Illuminate\Support\Str;

protected static function booted(): void
{
    static::creating(function (self $model): void {
        if (blank($model->public_id)) {
            $model->public_id = (string) Str::ulid();
        }
    });
}
```

Repita el patrón mediante un trait propio `HasPublicId` si lo implementa para varias entidades y compruebe que funciona en factories y bulk inserts. Los ULID no sustituyen las Policies ni el control de acceso.

---

## 3. Orden de migraciones

Respetar el orden de dependencias y el orden completo de `DATABASE_SPEC.md`:

### Fase 0 — núcleo/MVP

1. `companies`
2. `users`
3. `roles`, `permissions`, `role_user`, `permission_role`
4. `audit_logs` y, si se habilita, `security_events`, `notifications`
5. `approvals` (sin FK a `ai_runs`), `idempotency_keys`, `document_sequences`
6. `parties`, `party_addresses`, `party_roles`
7. `service_categories`, `services`, `price_lists`, `service_prices`, `service_cost_components`
8. `opportunities`, `opportunity_activities`
9. `quotes`, `quote_versions`, `quote_items`
10. `staff_profiles`, `staff_availability`, `service_orders`, `service_order_items`, `appointments`, `appointment_assignments`, `service_events`, `tasks`, `documents`, `document_versions`, `document_relations`
11. `ai_model_profiles`, `ai_agents`, `ai_agent_versions`, `prompt_templates`, `ai_conversations`, `ai_messages`, `ai_runs`, `ai_run_steps`, `ai_tool_invocations`
12. `automation_workflows`, `automation_runs`, `scheduled_jobs`
13. Índices/FK diferidas: `quotes.current_version_id`; `approvals.requested_by_agent_run_id`; `interactions.opportunity_id` y otras relaciones circulares si se introducen.

Las columnas de las tablas de los pasos 5, 10 (personal, asignaciones y documentos), 11 y 12 son las de `DATABASE_SPEC.md` §5.7, §19.1, §9, §12 y §16. En el MVP no se admiten documentos con `sensitivity = CLINICAL`. `appointment_assignments.equipment_asset_id` queda sin FK hasta la Fase 1.

### Fases posteriores

14. `party_relationships`, `customer_consents`, `company_locations` y `service_requirements` según dependencias.
15. `staff_skills`, perfiles de cargo y capacitación.
16. `document_templates` y `document_approvals`; luego FK desde otros dominios hacia `documents`.
17. Finanzas: categorías, transacciones, cuentas por cobrar/pagar, pagos, asignaciones, presupuestos y proyección.
18. Proveedores/solicitudes de compra, inventario, lotes, movimientos, reservas y activos.
19. Obligaciones de cumplimiento, riesgos y acciones correctivas.
20. Datos clínicos restringidos; solo después de aprobación específica de seguridad y responsable clínico.
21. Medicina deportiva, evaluaciones, mediciones, dispositivos e informes.
22. Base de conocimiento (`knowledge_documents`, `knowledge_chunks`).
23. Integraciones, outbox e infraestructura operativa.
24. Migraciones finales para FK cíclicas, índices compuestos y restricciones pendientes.

Una tabla futura puede definirse en documentación antes de desplegarse. No habilitar automáticamente rutas ni agentes por el hecho de que exista un esquema.

---

## 4. Migraciones del MVP: esquema de referencia

Los siguientes bloques muestran las columnas y restricciones mínimas que debe implementar el MVP. En el repositorio real, separarlos en archivos de migración individuales; no colocar todas las tablas en una sola migración monolítica.

### 4.1 `companies`

```php
Schema::create('companies', function (Blueprint $table) {
    $table->id();
    $table->char('public_id', 26)->unique();
    $table->string('legal_name', 180);
    $table->string('trade_name', 180)->nullable();
    $table->string('tax_identifier', 24)->nullable();
    $table->char('country_code', 2)->default('CL');
    $table->char('default_currency', 3)->default('CLP');
    $table->string('timezone', 64)->default('America/Santiago');
    $table->string('email', 254)->nullable();
    $table->string('phone', 40)->nullable();
    $table->string('website', 255)->nullable();
    $table->string('address_text', 500)->nullable();
    $table->json('settings')->nullable();
    $table->string('status', 24)->default('ACTIVE');
    $table->timestamps();
    $table->index('status');
});
```

Validaciones: nombre legal requerido; país/currency con formato válido; zona IANA reconocida. `tax_identifier` no debe registrarse en logs. Confirmar la normalización de RUT antes de imponer unicidad.

### 4.2 `users`

Para instalaciones que utilizan la tabla estándar de Laravel, adaptar su migración en vez de crear una segunda tabla de usuarios.

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->char('public_id', 26)->unique();
    $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
    $table->string('name', 160);
    $table->string('email', 254)->unique(); // decisión inicial: email globalmente único
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password')->nullable();
    $table->string('user_type', 24)->default('HUMAN');
    $table->string('status', 24)->default('INVITED');
    $table->dateTime('last_login_at', 3)->nullable();
    $table->dateTime('password_changed_at', 3)->nullable();
    $table->rememberToken();
    $table->timestamps();
    $table->softDeletes();
    $table->index(['company_id', 'status']);
});
```

**Decisión provisional:** email globalmente único. Si la identidad de múltiples empresas requiere cuentas por compañía, revisar antes de consolidar el diseño de autenticación. Nunca permitir `company_id` libre desde el payload del navegador.

### 4.3 `roles`, `permissions` y tablas pivot

```php
Schema::create('roles', function (Blueprint $table) {
    $table->id();
    $table->string('name', 80)->unique();
    $table->string('display_name', 120);
    $table->text('description')->nullable();
    $table->boolean('is_system')->default(false);
    $table->timestamps();
});

Schema::create('permissions', function (Blueprint $table) {
    $table->id();
    $table->string('name', 120)->unique();
    $table->string('display_name', 140);
    $table->string('module', 60);
    $table->text('description')->nullable();
    $table->timestamps();
    $table->index('module');
});

Schema::create('role_user', function (Blueprint $table) {
    $table->foreignId('user_id')->constrained()->restrictOnDelete();
    $table->foreignId('role_id')->constrained()->restrictOnDelete();
    $table->foreignId('company_id')->constrained()->restrictOnDelete();
    $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
    $table->dateTime('assigned_at', 3);
    $table->primary(['user_id', 'role_id', 'company_id']);
    $table->index(['company_id', 'role_id']);
});

Schema::create('permission_role', function (Blueprint $table) {
    $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
    $table->foreignId('role_id')->constrained()->cascadeOnDelete();
    $table->primary(['permission_id', 'role_id']);
});
```

La cascada se limita a las relaciones de catálogo RBAC, no a eventos de negocio. Si se adopta un paquete RBAC, inspeccionar su esquema y evitar duplicar estos pivots.

### 4.4 `audit_logs`

```php
Schema::create('audit_logs', function (Blueprint $table) {
    $table->id();
    $table->char('public_id', 26)->unique();
    $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
    $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('actor_type', 24); // HUMAN, AGENT, SYSTEM, INTEGRATION
    $table->string('action', 100);
    $table->string('resource_type', 100)->nullable();
    $table->string('resource_id', 64)->nullable();
    $table->string('result', 24); // SUCCESS, DENIED, FAILED
    $table->char('request_id', 26)->nullable();
    $table->char('ip_hash', 64)->nullable();
    $table->string('user_agent_summary', 255)->nullable();
    $table->json('before_summary')->nullable();
    $table->json('after_summary')->nullable();
    $table->string('reason', 500)->nullable();
    $table->dateTime('occurred_at', 3);
    $table->timestamp('created_at')->nullable();
    $table->index(['company_id', 'occurred_at']);
    $table->index(['actor_user_id', 'occurred_at']);
    $table->index(['resource_type', 'resource_id', 'occurred_at'], 'audit_resource_time_idx');
    $table->index(['action', 'occurred_at']);
    $table->index('request_id');
});
```

`audit_logs` es append-only a nivel de aplicación. No exponer `update()`/`delete()` desde endpoints. Para reforzar la inmutabilidad en producción, evaluar permisos SQL separados y/o exportación periódica a almacenamiento resistente a modificaciones.

### 4.5 `parties`, `party_addresses` y `party_roles`

```php
Schema::create('parties', function (Blueprint $table) {
    $table->id();
    $table->char('public_id', 26)->unique();
    $table->foreignId('company_id')->constrained()->restrictOnDelete();
    $table->string('party_type', 24); // PERSON, ORGANIZATION
    $table->string('display_name', 200);
    $table->string('legal_name', 200)->nullable();
    $table->string('given_name', 100)->nullable();
    $table->string('middle_name', 100)->nullable();
    $table->string('family_name', 100)->nullable();
    $table->string('second_family_name', 100)->nullable();
    $table->text('tax_identifier_encrypted')->nullable();
    $table->char('tax_identifier_lookup_hash', 64)->nullable();
    $table->string('email', 254)->nullable();
    $table->string('phone', 40)->nullable();
    $table->string('website', 255)->nullable();
    $table->string('status', 24)->default('ACTIVE');
    $table->text('notes')->nullable(); // prohibido ingresar datos clínicos aquí
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->dateTime('archived_at', 3)->nullable();
    $table->index(['company_id', 'party_type', 'status']);
    $table->index(['company_id', 'display_name']);
    $table->index(['company_id', 'tax_identifier_lookup_hash']);
});

Schema::create('party_addresses', function (Blueprint $table) {
    $table->id();
    $table->char('public_id', 26)->unique();
    $table->foreignId('company_id')->constrained()->restrictOnDelete();
    $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
    $table->string('label', 80);
    $table->string('address_line1', 180);
    $table->string('address_line2', 180)->nullable();
    $table->string('commune', 100)->nullable();
    $table->string('province', 100)->nullable();
    $table->string('region', 100)->nullable();
    $table->string('postal_code', 20)->nullable();
    $table->char('country_code', 2)->default('CL');
    $table->decimal('latitude', 10, 7)->nullable();
    $table->decimal('longitude', 10, 7)->nullable();
    $table->boolean('is_primary')->default(false);
    $table->boolean('is_billing')->default(false);
    $table->boolean('is_service_location')->default(false);
    $table->text('access_instructions')->nullable(); // acceso restringido por necesidad operativa
    $table->timestamps();
    $table->dateTime('archived_at', 3)->nullable();
    $table->index(['company_id', 'party_id']);
    $table->index(['company_id', 'commune', 'region']);
});

Schema::create('party_roles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->restrictOnDelete();
    $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
    $table->string('role_type', 32); // CUSTOMER, SUPPLIER, PARTNER, REFERRER, OTHER
    $table->string('status', 24)->default('ACTIVE');
    $table->string('source', 80)->nullable();
    $table->date('effective_from')->nullable();
    $table->date('effective_to')->nullable();
    $table->timestamps();
    $table->unique(['company_id', 'party_id', 'role_type']);
});
```

No crear una clave única en claro para RUT. Si se requiere búsqueda exacta, usar cifrado autenticado más HMAC con secreto mantenido fuera de MySQL y revisar colisiones/normalización. La unicidad de una parte por RUT debe estar definida explícitamente según el país y reglas de calidad de datos.

### 4.6 Catálogo `service_categories`, `services`, `price_lists`, `service_prices`

```php
Schema::create('service_categories', function (Blueprint $table) {
    $table->id();
    $table->char('public_id', 26)->unique();
    $table->foreignId('company_id')->constrained()->restrictOnDelete();
    $table->string('code', 80);
    $table->string('name', 160);
    $table->text('description')->nullable();
    $table->foreignId('parent_id')->nullable()->constrained('service_categories')->nullOnDelete();
    $table->smallInteger('sort_order')->default(0);
    $table->string('status', 24)->default('ACTIVE');
    $table->timestamps();
    $table->dateTime('archived_at', 3)->nullable();
    $table->unique(['company_id', 'code']);
});

Schema::create('services', function (Blueprint $table) {
    $table->id();
    $table->char('public_id', 26)->unique();
    $table->foreignId('company_id')->constrained()->restrictOnDelete();
    $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->nullOnDelete();
    $table->string('code', 80);
    $table->string('name', 180);
    $table->string('short_description', 500)->nullable();
    $table->text('description')->nullable();
    $table->string('delivery_mode', 32); // DeliveryMode: HOME, ON_SITE, FACILITY, REMOTE, MIXED
    $table->string('billing_unit', 32)->default('SERVICE'); // BillingUnit
    $table->string('status', 24)->default('DRAFT');
    $table->unsignedSmallInteger('default_duration_minutes')->nullable();
    $table->boolean('requires_appointment')->default(true);
    $table->boolean('requires_professional')->default(false);
    $table->boolean('requires_clinical_workflow')->default(false);
    $table->json('metadata')->nullable(); // sin datos de pacientes
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->dateTime('archived_at', 3)->nullable();
    $table->unique(['company_id', 'code']);
    $table->index(['company_id', 'status', 'service_category_id']);
});

Schema::create('price_lists', function (Blueprint $table) {
    $table->id();
    $table->char('public_id', 26)->unique();
    $table->foreignId('company_id')->constrained()->restrictOnDelete();
    $table->string('code', 80);
    $table->string('name', 160);
    $table->char('currency', 3)->default('CLP');
    $table->string('customer_segment', 50)->nullable();
    $table->date('valid_from');
    $table->date('valid_until')->nullable();
    $table->string('status', 24)->default('DRAFT');
    $table->timestamps();
    $table->unique(['company_id', 'code']);
    $table->index(['company_id', 'status', 'valid_from', 'valid_until']);
});

Schema::create('service_prices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('price_list_id')->constrained('price_lists')->restrictOnDelete();
    $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
    $table->decimal('unit_price', 14, 4);
    $table->string('tax_treatment', 24)->default('NOT_DEFINED'); // TAXABLE, EXEMPT, NOT_DEFINED; validar con contador
    $table->decimal('tax_rate', 7, 4)->nullable(); // porcentaje: 19.0000
    $table->decimal('minimum_quantity', 12, 3)->default(1);
    $table->date('valid_from');
    $table->date('valid_until')->nullable();
    $table->string('notes', 500)->nullable();
    $table->timestamps();
    $table->unique(['price_list_id', 'service_id', 'valid_from'], 'service_price_valid_from_uq');
});
```

Al crear el archivo real, confirmar el diseño exacto de `DATABASE_SPEC.md` para campos operacionales del catálogo y agregar `service_cost_components`/`service_requirements` conforme a ese documento. El impuesto y su tratamiento no se deben hardcodear por suposición; requieren configuración y validación contable por servicio.

### 4.7 `opportunities` y `opportunity_activities`

```php
Schema::create('opportunities', function (Blueprint $table) {
    $table->id();
    $table->char('public_id', 26)->unique();
    $table->foreignId('company_id')->constrained()->restrictOnDelete();
    $table->foreignId('customer_party_id')->constrained('parties')->restrictOnDelete();
    $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('title', 180);
    $table->text('description')->nullable();
    $table->string('source', 50)->nullable();
    $table->string('stage', 32)->default('NEW'); // OpportunityStage (DEC-010)
    $table->decimal('estimated_value', 14, 2)->nullable();
    $table->char('currency', 3)->default('CLP');
    $table->decimal('probability_percent', 5, 2)->nullable(); // 0–100
    $table->date('expected_close_date')->nullable();
    $table->dateTime('last_activity_at', 3)->nullable();
    $table->dateTime('next_action_at', 3)->nullable();
    $table->string('next_action_summary', 500)->nullable();
    $table->string('lost_reason', 500)->nullable();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->dateTime('archived_at', 3)->nullable();
    $table->index(['company_id', 'stage', 'next_action_at']);
    $table->index(['owner_user_id', 'stage']);
    $table->index(['customer_party_id', 'created_at']);
});

Schema::create('opportunity_activities', function (Blueprint $table) {
    $table->id();
    $table->foreignId('opportunity_id')->constrained('opportunities')->restrictOnDelete();
    $table->foreignId('company_id')->constrained()->restrictOnDelete();
    $table->string('activity_type', 32);
    $table->string('subject', 180);
    $table->text('details')->nullable();
    $table->dateTime('scheduled_at', 3)->nullable();
    $table->dateTime('completed_at', 3)->nullable();
    $table->string('status', 24)->default('PLANNED');
    $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->index(['opportunity_id', 'scheduled_at']);
});
```

Validar `probability_percent` entre 0 y 100 (DEC-007). Exigir `lost_reason` al pasar a `LOST` u `ON_HOLD`.

### 4.8 `quotes`, `quote_versions`, `quote_items`

```php
Schema::create('quotes', function (Blueprint $table) {
    $table->id();
    $table->char('public_id', 26)->unique();
    $table->foreignId('company_id')->constrained()->restrictOnDelete();
    $table->string('quote_number', 40);
    $table->foreignId('customer_party_id')->constrained('parties')->restrictOnDelete();
    $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
    $table->string('status', 32)->default('DRAFT');
    $table->unsignedBigInteger('current_version_id')->nullable(); // FK posterior
    $table->dateTime('issued_at', 3)->nullable();
    $table->date('valid_until')->nullable();
    $table->dateTime('accepted_at', 3)->nullable();
    $table->string('accepted_by_name', 160)->nullable();
    $table->string('acceptance_method', 40)->nullable();
    $table->char('currency', 3)->default('CLP');
    $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
    $table->text('internal_notes')->nullable();
    $table->timestamps();
    $table->dateTime('archived_at', 3)->nullable();
    $table->unique(['company_id', 'quote_number']);
    $table->index(['company_id', 'status', 'created_at']);
});

Schema::create('quote_versions', function (Blueprint $table) {
    $table->id();
    $table->char('public_id', 26)->unique();
    $table->foreignId('quote_id')->constrained('quotes')->restrictOnDelete();
    $table->unsignedInteger('version_number');
    $table->string('customer_name_snapshot', 200);
    $table->text('customer_tax_identifier_snapshot_encrypted')->nullable();
    $table->json('customer_address_snapshot')->nullable();
    $table->char('currency', 3)->default('CLP');
    $table->decimal('subtotal', 14, 2);
    $table->decimal('discount_total', 14, 2)->default(0);
    $table->decimal('tax_total', 14, 2)->default(0);
    $table->decimal('grand_total', 14, 2);
    $table->text('terms')->nullable();
    $table->text('notes_for_customer')->nullable();
    $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
    $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
    $table->dateTime('approved_at', 3)->nullable();
    $table->dateTime('issued_at', 3)->nullable();
    $table->char('snapshot_hash', 64)->nullable();
    $table->timestamp('created_at')->nullable();
    $table->unique(['quote_id', 'version_number']);
});

Schema::create('quote_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('quote_version_id')->constrained('quote_versions')->restrictOnDelete();
    $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
    $table->unsignedSmallInteger('line_number');
    $table->string('description_snapshot', 500);
    $table->decimal('quantity', 12, 3);
    $table->string('unit', 32);
    $table->decimal('unit_price', 14, 4);
    $table->decimal('discount_percent', 7, 4)->default(0); // entrada 0–100
    $table->decimal('discount_amount', 14, 2)->default(0); // calculado
    $table->string('tax_treatment', 24); // snapshot de service_prices
    $table->decimal('tax_rate', 7, 4)->nullable(); // porcentaje
    $table->decimal('tax_amount', 14, 2)->default(0); // informativo; autoritativo en quote_versions.tax_total
    $table->decimal('line_total', 14, 2); // neto sin IVA
    $table->decimal('cost_estimate', 14, 2)->nullable();
    $table->json('metadata_snapshot')->nullable();
    $table->timestamp('created_at')->nullable();
    $table->unique(['quote_version_id', 'line_number']);
});
```

Luego de crear `quote_versions`, agregar la FK de `quotes.current_version_id` en migración posterior:

```php
Schema::table('quotes', function (Blueprint $table) {
    $table->foreign('current_version_id', 'quotes_current_version_fk')
        ->references('id')->on('quote_versions')->nullOnDelete();
});
```

`quote_versions` y sus items son snapshots. Una versión emitida o aceptada no se modifica; la revisión comercial genera una nueva versión. Antes de desplegar, definir un mecanismo que garantice actualización inmutable del snapshot después de emisión (Service + Policy + pruebas; opcionalmente permisos SQL/trigger adicional).

### 4.9 `service_orders`, `service_order_items`, `appointments`, `service_events`

```php
Schema::create('service_orders', function (Blueprint $table) {
    $table->id();
    $table->char('public_id', 26)->unique();
    $table->foreignId('company_id')->constrained()->restrictOnDelete();
    $table->string('order_number', 40);
    $table->foreignId('customer_party_id')->constrained('parties')->restrictOnDelete();
    $table->foreignId('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
    $table->foreignId('quote_version_id')->nullable()->constrained('quote_versions')->nullOnDelete();
    $table->string('status', 32)->default('DRAFT');
    $table->string('priority', 16)->default('NORMAL');
    $table->dateTime('requested_at', 3);
    $table->dateTime('planned_start_at', 3)->nullable();
    $table->dateTime('planned_end_at', 3)->nullable();
    $table->dateTime('completed_at', 3)->nullable();
    $table->foreignId('service_address_id')->nullable()->constrained('party_addresses')->nullOnDelete();
    $table->json('location_snapshot')->nullable();
    $table->foreignId('assigned_coordinator_id')->nullable()->constrained('users')->nullOnDelete();
    $table->text('customer_instructions')->nullable();
    $table->text('internal_notes')->nullable();
    $table->string('cancellation_reason', 500)->nullable();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->dateTime('closed_at', 3)->nullable();
    $table->unique(['company_id', 'order_number']);
    $table->index(['company_id', 'status', 'planned_start_at']);
    $table->index(['customer_party_id', 'created_at']);
    $table->index(['assigned_coordinator_id', 'status']);
});

Schema::create('service_order_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('service_order_id')->constrained('service_orders')->restrictOnDelete();
    $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
    $table->foreignId('quote_item_id')->nullable()->constrained('quote_items')->nullOnDelete();
    $table->string('description_snapshot', 500);
    $table->decimal('quantity', 12, 3);
    $table->string('unit', 32);
    $table->decimal('unit_price_snapshot', 14, 4)->nullable();
    $table->decimal('line_total_snapshot', 14, 2)->nullable();
    $table->string('status', 24)->default('PENDING');
    $table->text('operational_notes')->nullable(); // sin resultados clínicos
    $table->timestamps();
});

Schema::create('appointments', function (Blueprint $table) {
    $table->id();
    $table->char('public_id', 26)->unique();
    $table->foreignId('company_id')->constrained()->restrictOnDelete();
    $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
    $table->foreignId('customer_party_id')->constrained('parties')->restrictOnDelete();
    $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
    $table->dateTime('start_at', 3);
    $table->dateTime('end_at', 3);
    $table->string('timezone', 64)->default('America/Santiago');
    $table->string('status', 24)->default('TENTATIVE');
    $table->string('location_type', 24);
    $table->foreignId('party_address_id')->nullable()->constrained('party_addresses')->nullOnDelete();
    $table->json('location_snapshot')->nullable();
    $table->foreignId('coordinator_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->dateTime('confirmed_at', 3)->nullable(); // la confirmación es el estado CONFIRMED (DEC-014)
    $table->string('cancellation_reason', 500)->nullable();
    $table->foreignId('rescheduled_from_appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->index(['company_id', 'start_at', 'end_at']);
    $table->index(['customer_party_id', 'start_at']);
    $table->index(['status', 'start_at']);
});

Schema::create('service_events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('service_order_id')->constrained('service_orders')->restrictOnDelete();
    $table->string('event_type', 50);
    $table->string('from_status', 32)->nullable();
    $table->string('to_status', 32)->nullable();
    $table->string('description', 1000)->nullable();
    $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('actor_type', 24);
    $table->dateTime('occurred_at', 3);
    $table->json('metadata_redacted')->nullable();
    $table->timestamp('created_at')->nullable();
    $table->index(['service_order_id', 'occurred_at']);
});
```

`tasks` se crea en migración propia. Sus relaciones polimórficas (`related_type`, `related_id`) solo aceptan tipos incluidos en una allowlist de backend; conocer un ID no confiere acceso al recurso vinculado.

### 4.10 Restricciones y validaciones que deben añadirse

- `appointments.end_at > appointments.start_at` en Form Request y Service; agregar `CHECK` MySQL si el motor soportado lo aplica correctamente.
- `quantity > 0` para items; política explícita de cantidades negativas para ajustes nunca se mezcla con cotizaciones.
- Importes no negativos salvo descuentos expresamente validados.
- `valid_until >= valid_from` en price lists.
- Validación de zona horaria IANA.
- Aislamiento de `company_id`: validar que `party`, servicio, oportunidad, cotización, orden y dirección pertenezcan a la misma empresa antes de relacionarlos.
- Validar transiciones de estado dentro de un servicio de dominio y persistir el evento correspondiente.
- Validar no solapamiento de citas/reservas en el backend y transacción. La comprobación de React no es un control de integridad.

---

## 5. Enums de dominio

Guardar estados en `VARCHAR` y usar enums PHP para validarlos. No depender de enums MySQL nativos, ya que son menos flexibles para cambios de dominio.

`app/Enums/QuoteStatus.php`:

```php
<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case Draft = 'DRAFT';
    case PendingApproval = 'PENDING_APPROVAL';
    case Issued = 'ISSUED';
    case Sent = 'SENT';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';

    /** Transiciones de DECISIONS.md DEC-011. Cada acción de dominio valida además permisos y reglas. */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Issued, self::Cancelled],
            self::PendingApproval => [self::Issued, self::Draft, self::Cancelled],
            self::Issued => [self::Sent, self::Accepted, self::Rejected, self::Expired, self::Draft, self::Cancelled],
            self::Sent => [self::Accepted, self::Rejected, self::Expired, self::Draft, self::Cancelled],
            self::Rejected, self::Expired => [self::Draft], // solo creando una nueva versión
            self::Accepted, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::PendingApproval => 'Pendiente de revisión',
            self::Issued => 'Emitida',
            self::Sent => 'Enviada',
            self::Accepted => 'Aceptada',
            self::Rejected => 'Rechazada',
            self::Expired => 'Vencida',
            self::Cancelled => 'Cancelada',
        };
    }
}
```

`app/Enums/ServiceOrderStatus.php`:

```php
<?php

namespace App\Enums;

enum ServiceOrderStatus: string
{
    case Draft = 'DRAFT';
    case PendingConfirmation = 'PENDING_CONFIRMATION';
    case Confirmed = 'CONFIRMED';
    case Assigned = 'ASSIGNED';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case PendingDocumentation = 'PENDING_DOCUMENTATION';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';
    case NotPerformed = 'NOT_PERFORMED';
    case OnHold = 'ON_HOLD';

    /** Transiciones de DECISIONS.md DEC-012. */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingConfirmation, self::Cancelled],
            self::PendingConfirmation => [self::Confirmed, self::OnHold, self::Cancelled],
            self::Confirmed => [self::Assigned, self::OnHold, self::Cancelled],
            self::Assigned => [self::InProgress, self::Confirmed, self::OnHold, self::Cancelled, self::NotPerformed],
            self::InProgress => [self::Completed, self::NotPerformed],
            self::Completed => [self::PendingDocumentation, self::Closed],
            self::PendingDocumentation => [self::Closed],
            self::OnHold => [self::PendingConfirmation, self::Confirmed, self::Cancelled],
            self::Closed, self::Cancelled, self::NotPerformed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::Cancelled, self::NotPerformed, self::OnHold], true);
    }
}
```

`app/Enums/OpportunityStage.php`:

```php
<?php

namespace App\Enums;

enum OpportunityStage: string
{
    case New = 'NEW';
    case Contacted = 'CONTACTED';
    case Qualified = 'QUALIFIED';
    case QuoteInPreparation = 'QUOTE_IN_PREPARATION';
    case Quoted = 'QUOTED';
    case Negotiation = 'NEGOTIATION';
    case Won = 'WON';
    case Lost = 'LOST';
    case OnHold = 'ON_HOLD';
}
```

`app/Enums/AppointmentStatus.php`:

```php
<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Tentative = 'TENTATIVE';
    case Scheduled = 'SCHEDULED';
    case Confirmed = 'CONFIRMED';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';
    case Rescheduled = 'RESCHEDULED';
}
```

Crear también `UserStatus`, `UserType`, `PartyType`, `PartyStatus`, `ServiceStatus`, `DeliveryMode`, `BillingUnit`, `ApprovalStatus`, `AiRunStatus`, `RiskLevel` (`R0`–`R4`), `AuditResult`, y enums de finanzas/inventario cuando esas fases se habiliten. Los valores deben coincidir exactamente con `DECISIONS.md`; no mezclar nombres humanos con códigos persistidos. Las etiquetas en español van en un método `label()`.

### 5.1 Enums en modelos

```php
protected function casts(): array
{
    return [
        'status' => \App\Enums\QuoteStatus::class,
        'issued_at' => 'immutable_datetime',
        'valid_until' => 'immutable_date',
        'archived_at' => 'immutable_datetime',
    ];
}
```

Si la versión instalada de Laravel todavía utiliza la propiedad `$casts`, seguir el patrón compatible. Agregar pruebas de serialización/validación de cada enum.

---

## 6. Modelos Eloquent y relaciones

Los siguientes ejemplos son la base sugerida. Completar las relaciones para cada tabla creada y adaptar nombres/namespace a la versión del proyecto.

### 6.1 `Company`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Company extends Model
{
    protected $fillable = [
        'legal_name', 'trade_name', 'tax_identifier', 'country_code',
        'default_currency', 'timezone', 'email', 'phone', 'website',
        'address_text', 'settings', 'status',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->public_id ??= (string) Str::ulid();
        });
    }

    public function users() { return $this->hasMany(User::class); }
    public function parties() { return $this->hasMany(Party::class); }
    public function services() { return $this->hasMany(Service::class); }
    public function quotes() { return $this->hasMany(Quote::class); }
    public function serviceOrders() { return $this->hasMany(ServiceOrder::class); }
}
```

### 6.2 `User`

Mantener el hash de contraseña mediante el mutator recomendado por la versión Laravel instalada y configurar autorización según el guard elegido.

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use Notifiable, SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'email', 'password', 'user_type', 'status',
        'email_verified_at', 'password_changed_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'status' => \App\Enums\UserStatus::class,
            'user_type' => \App\Enums\UserType::class,
            'email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->public_id ??= (string) Str::ulid();
        });
    }

    public function company() { return $this->belongsTo(Company::class); }

    public function roles()
    {
        return $this->belongsToMany(Role::class)
            ->withPivot(['company_id', 'assigned_by', 'assigned_at']);
    }

    public function hasRole(string $roleName, ?int $companyId = null): bool
    {
        $companyId ??= (int) $this->company_id;

        return $this->roles()
            ->where('roles.name', $roleName)
            ->wherePivot('company_id', $companyId)
            ->exists();
    }

    public function hasPermission(string $permissionName, ?int $companyId = null): bool
    {
        $companyId ??= (int) $this->company_id;

        return $this->roles()
            ->wherePivot('company_id', $companyId)
            ->whereHas('permissions', fn ($q) => $q->where('permissions.name', $permissionName))
            ->exists();
    }
}
```

No utilizar `$fillable = ['*']`. Si la versión PHP/Laravel no aplica automáticamente el cast `hashed`, asignar siempre `Hash::make($password)` en un flujo validado. Restringir cuentas `SERVICE` y agentes a credenciales/token de máquina, nunca a contraseñas compartidas.

### 6.3 `Role` y `Permission`

```php
class Role extends Model
{
    protected $fillable = ['name', 'display_name', 'description', 'is_system'];
    protected function casts(): array { return ['is_system' => 'boolean']; }
    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['company_id', 'assigned_by', 'assigned_at']);
    }
    public function permissions() { return $this->belongsToMany(Permission::class); }
}

class Permission extends Model
{
    protected $fillable = ['name', 'display_name', 'module', 'description'];
    public function roles() { return $this->belongsToMany(Role::class); }
}
```

Los métodos anteriores pueden residir en archivos `Role.php` y `Permission.php` separados.

### 6.4 `Party` y `PartyAddress`

```php
class Party extends Model
{
    protected $fillable = [
        'company_id', 'party_type', 'display_name', 'legal_name', 'given_name',
        'middle_name', 'family_name', 'second_family_name', 'email', 'phone',
        'website', 'status', 'notes', 'created_by', 'updated_by', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function addresses() { return $this->hasMany(PartyAddress::class); }
    public function roles() { return $this->hasMany(PartyRole::class); }
    public function opportunities() { return $this->hasMany(Opportunity::class, 'customer_party_id'); }
    public function quotes() { return $this->hasMany(Quote::class, 'customer_party_id'); }
    public function serviceOrders() { return $this->hasMany(ServiceOrder::class, 'customer_party_id'); }
}

class PartyAddress extends Model
{
    protected $fillable = [
        'company_id', 'party_id', 'label', 'address_line1', 'address_line2',
        'commune', 'province', 'region', 'postal_code', 'country_code',
        'latitude', 'longitude', 'is_primary', 'is_billing',
        'is_service_location', 'access_instructions', 'archived_at',
    ];
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7', 'longitude' => 'decimal:7',
            'is_primary' => 'boolean', 'is_billing' => 'boolean',
            'is_service_location' => 'boolean', 'archived_at' => 'immutable_datetime',
        ];
    }
    public function party() { return $this->belongsTo(Party::class); }
    public function company() { return $this->belongsTo(Company::class); }
}
```

No cifrar solamente en base a mutators improvisados. Para `tax_identifier_encrypted`, usar cast de cifrado de Laravel/servicio de cifrado documentado y una clave fuera de la base de datos; la columna hash de búsqueda se genera con HMAC/normalización dentro de servicio controlado.

### 6.5 `ServiceCategory` y `Service`

```php
class ServiceCategory extends Model
{
    protected $fillable = ['company_id', 'code', 'name', 'description', 'parent_id', 'sort_order', 'status', 'archived_at'];
    protected function casts(): array { return ['archived_at' => 'immutable_datetime']; }
    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }
    public function company() { return $this->belongsTo(Company::class); }
    public function services() { return $this->hasMany(Service::class); }
}

class Service extends Model
{
    protected $fillable = [
        'company_id', 'service_category_id', 'code', 'name', 'short_description', 'description',
        'delivery_mode', 'billing_unit', 'status', 'default_duration_minutes',
        'requires_appointment', 'requires_professional', 'requires_clinical_workflow', 'metadata',
        'created_by', 'updated_by', 'archived_at',
    ];
    protected function casts(): array
    {
        return [
            'status' => \App\Enums\ServiceStatus::class,
            'delivery_mode' => \App\Enums\DeliveryMode::class,
            'billing_unit' => \App\Enums\BillingUnit::class,
            'metadata' => 'array',
            'requires_appointment' => 'boolean',
            'requires_professional' => 'boolean',
            'requires_clinical_workflow' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }
    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }
    public function company() { return $this->belongsTo(Company::class); }
    public function category() { return $this->belongsTo(ServiceCategory::class, 'service_category_id'); }
    public function prices() { return $this->hasMany(ServicePrice::class); }
}
```

### 6.6 `Opportunity`

```php
class Opportunity extends Model
{
    protected $fillable = [
        'company_id', 'customer_party_id', 'owner_user_id', 'title', 'description', 'source',
        'stage', 'estimated_value', 'currency', 'probability_percent', 'expected_close_date',
        'last_activity_at', 'next_action_at', 'next_action_summary', 'lost_reason',
        'created_by', 'archived_at',
    ];
    protected function casts(): array
    {
        return [
            'stage' => \App\Enums\OpportunityStage::class,
            'estimated_value' => 'decimal:2',
            'probability_percent' => 'decimal:2',
            'expected_close_date' => 'immutable_date',
            'last_activity_at' => 'immutable_datetime',
            'next_action_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }
    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }
    public function customer() { return $this->belongsTo(Party::class, 'customer_party_id'); }
    public function owner() { return $this->belongsTo(User::class, 'owner_user_id'); }
    public function activities() { return $this->hasMany(OpportunityActivity::class); }
    public function quotes() { return $this->hasMany(Quote::class); }
}
```

### 6.7 `Quote`, `QuoteVersion` y `QuoteItem`

```php
class Quote extends Model
{
    protected $fillable = [
        'company_id', 'quote_number', 'customer_party_id', 'opportunity_id',
        'status', 'current_version_id', 'issued_at', 'valid_until', 'accepted_at',
        'accepted_by_name', 'acceptance_method', 'currency', 'owner_user_id',
        'internal_notes', 'archived_at',
    ];
    protected function casts(): array
    {
        return [
            'status' => \App\Enums\QuoteStatus::class,
            'issued_at' => 'immutable_datetime', 'valid_until' => 'immutable_date',
            'accepted_at' => 'immutable_datetime', 'archived_at' => 'immutable_datetime',
        ];
    }
    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }
    public function company() { return $this->belongsTo(Company::class); }
    public function customer() { return $this->belongsTo(Party::class, 'customer_party_id'); }
    public function opportunity() { return $this->belongsTo(Opportunity::class); }
    public function owner() { return $this->belongsTo(User::class, 'owner_user_id'); }
    public function versions() { return $this->hasMany(QuoteVersion::class); }
    public function currentVersion() { return $this->belongsTo(QuoteVersion::class, 'current_version_id'); }
}

class QuoteVersion extends Model
{
    public $timestamps = false; // solo created_at como definido en el diseño
    protected $fillable = [
        'quote_id', 'version_number', 'customer_name_snapshot',
        'customer_tax_identifier_snapshot_encrypted', 'customer_address_snapshot',
        'currency', 'subtotal', 'discount_total', 'tax_total', 'grand_total',
        'terms', 'notes_for_customer', 'prepared_by', 'approved_by', 'approved_at',
        'issued_at', 'snapshot_hash', 'created_at',
    ];
    protected function casts(): array
    {
        return [
            'customer_address_snapshot' => 'array',
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2', 'grand_total' => 'decimal:2',
            'approved_at' => 'immutable_datetime', 'issued_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
        static::updating(function (self $m): void {
            if ($m->getOriginal('issued_at') !== null) {
                throw new \DomainException('Una versión emitida de cotización es inmutable.');
            }
        });
    }
    public function quote() { return $this->belongsTo(Quote::class); }
    public function items() { return $this->hasMany(QuoteItem::class); }
    public function preparedBy() { return $this->belongsTo(User::class, 'prepared_by'); }
}

class QuoteItem extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'quote_version_id', 'service_id', 'line_number', 'description_snapshot',
        'quantity', 'unit', 'unit_price', 'discount_percent', 'discount_amount', 'tax_treatment',
        'tax_rate', 'tax_amount', 'line_total', 'cost_estimate', 'metadata_snapshot', 'created_at',
    ];
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:4', 'discount_amount' => 'decimal:2', 'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2', 'line_total' => 'decimal:2',
            'cost_estimate' => 'decimal:2', 'metadata_snapshot' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
    public function version() { return $this->belongsTo(QuoteVersion::class, 'quote_version_id'); }
    public function service() { return $this->belongsTo(Service::class); }
}
```

Los ejemplos de múltiples clases en una misma sección son para documentar; en código real cada clase va en su propio archivo con imports completos. Para mutabilidad de snapshots, endurecer también el servicio y las Policies; los eventos Eloquent no interceptan necesariamente actualizaciones masivas hechas directamente con query builder.

### 6.8 `ServiceOrder`, `ServiceOrderItem`, `Appointment`, `ServiceEvent`

```php
class ServiceOrder extends Model
{
    protected $fillable = [
        'company_id', 'order_number', 'customer_party_id', 'quote_id', 'quote_version_id',
        'status', 'priority', 'requested_at', 'planned_start_at', 'planned_end_at',
        'completed_at', 'service_address_id', 'location_snapshot', 'assigned_coordinator_id',
        'customer_instructions', 'internal_notes', 'cancellation_reason', 'created_by',
        'updated_by', 'closed_at',
    ];
    protected function casts(): array
    {
        return [
            'status' => \App\Enums\ServiceOrderStatus::class,
            'requested_at' => 'immutable_datetime', 'planned_start_at' => 'immutable_datetime',
            'planned_end_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime', 'location_snapshot' => 'array',
        ];
    }
    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }
    public function company() { return $this->belongsTo(Company::class); }
    public function customer() { return $this->belongsTo(Party::class, 'customer_party_id'); }
    public function quote() { return $this->belongsTo(Quote::class); }
    public function quoteVersion() { return $this->belongsTo(QuoteVersion::class); }
    public function items() { return $this->hasMany(ServiceOrderItem::class); }
    public function appointments() { return $this->hasMany(Appointment::class); }
    public function events() { return $this->hasMany(ServiceEvent::class)->orderBy('occurred_at'); }
}

class Appointment extends Model
{
    protected $fillable = [
        'company_id', 'service_order_id', 'customer_party_id', 'service_id', 'start_at',
        'end_at', 'timezone', 'status', 'location_type', 'party_address_id',
        'location_snapshot', 'coordinator_user_id', 'confirmed_at',
        'cancellation_reason', 'rescheduled_from_appointment_id', 'created_by',
    ];
    protected function casts(): array
    {
        return [
            'status' => \App\Enums\AppointmentStatus::class,
            'start_at' => 'immutable_datetime', 'end_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime', 'location_snapshot' => 'array',
        ];
    }
    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }
    public function serviceOrder() { return $this->belongsTo(ServiceOrder::class); }
    public function customer() { return $this->belongsTo(Party::class, 'customer_party_id'); }
    public function service() { return $this->belongsTo(Service::class); }
}
```

Crear de igual manera `ServiceOrderItem` y `ServiceEvent`. `ServiceEvent` debe tratarse como historial append-only. En estado terminal `CLOSED`, no editar atributos operacionales: registrar corrección en un nuevo evento o proceso autorizado.

### 6.9 Inventario de modelos y relaciones restantes

Cuando se creen las migraciones de la Fase 0 y dominios posteriores, generar los modelos siguientes (un archivo por clase):

| Modelo | Relaciones principales esperadas |
|---|---|
| `PartyRole` | `belongsTo(Party)`, `belongsTo(Company)` |
| `PriceList` | `belongsTo(Company)`, `hasMany(ServicePrice)` |
| `ServicePrice` | `belongsTo(PriceList)`, `belongsTo(Service)` |
| `ServiceCostComponent` | `belongsTo(Service)`; costo y vigencia con precisión decimal |
| `ServiceRequirement` | `belongsTo(Service)`; requisito operativo/profesional tipado |
| `OpportunityActivity` | `belongsTo(Opportunity)`, `belongsTo(User, assigned_to)` |
| `Task` | `belongsTo(Company)`, `belongsTo(User, assigned_to)`; resolver recursos relacionados con allowlist |
| `AuditLog` | `belongsTo(Company)`, `belongsTo(User, actor_user_id)`; sin update/delete habitual |
| `SecurityEvent` | `belongsTo(Company)`, `belongsTo(User)`; campos redacted |
| `Approval` | `belongsTo(Company)`, `belongsTo(User, requested_by/reviewed_by)` y relación lógica con run después de migración IA |
| `Notification` | `belongsTo(User)`, `belongsTo(Company)` |
| `StaffProfile` | `belongsTo(User)`, `belongsTo(Company)`, habilidades/disponibilidad |
| `Document` / `DocumentVersion` | relaciones por storage privado, hash y control de versiones |
| `FinancialTransaction` | `belongsTo(Company/FinancialCategory)`; importes y reversas trazables |
| `Receivable` / `Payable` | `hasMany(PaymentAllocation)` con balance derivado/reconciliado |
| `Payment` | asignaciones, referencia de conciliación; nunca borrar silenciosamente |
| `InventoryItem` / `InventoryLot` | movimientos y reservas; cantidades con unidad definida |
| `InventoryMovement` | historial append-only y reversas explícitas |
| `EquipmentAsset` | mantenimiento, calibraciones y asignaciones |
| `ClinicalSubject` / `ClinicalRecord` | repositorio/servicio y Policies de acceso restringido por especialidad/asignación |
| `AssessmentSession` / `AssessmentMeasurement` | protocolo, atleta y mediciones con unidad y procedencia |
| `AiAgent` / `AiAgentVersion` | versión aprobada, modelo, herramientas permitidas |
| `AiConversation` / `AiMessage` | participantes autorizados; retención y clasificación |
| `AiRun` / `AiRunStep` / `AiToolInvocation` | seguimiento de ejecuciones; resumen redactado y clave idempotente |
| `AutomationWorkflow` / `AutomationRun` | versiones, aprobación y ejecución con idempotencia |

No añadir relaciones polimórficas sin validación adicional. Para relaciones que apunten a varios tipos de recurso, resolver en backend mediante un registro explícito de tipos admitidos.

---

## 7. Autorización: RBAC, Policies y separación por compañía

### 7.1 Permisos sugeridos para seed

El catálogo canónico del MVP es `DECISIONS.md` DEC-022 y se siembra completo en `RolesAndPermissionsSeeder` (§9.2). Agregar permisos financieros, inventario y clínica solo cuando esos módulos se implementen. No crear permiso `admin.all` de uso general. La matriz de autorizaciones (`AUTHORIZATION_MATRIX.md`) debe identificar por acción el rol habilitado, alcance de datos y aprobaciones necesarias.

### 7.2 Política de ejemplo para `Quote`

```php
<?php

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\Quote;
use App\Models\User;

class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->status === UserStatus::Active
            && $user->hasPermission('sales.quotes.read');
    }

    public function view(User $user, Quote $quote): bool
    {
        return $user->status === UserStatus::Active
            && (int) $user->company_id === (int) $quote->company_id
            && $user->hasPermission('sales.quotes.read', (int) $quote->company_id);
    }

    public function create(User $user): bool
    {
        return $user->status === UserStatus::Active
            && $user->hasPermission('sales.quotes.create');
    }

    public function approve(User $user, Quote $quote): bool
    {
        return $user->status === UserStatus::Active
            && (int) $user->company_id === (int) $quote->company_id
            && $user->hasPermission('sales.quotes.approve', (int) $quote->company_id)
            // Segregación de funciones: quien preparó la versión no la aprueba.
            && (int) $quote->currentVersion?->prepared_by !== (int) $user->id;
    }
}
```

`status` se castea a `UserStatus` en el modelo `User`; compararlo con un string devolvería siempre `false`. No confiar en que una Policy se invoca automáticamente para todos los controladores: llamar `$this->authorize(...)` o utilizar middleware Gates de forma explícita y probarlo.

### 7.3 Alcance por compañía

Para el despliegue inicial de una empresa:

- obtener `company_id` de la sesión/usuario autenticado o un contexto de servidor autorizado;
- no confiar en el `company_id` que envía React o el agente;
- al vincular dos recursos, comprobar en backend que pertenecen a la misma empresa;
- aplicar el alcance en listados, búsquedas, exportaciones, Jobs, notificaciones y herramientas de IA;
- crear pruebas negativas que demuestren que otro `company_id` no puede ser consultado ni modificado.

No declarar multitenencia completa hasta cubrir y probar todos los vectores de acceso.

---

## 8. Services y transacciones de dominio

### 8.1 `AuditLogger`

```php
<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Str;

class AuditLogger
{
    public function log(
        ?User $actor,
        ?int $companyId,
        string $action,
        string $result,
        ?string $resourceType = null,
        int|string|null $resourceId = null,
        ?string $reason = null,
        array $before = [],
        array $after = [],
        ?string $requestId = null,
        string $actorType = 'HUMAN',
    ): AuditLog {
        // Los arrays caller-provided deben haberse redactado con allowlist antes de llamar.
        return AuditLog::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $companyId,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actorType,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId === null ? null : (string) $resourceId,
            'result' => $result,
            'request_id' => $requestId,
            'before_summary' => $this->redact($before),
            'after_summary' => $this->redact($after),
            'reason' => $reason,
            'occurred_at' => now('UTC'),
        ]);
    }

    private function redact(array $data): array
    {
        $blocked = ['password', 'token', 'secret', 'tax_identifier', 'clinical_record', 'patient_notes'];
        foreach ($blocked as $key) {
            unset($data[$key]);
        }
        // Producción: reemplazar por una allowlist estricta por tipo de recurso.
        return array_slice($data, 0, 40, true);
    }
}
```

Este ejemplo solo ilustra la interfaz; no es un filtro universal suficiente. En producción crear un normalizador por tipo de recurso, permitir campos explícitos y redacción recursiva. Registrar denegaciones mediante un canal de auditoría seguro sin duplicar datos sensibles.

### 8.2 Cálculo de cotización en backend

No aceptar `subtotal`, `tax_total`, `grand_total` ni `line_total` como cifras autoritativas de React o IA. El Request contiene solo servicio, cantidad, descuento solicitado y datos de presentación permitidos. El backend obtiene precios válidos desde el catálogo y recalcula.

Primero, un servicio de redondeo por moneda (DEC-005). `bcadd(..., 2)` **trunca**, por eso no sirve como redondeo:

```php
<?php

namespace App\Services\Support;

use InvalidArgumentException;

class Money
{
    private const SCALES = ['CLP' => 0, 'CLF' => 4];

    public static function scale(string $currency): int
    {
        return self::SCALES[$currency] ?? 2;
    }

    /** Redondeo half-up (0,5 se aleja de cero) a la escala indicada. */
    public static function round(string $amount, int $scale): string
    {
        if (!is_numeric($amount)) {
            throw new InvalidArgumentException('Importe no numérico.');
        }
        $half = '0.' . str_repeat('0', $scale) . '5';
        $adjusted = bccomp($amount, '0', $scale + 6) >= 0
            ? bcadd($amount, $half, $scale + 6)
            : bcsub($amount, $half, $scale + 6);

        return bcadd($adjusted, '0', $scale); // ahora sí trunca sobre el valor ya ajustado
    }

    public static function roundFor(string $amount, string $currency): string
    {
        return self::round($amount, self::scale($currency));
    }
}
```

Luego, el cálculo. El descuento llega como porcentaje y el IVA autoritativo se calcula a nivel de documento:

```php
<?php

namespace App\Services\Sales;

use App\Services\Support\Money;
use InvalidArgumentException;

class QuoteCalculator
{
    /**
     * @param array<int, array{quantity:string, unit_price:string, discount_percent:string,
     *                         tax_treatment:string, tax_rate:?string}> $lines
     */
    public function calculate(array $lines, string $currency): array
    {
        $resultLines = [];
        $subtotal = '0';
        $discountTotal = '0';
        $taxableNet = '0';
        $taxRate = null;

        foreach ($lines as $i => $line) {
            if (bccomp($line['quantity'], '0', 3) <= 0) {
                throw new InvalidArgumentException("Línea {$i}: la cantidad debe ser mayor que cero.");
            }
            if (bccomp($line['discount_percent'], '0', 4) < 0 || bccomp($line['discount_percent'], '100', 4) > 0) {
                throw new InvalidArgumentException("Línea {$i}: descuento fuera de 0–100.");
            }
            if ($line['tax_treatment'] === 'NOT_DEFINED') {
                throw new InvalidArgumentException("Línea {$i}: el servicio no tiene tratamiento tributario validado.");
            }

            $gross = Money::roundFor(bcmul($line['quantity'], $line['unit_price'], 8), $currency);
            $discount = Money::roundFor(bcdiv(bcmul($gross, $line['discount_percent'], 8), '100', 8), $currency);
            $net = bcsub($gross, $discount, Money::scale($currency));

            if ($line['tax_treatment'] === 'TAXABLE') {
                if ($line['tax_rate'] === null) {
                    throw new InvalidArgumentException("Línea {$i}: servicio gravado sin tasa configurada.");
                }
                $taxRate ??= $line['tax_rate'];
                if (bccomp($line['tax_rate'], $taxRate, 4) !== 0) {
                    throw new InvalidArgumentException('Tasas de impuesto distintas en un mismo documento: requiere decisión contable.');
                }
                $taxableNet = bcadd($taxableNet, $net, 8);
            }

            $subtotal = bcadd($subtotal, $gross, 8);
            $discountTotal = bcadd($discountTotal, $discount, 8);
            $resultLines[] = ['discount_amount' => $discount, 'line_total' => $net];
        }

        // IVA autoritativo: una sola vez sobre el neto gravado del documento.
        $taxTotal = $taxRate === null
            ? '0'
            : Money::roundFor(bcdiv(bcmul($taxableNet, $taxRate, 8), '100', 8), $currency);
        $netTotal = bcsub($subtotal, $discountTotal, 8);

        return [
            'lines' => $resultLines,
            'subtotal' => Money::roundFor($subtotal, $currency),
            'discount_total' => Money::roundFor($discountTotal, $currency),
            'tax_total' => $taxTotal,
            'grand_total' => Money::roundFor(bcadd($netTotal, $taxTotal, 8), $currency),
        ];
    }
}
```

`quote_items.tax_amount` (informativo) puede asignarse proporcionalmente después; nunca se suma para obtener `tax_total`. Pruebas obligatorias: CLP con resultados en x,5; descuento 0 % y 100 %; mezcla de líneas gravadas y exentas; tasa ausente en línea gravada. Verificar `php -m` que BCMath esté disponible antes de usarlo. La regla de IVA a nivel de documento debe confirmarla el contador (PEND-002).

### 8.3 Servicio de conversión de cotización aceptada a orden

La conversión es transaccional e idempotente mediante `idempotency_keys` (DEC-023). Primero, el servicio genérico:

```php
<?php

namespace App\Services\Support;

use App\Exceptions\IdempotencyConflictException;
use App\Models\IdempotencyKey;
use Illuminate\Database\UniqueConstraintViolationException;

class IdempotencyService
{
    /**
     * Ejecuta $operation una sola vez por (compañía, acción, clave).
     * Llamar DENTRO de la transacción de negocio: la clave y el resultado se confirman
     * o revierten juntos, así una falla no deja la clave bloqueada.
     *
     * @param callable(): \Illuminate\Database\Eloquent\Model $operation
     * @param callable(array): \Illuminate\Database\Eloquent\Model $resolveExisting
     */
    public function once(
        int $companyId,
        ?int $userId,
        string $action,
        string $key,
        string $payloadHash,
        callable $operation,
        callable $resolveExisting,
    ): mixed {
        $existing = IdempotencyKey::query()
            ->where('company_id', $companyId)
            ->where('action', $action)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if (!hash_equals($existing->payload_hash, $payloadHash)) {
                throw new IdempotencyConflictException('IDEMPOTENCY_KEY_REUSED');
            }
            return $resolveExisting($existing->response_reference);
        }

        try {
            $record = IdempotencyKey::create([
                'company_id' => $companyId,
                'user_id' => $userId,
                'action' => $action,
                'idempotency_key' => $key,
                'payload_hash' => $payloadHash,
                'status' => 'IN_PROGRESS',
                'expires_at' => now('UTC')->addDays(7),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otra petición concurrente con la misma clave ganó la carrera; el cliente reintenta.
            throw new IdempotencyConflictException('IDEMPOTENCY_IN_PROGRESS');
        }

        $result = $operation();
        $record->update([
            'status' => 'COMPLETED',
            'response_reference' => ['public_id' => $result->public_id],
        ]);

        return $result;
    }
}
```

`UniqueConstraintViolationException` existe desde Laravel 10.x; en versiones anteriores capturar `QueryException` con código SQLSTATE `23000`. Un interbloqueo entre dos peticiones concurrentes lo reintenta `DB::transaction(..., attempts: 3)`, y en el reintento la clave ya aparece como `COMPLETED`.

Luego, la conversión:

```php
<?php

namespace App\Services\Operations;

use App\Enums\QuoteStatus;
use App\Enums\ServiceOrderStatus;
use App\Models\Quote;
use App\Models\ServiceOrder;
use App\Models\ServiceEvent;
use App\Models\User;
use App\Services\Support\IdempotencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use DomainException;

class ConvertQuoteToServiceOrder
{
    public function __construct(private IdempotencyService $idempotency) {}

    public function execute(Quote $quote, User $actor, string $idempotencyKey): ServiceOrder
    {
        $payloadHash = hash('sha256', 'convert:'.$quote->public_id);

        return DB::transaction(fn () => $this->idempotency->once(
            (int) $quote->company_id,
            $actor->id,
            'operations.order.create_from_quote',
            $idempotencyKey,
            $payloadHash,
            fn () => $this->convert($quote, $actor),
            fn (array $ref) => ServiceOrder::query()
                ->where('company_id', $quote->company_id)
                ->where('public_id', $ref['public_id'])
                ->with('items', 'events')
                ->firstOrFail(),
        ), attempts: 3);
    }

    private function convert(Quote $quote, User $actor): ServiceOrder
    {
        $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

        if ((int) $quote->company_id !== (int) $actor->company_id) {
            throw new AuthorizationException('La cotización no pertenece a la compañía del usuario.');
        }
        if (!$actor->hasPermission('operations.orders.create', (int) $quote->company_id)) {
            throw new AuthorizationException('No tiene permiso para crear órdenes de servicio.');
        }
        if ($quote->status !== QuoteStatus::Accepted) {
            throw new DomainException('Solo una cotización aceptada puede convertirse en orden.');
        }
        if (!$quote->current_version_id) {
            throw new DomainException('La cotización no tiene versión vigente.');
        }

        $version = $quote->versions()->whereKey($quote->current_version_id)->firstOrFail();
        if ($version->issued_at === null) {
            throw new DomainException('La versión vigente no fue emitida.');
        }

        // DEC-023: una versión aceptada genera una sola orden, aunque llegue otra clave de idempotencia.
        $alreadyConverted = ServiceOrder::query()
            ->where('company_id', $quote->company_id)
            ->where('quote_version_id', $version->id)
            ->exists();
        if ($alreadyConverted) {
            throw new DomainException('Esta versión de la cotización ya tiene una orden de servicio.');
        }

        $order = ServiceOrder::create([
            'company_id' => $quote->company_id,
            'order_number' => app(\App\Services\Numbers\BusinessNumberGenerator::class)
                ->next((int) $quote->company_id, 'service_order'),
            'customer_party_id' => $quote->customer_party_id,
            'quote_id' => $quote->id,
            'quote_version_id' => $version->id,
            'status' => ServiceOrderStatus::Draft,
            'priority' => 'NORMAL',
            'requested_at' => now('UTC'),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        foreach ($version->items as $item) {
            $order->items()->create([
                'service_id' => $item->service_id,
                'quote_item_id' => $item->id,
                'description_snapshot' => $item->description_snapshot,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'unit_price_snapshot' => $item->unit_price,
                'line_total_snapshot' => $item->line_total,
                'status' => 'PENDING',
            ]);
        }

        ServiceEvent::create([
            'service_order_id' => $order->id,
            'event_type' => 'ORDER_CREATED_FROM_QUOTE',
            'from_status' => null,
            'to_status' => ServiceOrderStatus::Draft->value,
            'description' => 'Orden creada desde cotización aceptada.',
            'actor_user_id' => $actor->id,
            'actor_type' => 'HUMAN',
            'occurred_at' => now('UTC'),
            'metadata_redacted' => ['quote_public_id' => $quote->public_id],
        ]);

        app(\App\Services\Audit\AuditLogger::class)->log(
            $actor, (int) $quote->company_id, 'operations.order.create_from_quote', 'SUCCESS',
            'service_order', $order->public_id, null, [], ['status' => 'DRAFT']
        );

        return $order->load('items', 'events');
    }
}
```

Antes de usar en producción, completar obligatoriamente:

- Migración de `idempotency_keys` con `UNIQUE(company_id, action, idempotency_key)` y excepción `IdempotencyConflictException` mapeada a `409`.
- Índice `UNIQUE(quote_version_id)` en `service_orders` como respaldo en base de datos de DEC-023 (MySQL admite múltiples `NULL`).
- Servicio de folios atómico descrito en §8.4.
- Validación de moneda y snapshots.
- Prueba concurrente que demuestre que dos solicitudes paralelas no crean dos órdenes.
- Verificar que crear `ServiceEvent`/`AuditLog` no falle silenciosamente. Mantener todo en la misma transacción cuando se trate del mismo cambio de negocio.

### 8.4 Generador seguro de folios

Crear una tabla `document_sequences` con índice único `(company_id, sequence_key, period_key)`, campos `prefix`, `next_number`, `padding` y timestamps. Dentro de transacción:

1. Crear la fila si no existe, con protección contra carrera por índice único.
2. Bloquear la fila con `lockForUpdate()`.
3. Leer `next_number`, producir el folio y aumentar la secuencia.
4. Commit junto con la entidad de negocio cuando el servicio lo permita.
5. Agregar pruebas con concurrencia y rollback.

No usar `MAX(quote_number) + 1`; tampoco usar un `COUNT(*)` como secuencia. Los folios de la aplicación son internos y no sustituyen folios tributarios/legales.

### 8.5 Servicio de transición de estado de orden

```php
public function transition(
    ServiceOrder $order,
    ServiceOrderStatus $next,
    User $actor,
    ?string $reason = null,
): ServiceOrder {
    return DB::transaction(function () use ($order, $next, $actor, $reason) {
        $locked = ServiceOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        if ((int) $locked->company_id !== (int) $actor->company_id) {
            throw new AuthorizationException();
        }
        $this->authorizeTransition($locked, $actor, $next);
        $current = $locked->status;
        if (!$current->canTransitionTo($next)) {
            throw new DomainException("Transición inválida: {$current->value} -> {$next->value}");
        }
        if ($next->requiresReason() && blank($reason)) {
            throw new DomainException('Se requiere motivo para esta transición.');
        }
        $locked->status = $next;
        $locked->updated_by = $actor->id;
        if ($next === ServiceOrderStatus::Completed) $locked->completed_at = now('UTC');
        if ($next === ServiceOrderStatus::Closed) $locked->closed_at = now('UTC');
        if (in_array($next, [ServiceOrderStatus::Cancelled, ServiceOrderStatus::NotPerformed], true)) {
            $locked->cancellation_reason = $reason;
        }
        $locked->save();
        $locked->events()->create([
            'event_type' => 'STATUS_CHANGED',
            'from_status' => $current->value,
            'to_status' => $next->value,
            'description' => $reason,
            'actor_user_id' => $actor->id,
            'actor_type' => 'HUMAN',
            'occurred_at' => now('UTC'),
        ]);
        return $locked->refresh();
    }, attempts: 3);
}
```

El ejemplo depende de la clase que contenga `authorizeTransition` y del método/relación de eventos; adaptar imports y requerir autorización explícita por transición. `CLOSED` debe ser terminal y las correcciones posteriores deben registrarse como eventos/ajustes autorizados.

### 8.6 Agenda y prevención de colisiones

En `AppointmentService`:

1. Validar `end_at > start_at` y zona horaria.
2. Verificar que servicio, cliente, dirección y orden pertenezcan a la misma empresa.
3. Abrir transacción y bloquear el recurso de agenda (por ejemplo, trabajador/equipo o fila de calendario del día) con estrategia documentada.
4. Detectar intervalos que se solapan: `existing.start_at < new.end_at AND existing.end_at > new.start_at` excluyendo canceladas/no-show según reglas.
5. Validar disponibilidad, tiempo de desplazamiento y buffers configurados.
6. Crear/actualizar cita y asignaciones en la misma transacción.
7. Guardar evento y notificación mediante outbox/job después del commit.

Un `lockForUpdate` sobre las citas existentes no bloquea necesariamente la inexistencia de una cita y no resuelve todas las carreras. Elegir una estrategia de serialización (fila de agenda por recurso/fecha, advisory lock controlado o restricción de calendario derivada) y probarla en MySQL real.

---

## 9. Factories y seeders

### 9.1 `CompanySeeder`

Crear la empresa inicial solo si no existe. El `tax_identifier`, teléfono y dirección deben cargarse desde configuración segura o validarse con el administrador; no inventar datos corporativos.

```php
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $publicId = config('app.primary_company_public_id');
        if (blank($publicId)) {
            // Sin esta guarda, firstOrCreate buscaría public_id NULL, no encontraría nada
            // y crearía una empresa nueva en cada ejecución.
            throw new RuntimeException('Defina APP_PRIMARY_COMPANY_PUBLIC_ID antes de sembrar la empresa.');
        }

        if (Company::query()->where('public_id', $publicId)->exists()) {
            return;
        }

        $company = new Company([
            'legal_name' => 'Ergo SaniTas SpA',
            'trade_name' => 'Ergo SaniTas',
            'country_code' => 'CL',
            'default_currency' => 'CLP',
            'timezone' => 'America/Santiago',
            'status' => 'ACTIVE',
        ]);
        // public_id no es fillable: con firstOrCreate se descartaría en silencio y el hook
        // generaría un ULID aleatorio, duplicando la empresa en la siguiente ejecución.
        $company->public_id = $publicId;
        $company->save();
    }
}
```

Definir `APP_PRIMARY_COMPANY_PUBLIC_ID` o generar la clave inicial mediante un comando de instalación. No usar un `public_id` de producción fijo dentro de repositorios compartidos si ese valor forma parte de la identidad del entorno.

### 9.2 Roles/permisos idempotentes

```php
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'SUPER_ADMIN' => 'Administrador de plataforma',
            'DIRECCION' => 'Dirección',
            'ADMINISTRACION' => 'Administración',
            'FINANZAS' => 'Finanzas',
            'COMERCIAL' => 'Comercial',
            'OPERACIONES' => 'Operaciones',
            'PROFESIONAL_SALUD' => 'Profesional de salud',
            'NUTRICION' => 'Nutrición',
            'INVENTARIO' => 'Inventario',
            'RRHH' => 'Recursos humanos',
            'AUDITOR_LECTURA' => 'Auditor de lectura',
            'AGENT_SERVICE' => 'Servicio de agentes IA',
        ];

        foreach ($roles as $name => $label) {
            Role::firstOrCreate(['name' => $name], [
                'display_name' => $label,
                'is_system' => true,
            ]);
        }

        foreach ($this->permissions() as $permission) {
            Permission::firstOrCreate(['name' => $permission['name']], $permission);
        }

        // Asignación inicial propuesta; la definitiva sale de AUTHORIZATION_MATRIX.md (PEND-004).
        // Lista explícita por rol, sin wildcard.
        $ai = ['ai.agents.read', 'ai.conversations.read', 'ai.conversations.create', 'ai.runs.read', 'ai.runs.create', 'ai.runs.cancel'];
        $map = [
            'DIRECCION' => array_merge([
                'dashboard.read', 'crm.customers.read', 'catalog.services.read',
                'sales.opportunities.read', 'sales.quotes.read', 'sales.quotes.approve',
                'operations.orders.read', 'operations.appointments.read',
                'tasks.read', 'tasks.create', 'tasks.update', 'approvals.read', 'approvals.decide',
                'documents.read', 'documents.download', 'audit.read', 'ai.automations.manage',
            ], $ai),
            'ADMINISTRACION' => array_merge([
                'dashboard.read', 'crm.customers.read', 'crm.customers.create', 'crm.customers.update', 'crm.customers.archive',
                'catalog.services.read', 'catalog.services.manage', 'catalog.prices.manage',
                'documents.read', 'documents.upload', 'documents.download', 'documents.manage',
                'tasks.read', 'tasks.create', 'tasks.update', 'approvals.read',
            ], $ai),
            'COMERCIAL' => array_merge([
                'dashboard.read', 'crm.customers.read', 'crm.customers.create', 'crm.customers.update',
                'crm.interactions.read', 'crm.interactions.create', 'catalog.services.read',
                'sales.opportunities.read', 'sales.opportunities.create', 'sales.opportunities.update',
                'sales.quotes.read', 'sales.quotes.create', 'sales.quotes.update_draft', 'sales.quotes.submit',
                'sales.quotes.issue', 'sales.quotes.send', 'sales.quotes.accept', 'sales.quotes.cancel',
                'tasks.read', 'tasks.create', 'tasks.update', 'approvals.read',
            ], $ai),
            'OPERACIONES' => array_merge([
                'dashboard.read', 'crm.customers.read', 'catalog.services.read',
                'operations.orders.read', 'operations.orders.create', 'operations.orders.update',
                'operations.orders.transition', 'operations.orders.cancel',
                'operations.appointments.read', 'operations.appointments.manage',
                'tasks.read', 'tasks.create', 'tasks.update', 'approvals.read',
            ], $ai),
            'AUDITOR_LECTURA' => ['audit.read', 'security.events.read', 'approvals.read', 'ai.runs.read'],
            // AGENT_SERVICE no recibe permisos de rol: cada herramienta declara los suyos (DEC-020).
        ];
        foreach ($map as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->firstOrFail();
            $role->permissions()->syncWithoutDetaching(
                Permission::whereIn('name', $permissionNames)->pluck('id')->all()
            );
        }
    }

    /** Catálogo canónico del MVP: DECISIONS.md DEC-022. */
    private function permissions(): array
    {
        $labels = [
            'users.read' => 'Ver usuarios', 'users.manage' => 'Gestionar usuarios',
            'roles.read' => 'Ver roles', 'roles.manage' => 'Asignar roles',
            'dashboard.read' => 'Ver dashboard',
            'crm.customers.read' => 'Ver clientes', 'crm.customers.create' => 'Crear clientes',
            'crm.customers.update' => 'Editar clientes', 'crm.customers.archive' => 'Archivar clientes',
            'crm.interactions.read' => 'Ver interacciones', 'crm.interactions.create' => 'Registrar interacciones',
            'catalog.services.read' => 'Ver catálogo', 'catalog.services.manage' => 'Gestionar servicios',
            'catalog.prices.manage' => 'Gestionar precios',
            'sales.opportunities.read' => 'Ver oportunidades', 'sales.opportunities.create' => 'Crear oportunidades',
            'sales.opportunities.update' => 'Editar oportunidades',
            'sales.quotes.read' => 'Ver cotizaciones', 'sales.quotes.create' => 'Crear cotizaciones',
            'sales.quotes.update_draft' => 'Editar borradores', 'sales.quotes.submit' => 'Enviar a aprobación',
            'sales.quotes.issue' => 'Emitir cotizaciones', 'sales.quotes.approve' => 'Aprobar cotizaciones',
            'sales.quotes.send' => 'Registrar envío', 'sales.quotes.accept' => 'Registrar respuesta del cliente',
            'sales.quotes.cancel' => 'Cancelar cotizaciones',
            'operations.orders.read' => 'Ver órdenes', 'operations.orders.create' => 'Crear órdenes',
            'operations.orders.update' => 'Editar órdenes', 'operations.orders.transition' => 'Cambiar estado de órdenes',
            'operations.orders.cancel' => 'Cancelar órdenes',
            'operations.appointments.read' => 'Ver agenda', 'operations.appointments.manage' => 'Gestionar agenda',
            'tasks.read' => 'Ver tareas', 'tasks.create' => 'Crear tareas', 'tasks.update' => 'Actualizar tareas',
            'approvals.read' => 'Ver aprobaciones', 'approvals.decide' => 'Decidir aprobaciones',
            'documents.read' => 'Ver documentos', 'documents.upload' => 'Subir documentos',
            'documents.download' => 'Descargar documentos', 'documents.manage' => 'Gestionar documentos',
            'ai.agents.read' => 'Ver agentes', 'ai.conversations.read' => 'Ver conversaciones IA',
            'ai.conversations.create' => 'Crear conversaciones IA', 'ai.runs.read' => 'Ver ejecuciones IA',
            'ai.runs.create' => 'Solicitar tareas a la IA', 'ai.runs.cancel' => 'Cancelar ejecuciones IA',
            'ai.knowledge.read' => 'Ver fuentes de conocimiento', 'ai.knowledge.manage' => 'Gestionar fuentes de conocimiento',
            'ai.automations.manage' => 'Gestionar automatizaciones',
            'audit.read' => 'Consultar auditoría', 'security.events.read' => 'Ver eventos de seguridad',
        ];

        return array_map(
            fn (string $name, string $label) => [
                'name' => $name,
                'display_name' => $label,
                'module' => explode('.', $name)[0],
            ],
            array_keys($labels),
            $labels,
        );
    }
}
```

Ajustar los imports, agregar las relaciones `permissions()` y probar que los seeds se puedan ejecutar repetidamente. No asignar el rol `SUPER_ADMIN` automáticamente a cualquier usuario, no incluir contraseñas en el seeder y no crear usuarios administradores con credenciales conocidas.

### 9.3 Catálogo de servicios

`ServiceCatalogSeeder` debe ejecutarse únicamente con flag explícito en `local`/`testing` o durante una instalación autorizada. Servicios potenciales mencionados históricamente incluyen exámenes ocupacionales, toma de muestras a domicilio, ECG, chequeo cardiovascular deportivo, bioimpedancia, MAPA/Holter de presión arterial, evaluación deportiva, nutrición y telemedicina. Antes de activarlos, confirmar descripción vigente, modalidad, precio, duración, requisitos, impuestos e insumos. El seeder no debe sobrescribir precios modificados por una persona autorizada.

### 9.4 Administrador inicial

Implementar un comando interactivo de instalación que solicite nombre/correo, valide entorno y genere una contraseña temporal aleatoria que solo se muestre una vez o active un flujo de recuperación. Exigir cambio de contraseña y MFA cuando esté disponible. No mantener secretos en Git ni en fixtures.

---

## 10. Validación HTTP y recursos API

Cada endpoint debe utilizar `FormRequest` y `Policy`.

Ejemplo conceptual para crear una parte:

```php
public function rules(): array
{
    return [
        'party_type' => ['required', Rule::in(['PERSON', 'ORGANIZATION'])],
        'display_name' => ['required', 'string', 'max:200'],
        'legal_name' => ['nullable', 'string', 'max:200'],
        'email' => ['nullable', 'email:rfc', 'max:254'],
        'phone' => ['nullable', 'string', 'max:40'],
        'website' => ['nullable', 'url', 'max:255'],
        // company_id nunca se acepta de manera directa desde la petición.
    ];
}
```

El controlador establece `company_id` desde el contexto autenticado y el servicio vuelve a verificar las referencias relacionadas. No devolver `tax_identifier_encrypted`, `tax_identifier_lookup_hash`, instrucciones de acceso, información clínica ni columnas internas en un Resource público.

Formato consistente recomendado:

```json
{
  "data": { "public_id": "01...", "display_name": "Cliente de ejemplo" },
  "meta": { "request_id": "01..." }
}
```

Errores de validación/autorización deben devolver códigos HTTP correctos, sin stack trace ni detalles internos en producción. Paginación, filtrado y ordenamiento se limitan a campos allowlist.

---

## 11. Seguridad de datos y privacidad

### 11.1 Datos sensibles

- Separar CRM general de los módulos clínicos.
- Cifrar identificadores tributarios y campos personales que requieran cifrado de campo; controlar las claves fuera de la base de datos.
- Usar hash HMAC separado cuando se requiera búsqueda exacta de un identificador cifrado.
- No incluir domicilio detallado, instrucciones de acceso, diagnósticos, resultados de exámenes ni datos sanitarios en prompts/logs generales.
- El acceso clínico requiere permisos explícitos, relación de atención/propósito y auditoría; rol amplio no debe implicar acceso universal por defecto.
- No enviar datos identificables a proveedor/modelo externo de IA sin autorización, evaluación de privacidad, contrato y controles definidos.
- Configurar discos privados y enlaces temporales de corta duración para archivos privados.
- Proteger exportaciones, backups, adjuntos de correo y logs igual que la fuente.

### 11.2 Auditoría

Registrar actor, compañía, acción normalizada, resultado, recurso, fecha UTC y resumen redactado. No guardar contraseñas, tokens, cookies, secretos de proveedor, prompt completo con datos sensibles ni blobs clínicos. Los eventos de auditoría son append-only. Configurar retención, monitoreo de acceso y exportación segura.

### 11.3 Retención y borrado

Antes de producción se debe aprobar una matriz por tipo de información que defina finalidad, base jurídica, responsable, acceso, conservación, archivo y eliminación. No improvisar plazos. Para cotizaciones aceptadas, órdenes cerradas, finanzas e historia clínica utilizar procesos de retención/enmienda/reversa trazables conforme a las obligaciones aplicables.

### 11.4 Seguridad de configuración

- secretos solo en variables de entorno o gestor de secretos; nunca commitear `.env`;
- `APP_DEBUG=false` en producción;
- HTTPS obligatorio;
- cookies seguras/HttpOnly/SameSite acorde a la autenticación;
- CORS allowlist;
- rate limit en login, recuperación, endpoints IA y operaciones caras;
- rotación de secretos y tokens;
- permisos de sistema operativo mínimos;
- respaldos cifrados, acceso restringido y pruebas de recuperación;
- no habilitar SQL arbitrario o ejecución de shell a agentes IA.

---

## 12. Integridad, índices y rendimiento

### 12.1 Índices esenciales

Mantener, como mínimo:

- `companies(public_id UNIQUE)`, `companies(status)`.
- `users(public_id UNIQUE)`, `users(email UNIQUE)`, `users(company_id,status)`.
- `parties(public_id UNIQUE)`, `parties(company_id,party_type,status)`, `parties(company_id,display_name)`.
- `party_addresses(company_id,party_id)`, `party_addresses(company_id,commune,region)`.
- `services(company_id,code UNIQUE)`, `services(company_id,status,service_category_id)`.
- `service_prices(price_list_id,service_id,valid_from UNIQUE)` con reglas de nulidad evaluadas en MySQL.
- `opportunities(company_id,stage,next_action_at)`, `opportunities(owner_user_id,stage)`.
- `quotes(company_id,quote_number UNIQUE)`, `quotes(company_id,status,created_at)`.
- `quote_versions(quote_id,version_number UNIQUE)`.
- `quote_items(quote_version_id,line_number UNIQUE)`.
- `service_orders(company_id,order_number UNIQUE)`, `service_orders(company_id,status,planned_start_at)`.
- `appointments(company_id,start_at,end_at)`, `appointments(customer_party_id,start_at)`, `appointments(status,start_at)`.
- `service_events(service_order_id,occurred_at)`.
- `audit_logs(company_id,occurred_at)`, `audit_logs(resource_type,resource_id,occurred_at)`.

Comprobar planes de consulta sobre datos de prueba realistas. Los índices deben responder a los filtros reales, no duplicarse sin medición.

### 12.2 Paginación

Preferir paginación por cursor para historiales de gran volumen; usar paginación convencional en pantallas administrativas pequeñas. Aplicar límites máximos y orden determinista (por ejemplo, `created_at, id`). Evitar `SELECT *` en listados y N+1 queries mediante `with()`/selects explícitos.

### 12.3 Consultas scoped

Ejemplo:

```php
$quotes = Quote::query()
    ->where('company_id', $request->user()->company_id)
    ->with(['customer:id,public_id,display_name', 'currentVersion:id,quote_id,version_number,grand_total,currency'])
    ->latest('created_at')
    ->cursorPaginate(25);
```

La consulta de ejemplo no sustituye a la Policy ni al filtrado por alcance de usuario; los permisos/relaciones pueden requerir restricciones adicionales.

---

## 13. Pruebas automatizadas obligatorias

### 13.1 Suite mínima del MVP

1. `migrate:fresh --seed` en una base descartable de testing.
2. Crear una compañía y usuario con factory.
3. Validar unicidad de emails, códigos de servicio, folios y versión de cotización.
4. Validar acceso restringido por compañía (lectura, edición, exportación y API directa).
5. Validar permisos de CRM, comercial y operaciones.
6. Rechazar creación de cotización con servicio de otra compañía.
7. Recalcular totales en backend y rechazar descuentos inválidos.
8. Impedir cambiar una versión emitida/aceptada.
9. Rechazar transición de orden no permitida.
10. Convertir cotización aceptada a orden con snapshots correctos.
11. Repetir la conversión con la misma idempotency key sin duplicar orden/items.
12. Registrar evento y auditoría dentro de transacción; verificar rollback en error.
13. Rechazar cita con `end_at <= start_at`.
14. Verificar prevención concurrente de colisiones con MySQL real.
15. Asegurar que los logs no incluyen RUT, contraseñas, tokens o datos clínicos.
16. Confirmar que un usuario sin permiso no puede acceder a una URL por conocer el `public_id`.

### 13.2 Ejemplo de prueba de Policy

```php
public function test_user_cannot_view_quote_from_another_company(): void
{
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::factory()->for($companyA)->create(['status' => 'ACTIVE']);
    $quote = Quote::factory()->for($companyB)->create();

    $this->actingAs($user)
        ->getJson('/api/v1/quotes/'.$quote->public_id)
        ->assertForbidden();
}
```

Adaptar la prueba al mecanismo de autenticación API de la aplicación y asegurar que la respuesta no filtra datos del recurso denegado. Para algunas estrategias se prefiere devolver `404` por no revelar existencia; elegir una política uniforme.

### 13.3 Entorno de prueba

Usar MySQL de pruebas para constraints y concurrencia; SQLite puede servir para unit tests, pero no es sustituto perfecto de MySQL (tipos, FK, collation, `CHECK`, bloqueo y comportamiento JSON pueden diferir). Nunca ejecutar factories con pacientes reales.

### 13.4 Comandos de verificación

```bash
php artisan test
php artisan test --testsuite=Feature
php artisan migrate:status
php artisan route:list --path=api
php artisan config:clear
php artisan cache:clear
```

Solo en base local/test descartable:

```bash
php artisan migrate:fresh --seed
```

Antes de usarlo, verificar la conexión activa con `php artisan about`, variables del `.env` y nombre de la base de datos. No ejecutar ese comando en producción ni sobre una base con información que deba conservarse.

---

## 14. Comandos de implementación por etapas

### Etapa A — inventariar entorno

```bash
php artisan about
composer show laravel/framework
php -m
php artisan migrate:status
```

### Etapa B — generar esqueletos (ejemplo)

```bash
php artisan make:model Company -m
php artisan make:model Party -m
php artisan make:model PartyAddress -m
php artisan make:model ServiceCategory -m
php artisan make:model Service -m
php artisan make:model Opportunity -m
php artisan make:model Quote -m
php artisan make:model QuoteVersion -m
php artisan make:model QuoteItem -m
php artisan make:model ServiceOrder -m
php artisan make:model ServiceOrderItem -m
php artisan make:model Appointment -m
php artisan make:policy QuotePolicy --model=Quote
php artisan make:policy ServiceOrderPolicy --model=ServiceOrder
php artisan make:seeder CompanySeeder
php artisan make:seeder RolesAndPermissionsSeeder
```

Antes de crear migraciones duplicadas, comprobar si `users`, `cache`, `jobs`, `personal_access_tokens` u otras tablas ya existen por el skeleton de Laravel o los paquetes instalados.

### Etapa C — ordenar, revisar y migrar

```bash
php artisan migrate --pretend
php artisan migrate
php artisan migrate:status
```

Ejecutar primero contra MySQL local de desarrollo. `--pretend` ayuda a inspeccionar SQL, pero no garantiza que las migraciones estén libres de errores en runtime.

### Etapa D — pruebas y calidad

```bash
php artisan test
composer validate
composer audit
```

Ejecutar `composer audit` según la política de dependencias del proyecto y revisar los resultados antes de actualizar paquetes. Si el repositorio usa Pint/PHPStan/Pest u otras herramientas, ejecutar los comandos realmente configurados en `composer.json`; no asumir que están instalados.

---

## 15. Tabla de trazabilidad de dominios

| Dominio de `DATABASE_SPEC.md` | Implementación Laravel | Fase sugerida | Estado inicial |
|---|---|---:|---|
| Empresa/identidad | `Company`, `User` | 0 | Implementar primero |
| Roles/permisos | `Role`, `Permission`, Policies | 0 | Implementar primero |
| Auditoría/seguridad | `AuditLog`, `SecurityEvent` | 0 | Auditoría mínima obligatoria |
| CRM | `Party`, `PartyAddress`, `PartyRole`, `Interaction` | 0 | Implementar núcleo |
| Catálogo de servicios | categorías, servicios, precios, costos, requisitos | 0 | Implementar; precios configurables |
| Oportunidades | `Opportunity`, `OpportunityActivity` | 0 | Implementar núcleo |
| Cotizaciones | `Quote`, `QuoteVersion`, `QuoteItem` | 0 | Implementar snapshots y folios |
| Agenda/órdenes | `ServiceOrder`, `Appointment`, eventos/tareas | 0 | Implementar con control de estado |
| Documentos | documentos, versiones, relaciones (MVP); plantillas y aprobaciones (posterior) | 0 / posterior | Solo documentos administrativos en el MVP |
| IA y automatización interna | agentes, versiones, conversaciones, runs, tools, workflows | 0 (MVP-3) | Herramientas R0–R2; Ollama local |
| Finanzas | categorías, transacciones, CxC/CxP, pagos, presupuesto | 1 | No mezclar con facturación oficial sin integración validada |
| Compras/inventario | proveedores, solicitudes, stock, lotes, activos | 1 | Movimientos append-only/reversas |
| IA ampliada | base de conocimiento (RAG), nuevos agentes | 2 | Herramientas allowlist; aprobación por riesgo |
| Personas/cumplimiento | perfiles, competencias, obligaciones, riesgos | 3 | Acceso por rol y finalidad |
| Clínica | sujetos, fichas, mediciones y signoffs | 4 | Desactivado hasta validación específica |
| Medicina deportiva | atletas, protocolos, sesiones, cálculos e informes | 4 | Datos de salud/rendimiento restringidos |
| Integraciones/producción | outbox, webhooks, monitoreo y recuperación | 5 | Requiere diseño operacional |

---

## 16. Decisiones pendientes y riesgos

Antes de cerrar una versión apta para producción, resolver y registrar estas decisiones:

1. Versión efectiva de PHP, Laravel y MySQL.
2. Estrategia de autenticación SPA/API y expiración de sesiones/tokens.
3. Unicidad global o por compañía de email de usuario.
4. Unicidad/normalización de RUT y claves de cifrado/HMAC.
5. Mecanismo de folios y reinicio por periodo (si aplica).
6. Reglas de precio, descuento, IVA/exención, redondeo y CLP por tipo de servicio; revisión contable.
7. Estado válido de la cotización/versión que permite aceptar y convertir a orden.
8. Política de solapamiento de citas, trabajadores, equipos, rutas y buffers de traslado.
9. Retención y eliminación por categoría de dato.
10. Almacenamiento privado de documentos, backup y recuperación.
11. Matriz RBAC aprobada y separación de funciones.
12. Política de acceso a datos clínicos y profesionales autorizados.
13. Proveedor/modelos de IA, ubicación del procesamiento y clases de datos permitidas.
14. Política de auditoría, alertas de seguridad y respuesta a incidentes.
15. Integraciones y sistema contable/tributario oficial; esta base administrativa no sustituye registros tributarios.

No afirmar que el sistema satisface obligaciones legales solo porque existen tablas o roles con nombres relacionados con cumplimiento. La adecuación legal requiere evaluación de procesos, controles y normativa aplicable vigente.

---

## 17. Definición de terminado (Definition of Done)

Una tabla/módulo se considera implementado solo cuando:

- la migración ha sido ejecutada y verificada en MySQL de desarrollo/test;
- índices, FKs y constraints coinciden con el diseño aprobado;
- el modelo contiene relaciones y casts necesarios;
- no existe mass assignment abierto;
- requests validan entradas y excluyen columnas internas;
- Policies comprueban permisos, compañía, relación con el recurso y estado;
- reglas de negocio están en Services/Actions transaccionales;
- el evento/auditoría se registra sin datos sensibles innecesarios;
- factories/seeds son repetibles y no crean secretos ni datos reales;
- hay pruebas positivas, negativas, de integridad y, si aplica, concurrencia/idempotencia;
- errores/rollback se han probado;
- endpoints y recursos han sido documentados;
- se ha actualizado `DATABASE_SPEC.md` si existe desviación aprobada;
- los resultados de los comandos de prueba han sido realmente ejecutados y registrados.

---

## 18. Instrucción para el agente programador

1. Lee completos `INGENERIA_JARVIS_MASTER_SPEC.md`, `PRODUCT_REQUIREMENTS.md` y `DATABASE_SPEC.md` antes de modificar el repositorio.
2. Inspecciona el proyecto actual; no dupliques tablas, auth, paquetes ni archivos existentes.
3. Verifica versiones instaladas y adapta los ejemplos a ellas.
4. Implementa **solo la fase autorizada** y documenta qué tablas/rutas quedan inactivas.
5. Genera una migración independiente por tabla/cambio lógico y respeta las dependencias.
6. Implementa relaciones Eloquent, casts, enums, factories, seeders, Policies y Services.
7. Usa transacciones, bloqueo e idempotencia donde el proceso pueda duplicarse o competir.
8. No aceptar importes, estados, compañía o permisos desde el payload del agente IA.
9. Mantener separados CRM, finanzas y datos clínicos; los datos clínicos no pasan por endpoints genéricos.
10. Ejecuta y reporta comandos reales de test/migrate. Nunca informes pruebas no realizadas.
11. No ejecutar comandos destructivos sin verificar entorno y autorización explícita.
12. Documenta diferencias o dudas en un archivo `IMPLEMENTATION_NOTES.md` y propone la corrección del documento fuente cuando sea necesaria.

### Primer encargo de código recomendado

Implementar las migraciones y modelos de Fase 0, empezando por `companies`, `users`, RBAC y auditoría; después CRM, catálogo, oportunidades, cotizaciones versionadas, órdenes, agenda y eventos. Una vez aprobados esos cimientos, crear `API_SPEC.md`, `AUTHORIZATION_MATRIX.md` y pruebas de aceptación de los flujos comerciales y operacionales.

---

## 19. Historial de versiones

| Versión | Fecha | Cambios |
|---|---|---|
| 1.0.0 | 2026-10-11 | Guía inicial derivada de la especificación maestra, PRD y `DATABASE_SPEC.md`; alcance por fases, esquema del MVP, modelos, enums, Policies, Services, seeders y pruebas. |
| 1.1.0 | 2026-10-15 | Alineada con `DECISIONS.md`: enums y transiciones nuevos, campos de `services`/`opportunities`/`price_lists`/`quote_items`, IA en el MVP, catálogo completo de permisos. Corregidos: duplicación de empresa en `CompanySeeder`, truncamiento en `QuoteCalculator` (nuevo `Money` con half-up e IVA por documento), comparación string/enum en `QuotePolicy`, idempotencia real en `ConvertQuoteToServiceOrder`, namespace de `AuthorizationException`. |

