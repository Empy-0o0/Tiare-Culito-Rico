# INGENERIA JARVIS — PRODUCT REQUIREMENTS DOCUMENT (PRD)

**Proyecto:** INGENERIA JARVIS / ERGO JARVIS  
**Organización inicial:** Ergo SaniTas SpA  
**Documento:** Requisitos de producto y criterios de aceptación  
**Versión:** 1.1.0  
**Estado:** Borrador funcional para revisión antes de implementación  
**Fecha:** 11 de octubre de 2026 (actualizada 15 de octubre de 2026)  
**Decisiones vinculantes:** `DECISIONS.md` (enums, campos, permisos, riesgos y alcance del MVP; prevalece ante contradicciones)  
**Idioma de interfaz:** Español  
**Zona horaria:** `America/Santiago`  
**Moneda predeterminada:** CLP  
**Documento padre:** `INGENERIA_JARVIS_MASTER_SPEC.md`  
**Arquitectura de referencia:** React + Vite + TypeScript, Laravel API, MySQL, Ollama/Qwen y Python opcional.

> Este documento define qué debe hacer el producto y cómo comprobarlo. No demuestra que las funciones estén implementadas. La implementación se realizará por fases y las decisiones aún no confirmadas se registran como pendientes, sin inventar procesos, cifras, precios ni obligaciones de la empresa.

---

## 1. Propósito

Establecer requisitos de producto claros, verificables y priorizados para desarrollar una plataforma empresarial de gestión para Ergo SaniTas SpA. La plataforma centralizará la información administrativa y comercial, coordinará los servicios prestados en terreno, mejorará la visibilidad financiera y operativa y permitirá incorporar agentes de inteligencia artificial especializados bajo controles de seguridad, trazabilidad y aprobación humana.

Este documento debe servir como contrato funcional entre dirección, responsables de procesos, diseño, desarrollo, pruebas y despliegue.

### 1.1 Objetivos de negocio

- **OBJ-01:** Mantener un registro centralizado de clientes, organizaciones, contactos, servicios, cotizaciones y órdenes de atención.
- **OBJ-02:** Dar seguimiento a oportunidades comerciales desde el primer contacto hasta el cierre y la prestación del servicio.
- **OBJ-03:** Reducir duplicidad de información y errores manuales mediante validaciones y vínculos entre registros.
- **OBJ-04:** Mejorar la programación de citas, recursos, profesionales e insumos para atenciones domiciliarias y en terreno.
- **OBJ-05:** Entregar datos financieros administrativos trazables, distinguiendo estimaciones de operaciones confirmadas.
- **OBJ-06:** Mantener trazabilidad de cambios, aprobaciones, documentos y acciones sensibles.
- **OBJ-07:** Automatizar tareas de bajo riesgo y apoyar la preparación de decisiones con agentes especializados.
- **OBJ-08:** Proteger datos personales, laborales, comerciales y sanitarios mediante acceso mínimo necesario.
- **OBJ-09:** Facilitar análisis de gestión mediante indicadores que tengan definiciones, fuentes y periodos explícitos.
- **OBJ-10:** Permitir incorporar nuevos módulos sin rehacer el núcleo de la aplicación.

### 1.2 Indicadores de éxito del producto

Durante el descubrimiento, la empresa deberá establecer las líneas base y metas cuantitativas. Hasta que sean aprobadas, se usarán como indicadores medibles, sin atribuirles metas inventadas:

- Porcentaje de clientes con información obligatoria completa.
- Porcentaje de cotizaciones con estado y siguiente acción registrados.
- Tiempo transcurrido entre creación y emisión de cotización.
- Tasa de conversión de cotizaciones aceptadas respecto de cotizaciones emitidas.
- Porcentaje de órdenes con estados operativos y responsable asignado correctamente.
- Número de conflictos de agenda detectados y resueltos antes de la prestación.
- Diferencia entre proyección de caja y movimientos confirmados para el mismo periodo.
- Porcentaje de movimientos de inventario con referencia y responsable.
- Tasa de tareas de IA completadas, fallidas, rechazadas o escaladas a una persona.
- Número de accesos no autorizados detectados en pruebas y eventos de seguridad pendientes.

Las metas deberán configurarse una vez que exista una línea base confiable.

---

## 2. Alcance del producto

### 2.1 Incluido en la visión completa

1. Dashboard ejecutivo.
2. CRM de personas, organizaciones, contactos y sedes.
3. Catálogo de servicios, categorías, recursos y precios versionados.
4. Gestión de oportunidades, cotizaciones y seguimiento comercial.
5. Agenda, asignación de recursos y órdenes de servicio domiciliarias o en terreno.
6. Administración de ingresos, egresos, cuentas por cobrar/pagar y proyecciones identificadas como tales.
7. Inventario de insumos, lotes, vencimientos, equipos y mantenimiento.
8. Gestión documental y generación de borradores.
9. Gestión restringida de documentación clínica y medicina deportiva, sujeta a revisión profesional y de cumplimiento.
10. Gestión administrativa de personas, turnos, perfiles y capacitación.
11. Cumplimiento, calendario de obligaciones, riesgos y evidencias.
12. Inteligencia de negocios y reportes.
13. Agentes de IA especializados y un orquestador central.
14. Automatizaciones y notificaciones mediante integraciones autorizadas.
15. Usuarios, roles, permisos, auditoría y configuración general.

### 2.2 MVP — primera versión utilizable

El MVP deberá priorizar una ruta completa y comprobable desde el cliente hasta la orden de servicio, **asistida por agentes de IA de bajo riesgo** para aumentar automatización y productividad (`DECISIONS.md` DEC-002). Se entrega en tres incrementos: MVP-1 núcleo, MVP-2 comercial-operativo y MVP-3 IA y automatización interna.

**MVP-1 y MVP-2:**

- Inicio de sesión y control de acceso por roles.
- Configuración básica de la empresa.
- CRM de clientes y organizaciones.
- Catálogo administrable de servicios.
- Oportunidades y seguimiento comercial.
- Cotizaciones versionadas, con exportación a PDF o impresión.
- Agenda básica y detección de conflictos de recursos.
- Órdenes de servicio vinculadas a cotizaciones aceptadas.
- Registro de estados, responsables e incidencias operativas.
- Dashboard básico de actividad comercial y operativa.
- Auditoría de operaciones críticas.
- Aprobaciones persistentes, idempotencia y folios internos.
- Datos ficticios para pruebas y entorno local documentado.

**MVP-3 — IA y automatización interna:**

- Orquestador central con modelo local vía Ollama (sin proveedores externos).
- Agentes `SALES_CRM`, `OPERATIONS_COORDINATOR`, `BUSINESS_INTELLIGENCE`, `BUSINESS_MANAGER` y `AUTOMATION_COORDINATOR`.
- Herramientas de nivel R0–R2 únicamente; las acciones R3 se preparan como propuesta con aprobación y se ejecutan manualmente.
- Automatizaciones internas: tareas, recordatorios internos, detección de oportunidades sin próxima acción, conflictos de agenda y reportes programados.
- Panel de ejecuciones de IA con estado, agente, fuentes, advertencias y aprobaciones pendientes.

### 2.3 Fases posteriores

- **Fase A:** Finanzas administrativas e inventario inicial; agentes `FINANCE_ANALYST` e `INVENTORY_EQUIPMENT`.
- **Fase B:** Ampliación de agentes y herramientas según métricas del MVP-3.
- **Fase C:** Comunicaciones externas integradas (habilita herramientas R3 con aprobación).
- **Fase D:** Gestión clínica restringida y documentos sanitarios.
- **Fase E:** Medicina deportiva, composición corporal, evaluación del rendimiento y protocolos especializados.
- **Fase F:** Preparación de producción, hardening, copias de seguridad, recuperación y revisión de cumplimiento.

La activación de una fase posterior requiere que sus dependencias estén implementadas y que cumpla criterios de aceptación propios.

### 2.4 Fuera de alcance inicial

- Contabilidad general o tributaria oficial completa.
- Emisión real de documentos tributarios sin integración validada con un proveedor o sistema autorizado.
- Ejecución automática de transferencias o pagos bancarios.
- Diagnóstico, prescripción o decisiones clínicas autónomas de la IA.
- Presentación de trámites oficiales sin autorización e integración confirmadas.
- Despliegue público antes de completar la revisión de seguridad y recuperación.
- Entrenamiento de modelos con datos reales de pacientes sin evaluación de la base legal, consentimiento cuando corresponda y controles autorizados.

---

## 3. Usuarios, actores y necesidades

### 3.1 Actores del sistema

| ID | Actor | Objetivo principal | Necesidades clave |
|---|---|---|---|
| ACT-01 | Dirección | Entender el estado del negocio y autorizar decisiones | Indicadores confiables, aprobaciones, alertas y reportes |
| ACT-02 | Administración | Mantener datos y documentos operativos | Formularios consistentes, búsquedas, seguimiento y exportación |
| ACT-03 | Comercial | Convertir consultas en ventas y servicios | CRM, oportunidades, cotizaciones, recordatorios y métricas |
| ACT-04 | Finanzas | Revisar ingresos, gastos y cuentas pendientes | Registro con respaldo, vencimientos, flujo de caja y análisis de márgenes |
| ACT-05 | Operaciones | Coordinar prestaciones | Agenda, disponibilidad, órdenes, ubicación operativa e incidencias |
| ACT-06 | Profesional de salud | Consultar o registrar información de su competencia | Acceso restringido, documentación controlada y trazabilidad |
| ACT-07 | Inventario | Mantener stock y equipos disponibles | Movimientos, lotes, vencimientos, mantenimiento y alertas |
| ACT-08 | RR. HH. | Administrar información laboral autorizada | Documentos, turnos, competencias y alertas de vencimiento |
| ACT-09 | Auditor de lectura | Revisar acciones y evidencias | Bitácora, filtros y reportes sin permisos de modificación |
| ACT-10 | Agente de IA | Resolver tareas delimitadas mediante herramientas autorizadas | Contexto mínimo, herramientas tipadas, límites y registro de ejecución |
| ACT-11 | Administrador técnico | Mantener la plataforma | Configuración, monitoreo, migraciones y soporte sin acceso de negocio innecesario |

### 3.2 Principios de autorización

- Los roles son perfiles de partida, no una sustitución de permisos granulares.
- La autorización se comprueba siempre en Laravel; ocultar elementos en React no basta.
- Los usuarios y agentes solo podrán ver datos necesarios para sus funciones.
- La identidad técnica de los agentes será distinta de la identidad de los usuarios humanos.
- Los permisos se aplicarán por módulo, acción y recurso; cuando corresponda, también por asignación, sede, organización o estado.
- Los accesos excepcionales a información sensible deberán justificarse y quedar auditados.

### 3.3 Roles iniciales sugeridos

`SUPER_ADMIN`, `DIRECCION`, `ADMINISTRACION`, `FINANZAS`, `COMERCIAL`, `OPERACIONES`, `PROFESIONAL_SALUD`, `NUTRICION`, `INVENTARIO`, `RRHH`, `AUDITOR_LECTURA` y `AGENT_SERVICE`.

El diseño de permisos final debe quedar documentado en una matriz de acceso antes de usar datos reales.

---

## 4. Prioridad y convención de requisitos

Cada requisito utiliza un identificador estable para permitir trazabilidad entre desarrollo, pruebas, incidencias y decisiones.

- **MUST:** imprescindible para aceptar la fase o versión.
- **SHOULD:** importante; puede postergarse solo con una decisión documentada.
- **COULD:** valor adicional deseable.
- **WON'T (por ahora):** explícitamente fuera de la versión actual.

Los requisitos de seguridad y privacidad aplicables no pueden rebajarse simplemente por priorización comercial.

---

## 5. Requisitos funcionales transversales

### 5.1 Identidad y sesión

- **FR-CORE-001 — MUST:** permitir iniciar y cerrar sesión de manera segura.
- **FR-CORE-002 — MUST:** permitir crear, activar, desactivar y administrar cuentas mediante usuarios autorizados.
- **FR-CORE-003 — MUST:** aplicar autorización en backend para cada endpoint protegido.
- **FR-CORE-004 — MUST:** registrar fecha, resultado y contexto mínimo de los eventos relevantes de autenticación y autorización, sin guardar contraseñas ni secretos.
- **FR-CORE-005 — SHOULD:** permitir MFA para cuentas privilegiadas antes de producción.
- **FR-CORE-006 — MUST:** revocar el acceso de usuarios deshabilitados y permitir invalidar sesiones según el mecanismo seleccionado.
- **FR-CORE-007 — MUST:** impedir que una cuenta de servicio de IA se utilice como sesión humana.

### 5.2 Navegación y experiencia general

- **FR-UX-001 — MUST:** ofrecer interfaz en español y navegación coherente entre módulos.
- **FR-UX-002 — MUST:** mostrar estados de carga, éxito, ausencia de datos y error en las operaciones relevantes.
- **FR-UX-003 — MUST:** validar formularios tanto en frontend como en backend.
- **FR-UX-004 — MUST:** mantener filtros y paginación en listados extensos.
- **FR-UX-005 — SHOULD:** adaptar las vistas principales a escritorio, tablet y móvil.
- **FR-UX-006 — MUST:** presentar fechas y horas usando la zona horaria de negocio `America/Santiago`, con almacenamiento técnico consistente.
- **FR-UX-007 — MUST:** presentar importes en CLP con reglas de formato uniformes, manteniendo precisión decimal en el backend.
- **FR-UX-008 — MUST:** no comunicar un resultado satisfactorio hasta recibir confirmación del backend.
- **FR-UX-009 — SHOULD:** cumplir criterios de accesibilidad para navegación por teclado, etiquetas, mensajes y contraste.

### 5.3 Búsqueda y datos

- **FR-DATA-001 — MUST:** permitir buscar registros por los atributos relevantes de cada módulo, respetando permisos.
- **FR-DATA-002 — MUST:** mostrar cuando un registro fue creado, actualizado y por quién, si esa información forma parte de su auditoría.
- **FR-DATA-003 — MUST:** prevenir duplicados evidentes mediante detección y confirmación; no fusionar automáticamente registros ambiguos.
- **FR-DATA-004 — MUST:** usar identificadores internos estables que no dependan del RUT ni de otros datos personales.
- **FR-DATA-005 — MUST:** distinguir campos obligatorios, opcionales, calculados y derivados de integraciones.
- **FR-DATA-006 — MUST:** evitar la eliminación física de registros que forman parte de operaciones históricas o auditables; definir archivo, cancelación o corrección trazable según el dominio.

---

## 6. Requisitos por módulo

## 6.1 Dashboard ejecutivo

**Objetivo:** presentar información de dirección basada en registros autorizados y cálculos reproducibles.

- **FR-DASH-001 — MUST:** mostrar resumen de oportunidades y cotizaciones por estado.
- **FR-DASH-002 — MUST:** mostrar agenda de servicios de hoy y próximos periodos conforme a permisos.
- **FR-DASH-003 — MUST:** mostrar órdenes por estado operativo.
- **FR-DASH-004 — SHOULD:** mostrar ingresos, egresos, cuentas pendientes y proyección de caja cuando exista módulo financiero.
- **FR-DASH-005 — SHOULD:** permitir filtrar por rango de fechas, tipo de servicio, segmento y responsable cuando los datos estén disponibles.
- **FR-DASH-006 — MUST:** indicar periodo, definición y momento de actualización de cada indicador.
- **FR-DASH-007 — MUST:** distinguir valores cotizados, aceptados, prestados, facturados y cobrados.
- **FR-DASH-008 — MUST:** mostrar “sin datos” si no existen registros suficientes; nunca inventar cifras para completar una tarjeta.
- **FR-DASH-009 — SHOULD:** permitir exportar un reporte autorizado con fecha de generación y filtros aplicados.

**Criterio de aceptación:** un usuario con rol de dirección puede contrastar un indicador con los registros subyacentes autorizados y obtener el mismo resultado para el mismo periodo y definición.

## 6.2 CRM y clientes

- **FR-CRM-001 — MUST:** crear, visualizar, actualizar y archivar clientes particulares y organizaciones.
- **FR-CRM-002 — MUST:** permitir múltiples contactos y sedes asociados a una organización.
- **FR-CRM-003 — MUST:** clasificar el tipo de cliente usando categorías configurables, como particular, empresa, establecimiento educacional, organización deportiva u otro.
- **FR-CRM-004 — MUST:** registrar datos de contacto y direcciones operativas con validaciones adecuadas.
- **FR-CRM-005 — MUST:** registrar interacciones comerciales con fecha, responsable, canal, resumen y próxima acción cuando proceda.
- **FR-CRM-006 — MUST:** vincular clientes con oportunidades, cotizaciones, órdenes y documentos comerciales.
- **FR-CRM-007 — MUST:** detectar coincidencias potenciales para reducir duplicados antes de crear registros nuevos.
- **FR-CRM-008 — SHOULD:** mantener preferencias de comunicación y consentimiento aplicable con fecha y procedencia.
- **FR-CRM-009 — MUST:** impedir que campos generales del CRM se utilicen como repositorio libre de información clínica sensible.
- **FR-CRM-010 — MUST:** permitir exportación solo a perfiles autorizados y registrar exportaciones sensibles.

**Criterios de aceptación:**

1. Se puede crear una empresa con más de un contacto y sede.
2. Un contacto puede vincularse con cotizaciones y actividades sin duplicar el registro de cliente.
3. Los usuarios sin permiso no pueden consultar ni exportar los registros restringidos mediante llamadas directas a la API.

## 6.3 Catálogo de servicios, recursos y precios

- **FR-SVC-001 — MUST:** crear, editar, activar e inactivar servicios sin modificar el código fuente.
- **FR-SVC-002 — MUST:** mantener código interno, nombre, descripción, categoría, modalidad, unidad de cobro y estado.
- **FR-SVC-003 — MUST:** permitir asociar requisitos de personal, insumos, equipos y documentos, si aplican.
- **FR-SVC-004 — MUST:** almacenar precios y costos en tipos decimales apropiados; no usar coma flotante binaria para importes monetarios.
- **FR-SVC-005 — MUST:** conservar el histórico de precios con fechas de vigencia.
- **FR-SVC-006 — MUST:** las cotizaciones emitidas conservarán los valores y condiciones de su versión, aunque el catálogo cambie después.
- **FR-SVC-007 — SHOULD:** mantener listas de precios por segmento, organización o convenio.
- **FR-SVC-008 — MUST:** distinguir precio comercial, costo estimado, costo real y margen calculado.
- **FR-SVC-009 — MUST:** etiquetar las reglas tributarias como configuración pendiente de validación contable antes de aplicarlas.
- **FR-SVC-010 — MUST:** registrar usuario, fecha y motivo en cambios relevantes de precio o parámetros comerciales.

**Criterio de aceptación:** una cotización ya emitida no cambia retroactivamente al editar el precio del servicio.

## 6.4 Oportunidades y seguimiento comercial

- **FR-SALES-001 — MUST:** crear oportunidades vinculadas a un cliente u organización.
- **FR-SALES-002 — MUST:** admitir las etapas de `DECISIONS.md` DEC-010: `NEW` (Nueva), `CONTACTED` (Contactada), `QUALIFIED` (Calificada), `QUOTE_IN_PREPARATION` (Cotización en preparación), `QUOTED` (Cotizada), `NEGOTIATION` (Negociación), `WON` (Ganada), `LOST` (Perdida) y `ON_HOLD` (Pausada).
- **FR-SALES-003 — MUST:** asignar responsable, fecha de creación, próxima acción y fecha de seguimiento cuando proceda.
- **FR-SALES-004 — MUST:** registrar origen de oportunidad cuando se conozca.
- **FR-SALES-005 — MUST:** registrar motivo de pérdida o pausa cuando se cierre con ese resultado.
- **FR-SALES-006 — SHOULD:** permitir crear recordatorios y tareas comerciales.
- **FR-SALES-007 — MUST:** mostrar el historial de cambios de estado.
- **FR-SALES-008 — SHOULD:** calcular tasas de conversión con definición y ventana temporal explícitas.

**Criterio de aceptación:** el usuario puede identificar oportunidades sin próximo paso y filtrar por responsable, estado y fecha de seguimiento.

## 6.5 Cotizaciones

- **FR-QUO-001 — MUST:** crear cotizaciones a partir de un cliente y una o más líneas de servicio.
- **FR-QUO-002 — MUST:** calcular subtotal, descuentos aprobados y totales mediante código de backend, con reglas reproducibles.
- **FR-QUO-003 — MUST:** registrar cantidad, precio unitario, descripción, condiciones, vigencia y versión.
- **FR-QUO-004 — MUST:** permitir los estados y transiciones de `DECISIONS.md` DEC-011: `DRAFT` (Borrador), `PENDING_APPROVAL` (Pendiente de revisión), `ISSUED` (Emitida), `SENT` (Enviada), `ACCEPTED` (Aceptada), `REJECTED` (Rechazada), `EXPIRED` (Vencida) y `CANCELLED` (Cancelada).
- **FR-QUO-005 — MUST:** mantener inmutable el contenido de las versiones emitidas; cambios posteriores generan una nueva versión.
- **FR-QUO-006 — MUST:** aplicar aprobación cuando se supere el límite de descuento o excepción configurado.
- **FR-QUO-007 — MUST:** generar un PDF o formato imprimible con datos empresariales configurados, identificador, fecha, versión y vigencia.
- **FR-QUO-008 — MUST:** registrar si el documento fue generado, descargado, emitido o enviado, sin afirmar envíos externos no realizados.
- **FR-QUO-009 — MUST:** convertir una cotización aceptada en orden de servicio conservando las relaciones de origen.
- **FR-QUO-010 — SHOULD:** permitir registrar el motivo de rechazo y próxima acción comercial.
- **FR-QUO-011 — MUST:** impedir que un agente envíe una cotización externamente sin permiso y aprobación requeridos.

**Criterios de aceptación:**

1. El mismo contenido produce el mismo resultado de cálculo según la versión de las reglas.
2. Una modificación de catálogo no altera una cotización histórica.
3. Un descuento fuera del límite exige una aprobación registrada.
4. Una cotización no puede generar órdenes duplicadas por un doble clic o reintento.

## 6.6 Agenda, operaciones y órdenes de servicio

- **FR-OPS-001 — MUST:** crear y modificar citas o ventanas horarias con cliente, lugar, modalidad y servicio.
- **FR-OPS-002 — MUST:** asignar personal y recursos requeridos solo si están activos y disponibles conforme a las reglas configuradas.
- **FR-OPS-003 — MUST:** detectar conflictos de horario para profesionales y recursos compartidos.
- **FR-OPS-004 — MUST:** permitir crear órdenes desde cotizaciones aceptadas u otra fuente autorizada.
- **FR-OPS-005 — MUST:** admitir los estados de `DECISIONS.md` DEC-012: `DRAFT` (Borrador), `PENDING_CONFIRMATION` (Pendiente de confirmación), `CONFIRMED` (Confirmada), `ASSIGNED` (Asignada), `IN_PROGRESS` (En curso), `COMPLETED` (Realizada), `PENDING_DOCUMENTATION` (Pendiente de documentación), `CLOSED` (Cerrada), `CANCELLED` (Cancelada), `NOT_PERFORMED` (No realizada) y `ON_HOLD` (En pausa).
- **FR-OPS-006 — MUST:** validar transiciones de estado según reglas de negocio y permisos.
- **FR-OPS-007 — MUST:** registrar reprogramación, cancelación, no realización e incidencias con fecha, responsable y motivo.
- **FR-OPS-008 — MUST:** mantener historial de asignaciones y cambios de hora o lugar.
- **FR-OPS-009 — MUST:** separar notas de logística de información clínica.
- **FR-OPS-010 — SHOULD:** sugerir rutas o agrupar servicios por sector cuando existan datos adecuados; presentar tiempos estimados como estimaciones.
- **FR-OPS-011 — SHOULD:** enviar recordatorios únicamente cuando exista integración activa, configuración y autorización válida del canal.
- **FR-OPS-012 — MUST:** permitir marcar la prestación como realizada sin confundir ese estado con facturación o pago.
- **FR-OPS-013 — MUST:** mostrar al personal en terreno solo los datos mínimos necesarios para realizar el servicio.

**Criterios de aceptación:**

1. La agenda rechaza o solicita resolver asignaciones incompatibles según reglas configuradas.
2. Se puede reprogramar una atención sin perder el historial anterior.
3. Una orden cerrada mantiene su trazabilidad y no puede borrarse silenciosamente.
4. La confirmación de prestación no genera automáticamente un pago ni una factura no integrada.

## 6.7 Finanzas administrativas

Este módulo registra información administrativa y apoya el análisis. No reemplaza un sistema contable o tributario oficial.

- **FR-FIN-001 — MUST:** registrar ingresos y egresos administrativos con fecha, categoría, importe, referencia y estado.
- **FR-FIN-002 — MUST:** relacionar movimientos con clientes, proveedores, servicios, órdenes y documentos cuando corresponda.
- **FR-FIN-003 — MUST:** distinguir cuenta por cobrar, documento emitido, pago recibido y saldo pendiente.
- **FR-FIN-004 — MUST:** distinguir gasto proyectado, gasto comprometido, documento recibido y pago realizado.
- **FR-FIN-005 — MUST:** registrar vencimientos, estado de seguimiento y responsable para cuentas por cobrar y pagar.
- **FR-FIN-006 — MUST:** elaborar una proyección de caja desde registros identificables y mostrar de forma explícita los supuestos.
- **FR-FIN-007 — MUST:** permitir comparación de presupuesto frente a resultado registrado.
- **FR-FIN-008 — MUST:** calcular margen por servicio a partir de costos definidos e indicar cuáles categorías se incluyeron.
- **FR-FIN-009 — MUST:** conservar la fórmula, periodo y origen de datos de informes financieros.
- **FR-FIN-010 — MUST:** no ejecutar pagos bancarios en el MVP.
- **FR-FIN-011 — MUST:** no presentar cifras estimadas o generadas por IA como movimientos confirmados.
- **FR-FIN-012 — SHOULD:** exportar reportes para revisión contable.
- **FR-FIN-013 — MUST:** corregir movimientos consolidados mediante reversa o mecanismo trazable, no mediante eliminación silenciosa.

**Criterio de aceptación:** un reporte permite diferenciar claramente estimaciones, documentos registrados, cuentas pendientes y pagos confirmados.

## 6.8 Inventario y equipos

- **FR-INV-001 — MUST:** crear un catálogo de productos, insumos, equipos y unidades de medida.
- **FR-INV-002 — MUST:** registrar existencias por ubicación.
- **FR-INV-003 — MUST:** registrar entradas, salidas, ajustes, transferencias y reservas con referencia y responsable.
- **FR-INV-004 — MUST:** gestionar lote y vencimiento para los artículos que lo requieran.
- **FR-INV-005 — MUST:** permitir configurar mínimos y generar alertas por bajo stock.
- **FR-INV-006 — MUST:** mostrar historial de movimientos y saldo actual derivado de movimientos consistentes.
- **FR-INV-007 — SHOULD:** reservar insumos para órdenes programadas.
- **FR-INV-008 — SHOULD:** administrar mantenimiento, inspección, calibración o disponibilidad de equipos cuando aplique.
- **FR-INV-009 — MUST:** no permitir que la IA confirme compras; las recomendaciones deben pasar por el flujo de aprobación.
- **FR-INV-010 — MUST:** evitar stock negativo salvo política explícita y autorizada, si alguna vez se aprobara.
- **FR-INV-011 — MUST:** corregir movimientos consolidados con movimientos compensatorios trazables.

**Criterio de aceptación:** el saldo de stock puede reconstruirse a partir de los movimientos registrados y un ajuste indica quién lo realizó y por qué.

## 6.9 Gestión documental

- **FR-DOC-001 — MUST:** almacenar metadatos de documentos, propietario funcional, fecha, categoría, versión y nivel de sensibilidad.
- **FR-DOC-002 — MUST:** asociar documentos con los registros autorizados, como cliente, cotización, orden, equipo o proceso.
- **FR-DOC-003 — MUST:** administrar plantillas aprobadas con versión, responsable y fecha de revisión.
- **FR-DOC-004 — MUST:** marcar los documentos generados por IA como borradores hasta su revisión y aprobación.
- **FR-DOC-005 — MUST:** registrar acciones de acceso, descarga, publicación y modificación para documentos sensibles.
- **FR-DOC-006 — MUST:** impedir el acceso a documentos privados mediante URLs públicas sin autorización.
- **FR-DOC-007 — SHOULD:** generar formatos PDF y DOCX según plantillas y disponibilidad técnica.
- **FR-DOC-008 — MUST:** mantener historial de cambios y versiones.
- **FR-DOC-009 — MUST:** no inventar firmas, folios oficiales, autorizaciones ni hechos para completar plantillas.

## 6.10 Personas y recursos humanos

- **FR-HR-001 — SHOULD:** mantener perfiles de cargo y competencias necesarias.
- **FR-HR-002 — SHOULD:** registrar documentos administrativos laborales con acceso restringido.
- **FR-HR-003 — SHOULD:** planificar disponibilidad y turnos cuando la operación lo requiera.
- **FR-HR-004 — SHOULD:** registrar capacitaciones y fechas de vencimiento de certificaciones pertinentes.
- **FR-HR-005 — MUST:** impedir que agentes tomen automáticamente decisiones de contratación, sanción o término laboral.
- **FR-HR-006 — MUST:** restringir los datos personales laborales por rol y finalidad.
- **FR-HR-007 — MUST:** no presentar estimaciones salariales o cálculos de remuneraciones como liquidados oficialmente sin validación competente.

El alcance y prioridad de este módulo se confirmarán después del MVP comercial-operativo.

## 6.11 Gestión clínica y documentos sanitarios

**Estado de producto:** restringido; no habilitar con datos reales hasta validar el alcance funcional, las competencias profesionales, los protocolos y la revisión de cumplimiento aplicable.

- **FR-CLN-001 — MUST:** separar permisos y almacenamiento lógico de la documentación clínica respecto del CRM comercial general.
- **FR-CLN-002 — MUST:** permitir acceso únicamente a perfiles autorizados por función, asignación y finalidad.
- **FR-CLN-003 — MUST:** registrar accesos, modificaciones, exportaciones y consultas relevantes.
- **FR-CLN-004 — MUST:** distinguir borradores generados por IA de documentos revisados, aprobados o firmados profesionalmente.
- **FR-CLN-005 — MUST:** no permitir a la IA diagnosticar, prescribir ni decidir tratamientos de forma autónoma.
- **FR-CLN-006 — MUST:** mantener la trazabilidad de correcciones de registros cerrados, sin sobrescritura silenciosa.
- **FR-CLN-007 — MUST:** no incluir datos clínicos identificables en logs generales, métricas técnicas o prompts de depuración innecesarios.
- **FR-CLN-008 — MUST:** usar datos ficticios o anonimizados en pruebas hasta que se autorice formalmente otro tratamiento.
- **FR-CLN-009 — SHOULD:** permitir formularios y documentos sanitarios configurables una vez aprobados por responsables profesionales.
- **FR-CLN-010 — MUST:** cada variable clínica o de rendimiento debe tener unidad, método, fecha, procedencia y responsable cuando corresponda.

**Criterio de aceptación:** las pruebas de autorización demuestran que un usuario comercial no puede consultar datos clínicos mediante la interfaz, URL de documento o API directa.

## 6.12 Medicina deportiva y rendimiento

- **FR-SPT-001 — COULD:** mantener perfiles de deportistas y organizaciones deportivas con controles de acceso apropiados.
- **FR-SPT-002 — COULD:** registrar evaluaciones de antropometría, composición corporal, capacidad aeróbica, fuerza, salto, movilidad, agilidad y otras pruebas aprobadas.
- **FR-SPT-003 — MUST cuando se habilite:** identificar unidades, protocolo, equipo, fecha, condiciones y responsable de cada medición.
- **FR-SPT-004 — MUST cuando se habilite:** separar medición observada, valor calculado e interpretación.
- **FR-SPT-005 — MUST cuando se habilite:** documentar y versionar fórmulas y validarlas con casos conocidos.
- **FR-SPT-006 — MUST cuando se habilite:** no inventar variables que no entregue el dispositivo ni interpretar umbrales sin respaldo validado.
- **FR-SPT-007 — MUST cuando se habilite:** generar recomendaciones como borradores para revisión profesional cuando tengan impacto sanitario.
- **FR-SPT-008 — SHOULD:** permitir comparación longitudinal y reportes autorizados.

## 6.13 Cumplimiento y riesgos

- **FR-CMP-001 — SHOULD:** registrar obligaciones internas o externas confirmadas, responsable, frecuencia, fecha de vencimiento y fuente.
- **FR-CMP-002 — SHOULD:** gestionar evidencias y tareas preventivas/correctivas.
- **FR-CMP-003 — SHOULD:** mantener una matriz de riesgos con probabilidad, impacto, controles y responsable.
- **FR-CMP-004 — MUST:** distinguir obligación confirmada, interpretación preliminar y recomendación.
- **FR-CMP-005 — MUST:** permitir señalar revisión profesional pendiente.
- **FR-CMP-006 — MUST:** no afirmar que la empresa cumple legalmente por el solo hecho de que un agente no detecte desviaciones.

## 6.14 Inteligencia de negocios e informes

- **FR-BI-001 — MUST:** definir cada KPI mediante nombre, propósito, fórmula, fuente, periodicidad y filtros admitidos.
- **FR-BI-002 — MUST:** utilizar cálculos deterministas del backend para valores numéricos importantes.
- **FR-BI-003 — MUST:** presentar periodo, fecha de extracción y estado de calidad de datos.
- **FR-BI-004 — SHOULD:** exportar informes en formatos adecuados para el área usuaria.
- **FR-BI-005 — MUST:** no combinar datos de ámbitos con permisos incompatibles.
- **FR-BI-006 — MUST:** informar datos faltantes y supuestos en lugar de completar valores inventados.
- **FR-BI-007 — SHOULD:** mostrar tendencias y comparación con periodos anteriores cuando la base de datos tenga profundidad suficiente.

## 6.15 Automatización y notificaciones

- **FR-AUTO-001 — MUST:** cada automatización tendrá nombre, propósito, propietario, desencadenante, condiciones, acciones y política de error.
- **FR-AUTO-002 — MUST:** las automatizaciones deberán poder desactivarse sin despliegue de código cuando sea razonable.
- **FR-AUTO-003 — MUST:** prevenir acciones duplicadas causadas por reintentos, mediante idempotencia o clave de ejecución.
- **FR-AUTO-004 — MUST:** registrar estado `PENDIENTE`, `EN_PROCESO`, `COMPLETADA`, `FALLIDA`, `CANCELADA` o equivalentes documentados.
- **FR-AUTO-005 — MUST:** las comunicaciones externas solo se ejecutan cuando el proveedor, la plantilla, la autorización y las preferencias aplicables se encuentran configurados.
- **FR-AUTO-006 — MUST:** registrar fallos, reintentos y resultado final sin filtrar información confidencial en mensajes técnicos.
- **FR-AUTO-007 — MUST:** las tareas que produzcan compromisos, efectos externos o cambios sensibles deben requerir autorización conforme a su nivel de riesgo.

---

## 7. Requisitos para el sistema multiagente

### 7.1 Agentes previstos

| ID | Nombre técnico | Función principal | Prioridad inicial |
|---|---|---|---|
| AG-00 | `ORCHESTRATOR` | Interpretar solicitudes, planificar, enrutar y consolidar resultados | MUST — MVP-3 |
| AG-01 | `BUSINESS_MANAGER` | Análisis general y planificación | MUST — MVP-3 (R0–R1) |
| AG-02 | `FINANCE_ANALYST` | Cálculos e interpretación financiera sobre datos autorizados | Fase A, primero lectura/propuesta |
| AG-03 | `SALES_CRM` | Apoyo en clientes, seguimiento y cotizaciones | MUST — MVP-3 (R0–R2) |
| AG-04 | `OPERATIONS_COORDINATOR` | Apoyo en agenda y órdenes | MUST — MVP-3 (R0–R2) |
| AG-05 | `CLINICAL_DOCUMENTATION` | Estructuración de documentos clínicos autorizados | Posterior y restringido |
| AG-06 | `INVENTORY_EQUIPMENT` | Consultas y alertas de insumos/equipos | Fase A |
| AG-07 | `PEOPLE_COORDINATOR` | Apoyo administrativo de personas | Posterior |
| AG-08 | `COMPLIANCE_RISK` | Identificar pendientes y riesgos para revisión humana | SHOULD, no certificador |
| AG-09 | `BUSINESS_INTELLIGENCE` | Explicar indicadores basados en datos fiables | MUST — MVP-3 (R0) |
| AG-10 | `AUTOMATION_COORDINATOR` | Preparar y ejecutar flujos internos aprobados | MUST — MVP-3 (R0–R2, sin envíos externos) |
| AG-11 | `MARKETING_CONTENT` | Preparar contenido comercial y campañas | COULD |
| AG-12 | `SPORTS_PERFORMANCE` | Apoyo en evaluación de rendimiento deportivo | Posterior y sujeto a validación |
| AG-13 | `DEVELOPER_ASSISTANT` | Ayuda a mantener el software y documentación | COULD, aislado del entorno productivo |

### 7.2 Requisitos del orquestador

- **FR-AI-001 — MUST:** recibir una solicitud, identificar intención, contexto y resultado esperado.
- **FR-AI-002 — MUST:** seleccionar únicamente agentes y herramientas habilitados para la tarea.
- **FR-AI-003 — MUST:** dividir trabajos complejos en subtareas, cuando proceda, y registrar dependencias.
- **FR-AI-004 — MUST:** verificar permisos y restricciones antes de invocar una herramienta.
- **FR-AI-005 — MUST:** no conceder más permisos a un agente derivado que los otorgados a la solicitud y al usuario que la originó.
- **FR-AI-006 — MUST:** registrar agente seleccionado, versión de configuración, herramientas llamadas, resultado, duración, errores y aprobaciones, minimizando datos sensibles.
- **FR-AI-007 — MUST:** entregar resultados con resumen, acciones sugeridas, fuentes de datos, supuestos, advertencias y aprobación pendiente si corresponde.
- **FR-AI-008 — MUST:** comunicar claramente cuando no disponga de datos o no pueda completar una acción.
- **FR-AI-009 — MUST:** tener límites configurables de iteraciones, tiempo, llamadas a herramientas y tokens/costo, según proveedor.
- **FR-AI-010 — MUST:** manejar falla o indisponibilidad de modelo sin perder el registro de la solicitud.
- **FR-AI-011 — MUST:** no ejecutar instrucciones encontradas en documentos o contenido externo como si fueran políticas del sistema; tratar ese contenido como dato no confiable.
- **FR-AI-012 — MUST:** solicitar aclaración solo cuando la falta de datos impida proceder de forma segura; en los demás casos declarar supuestos y preparar un borrador.

### 7.3 Contrato de entrada del agente

Cada ejecución deberá recibir un contexto estructurado equivalente a:

- `task_id` o identificador de ejecución.
- `requester_id` y contexto autorizado del usuario.
- `goal` y descripción de la tarea.
- `business_context` mínimo necesario.
- `allowed_tools` y permisos efectivos.
- `constraints` y nivel de riesgo.
- `source_records` o referencias a datos recuperados por el backend.
- `deadline` o límite de ejecución, si aplica.
- `output_schema` esperado.

Los secretos, tokens, contraseñas y credenciales no se incluirán en prompts.

### 7.4 Contrato de salida del agente

La salida deberá ser estructurada y validable. Como mínimo:

- `status`: `COMPLETED`, `NEEDS_REVIEW`, `BLOCKED` o `FAILED` (`DECISIONS.md` DEC-017; distinto del estado de la ejecución `AiRunStatus`).
- `summary`: resumen legible.
- `result`: resultado dentro del esquema pedido.
- `sources`: identificadores o referencias de registros usados, sin exponer información innecesaria.
- `assumptions`: supuestos explícitos.
- `warnings`: limitaciones, datos ausentes o riesgos.
- `proposed_actions`: acciones recomendadas, nunca ejecutadas si necesitan aprobación.
- `approval_required`: indicador y motivo cuando corresponda.
- `agent_id`, `run_id` y datos de trazabilidad técnica.

Una respuesta de texto libre no se considerará evidencia suficiente de que una operación se ejecutó.

### 7.5 Herramientas permitidas

Las herramientas serán funciones del backend con esquemas definidos, autorizaciones y validación. Ejemplos:

- Consultar cliente o servicio autorizado.
- Buscar oportunidades, cotizaciones u órdenes según permisos.
- Calcular indicadores utilizando código determinista.
- Preparar un borrador de cotización o documento.
- Crear una tarea o recordatorio después de validar permisos.
- Proponer ajustes de inventario sin confirmar una compra.
- Consultar disponibilidad de recursos.
- Preparar informes a partir de registros autorizados.

Los agentes no tendrán acceso directo irrestricto a MySQL, al sistema operativo, a archivos arbitrarios ni a comandos de shell. Toda herramienta nueva deberá tener propietario, entradas validadas, permisos, nivel de riesgo, efecto observable y pruebas.

### 7.6 Niveles de riesgo y aprobación

- **R0 — Lectura:** consulta de datos no sensibles autorizados. No requiere aprobación individual adicional si el permiso ya está concedido.
- **R1 — Borrador/análisis:** generar texto, cálculos, recomendaciones o reportes preliminares. El resultado se identifica como borrador.
- **R2 — Cambio interno reversible:** crear tarea, actualizar un estado no crítico o preparar un registro. Requiere permisos, auditoría y reglas de idempotencia; según el caso, confirmación de usuario.
- **R3 — Efecto externo o compromiso:** enviar comunicaciones, emitir documentación comercial o crear un compromiso contractual. Requiere aprobación explícita configurada.
- **R4 — Alto impacto/sensible:** pagos, eliminación irreversible, decisión clínica, acción legal oficial, cambio de permisos privilegiados o tratamiento sensible no autorizado. Bloqueado para ejecución autónoma; exige proceso formal y control humano apropiado.

El nivel final de una operación será el más restrictivo entre el tipo de dato, la herramienta y el impacto de la acción. Esta clasificación es la canónica (`DECISIONS.md` DEC-020). En el MVP los agentes solo ejecutan R0–R2.

### 7.7 Requisitos de calidad de IA

- **FR-AI-013 — MUST:** los cálculos financieros y métricas se ejecutan con código probado, no se delegan únicamente al lenguaje generativo.
- **FR-AI-014 — MUST:** cada agente tendrá instrucciones versionadas y un conjunto de pruebas de regresión.
- **FR-AI-015 — MUST:** evaluar al menos corrección, cumplimiento de permisos, formato, citas a registros fuente y comportamiento ante datos insuficientes.
- **FR-AI-016 — MUST:** disponer de límites de uso y mecanismos para detener ciclos o llamadas repetidas.
- **FR-AI-017 — MUST:** registrar resultados de pruebas y regresiones antes de cambios de prompt, herramienta o modelo.
- **FR-AI-018 — MUST:** el modelo no debe inferir que una acción externa ocurrió solo porque produjo un mensaje que lo afirma.
- **FR-AI-019 — SHOULD:** permitir cambiar entre modelos locales y proveedores externos aprobados mediante una capa de abstracción.
- **FR-AI-020 — MUST:** cuando el proveedor externo se habilite, comprobar controles de transferencia y tratamiento de datos antes de enviar información.

---

## 8. Procesos de negocio y recorridos principales

### 8.1 Flujo comercial a servicio

1. El usuario crea o localiza al cliente.
2. Registra una oportunidad o solicitud.
3. Selecciona los servicios del catálogo y los parámetros requeridos.
4. El sistema calcula importes desde reglas verificables.
5. La cotización queda como borrador y pasa a revisión si corresponde.
6. Un usuario autorizado emite y envía la cotización mediante un canal configurado, si aplica.
7. Se registra aceptación, rechazo o vencimiento.
8. Una cotización aceptada puede convertirse en orden de servicio de manera idempotente.
9. Operaciones asigna fecha, responsable y recursos después de comprobar disponibilidad.
10. Se registra la prestación, incidencia, documentación pendiente o cancelación.
11. El cierre operativo no implica automáticamente facturación o pago.
12. Los indicadores se actualizan desde los registros fuente.

**Criterio de aceptación del flujo:** debe poder recorrerse íntegramente en un entorno de prueba, manteniendo relaciones y registro de cambios, sin crear órdenes duplicadas.

### 8.2 Seguimiento de cuenta por cobrar

1. Registrar documento o cuenta pendiente mediante una fuente autorizada.
2. Asociar cliente, referencia, importe, vencimiento y estado.
3. Mostrar seguimiento pendiente y responsable.
4. Registrar pagos confirmados según respaldo o integración validada.
5. Actualizar saldo por código determinista.
6. Mantener evidencia y cambios de estado.

No se marcará una cuenta como pagada por una recomendación de la IA o por el simple envío de un recordatorio.

### 8.3 Reposición de insumos

1. Consultar disponibilidad y demanda prevista desde órdenes programadas.
2. Detectar stock bajo, vencimientos o necesidades próximas.
3. El agente propone una reposición y muestra origen del cálculo.
4. Inventario o administración revisa la recomendación.
5. La compra sigue el procedimiento de aprobación establecido por la empresa.
6. Al recibir los insumos, se registran cantidad, lote y vencimiento cuando corresponda.
7. El inventario se actualiza mediante un movimiento auditado.

### 8.4 Informe gerencial asistido por IA

1. El usuario selecciona informe, periodo y filtros.
2. El backend comprueba permisos y recupera los datos autorizados.
3. El software calcula métricas reproducibles.
4. La IA prepara explicación, hallazgos y posibles acciones.
5. Se muestran fuentes, supuestos, datos faltantes y límites.
6. El usuario revisa y aprueba el informe.
7. La versión final se almacena o exporta con fecha, periodo y responsable.

### 8.5 Gestión de tarea multiagente

1. Registrar solicitud y contexto autorizado.
2. Clasificar intención y riesgo.
3. Elaborar plan de ejecución y seleccionar agentes autorizados.
4. Ejecutar subtareas en el orden apropiado.
5. Validar salidas con esquemas y reglas de negocio.
6. Solicitar aprobación antes de las acciones restringidas.
7. Ejecutar solo acciones aprobadas y registrar el resultado real del backend.
8. Consolidar la respuesta y mostrar tareas completadas, fallidas o pendientes.

---

## 9. Requisitos de datos

### 9.1 Dominios iniciales

El diseño de `DATABASE_SPEC.md` deberá cubrir al menos los dominios siguientes:

- Identidad, usuarios, roles y permisos.
- Organización, configuración y sedes.
- Clientes, empresas, contactos y direcciones.
- Catálogo de servicios, categorías, precios y costos.
- Oportunidades, actividades y cotizaciones versionadas.
- Agenda, disponibilidad, recursos y órdenes de servicio.
- Ingresos, egresos, cuentas por cobrar/pagar y categorías.
- Productos, inventario, ubicaciones, lotes, movimientos y activos.
- Documentos, versiones, aprobaciones y archivos.
- Personal, disponibilidad, competencias y capacitación.
- Información clínica restringida y evaluación deportiva, en fases posteriores.
- Agentes, configuraciones, tareas, ejecuciones, herramientas y aprobaciones.
- Auditoría, notificaciones, automatizaciones y errores de integración.

### 9.2 Reglas de integridad

- **DR-001 — MUST:** usar claves primarias internas estables.
- **DR-002 — MUST:** definir claves foráneas e índices para las relaciones usadas habitualmente.
- **DR-003 — MUST:** definir estados mediante enums de aplicación o catálogos controlados, con convenciones coherentes.
- **DR-004 — MUST:** usar tipos decimales para cantidades monetarias.
- **DR-005 — MUST:** mantener fechas de creación y actualización; registrar autoría en operaciones que lo requieran.
- **DR-006 — MUST:** usar transacciones para cambios que deban persistir como una unidad, como convertir una cotización en orden.
- **DR-007 — MUST:** aplicar mecanismos idempotentes a operaciones que podrían repetirse por reintento.
- **DR-008 — MUST:** definir borrado lógico, archivo, anulación o corrección por dominio; no eliminar registros financieros u operativos consolidados sin trazabilidad.
- **DR-009 — MUST:** no almacenar credenciales en tablas de negocio ni en código fuente.
- **DR-010 — MUST:** separar información clínica del dominio comercial y definir autorización por registro.
- **DR-011 — MUST:** definir estrategia de retención y eliminación para cada clase de dato antes de producción.
- **DR-012 — MUST:** utilizar datos sintéticos en tests automatizados por defecto.
- **DR-013 — SHOULD:** incluir procedencia de datos externos y fecha de sincronización cuando existan integraciones.

### 9.3 Cálculos y fuentes

Todo indicador financiero o de negocio debe documentar:

- Nombre y finalidad.
- Fórmula y reglas de inclusión/exclusión.
- Tablas o fuentes autorizadas.
- Periodo y zona horaria.
- Tratamiento de estados cancelados, reversas y duplicados.
- Política de redondeo.
- Distinción entre observado, calculado y estimado.
- Casos de prueba con resultados conocidos.

---

## 10. Requisitos de API e integración

- **FR-API-001 — MUST:** exponer funciones de backend mediante una API autenticada y versionada según el diseño final.
- **FR-API-002 — MUST:** validar tipo, formato, tamaño y reglas de negocio de todas las entradas.
- **FR-API-003 — MUST:** entregar códigos HTTP consistentes y errores estructurados sin exponer trazas internas o secretos.
- **FR-API-004 — MUST:** paginar las colecciones grandes y limitar tamaños de petición.
- **FR-API-005 — MUST:** verificar autorización a nivel de recurso y acción.
- **FR-API-006 — MUST:** usar transacciones para flujos que actualizan varios registros vinculados.
- **FR-API-007 — MUST:** definir idempotencia donde un reintento pueda duplicar una operación.
- **FR-API-008 — MUST:** validar el origen y los permisos para descargas de documentos.
- **FR-API-009 — SHOULD:** documentar endpoints y esquemas con OpenAPI u otro estándar elegido.
- **FR-API-010 — MUST:** nunca incluir credenciales, tokens privados o información clínica innecesaria en mensajes de error.

### 10.1 Integraciones potenciales

Las siguientes integraciones son posibilidades sujetas a elección del proveedor, disponibilidad de API, credenciales, permisos, condiciones comerciales y pruebas:

- Ollama para inferencia de modelos locales.
- Correo electrónico transaccional.
- Calendario.
- Mensajería empresarial y recordatorios.
- Facturación electrónica/DTE o sistema tributario actualmente utilizado.
- Herramientas de exportación PDF/DOCX.
- Sistemas de laboratorio, equipos de bioimpedancia u otros dispositivos compatibles.
- Plataformas de videoconferencia o telemedicina.

No se declarará una integración como disponible hasta que se haya probado técnicamente y se haya definido el manejo de errores, límites, autenticación y datos compartidos.

---

## 11. Seguridad, privacidad y cumplimiento

### 11.1 Requisitos de seguridad

- **NFR-SEC-001 — MUST:** validar autenticación y autorización en backend.
- **NFR-SEC-002 — MUST:** usar HTTPS y una configuración segura en los entornos expuestos a Internet.
- **NFR-SEC-003 — MUST:** guardar secretos en configuración segura del entorno o gestor de secretos, no en el repositorio.
- **NFR-SEC-004 — MUST:** aplicar protección contra CSRF/XSS/inyección y ataques relevantes según la arquitectura adoptada.
- **NFR-SEC-005 — MUST:** limitar intentos de autenticación y peticiones sensibles.
- **NFR-SEC-006 — MUST:** registrar acciones relevantes sin registrar contraseñas, tokens ni datos clínicos innecesarios.
- **NFR-SEC-007 — MUST:** aplicar mínimo privilegio a usuarios, agentes, procesos y cuentas de infraestructura.
- **NFR-SEC-008 — MUST:** almacenar archivos privados fuera de acceso público directo o mediante controles de descarga autorizada.
- **NFR-SEC-009 — MUST:** tener copias de seguridad y probar la restauración antes de producción.
- **NFR-SEC-010 — MUST:** contar con procedimiento de gestión de vulnerabilidades e incidentes antes del despliegue público.
- **NFR-SEC-011 — MUST:** probar controles de acceso mediante casos negativos, no solo casos exitosos.
- **NFR-SEC-012 — SHOULD:** usar cifrado en reposo para datos y respaldos sensibles según capacidades de infraestructura y riesgo.

### 11.2 Privacidad por diseño

- Recopilar solo los datos necesarios para una finalidad definida.
- Separar datos comerciales, financieros, laborales y sanitarios conforme a sus niveles de sensibilidad.
- Limitar la exposición de datos a los agentes y modelos.
- Definir finalidad, retención, exportación, corrección y eliminación de cada categoría de información.
- Establecer permisos diferenciados para lectura, edición, descarga y exportación.
- Verificar la normativa chilena aplicable y las fechas de entrada en vigor de modificaciones legales antes de producción.
- No asumir que una consulta a la IA equivale a una base válida para procesar datos personales o sanitarios.

### 11.3 Requisitos legales de producto

La aplicación debe facilitar el cumplimiento, pero no sustituye asesoría legal, contable o clínica. Antes de producción, responsables designados deberán revisar las obligaciones efectivamente aplicables a Ergo SaniTas SpA, sus servicios, contratos, personal, tratamiento de datos y documentos. El módulo de cumplimiento debe distinguir fuentes oficiales verificadas de interpretaciones preliminares.

---

## 12. Requisitos no funcionales

### 12.1 Fiabilidad

- **NFR-REL-001 — MUST:** las operaciones críticas deben mantener consistencia transaccional.
- **NFR-REL-002 — MUST:** los fallos de IA o integraciones no deben borrar ni duplicar solicitudes.
- **NFR-REL-003 — MUST:** mostrar estados pendientes, completados y fallidos de tareas asíncronas.
- **NFR-REL-004 — MUST:** registrar errores técnicos con identificador de correlación y mensajes sanitizados.
- **NFR-REL-005 — MUST:** tener un procedimiento documentado para migraciones y restauración.

### 12.2 Rendimiento y capacidad

- **NFR-PERF-001 — MUST:** paginar listados y evitar cargar todos los registros de una entidad en una sola petición.
- **NFR-PERF-002 — MUST:** aplicar índices con base en relaciones y consultas reales.
- **NFR-PERF-003 — SHOULD:** procesar informes pesados y tareas largas mediante colas cuando resulte necesario.
- **NFR-PERF-004 — MUST:** establecer pruebas de referencia antes de definir metas finales de latencia y concurrencia.
- **NFR-PERF-005 — SHOULD:** mantener la interfaz operativa cuando el proveedor de IA no esté disponible, mostrando una advertencia clara.

No se fijan tiempos específicos de respuesta hasta conocer infraestructura, datos y concurrencia prevista.

### 12.3 Mantenibilidad

- **NFR-MAIN-001 — MUST:** versionar código, migraciones y documentación.
- **NFR-MAIN-002 — MUST:** separar configuración por entorno.
- **NFR-MAIN-003 — MUST:** agregar pruebas para reglas críticas de negocio.
- **NFR-MAIN-004 — MUST:** mantener las reglas de negocio en backend, no solo en componentes de React.
- **NFR-MAIN-005 — MUST:** versionar prompts, herramientas, fórmulas e instrucciones de agentes.
- **NFR-MAIN-006 — SHOULD:** configurar formateo, análisis estático y pruebas automáticas en integración continua.

### 12.4 Usabilidad y accesibilidad

- **NFR-UX-001 — MUST:** mantener lenguaje claro y coherente en español.
- **NFR-UX-002 — MUST:** identificar acciones sensibles y explicar el efecto de confirmarlas.
- **NFR-UX-003 — MUST:** indicar errores junto al campo o acción que los provoca y orientar la corrección.
- **NFR-UX-004 — SHOULD:** permitir navegación por teclado en flujos principales.
- **NFR-UX-005 — SHOULD:** proporcionar contraste adecuado y no comunicar estados únicamente mediante color.

### 12.5 Portabilidad

- **NFR-PORT-001 — MUST:** la configuración local no debe depender de rutas absolutas específicas del computador del desarrollador.
- **NFR-PORT-002 — MUST:** documentar requisitos, variables de entorno y comandos para levantar el proyecto.
- **NFR-PORT-003 — SHOULD:** usar contenedores o scripts reproducibles si facilitan el entorno del equipo, sin imponerlos antes de confirmar las condiciones de trabajo.

---

## 13. Auditoría y observabilidad

- **FR-AUD-001 — MUST:** registrar quién ejecutó una acción sensible, qué acción fue, sobre qué tipo de recurso y cuándo.
- **FR-AUD-002 — MUST:** registrar actor humano o técnico, resultado y origen de la acción cuando sea posible.
- **FR-AUD-003 — MUST:** las aprobaciones incluirán solicitante, aprobador, fecha, decisión, alcance y justificación cuando aplique.
- **FR-AUD-004 — MUST:** mantener correlación entre una solicitud al orquestador, las ejecuciones de agentes y las herramientas llamadas.
- **FR-AUD-005 — MUST:** evitar alterar o borrar registros de auditoría desde los flujos normales de la aplicación.
- **FR-AUD-006 — MUST:** aplicar restricciones de acceso a los logs y límites de retención.
- **FR-AUD-007 — MUST:** no almacenar prompts completos con datos sensibles si no es necesario; aplicar minimización o redacción.
- **FR-AUD-008 — SHOULD:** permitir búsquedas administrativas por fecha, usuario, módulo, tipo de acción, estado e identificador de correlación.
- **FR-AUD-009 — MUST:** diferenciar error del modelo, error de herramienta, rechazo por permisos y rechazo por validación de negocio.

---

## 14. Pruebas y calidad

### 14.1 Pruebas backend

- Autenticación, permisos y políticas por recurso.
- Validación de entradas y cambios de estado.
- Cálculo de cotizaciones y descuentos.
- Versionado de precios y cotizaciones.
- Conversión idempotente de cotización en orden.
- Detección de conflictos de agenda.
- Movimientos de inventario, concurrencia y ajustes.
- Cálculos financieros con casos de referencia.
- Acceso autorizado a documentos.
- Auditoría de acciones sensibles.

### 14.2 Pruebas frontend

- Inicio/cierre de sesión y navegación según permisos.
- Formularios, validaciones y mensajes de error.
- Estados de carga, ausencia de resultados y fallos de red.
- Filtros, paginación y búsqueda.
- Flujos de cotización, programación y seguimiento de órdenes.
- Presentación responsive de las vistas principales.
- Confirmaciones para acciones de riesgo.

### 14.3 Pruebas de IA

- Enrutamiento de la tarea al agente apropiado.
- Rechazo de herramientas no permitidas.
- Respuestas frente a datos insuficientes.
- Prevención de cifras no respaldadas.
- Validación de salida estructurada.
- Ataques de prompt injection contenidos en documentos.
- Respeto de permisos del usuario originador.
- No ejecución de acciones externas sin aprobación.
- Gestión de timeout, error o indisponibilidad del modelo.
- Regresión ante cambios de modelo o prompt.

### 14.4 Pruebas de seguridad

- Acceso directo a endpoints sin permiso.
- Elevación de privilegios y manipulación de roles.
- Descarga de documentos mediante URLs o identificadores manipulados.
- Inyección en parámetros, filtros, búsquedas y formularios.
- Protección de secretos y sanitización de logs.
- Rate limiting y sesiones.
- Restauración de respaldo.
- Verificación de que los datos clínicos están fuera del alcance de usuarios comerciales.

### 14.5 Datos de prueba

Se utilizarán clientes, RUT, direcciones, casos clínicos, importes y demás valores sintéticos. Los datos deberán ser marcados claramente como demostrativos. No se copiará información real de pacientes al entorno de desarrollo sin autorización y revisión específica.

### 14.6 Definición de terminado (Definition of Done)

Un requisito se considera terminado solo cuando:

1. Está implementado de acuerdo con su regla de negocio.
2. Tiene pruebas proporcionadas a su criticidad.
3. Valida autorización en backend.
4. Registra auditoría cuando corresponde.
5. Maneja errores y casos límite.
6. La documentación técnica y funcional está actualizada.
7. La interfaz informa el estado real, no el esperado.
8. La funcionalidad fue demostrada en un entorno de prueba.

---

## 15. Prioridades de implementación

### P0 — Bloqueantes del núcleo (MVP-1 y MVP-2)

- Repositorio y configuración local documentada.
- Autenticación, roles y autorización backend.
- Configuración general de empresa.
- Aprobaciones persistentes, idempotencia y folios.
- Catálogo y CRM básicos.
- Oportunidades y cotizaciones versionadas.
- Agenda y órdenes de servicio.
- Auditoría inicial.
- Pruebas de las reglas críticas.

### P1 — IA y automatización interna (MVP-3)

- Orquestador central con Ollama local.
- Agentes `SALES_CRM`, `OPERATIONS_COORDINATOR`, `BUSINESS_INTELLIGENCE`, `BUSINESS_MANAGER` y `AUTOMATION_COORDINATOR`.
- Herramientas R0–R2 tipadas y salida estructurada validada.
- Propuestas R3 con aprobación persistente.
- Dashboard ejecutivo básico y reportes de ventas y operación (los agentes los explican, el backend los calcula).
- Recordatorios internos y seguimiento de actividades automatizados.
- Métricas y evaluación de calidad de agentes.

### P2 — Operación ampliada

- Exportación de cotizaciones y documentos.
- Finanzas administrativas e inventario inicial con `FINANCE_ANALYST` e `INVENTORY_EQUIPMENT`.
- Comunicaciones externas integradas (habilita R3 con aprobación).

### P3 — Dominios especializados

- Gestión clínica y documentos sanitarios restringidos.
- Medicina deportiva y evaluación de rendimiento.
- Gestión de personas extendida.
- Cumplimiento y riesgos avanzado.
- Integraciones externas confirmadas.

### P4 — Producción

- Configuración de infraestructura segura.
- HTTPS, secretos, monitoreo y alerta.
- Copias de seguridad y recuperación ensayada.
- Pruebas de carga basadas en carga esperada.
- Revisión de privacidad y cumplimiento.
- Capacitación, manuales y aprobación de puesta en marcha.

---

## 16. Criterios de aceptación del MVP

El MVP no será aceptado hasta cumplir todos los criterios MUST de esta sección:

- **AC-MVP-001:** un usuario puede iniciar sesión y acceder solo a sus módulos autorizados.
- **AC-MVP-002:** un intento de acceso no autorizado a un endpoint es rechazado desde el backend.
- **AC-MVP-003:** se pueden crear, actualizar, buscar y archivar clientes y organizaciones sin duplicación evidente.
- **AC-MVP-004:** se pueden crear servicios y modificar precios desde la interfaz autorizada, conservando versiones cuando correspondan.
- **AC-MVP-005:** se puede registrar una oportunidad con responsable, estado y próxima acción.
- **AC-MVP-006:** se puede generar una cotización con líneas, cantidades, precios, totales y fecha de vigencia.
- **AC-MVP-007:** la cotización emitida no cambia retroactivamente y conserva historial de versiones.
- **AC-MVP-008:** los descuentos fuera de los límites configurados no se emiten sin aprobación.
- **AC-MVP-009:** una cotización aceptada puede convertirse en orden sin crear duplicados ante reintentos.
- **AC-MVP-010:** la agenda identifica conflictos configurados de horario o recursos.
- **AC-MVP-011:** los cambios de programación y los motivos de cancelación quedan registrados.
- **AC-MVP-012:** la orden de servicio no confunde prestación, emisión de documento tributario y pago.
- **AC-MVP-013:** los usuarios pueden identificar tareas y órdenes pendientes dentro de sus permisos.
- **AC-MVP-014:** los errores se muestran de forma comprensible y no exponen secretos ni trazas internas.
- **AC-MVP-015:** se dispone de pruebas automatizadas para reglas comerciales críticas.
- **AC-MVP-016:** el entorno local puede instalarse y levantarse siguiendo documentación versionada.
- **AC-MVP-017:** los datos usados en demostraciones son ficticios.
- **AC-MVP-018:** existe una lista de limitaciones conocidas y decisiones pendientes para la siguiente fase.

### 16.1 Aceptación del sistema multiagente

La capa de IA forma parte del MVP (MVP-3). El MVP no se acepta sin cumplir además:

- **AC-AI-001:** la solicitud conserva identificador y estado durante todo el flujo.
- **AC-AI-002:** cada agente utiliza solo herramientas declaradas y autorizadas.
- **AC-AI-003:** las salidas cumplen un esquema validado o se marcan como fallidas/no válidas.
- **AC-AI-004:** se registran tareas, herramientas, errores, advertencias y aprobaciones.
- **AC-AI-005:** las acciones R3 y R4 no se ejecutan sin los controles previstos.
- **AC-AI-006:** el orquestador no inventa resultados de una herramienta fallida.
- **AC-AI-007:** el sistema informa claramente cuando la inferencia no está disponible.
- **AC-AI-008:** se puede reproducir una prueba de regresión con versiones identificadas de agente y modelo.

---

## 17. Matriz de trazabilidad inicial

| Objetivo | Módulos que contribuyen | Requisitos representativos | Evidencia de aceptación |
|---|---|---|---|
| OBJ-01 Centralizar información | CRM, catálogo, cotizaciones, órdenes | FR-CRM-001 a 006; FR-SVC-001 a 008; FR-QUO-001 a 009 | Recorrido de cliente a orden vinculada |
| OBJ-02 Mejorar seguimiento comercial | CRM, oportunidades, cotizaciones, dashboard | FR-SALES-001 a 008; FR-QUO-001 a 011 | Embudo comercial y próximas acciones |
| OBJ-03 Reducir errores y duplicidad | Datos, API, transacciones | FR-DATA-003; FR-QUO-009; DR-006/007 | Pruebas de duplicados e idempotencia |
| OBJ-04 Coordinar operaciones | Agenda, órdenes, recursos | FR-OPS-001 a 013 | Agenda sin asignación conflictiva no detectada en casos cubiertos |
| OBJ-05 Mejorar visibilidad financiera | Finanzas, BI, dashboard | FR-FIN-001 a 013; FR-BI-001 a 007 | Reportes que distinguen registrado y estimado |
| OBJ-06 Asegurar trazabilidad | Auditoría, documentos, inventario | FR-AUD-001 a 009; FR-DOC; FR-INV | Historial y evidencia de acciones sensibles |
| OBJ-07 Incorporar IA con control | Orquestador, agentes, aprobación | FR-AI-001 a 020 | Pruebas de permisos, esquema y aprobación |
| OBJ-08 Proteger datos | Seguridad, privacidad, clínica | NFR-SEC; FR-CLN; reglas de acceso | Suite de pruebas negativas y revisión de seguridad |
| OBJ-09 Mejorar análisis | Dashboard, BI, finanzas | FR-DASH; FR-BI | KPI reproducibles con fuente y fórmula |
| OBJ-10 Facilitar evolución | API, modularidad, documentación | NFR-MAIN; FR-API | Cambio de módulo sin romper contratos probados |

---

## 18. Riesgos y decisiones pendientes

### 18.1 Riesgos del producto

- **Alcance excesivo:** mitigación mediante MVP y fases, sin activar todos los agentes simultáneamente.
- **Datos incompletos:** mostrar faltantes y no presentar proyecciones como hechos.
- **Cálculos financieros inconsistentes:** centralizar fórmulas en backend y probar casos de referencia.
- **Permisos demasiado amplios:** revisar matriz de acceso y ejecutar pruebas negativas.
- **IA que actúa fuera de alcance:** herramientas limitadas, validación backend, idempotencia y aprobación.
- **Exposición de datos clínicos:** módulo restringido, mínimo privilegio, auditoría y revisión previa a producción.
- **Dependencias externas:** estado visible, reintentos controlados y alternativa manual cuando sea posible.
- **Pérdida de datos:** respaldos automatizados y pruebas periódicas de restauración.
- **Cambios legales o de integración:** revisión de fuentes oficiales y contratos técnicos actualizados.

### 18.2 Decisiones por confirmar con Ergo SaniTas SpA

1. Nombre definitivo visible de la plataforma: INGENERIA JARVIS o ERGO JARVIS.
2. Estado actual del código y herramientas instaladas.
3. Usuarios, perfiles y aprobadores reales.
4. Catálogo oficial de servicios, modalidades, precios, costos y recursos.
5. Estados y documentos que se utilizan actualmente para cotizar y realizar servicios.
6. Método de seguimiento financiero y relación con el sistema contable actual.
7. Sistema de facturación electrónica utilizado y posibilidad de integración.
8. Canales de comunicación que la empresa autoriza usar.
9. Sistemas de agenda, correo y almacenamiento documental disponibles.
10. Volumen esperado de registros, usuarios simultáneos y ubicación del despliegue.
11. Alcance de los datos clínicos que se mantendrán y quién será responsable de su revisión.
12. Protocolos aprobados para los servicios sanitarios y de medicina deportiva.
13. Política de respaldos, retención, exportación y eliminación.
14. Umbrales de aprobación para descuentos y otras decisiones comerciales.

Mientras estas decisiones estén pendientes, deben utilizarse valores demostrativos o configuraciones seguras, claramente marcadas como pendientes.

---

## 19. Entregables técnicos derivados

Este PRD deberá traducirse y mantenerse alineado con los siguientes documentos:

1. `SYSTEM_ARCHITECTURE.md` — componentes, límites, despliegue y decisiones de arquitectura.
2. `DATABASE_SPEC.md` — entidades, campos, relaciones, restricciones, índices y ciclo de vida de datos.
3. `LARAVEL_DATABASE_IMPLEMENTATION.md` — migraciones, modelos Eloquent, Policies y Services.
4. `API_SPEC.md` — contratos, validaciones, rutas y códigos de respuesta.
5. `AGENT_CATALOG.md` — ficha individual, herramientas, permisos, riesgos y pruebas por agente.
6. `AI_ORCHESTRATION_SPEC.md` — delegación, ciclo de vida, límites y aprobación.
7. `SECURITY_PRIVACY_SPEC.md` — amenazas, permisos, clasificación y protección de datos.
8. `FRONTEND_DESIGN_SPEC.md` — mapa de pantallas, navegación y estados.
9. `TEST_PLAN.md` — pruebas derivadas de cada requisito.
10. `DEPLOYMENT_RUNBOOK.md` — instalación, configuración, operación y recuperación.

Cada requisito MUST debe enlazarse con al menos una prueba o evidencia de aceptación en el plan de pruebas.

---

## 20. Instrucción para el equipo de desarrollo o agente programador

Usa `INGENERIA_JARVIS_MASTER_SPEC.md` y este `PRODUCT_REQUIREMENTS.md` como fuentes funcionales iniciales. Antes de implementar:

1. Inspecciona el repositorio y el entorno actual; no sobrescribas código existente sin comprenderlo.
2. Identifica requisitos contradictorios, dependencias y decisiones pendientes.
3. Implementa por fases, empezando por el núcleo y el flujo comercial-operativo del MVP.
4. Mantén los cálculos de negocio y controles de autorización en Laravel.
5. Usa migraciones y pruebas versionadas para cambios de base de datos.
6. No implementes acciones de IA sin herramienta explícita, permiso, validación y nivel de riesgo.
7. Usa datos ficticios para desarrollo y pruebas.
8. Documenta comandos exactos de instalación y ejecución para Windows y para el entorno de despliegue finalmente elegido.
9. Ejecuta pruebas relevantes después de cada cambio e informa cuáles pasaron y cuáles no se pudieron ejecutar.
10. No afirmes que una función está completa si solo existe la interfaz o si no se ha probado el backend.
11. Si una decisión de producto está pendiente, regístrala en vez de inventar el comportamiento.
12. Actualiza este documento cuando una decisión aprobada cambie un requisito.

### Primer encargo recomendado

Preparar un plan de implementación del MVP que identifique:

- estructura existente del repositorio;
- dependencias y comandos de ejecución;
- módulos y tablas requeridos para el primer flujo;
- matriz inicial de roles y permisos;
- contratos API mínimos;
- orden de migraciones;
- pruebas automáticas y manuales;
- riesgos, supuestos y bloqueantes;
- lista ordenada de tareas pequeñas y verificables.

No empezar por crear todos los agentes. Primero validar núcleo de datos, permisos, catálogo, CRM, cotización, agenda y orden de servicio.

---

## 21. Historial de versiones

| Versión | Fecha | Descripción |
|---|---|---|
| 1.0.0 | 2026-10-11 | Primera versión del PRD derivada de la especificación maestra de INGENERIA JARVIS para Ergo SaniTas SpA. |
| 1.1.0 | 2026-10-15 | Alineado con `DECISIONS.md`: enums en inglés con etiquetas en español, IA de bajo riesgo dentro del MVP (MVP-3), prioridades reordenadas. |

---

## 22. Definición de éxito

El producto habrá logrado su objetivo inicial cuando Ergo SaniTas SpA pueda gestionar de manera trazable el recorrido desde cliente y oportunidad hasta cotización aceptada, programación y orden de servicio; consultar reportes coherentes basados en registros reales; mantener acceso controlado y auditable; y posteriormente incorporar agentes que ayuden a analizar y preparar acciones sin saltarse las reglas del negocio ni la aprobación humana.
