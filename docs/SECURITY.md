# TIARE — Seguridad (estado Fase 1)

## Implementado y probado

| Control | Dónde | Prueba |
|---|---|---|
| Ticket Mercado Público solo en `backend/.env`, enviado como header `ticket` (V2) | `MercadoPublicoV2Client` | `test_ticket_is_sent_only_as_header_never_in_query` |
| Ticket ausente de respuestas JSON, logs, sync runs y bundle frontend | Cliente, controladores, `phpunit.xml` usa ticket ficticio | `*_without_ticket`, `test_failure_logs_never_contain_ticket`, grep en `frontend/dist` |
| Logs del conector sin query string ni payload | `logFailure()` | idem |
| TLS verificado, timeout y connect timeout | Cliente HTTP (no se desactiva `verify`) | revisión de código |
| Códigos externos validados antes de construir rutas (sin path traversal/SSRF) | `CompraAgilSearchQuery::isValidCode` + `rawurlencode` | `test_invalid_code_is_rejected_before_any_request` |
| Autenticación SPA Sanctum por cookie + CSRF; login solo desde dominios stateful | `AuthController`, `bootstrap/app.php` | `AuthAndTenantTest` |
| Tenant activo resuelto en backend (sesión + pertenencia), nunca desde el navegador | `ResolveCurrentTenant`, `CurrentTenant` | `test_user_cannot_switch_to_foreign_tenant`, `test_suspended_tenant_is_not_resolved` |
| RBAC: permisos de plataforma ∪ rol en tenant activo; `public_procurement.read` y `.sync` separados | `RequirePermission`, `User::permissionsFor` | `test_sync_requires_sync_permission`, `test_user_without_read_permission_is_forbidden` |
| Rate limiting: login 5/min por correo+IP, API 120/min, detalle MP 30/min, sync 2/10 min | `AppServiceProvider` | — |
| Auditoría append-only: login, login fallido (hash del correo), logout, cambio de tenant, solicitud de sync | `AuditLogger` | tests de auth y sync |
| Errores sin stack trace ni detalles internos fuera de debug; formato `{error:{code,message}}` | `bootstrap/app.php` | tests de API |
| `Cache-Control: no-store` y `X-Request-ID` en respuestas API | `AssignRequestId` | `test_health_is_public_and_has_no_secrets` |
| CORS restringido a `FRONTEND_URL` con credenciales | `config/cors.php` | — |
| Pruebas sin acceso a internet | `Http::preventStrayRequests()` en `TestCase` | toda la suite |

## Fase 2b (17-11-2026)

| Control | Implementación | Prueba |
|---|---|---|
| Usuarios de empresa | Permiso `users.manage`; solo roles de alcance `tenant`; nadie cambia su propio rol ni se desactiva; la empresa conserva al menos un administrador activo; todo auditado | `CompanyApiTest` |
| Invitaciones y recuperación | Cuenta nueva con contraseña aleatoria que nadie conoce + enlace de un solo uso del broker de Laravel (vence en `auth.passwords.users.expire` min). Token y correo en el **fragmento** de la URL (`/restablecer#token=…`), no en la query: no llegan a logs ni Referer. Respuesta de "olvidé mi contraseña" idéntica exista o no la cuenta. Contraseña: mínimo 10, letras y números. Límite `login` | `CompanyApiTest::test_password_reset_flow`, `test_inviting_a_new_person…` |
| Documentos de proveedores | Disco privado `local`, ruta por tenant y proveedor con nombre aleatorio; solo PDF/JPG/PNG por tipo de contenido (fileinfo) hasta 10 MB; SHA-256 guardado; descarga solo por API autenticada, como adjunto, `nosniff` y `no-store`; `storage_path` nunca se expone; descargas y subidas auditadas | `SupplierEvaluationAndDocumentTest` |
| Evaluaciones | Inmutables (modelo bloquea update/delete); permiso `suppliers.evaluate` | idem |

## Fase 6: agentes IA (17-11-2026)

- La clave de Claude (`ANTHROPIC_API_KEY`) vive solo en `backend/.env`; no se expone al navegador, no se registra y no se guarda en la base de datos. Los errores del modelo se traducen a mensajes sin la clave ni el contenido enviado.
- El modelo no tiene herramientas: no accede a la base de datos, a internet ni a Mercado Público. Los datos que recibe los selecciona el backend con alcance de la empresa activa.
- Los textos de terceros (descripciones de oportunidades, notas de cotizaciones) se envían marcados como datos; las instrucciones fijas prohíben ofertar, adjudicar, aprobar o comprometer fondos.
- Ejecutar exige `agents.run` más los permisos del caso; aislamiento por empresa probado (otra empresa no ve ni analiza el caso, ni sus ejecuciones).
- Límite diario por empresa y límite por minuto por usuario para acotar costo y abuso.
- Antes de producción: revisar con el área legal el envío de datos de clientes a un proveedor externo de IA (contratos y política de privacidad).

## Perfiles, planes y tokens API (17-11-2026, TD-024)

| Control | Implementación | Prueba |
|---|---|---|
| Admin TIARE separado de la empresa | `platform.tenants.manage` solo en roles de plataforma; el admin no queda como miembro de las empresas que crea | `test_company_sees_only_its_own_plan_and_cannot_use_admin_api`, `test_platform_admin_creates_company…` |
| Plan como límite de permisos | Permisos del rol de empresa ∩ módulos del plan vigente; sin plan vigente no hay módulos | `test_plan_limits_role_permissions_to_its_modules`, `test_tenant_without_current_subscription…` |
| Tokens API | Hash SHA-256 en BD, prefijo `tiare_`, vencimiento obligatorio, abilities ⊆ permisos del emisor (sin `users.*`/`company.*`/`api_tokens.*`/`platform.*`), fijado a una empresa, solo emisión/revocación por sesión web, auditoría de creación/revocación | `test_token_is_issued_once…`, `test_token_cannot_be_granted…`, `test_bearer_token_works_only_within…`, `test_expired_revoked_or_orphaned…` |

## TD-025 (17-11-2026)

| Control | Implementación | Prueba |
|---|---|---|
| Copias de documentos MP | Privadas por empresa (global scope), disco privado, nombre aleatorio, tipo por contenido, ≤ 20 MB, descarga con `nosniff` y `no-store`, límite `uploads` 20/min, auditoría | `test_official_documents_are_listed_and_tenant_copies_are_private`, `test_document_upload_validation_and_permission` |
| Eliminación CRM | Policy `delete` por tenant, bloqueo con historial (409), borrado de archivos, auditoría con nombre/RUT | `DeleteAndDocumentsTest` |
| Mantenedor de cuentas/roles | Solo permisos `platform.*`; anti-bloqueo (auto-desactivación, último admin), superadmin solo por superadmin, roles admin bloqueados, roles de empresa sin permisos de plataforma, desactivar = cerrar sesiones + revocar tokens | `AccountsAndRolesAdminTest` |

## TD-026 (17-11-2026)

| Control | Implementación | Prueba |
|---|---|---|
| Contraseña de inicio | Generada en servidor (~90 bits), mostrada una vez, solo hash en BD, nunca en auditoría/logs; cierra sesiones y revoca tokens; cambio obligatorio (`403 PASSWORD_CHANGE_REQUIRED` salvo perfil/logout/cambio) | `InitialPasswordAndMyAccountTest` |
| Cambio de contraseña propio | Exige la actual, política ≥ 10 con letras y números y distinta, 5 intentos/min por cuenta, cierra otras sesiones, prohibido vía token API, auditoría | `test_change_password_validation`, `test_api_token_cannot_change_password` |

| Contraseña de inicio desde la empresa (TD-027) | Solo cuentas nuevas al invitar; regenerar solo si la persona pertenece únicamente a esa empresa y no es personal TIARE; límite 10/min por administrador | `CompanyInitialPasswordTest` |

| Portal de cliente/proveedor (TD-028) | Cuenta atada a su propio registro; solo `portal.*`; resto de la API 403; sin evaluaciones ni notas internas; correo de equipo o personal TIARE rechazado; revocar/eliminar quita membresía y tokens | `PortalAccessTest` |
| Superadministrador (TD-028) | Solo por consola; contraseña impresa una vez en la terminal del operador, nunca en web/logs/auditoría | `test_superadmin_console_command` |

Riesgo aceptado: la contraseña de inicio la ve el administrador que la genera; por eso se exige cambiarla al primer ingreso y la UI indica entregarla por un canal seguro.

## Pendiente

- MFA para cuentas administrativas (prioritario ahora que existe el panel de Administración TIARE).
- **Antimalware para adjuntos** (CLAUDE.md §11) antes de producción: hoy se valida tipo y tamaño, no se escanea el contenido.
- En desarrollo `MAIL_MAILER=log`: los enlaces de invitación quedan en `storage/logs/laravel.log`. Configurar un proveedor de correo real antes de producción.
- Cliente V1 (ticket en query string): redacción en logs/proxy/APM antes de habilitarlo.
- Retención de `public_procurement_raw_payloads` (PEND-T05).
- HTTPS, cookies `Secure`, `SESSION_ENCRYPT=true` y `APP_DEBUG=false` en ambientes no locales.
- Reemplazo del ticket expuesto en el chat (PEND-T04).
