# INGENERIA JARVIS — MASTER SPECIFICATION

**Proyecto:** INGENERIA JARVIS / ERGO JARVIS  
**Organización inicial:** Ergo SaniTas SpA  
**Tipo de sistema:** Plataforma empresarial de gestión con agentes de inteligencia artificial especializados  
**Documento:** Especificación maestra funcional y técnica  
**Versión:** 1.1.0  
**Estado:** Especificación inicial para revisión y descomposición en documentos de implementación  
**Fecha:** 11 de octubre de 2026 (actualizada 15 de octubre de 2026)  
**Fuente única de enums, campos, permisos y alcance del MVP:** `DECISIONS.md` (prevalece ante contradicciones)  
**Idioma principal:** Español  
**Zona horaria de negocio:** `America/Santiago`  
**Moneda predeterminada:** CLP (peso chileno)  
**Arquitectura de referencia:** React + Vite + TypeScript, Laravel API, MySQL, Ollama/Qwen y Python opcional para servicios analíticos especializados.

> **Nota de nomenclatura:** Se conserva el nombre de archivo solicitado, `INGENERIA_JARVIS_MASTER_SPEC.md`. En la interfaz, el producto podrá presentarse como **ERGO JARVIS**, mientras que INGENERIA JARVIS identifica este documento/arquitectura de referencia. El nombre comercial definitivo es una decisión pendiente.

---

## 1. Propósito del documento

Este archivo constituye la fuente inicial de verdad para diseñar, construir, probar, desplegar y mantener una plataforma integral de gestión para Ergo SaniTas SpA. Define el propósito del producto, sus dominios funcionales, agentes especializados, límites de automatización, arquitectura técnica, requisitos de seguridad, modelo de información preliminar, interfaces, fases y criterios de aceptación.

Los documentos técnicos posteriores deben derivarse de esta especificación. Si una implementación contradice este documento, el equipo debe registrar la discrepancia, proponer una decisión de arquitectura y actualizar la documentación antes de introducir cambios estructurales.

Las decisiones que cierran ambigüedades (valores de enums, nombres de campos, permisos, niveles de riesgo y alcance del MVP) se registran en `DECISIONS.md`, que prevalece sobre este documento y los derivados. En particular: el producto es para **una sola empresa** (DEC-001) y la **IA forma parte del MVP** con herramientas de riesgo R0–R2 (DEC-002).

### 1.1 Documentos derivados previstos

1. `PRODUCT_REQUIREMENTS.md`: requisitos funcionales y no funcionales detallados.
2. `SYSTEM_ARCHITECTURE.md`: arquitectura lógica y física.
3. `DATABASE_SPEC.md`: entidades, atributos, relaciones, restricciones e índices.
4. `LARAVEL_DATABASE_IMPLEMENTATION.md`: migraciones, modelos Eloquent, relaciones, enums, Policies, Services y Repositories cuando procedan.
5. `API_SPEC.md`: contratos REST, validaciones, códigos de respuesta y ejemplos.
6. `AGENT_CATALOG.md`: definición formal de cada agente, prompt, permisos, herramientas y pruebas.
7. `AI_ORCHESTRATION_SPEC.md`: ciclo de vida de tareas multiagente, delegación, límites y trazabilidad.
8. `SECURITY_PRIVACY_SPEC.md`: amenazas, controles, clasificación de datos, retención y respuesta a incidentes.
9. `FRONTEND_DESIGN_SPEC.md`: navegación, pantallas, componentes, estados, accesibilidad y diseño responsive.
10. `TEST_PLAN.md`: estrategia de pruebas unitarias, integración, seguridad, rendimiento y aceptación.
11. `DEPLOYMENT_RUNBOOK.md`: configuración local, staging, producción, copias de seguridad, restauración y monitoreo.
12. `DATA_GOVERNANCE_SPEC.md`: calidad, procedencia, permisos, conservación y ciclo de vida de los datos.

---

## 2. Resumen ejecutivo

INGENERIA JARVIS será una plataforma modular que permitirá a Ergo SaniTas SpA administrar clientes, empresas, catálogo de servicios, oportunidades comerciales, cotizaciones, agenda, órdenes de atención, recursos humanos, inventario, equipos, documentos, seguimiento financiero e indicadores de gestión desde una única aplicación.

La plataforma incorporará un **orquestador de IA** que interprete solicitudes de usuarios, divida tareas, seleccione agentes especializados, invoque herramientas autorizadas y consolide resultados. Los agentes no tendrán acceso irrestricto a la base de datos ni podrán ejecutar acciones sensibles por iniciativa propia. Toda acción se realizará mediante servicios del backend con validación de identidad, permisos, reglas de negocio y registro de auditoría.

La primera versión debe priorizar operaciones administrativas y comerciales de bajo riesgo. Los flujos clínicos y el tratamiento de datos de salud se incorporarán con permisos estrictos, registro de accesos, revisión humana y validación de requisitos normativos antes de usar datos reales.

### 2.1 Resultados de negocio esperados

- Disponer de una fuente centralizada y coherente de información empresarial.
- Reducir duplicidad de registros y trabajo administrativo manual.
- Mejorar el seguimiento de clientes, cotizaciones y ventas.
- Conocer costos, ingresos, márgenes y necesidades de caja con información verificable.
- Coordinar citas, domicilios, profesionales, equipos e insumos.
- Mantener trazabilidad de las acciones y decisiones administrativas.
- Estandarizar documentos, informes y procedimientos.
- Obtener alertas oportunas sobre tareas pendientes, inventario, vencimientos y desviaciones.
- Facilitar análisis gerenciales con indicadores y datos actualizados.
- Incorporar agentes especializados de forma gradual, medible y segura.

### 2.2 Principios rectores

1. **Primero los procesos, después los agentes:** no se automatizará un proceso que no esté suficientemente definido.
2. **Una fuente de verdad:** los datos operacionales se registran en el backend y la base de datos; los modelos no son el repositorio oficial.
3. **Permisos mínimos:** cada usuario, servicio y agente solo accede a los recursos necesarios.
4. **Humano en el circuito:** se exige aprobación en operaciones financieras, contractuales, clínicas, legales o destructivas.
5. **Trazabilidad:** las acciones sensibles dejan evidencia verificable.
6. **Privacidad desde el diseño:** el acceso a datos personales y sanitarios se minimiza y se controla.
7. **Modularidad:** comenzar con un monolito modular en Laravel y extraer servicios separados solo cuando haya una razón técnica medible.
8. **Modelos intercambiables:** la lógica de negocio no depende de un único modelo de IA.
9. **Resultados verificables:** las salidas de agentes deben señalar fuente, supuestos, nivel de confianza y pasos pendientes cuando corresponda.
10. **Implementación incremental:** entregar valor funcional en etapas, con pruebas y validación por usuarios.

---

## 3. Contexto de Ergo SaniTas SpA

### 3.1 Perfil funcional de referencia

Ergo SaniTas SpA presta servicios de salud y enfermería a domicilio y servicios de apoyo preventivo y deportivo a personas, empresas, establecimientos educacionales y organizaciones deportivas. La aplicación debe soportar servicios realizados en terreno y servicios coordinados de forma remota.

El catálogo funcional inicial puede incluir, sujeto a revisión y configuración por la empresa:

- Exámenes ocupacionales.
- Test de drogas en orina.
- Electrocardiograma.
- Toma de muestras a domicilio.
- Chequeo preventivo cardiovascular.
- Chequeo cardiovascular deportivo.
- Bioimpedancia corporal.
- Holter de presión arterial.
- Evaluaciones deportivas integrales.
- Nutrición.
- Telemedicina.
- Otros servicios preventivos o de enfermería autorizados por la empresa.

El catálogo es administrable: ningún servicio, precio, costo, duración, requisito clínico, profesional habilitado o protocolo debe quedar fijado permanentemente en código.

### 3.2 Segmentos de clientes

- Personas particulares.
- Empresas y sus trabajadores.
- Establecimientos educacionales y apoderados.
- Escuelas deportivas y clubes.
- Centros deportivos.
- Comunidades y organizaciones.
- Instituciones y entidades que contraten servicios preventivos.

### 3.3 Operación domiciliaria y en terreno

El sistema debe contemplar dirección de prestación, contacto en terreno, fecha y ventana horaria, profesional responsable, equipamiento, insumos, estado de confirmación, resultado operativo, evidencia documental y cambios o incidencias. La información de ubicación solo estará disponible para los roles que necesiten utilizarla.

### 3.4 Configuración de negocio

Los datos que cambian con el tiempo deben configurarse desde la aplicación: precios, costos estimados, impuestos aplicables, políticas comerciales, tiempos de servicio, disponibilidad, segmentos de clientes, zonas operativas, plantillas y parámetros de alertas. Todo cambio relevante debe registrar usuario, fecha, valor anterior y valor nuevo.

---

## 4. Objetivos, alcance y exclusiones

### 4.1 Objetivos incluidos

- Administrar el ciclo de vida del cliente, desde el contacto hasta la prestación y el seguimiento.
- Gestionar el catálogo de servicios, listas de precios y cotizaciones.
- Coordinar agenda, órdenes de trabajo y servicios domiciliarios.
- Registrar costos operacionales y datos financieros administrativos.
- Gestionar inventario, lotes, vencimientos, equipos y mantenciones.
- Administrar documentos y plantillas con control de acceso.
- Ofrecer paneles gerenciales e indicadores.
- Operar un entorno multiagente con permisos explícitos y trazabilidad.
- Permitir conexión a modelos locales vía Ollama y dejar abierta una interfaz para proveedores de modelos externos aprobados.
- Integrar progresivamente servicios de correo, mensajería, calendario y facturación cuando exista proveedor, credenciales y validación técnica.

### 4.2 Exclusiones iniciales

No forman parte del primer MVP, salvo decisión expresa posterior:

- Reemplazar un sistema contable o tributario autorizado.
- Sustituir un sistema de ficha clínica que tenga requisitos regulatorios específicos sin el análisis correspondiente.
- Diagnosticar, prescribir o decidir tratamientos de forma autónoma mediante IA.
- Ejecutar pagos bancarios sin confirmación humana y mecanismos formales de autorización.
- Emitir automáticamente documentos tributarios sin integración válida y pruebas de cumplimiento.
- Sustituir el juicio profesional de personal de salud.
- Presentar trámites oficiales en nombre de la empresa sin permisos y aprobación explícita.
- Abrir el sistema al público sin pruebas de seguridad, respaldo y recuperación.

### 4.3 Supuestos explícitos

- La primera instalación se desarrollará y validará en entorno local.
- La empresa es el usuario organizacional inicial; la arquitectura no debe impedir incorporar sedes o unidades más adelante.
- React + Vite, Laravel y MySQL son la base tecnológica propuesta.
- Python se utilizará solo cuando sea conveniente para procesamiento analítico o científico.
- Ollama y modelos Qwen son opciones iniciales para inferencia local, no una dependencia funcional rígida.
- La empresa confirmará los usuarios, permisos, procesos actuales, precios, protocolos, proveedores y obligaciones concretas antes de producción.
- Los datos de prueba serán ficticios y no podrán confundirse con registros reales.

---

## 5. Usuarios, roles y control de acceso

El control de acceso se implementará en backend. Ocultar botones en frontend no constituye un control de seguridad suficiente.

### 5.1 Roles iniciales

| Rol | Responsabilidad principal | Alcance por defecto |
|---|---|---|
| `SUPER_ADMIN` | Configuración técnica y administración global | Configuración de plataforma; acceso a contenido sensible solo cuando esté justificado y auditado |
| `DIRECCION` | Dirección empresarial y aprobación de decisiones | Paneles, decisiones, indicadores y aprobaciones autorizadas |
| `ADMINISTRACION` | Gestión operativa administrativa | Clientes, documentos y tareas administrativas asignadas |
| `FINANZAS` | Seguimiento de ingresos, gastos y cuentas | Datos financieros autorizados; sin facultad automática de pago |
| `COMERCIAL` | Clientes, contactos, oportunidades y cotizaciones | CRM y datos comerciales necesarios |
| `OPERACIONES` | Agenda, rutas y órdenes de servicio | Información necesaria para coordinar servicios |
| `PROFESIONAL_SALUD` | Registro y revisión de actividades sanitarias autorizadas | Solo registros clínicos relacionados con su función y permisos |
| `NUTRICION` | Atención y documentos nutricionales autorizados | Registros asignados al ámbito profesional correspondiente |
| `INVENTARIO` | Stock, lotes, insumos y equipos | Inventario y movimientos autorizados |
| `RRHH` | Personal, turnos y documentos laborales | Datos laborales estrictamente necesarios |
| `AUDITOR_LECTURA` | Revisión de trazabilidad | Lectura acotada, sin modificación de registros |
| `AGENT_SERVICE` | Identidad técnica para ejecutar herramientas de IA | Acceso por herramienta y permiso explícito; nunca sesión humana compartida |

Los roles son perfiles de permisos iniciales, no una lista cerrada. Se usarán permisos granulares y Policies en Laravel para limitar operaciones por recurso, estado, relación con el usuario y sensibilidad de los datos.

### 5.2 Requisitos de autenticación

- Inicio de sesión individual; no compartir credenciales.
- Gestión segura de contraseñas mediante mecanismos estándar del framework.
- Recuperación de acceso con verificación segura.
- Cierre de sesión y expiración de sesión configurables.
- Protección ante intentos repetidos y limitación de tasa.
- MFA recomendado y requerido para roles privilegiados antes de producción cuando la infraestructura lo soporte.
- Revocación inmediata de sesiones cuando una cuenta sea deshabilitada.
- Separación entre cuentas humanas y cuentas de servicios.

### 5.3 Autorización

Cada operación debe validar:

1. Identidad autenticada.
2. Permiso para la acción.
3. Acceso al recurso específico.
4. Estado válido del registro.
5. Reglas de negocio y dependencias.
6. Necesidad de confirmación adicional.
7. Registro de auditoría para acciones sensibles.

---

## 6. Catálogo de módulos funcionales

### 6.1 Dashboard ejecutivo

**Propósito:** entregar una vista consolidada de la operación y facilitar decisiones con datos trazables.

Funciones:

- Resumen de ventas cotizadas, aceptadas y realizadas.
- Ingresos y egresos registrados, pendientes y vencidos.
- Agenda del día y próximos servicios.
- Oportunidades comerciales abiertas.
- Servicios pendientes de confirmar o cerrar.
- Alertas de inventario y equipos.
- Tareas pendientes y aprobaciones.
- Indicadores por período, servicio, segmento y responsable.
- Filtros por fechas, estado, zona y unidad.

Reglas:

- Cada indicador muestra periodo, fecha de actualización y definición del cálculo.
- No mezclar ventas cotizadas con ventas aceptadas, servicios ejecutados ni pagos recibidos.
- Los totales deben proceder de consultas verificables al backend.
- Mostrar “sin datos” en lugar de inventar valores.

### 6.2 CRM y clientes

Funciones:

- Crear y mantener clientes personas y organizaciones.
- Registrar contactos, canales autorizados, direcciones y preferencias.
- Mantener historial de interacciones y seguimientos.
- Clasificar clientes por segmento y estado.
- Detectar posibles duplicados.
- Registrar consentimiento y preferencias de comunicación cuando corresponda.
- Relacionar clientes con cotizaciones, órdenes de servicio, documentos y facturación.
- Gestionar empresas con múltiples contactos y sedes.

Reglas:

- No usar el RUT como clave primaria de base de datos.
- Validar formato cuando aplique, pero no inferir identidad a partir de datos incompletos.
- No registrar datos clínicos dentro de campos generales de CRM.
- Los contactos no deben recibir comunicaciones automatizadas sin base válida y configuración aprobada.

### 6.3 Catálogo de servicios y precios

Funciones:

- Crear servicios y categorías.
- Mantener código interno, descripción comercial y alcance.
- Definir unidades de cobro, requisitos, modalidad presencial/domiciliaria/remota, recursos requeridos y documentación asociada.
- Mantener listas de precios por tipo de cliente o convenio.
- Registrar costos estimados y costos reales separados.
- Activar e inactivar servicios sin perder su historial.
- Versionar precios con fecha de inicio y término.

Reglas:

- Precios y costos se almacenan con tipo decimal, nunca coma flotante binaria.
- Los cambios de precio no modifican cotizaciones históricas ya emitidas.
- Los márgenes deben indicar qué categorías de costo incluyen.
- Impuestos y tratamientos tributarios deben configurarse tras validación contable.

### 6.4 Ventas, oportunidades y cotizaciones

Etapas de oportunidad (código / etiqueta, ver `DECISIONS.md` DEC-010): `NEW` Nueva, `CONTACTED` Contactada, `QUALIFIED` Calificada, `QUOTE_IN_PREPARATION` Cotización en preparación, `QUOTED` Cotizada, `NEGOTIATION` Negociación, `WON` Ganada, `LOST` Perdida, `ON_HOLD` Pausada.

Estados de cotización (DEC-011): `DRAFT` Borrador, `PENDING_APPROVAL` Pendiente de revisión, `ISSUED` Emitida, `SENT` Enviada, `ACCEPTED` Aceptada, `REJECTED` Rechazada, `EXPIRED` Vencida, `CANCELLED` Cancelada.

Funciones:

- Registrar necesidades y volumen estimado.
- Asociar servicios, cantidades, precios, descuentos autorizados y condiciones.
- Generar cotización PDF con versión, fecha de validez y datos empresariales.
- Aprobar excepciones según límites configurables.
- Registrar motivo de pérdida y próxima acción.
- Convertir cotización aceptada en orden de servicio sin duplicar información.
- Medir tasas de conversión, ciclo comercial y valor de oportunidades.

Reglas:

- Los descuentos fuera de rango necesitan aprobación.
- Las versiones emitidas permanecen inmutables; una revisión genera nueva versión.
- La IA puede redactar y proponer, pero la cotización no se envía hasta cumplir las reglas de autorización configuradas.

### 6.5 Agenda, operaciones y servicios en terreno

Funciones:

- Programar citas y ventanas horarias.
- Asignar profesionales y recursos.
- Gestionar dirección de prestación y datos de contacto operativo.
- Validar disponibilidad y detectar conflictos.
- Crear órdenes de servicio desde cotizaciones aceptadas o solicitudes autorizadas.
- Registrar confirmación, salida, llegada, prestación, incidencias, cierre y reprogramación.
- Administrar rutas como apoyo operativo, sin asumir tiempos de desplazamiento no calculados.
- Enviar recordatorios por canales integrados y autorizados.
- Registrar motivos de cancelación y de servicio no realizado.

Estados de orden (DEC-012, con transiciones definidas allí): `DRAFT` Borrador, `PENDING_CONFIRMATION` Pendiente de confirmación, `CONFIRMED` Confirmada, `ASSIGNED` Asignada, `IN_PROGRESS` En curso, `COMPLETED` Realizada, `PENDING_DOCUMENTATION` Pendiente de documentación, `CLOSED` Cerrada, `CANCELLED` Cancelada, `NOT_PERFORMED` No realizada, `ON_HOLD` En pausa.

Reglas:

- No asignar un recurso sin validar disponibilidad.
- Los cambios de hora o profesional deben quedar registrados.
- La agenda operativa muestra únicamente la información clínica mínima necesaria.
- Los criterios de atención urgente o prioritaria deben ser definidos por profesionales responsables, no improvisados por el agente.

### 6.6 Gestión clínica y documentos sanitarios

**Módulo restringido.** Su desarrollo requiere definir el alcance exacto, profesionales responsables, formularios, protocolos y marco normativo aplicable antes de producción.

Funciones potenciales:

- Formularios y registros de prestación autorizados.
- Identificación del profesional responsable.
- Registro de evaluaciones y mediciones permitidas.
- Adjuntar informes y documentos con control de versiones.
- Seguimiento de estado documental.
- Generación de borradores para revisión y firma profesional.
- Trazabilidad de accesos, consultas, modificaciones y exportaciones.
- Separación de información administrativa y clínica.

Reglas de seguridad clínica:

- La IA no diagnostica, prescribe ni decide tratamientos autónomamente.
- La IA puede resumir o estructurar información solo dentro de permisos definidos y con revisión humana cuando exista impacto clínico.
- Un borrador generado por IA no equivale a un informe validado.
- Los documentos finales deben identificar al profesional responsable según el flujo y las normas aplicables.
- Los registros firmados o cerrados no se sobrescriben silenciosamente; se corrigen mediante mecanismos trazables.
- No incluir información clínica sensible en logs técnicos, prompts de depuración ni analítica general.
- No usar datos sanitarios reales para pruebas o entrenamiento sin autorización, base legítima y controles aplicables.

### 6.7 Medicina deportiva y evaluación del rendimiento

Este módulo puede crecer en fases posteriores, alineado con el desarrollo de estaciones de evaluación deportiva.

Capacidades configurables:

- Perfil de deportista y organización deportiva.
- Antropometría y composición corporal.
- Integración de resultados de bioimpedancia, incluida la información disponible desde equipos compatibles.
- Evaluación cardiovascular deportiva.
- Pruebas de saltos, fuerza, movilidad, resistencia y agilidad.
- Capacidad aeróbica y lactato cuando exista protocolo validado.
- Historial longitudinal y comparaciones entre evaluaciones.
- Informes para profesionales y recomendaciones revisadas por especialistas.
- Alertas configuradas por profesionales responsables.

Reglas:

- Cada variable debe registrar unidad, método, equipo, fecha, condiciones y profesional/operador responsable cuando corresponda.
- Las fórmulas de índices y estimaciones deben documentarse, versionarse y probarse.
- Los valores calculados deben distinguirse de las mediciones observadas.
- No inventar parámetros que el dispositivo o protocolo no proporcione.
- Los resultados deben interpretarse según población y metodología; no extrapolar umbrales sin respaldo validado.
- El procesamiento de video deportivo, si se agrega, se mantendrá separado de datos clínicos y tendrá controles de consentimiento, acceso y conservación.

### 6.8 Finanzas y administración

Funciones iniciales:

- Registrar ingresos y egresos administrativos.
- Relacionar movimientos con cliente, servicio, proveedor, categoría y documento.
- Gestionar cuentas por cobrar y pagar en estado administrativo.
- Registrar fechas de vencimiento y estado de seguimiento.
- Preparar flujo de caja proyectado a partir de datos identificados.
- Comparar presupuesto contra resultado registrado.
- Calcular costos directos e indirectos según método configurado.
- Analizar margen por servicio, cliente, segmento y periodo.
- Exportar reportes para contabilidad.

Reglas:

- Distinguir cotización, venta aceptada, prestación realizada, factura emitida, cuenta por cobrar y pago recibido.
- Distinguir egreso comprometido, documento recibido y pago realizado.
- No presentar estimaciones como movimientos confirmados.
- No generar asientos contables oficiales sin diseño y revisión contable específica.
- No ejecutar pagos bancarios desde el agente en la primera versión.
- Los cálculos financieros tendrán fórmula documentada, periodo, origen de datos y pruebas automatizadas.

### 6.9 Inventario, insumos y equipamiento

Funciones:

- Catálogo de insumos, equipos y materiales.
- Unidades de medida y conversiones autorizadas.
- Existencias por ubicación.
- Lotes, números de serie y vencimientos cuando aplique.
- Entradas, salidas, ajustes y transferencias.
- Reserva de insumos para servicios programados.
- Stock mínimo y alertas.
- Mantenimiento preventivo de equipos.
- Registro de calibración, inspección y disponibilidad cuando corresponda.
- Historial de consumo por servicio.

Reglas:

- Los movimientos de inventario son transaccionales y auditables.
- Un ajuste debe indicar motivo y responsable.
- No eliminar movimientos consolidados; corregir mediante movimientos compensatorios.
- Alertas de vencimiento deben usar fechas y reglas configurables.
- La IA puede recomendar reposición, pero toda compra queda sujeta al flujo de autorización.

### 6.10 Recursos humanos

Funciones potenciales:

- Ficha laboral administrativa con acceso restringido.
- Perfiles de cargo y competencias.
- Documentos de contratación y capacitación.
- Planificación de turnos y disponibilidad.
- Registro de certificaciones y vencimientos, cuando sea pertinente.
- Matriz de responsabilidades.
- Indicadores agregados de dotación y capacitación.

Reglas:

- Los datos laborales sensibles no se comparten con agentes no autorizados.
- No tomar decisiones de contratación, sanción o término de relación laboral de forma automática.
- Los cálculos de remuneraciones y obligaciones deben validarse con el sistema y profesional competente.

### 6.11 Documentos y gestión documental

Funciones:

- Plantillas empresariales aprobadas.
- Generación de borradores de cartas, informes, actas, cotizaciones y procedimientos.
- Control de versiones y metadatos.
- Clasificación por cliente, área, servicio y nivel de sensibilidad.
- Revisión, aprobación y publicación.
- Registro de descarga y acceso a documentos sensibles.
- Exportación a PDF/DOCX según necesidad.

Reglas:

- Los documentos generados deben marcarse como borrador hasta su aprobación.
- No inventar firmas, folios oficiales, autorizaciones ni antecedentes.
- Las plantillas deben tener propietario, versión y fecha de aprobación.

### 6.12 Cumplimiento y riesgos

Funciones:

- Registro de obligaciones aplicables y responsable interno.
- Calendario de vencimientos.
- Evidencias de cumplimiento.
- Registro y seguimiento de incidentes.
- Matriz de riesgos operativos y de información.
- Acciones preventivas y correctivas.
- Preparación de documentación para revisión profesional.

El agente de cumplimiento entrega alertas y análisis preliminares; no certifica por sí mismo el cumplimiento legal de la empresa. La aplicabilidad normativa debe ser validada con fuentes oficiales y asesoría competente.

### 6.13 Inteligencia de negocios

Funciones:

- KPI comerciales, operativos, financieros y de calidad.
- Tendencias por periodo.
- Comparaciones de presupuesto y resultados.
- Tableros por perfil.
- Exportación de información agregada.
- Detección de anomalías para revisión humana.
- Informe semanal y mensual configurable.

Todo KPI tendrá nombre, definición, fórmula, unidad, filtros, fuente, frecuencia de actualización y responsable funcional.

### 6.14 Automatización

Funciones:

- Reglas de activación por evento o calendario.
- Notificaciones de tareas y vencimientos.
- Creación de borradores de documentos.
- Recordatorios comerciales.
- Reportes recurrentes.
- Flujos de aprobación.
- Reintentos controlados y manejo de errores.

Las automatizaciones deberán ser idempotentes cuando corresponda, contar con estados visibles y permitir pausa, cancelación y reejecución controlada. No ejecutar acciones irreversibles sin aprobación explícita.

---

## 7. Arquitectura multiagente

### 7.1 Modelo de ejecución

El patrón recomendado es un **orquestador central con agentes especializados invocables como capacidades**, no una colección de bots con acceso independiente y libre a todos los datos.

Ciclo general:

1. El usuario envía una solicitud autenticada.
2. El backend valida sesión, permisos, límites y contexto.
3. El orquestador clasifica la tarea y detecta el dominio.
4. Define un plan de trabajo acotado.
5. Selecciona uno o varios agentes.
6. Cada agente recibe solo los datos mínimos necesarios.
7. Los agentes proponen respuestas o solicitan herramientas registradas.
8. Laravel valida cada herramienta y sus argumentos.
9. Si la acción requiere aprobación, el flujo queda pendiente.
10. El backend ejecuta operaciones autorizadas y registra resultados.
11. El orquestador consolida la respuesta, fuentes, advertencias y pendientes.
12. La interfaz muestra resultado y acciones disponibles.

### 7.2 Contrato estándar de agente

Cada agente deberá definir:

- `agent_id` y nombre legible.
- Versión y estado (`draft`, `active`, `disabled`, `retired`).
- Propósito y alcance.
- Dominios permitidos.
- Tipos de entrada y salida.
- Herramientas permitidas.
- Permisos requeridos.
- Fuentes documentales autorizadas.
- Restricciones y acciones prohibidas.
- Política de revisión humana.
- Modelo/provider preferido y fallback.
- Límites de tokens, tiempo y coste.
- Política de reintentos y manejo de errores.
- Criterios de evaluación.
- Casos de prueba.
- Responsable funcional.

### 7.3 Agentes iniciales

#### AGENT-00 — ORCHESTRATOR

**Misión:** convertir la petición del usuario en un plan ejecutable, seleccionar agentes, resolver dependencias y consolidar resultados.

**Puede:** clasificar, planificar, delegar, solicitar datos faltantes indispensables y emitir resultados consolidados.

**No puede:** saltarse permisos, inventar ejecuciones, ejecutar herramientas no registradas ni autorizarse a sí mismo operaciones sensibles.

**Salida estructurada:** objetivo, plan, agentes invocados, resultados, fuentes, advertencias, aprobaciones pendientes y estado final.

#### AGENT-01 — BUSINESS_MANAGER

**Misión:** apoyar la dirección y planificación empresarial.

**Tareas:** objetivos, planes de acción, análisis FODA, seguimiento de metas, priorización e informes ejecutivos.

**Herramientas:** lectura de KPI permitidos, consulta de tareas y preparación de planes.

**Restricciones:** no modificar precios, presupuestos o contratos sin el flujo de aprobación adecuado.

#### AGENT-02 — FINANCE_ANALYST

**Misión:** analizar costos, ingresos registrados, márgenes, cuentas y proyecciones de caja.

**Tareas:** flujo de caja, punto de equilibrio, desviaciones presupuestarias, rentabilidad por servicio y reportes para revisión contable.

**Herramientas:** consultas financieras autorizadas, calculadora validada, exportación de reportes agregados.

**Restricciones:** no inventar movimientos, no presentar proyecciones como reales, no realizar pagos ni alterar saldos confirmados.

#### AGENT-03 — SALES_CRM

**Misión:** apoyar la captación, seguimiento y conversión de oportunidades.

**Tareas:** clasificar oportunidades, preparar propuestas y cotizaciones, redactar seguimientos y detectar tareas vencidas.

**Herramientas:** lectura/escritura limitada de CRM, borrador de cotización, borrador de comunicación.

**Restricciones:** no enviar mensajes masivos o comunicaciones a clientes sin consentimiento, reglas de canal y autorización aplicables.

#### AGENT-04 — OPERATIONS_COORDINATOR

**Misión:** coordinar órdenes, agenda, recursos, domicilios y estado operativo de servicios.

**Tareas:** detectar conflictos de agenda, proponer asignaciones, generar listas de preparación y reportar incidencias.

**Herramientas:** consulta de disponibilidad, borrador de asignación, creación de tarea.

**Restricciones:** no revelar datos clínicos innecesarios ni modificar citas confirmadas sin cumplir las reglas de autorización.

#### AGENT-05 — CLINICAL_DOCUMENTATION

**Misión:** asistir en la organización y redacción documental sanitaria dentro de un ámbito autorizado.

**Tareas:** estructurar borradores, comprobar campos requeridos, resumir documentación permitida y detectar documentos faltantes.

**Herramientas:** plantillas clínicas autorizadas y acceso a datos mínimos bajo permisos profesionales.

**Restricciones:** no diagnosticar ni prescribir autónomamente; no emitir informe clínico final sin revisión y aprobación del profesional responsable; no acceder a registros no asignados.

#### AGENT-06 — INVENTORY_EQUIPMENT

**Misión:** apoyar el control de inventario, consumibles, vencimientos y equipos.

**Tareas:** alertas de stock, propuesta de reposición, seguimiento de mantenimiento y consumo estimado.

**Herramientas:** lectura de existencias, borrador de solicitud de compra, creación de alerta.

**Restricciones:** no aprobar compras ni registrar ajustes sin autorización.

#### AGENT-07 — PEOPLE_COORDINATOR

**Misión:** asistir en la planificación administrativa de personal, turnos, competencias y capacitación.

**Herramientas:** consultas de disponibilidad laboral autorizada, preparación de documentos y matrices de capacitación.

**Restricciones:** no tomar decisiones automatizadas sobre contratación, sanción, evaluación final o desvinculación.

#### AGENT-08 — COMPLIANCE_RISK

**Misión:** mantener un mapa de obligaciones, evidencias, plazos y riesgos para evaluación humana.

**Tareas:** checklists, alertas, matrices de cumplimiento y preparación de antecedentes.

**Herramientas:** biblioteca normativa aprobada, calendario y registro de evidencias.

**Restricciones:** no emitir certificación legal ni afirmar vigencia normativa sin fuente comprobada.

#### AGENT-09 — BUSINESS_INTELLIGENCE

**Misión:** convertir datos verificados en métricas e informes comprensibles.

**Tareas:** calcular KPI aprobados, comparar periodos, detectar anomalías y preparar tableros.

**Herramientas:** consultas analíticas de solo lectura y exportación autorizada.

**Restricciones:** no alterar datos fuente ni confundir correlación con causalidad.

#### AGENT-10 — AUTOMATION_COORDINATOR

**Misión:** proponer y ejecutar flujos aprobados de baja criticidad.

**Tareas:** recordatorios, generación de borradores, creación de tareas, reportes programados y alertas.

**Herramientas:** motor de flujos, notificaciones y tareas de cola autorizadas.

**Restricciones:** nunca encadenar acciones sensibles fuera de una aprobación vigente.

#### AGENT-11 — MARKETING_CONTENT

**Misión:** generar campañas, material comercial, contenidos y propuestas por segmento.

**Tareas:** calendario de contenidos, correos, folletos, páginas de servicios y análisis de campañas.

**Herramientas:** catálogo comercial aprobado, plantillas de marca y métricas agregadas.

**Restricciones:** no realizar afirmaciones clínicas no verificadas ni promesas comerciales engañosas; respetar preferencias y consentimientos de contacto.

#### AGENT-12 — SPORTS_PERFORMANCE

**Misión:** apoyar la organización y análisis de datos de evaluaciones deportivas mediante fórmulas y protocolos documentados.

**Tareas:** validar integridad de mediciones, calcular indicadores aprobados, comparar evaluaciones y preparar informes para revisión profesional.

**Herramientas:** fórmulas versionadas, catálogo de variables y protocolos autorizados.

**Restricciones:** distinguir medida y estimación; no hacer diagnóstico ni recomendar retorno a la práctica de forma autónoma.

#### AGENT-13 — DEVELOPER_ASSISTANT

**Misión:** apoyar al equipo de desarrollo en documentación, pruebas, revisión de código y diagnóstico técnico.

**Herramientas:** repositorio y entorno de desarrollo, inicialmente de solo lectura; generación de propuestas de cambios.

**Restricciones:** no ejecutar comandos destructivos ni desplegar código en producción sin revisión y aprobación humana.

### 7.4 Enrutamiento de solicitudes

El orquestador clasificará las solicitudes, por ejemplo:

- “¿Qué servicio deja mejor margen?” → FINANCE_ANALYST + BUSINESS_INTELLIGENCE.
- “Prepara una cotización para una empresa” → SALES_CRM; requiere aprobación antes de enviar.
- “Coordina las atenciones del viernes” → OPERATIONS_COORDINATOR; validar disponibilidad y permisos.
- “¿Qué insumos debo reponer?” → INVENTORY_EQUIPMENT + FINANCE_ANALYST para estimación, si procede.
- “Prepara el informe de resultados deportivos” → SPORTS_PERFORMANCE y, cuando corresponda, CLINICAL_DOCUMENTATION; requiere revisión profesional.
- “Resume las tareas administrativas pendientes” → BUSINESS_MANAGER + AUTOMATION_COORDINATOR.

El uso de varios agentes debe justificarse por la tarea y no ejecutarse por defecto cuando un agente único sea suficiente.

---

## 8. Herramientas controladas para agentes

Los agentes interactúan con el sistema mediante herramientas tipadas ofrecidas por Laravel, no mediante SQL libre ni acceso directo a credenciales.

Herramientas conceptuales iniciales:

- `crm.customer.search`
- `crm.customer.get`
- `crm.customer.create_draft`
- `crm.opportunity.list`
- `crm.opportunity.update_status`
- `sales.quote.create_draft`
- `sales.quote.calculate`
- `sales.quote.request_approval`
- `operations.availability.check`
- `operations.service_order.create_draft`
- `operations.schedule.propose_changes`
- `finance.report.read`
- `finance.cashflow.forecast`
- `finance.margin.calculate`
- `inventory.stock.read`
- `inventory.reorder.propose`
- `documents.template.list`
- `documents.draft.generate`
- `analytics.kpi.read`
- `tasks.create`
- `notifications.draft.create`
- `approvals.request`

Cada herramienta documentará esquema de entrada y salida, permisos necesarios, validaciones, idempotencia, límites, efectos secundarios y eventos de auditoría. Los nombres son contractuales preliminares y podrán ajustarse en `API_SPEC.md`.

### 8.1 Niveles de riesgo de herramienta

Definición canónica en `DECISIONS.md` DEC-020:

- **R0 — Lectura:** datos autorizados para el usuario; permiso del recurso y auditoría según sensibilidad.
- **R1 — Borrador/análisis:** texto, cálculo o recomendación sin modificar registros de negocio; se marca como borrador.
- **R2 — Cambio interno reversible:** crear tarea, borrador de cotización, recordatorio interno o propuesta de asignación; permiso, auditoría e idempotencia.
- **R3 — Efecto externo o compromiso:** aprobación explícita persistida antes de ejecutar.
- **R4 — Alto impacto (pago, eliminación irreversible, decisión clínica, acto legal, permisos privilegiados):** prohibida de forma autónoma.

En el MVP los agentes solo ejecutan herramientas R0–R2; las R3 quedan como propuesta con aprobación (DEC-002).

El motor de herramientas debe rechazar acciones por defecto cuando no exista clasificación o política definida.

---

## 9. Ciclo de aprobación humana

Las aprobaciones serán objetos persistentes, no simples mensajes en el chat.

Campos mínimos:

- Solicitante y rol.
- Agente que propuso la acción.
- Tipo de acción y recurso afectado.
- Resumen legible del cambio.
- Datos anteriores y propuestos, cuando sea procedente.
- Razón y fuentes de apoyo.
- Nivel de riesgo.
- Aprobador requerido.
- Fecha de expiración.
- Estado: `PENDING`, `APPROVED`, `REJECTED`, `EXPIRED`, `CANCELLED`, `EXECUTED`, `FAILED`.
- Resultado de ejecución y referencia de auditoría.

Una aprobación no será reutilizable para una acción diferente ni para datos que hayan cambiado materialmente desde su autorización. El backend volverá a validar permisos y reglas justo antes de ejecutar.

---

## 10. Arquitectura tecnológica

### 10.1 Arquitectura recomendada

**Monolito modular + capa de IA intercambiable.** Para el MVP, Laravel alberga los módulos del dominio, la API, autorización, validación y orquestación. Python queda como servicio opcional para algoritmos especializados, no como una segunda fuente de verdad.

Componentes:

1. **Frontend:** React, Vite y TypeScript.
2. **API y dominio:** Laravel REST API.
3. **Base de datos:** MySQL/InnoDB.
4. **Autenticación:** Laravel Sanctum o mecanismo equivalente validado para la modalidad de aplicación.
5. **Colas:** driver de base de datos en desarrollo; Redis recomendado en producción si los procesos lo justifican.
6. **IA local:** Ollama con modelos configurados por entorno.
7. **Proveedor de modelos:** interfaz/adapter para permitir cambios sin reescribir la lógica de negocio.
8. **Python:** servicio acotado para procesamiento científico, video o analítica que realmente lo requiera.
9. **Documentos:** almacenamiento privado con control de acceso; almacenamiento compatible con S3 u otra opción gestionada en producción si se requiere.
10. **Observabilidad:** logs estructurados, métricas, seguimiento de tareas y alertas, sin exponer datos clínicos innecesarios.

### 10.2 Principios de diseño de backend

- Separar controladores HTTP, servicios de aplicación, reglas de dominio y persistencia.
- Usar Form Requests o validadores equivalentes para entradas.
- Usar Policies/Gates para autorización.
- Transacciones de base de datos para operaciones con múltiples escrituras.
- Evitar lógica de negocio relevante dentro de controladores o prompts.
- Usar eventos de dominio/cola para tareas demoradas.
- Manejar errores mediante respuestas normalizadas.
- Añadir idempotencia a operaciones que puedan duplicarse por reintentos.
- No crear un Repository por obligación si Eloquent y un servicio son suficientes; incorporar repositorios cuando simplifiquen la complejidad o aislamiento.
- Versionar API (`/api/v1`).

### 10.3 Principios de frontend

- TypeScript estricto.
- Componentes reutilizables y formularios con validación.
- Diseño responsive para escritorio, tablet y móvil.
- Estados explícitos: carga, vacío, error, éxito, permiso denegado y dato desactualizado.
- Accesibilidad por teclado y etiquetas comprensibles.
- Manejo de errores de API sin revelar detalles internos.
- No confiar en filtros de cliente para proteger datos.
- Evitar almacenar información clínica en `localStorage`.
- No incluir claves secretas en el bundle.

### 10.4 IA y modelos

- Encapsular cada proveedor mediante una interfaz común.
- Mantener prompts de agentes versionados y revisables.
- Registrar versión de modelo, proveedor, plantilla de prompt y herramientas utilizadas para tareas relevantes.
- Definir timeouts, límites de contexto, reintentos y comportamiento fallback.
- Validar salidas estructuradas con esquemas antes de actuar.
- Tratar documentos y resultados recuperados como datos potencialmente no confiables; sus instrucciones no pueden cambiar las políticas del sistema.
- Aplicar defensa frente a prompt injection, exfiltración y llamadas no autorizadas a herramientas.
- No enviar datos personales/sanitarios a proveedores externos sin aprobación, base válida y controles definidos.

### 10.5 Recuperación de conocimiento (RAG)

RAG podrá buscar en documentos aprobados: manuales internos, catálogo de servicios, políticas, plantillas y normas de trabajo. Cada fragmento recuperado debe conservar metadatos de archivo, versión, fecha, área propietaria, clasificación y permisos.

Controles mínimos:

- Filtrado de documentos por permisos antes de incluirlos en contexto.
- Referencias a documento/página/sección cuando sea posible.
- Exclusión de archivos inactivos u obsoletos salvo consulta histórica explícita.
- Revisión de contenido antes de indexarlo.
- Separación entre conocimiento interno y datos transaccionales.
- No usar RAG como sustituto de consultas exactas a la base de datos para cifras operativas.

---

## 11. Modelo de datos preliminar

El modelo definitivo será definido en `DATABASE_SPEC.md`. La lista siguiente establece los dominios mínimos que se deben analizar; no constituye todavía una migración ejecutable.

### 11.1 Identidad, autorización y auditoría

- `users`
- `roles`
- `permissions`
- `role_user` / asignaciones de roles
- `permission_role` / asignaciones de permisos
- `user_sessions` cuando la estrategia lo requiera
- `audit_logs`
- `security_events`
- `approvals`
- `notifications`

### 11.2 Organización y CRM

- `company_settings`
- `branches` o `service_locations` si se necesitan sedes/ubicaciones operativas
- `customers`
- `organizations`
- `contacts`
- `addresses`
- `customer_consents`
- `interactions`
- `opportunities`
- `opportunity_activities`

La decisión entre `customers` y `organizations` debe evitar duplicación: una persona o institución puede ser cliente, contacto o representante de otra entidad según el modelo aprobado.

### 11.3 Catálogo y ventas

- `service_categories`
- `services`
- `service_requirements`
- `price_lists`
- `service_prices`
- `cost_components`
- `quotes`
- `quote_versions`
- `quote_items`
- `sales_orders` o entidad equivalente si se diferencia de orden de servicio
- `contracts` cuando el alcance comercial lo requiera

### 11.4 Operación

- `appointments`
- `service_orders`
- `service_order_items`
- `staff_assignments`
- `resource_assignments`
- `service_events`
- `service_incidents`
- `service_locations`
- `availability_rules`
- `tasks`

### 11.5 Finanzas administrativas

- `financial_categories`
- `financial_transactions`
- `receivables`
- `payables`
- `payments` como registro administrativo cuando el sistema no ejecute pagos
- `budgets`
- `budget_items`
- `cashflow_forecasts`
- `cost_allocations`

Este módulo no debe denominarse ni operar como contabilidad oficial sin diseño y validación adicionales.

### 11.6 Inventario y activos

- `inventory_items`
- `inventory_locations`
- `inventory_lots`
- `inventory_movements`
- `stock_reservations`
- `equipment_assets`
- `equipment_maintenance`
- `equipment_calibrations` si procede
- `suppliers`
- `purchase_requests`
- `purchase_request_items`

### 11.7 Documentos y clínica

- `documents`
- `document_versions`
- `document_templates`
- `document_approvals`
- `clinical_records` o entidades específicas por tipo de registro
- `clinical_measurements`
- `clinical_form_templates`
- `professional_signoffs`

Las tablas clínicas requieren diseño separado para definir quién puede acceder a cada registro, qué se conserva y cómo se corrigen datos sin perder la trazabilidad.

### 11.8 Personas y capacitación

- `staff_profiles`
- `staff_credentials`
- `staff_availability`
- `shifts`
- `training_records`
- `job_profiles`

### 11.9 Medicina deportiva

- `athletes`
- `sports_organizations`
- `assessment_sessions`
- `assessment_protocols`
- `assessment_variables`
- `assessment_measurements`
- `assessment_calculations`
- `devices`
- `device_measurements`
- `assessment_reports`

Los protocolos, variables, unidades y fórmulas deben versionarse.

### 11.10 Agentes y automatización

- `ai_agents`
- `ai_agent_versions`
- `ai_conversations`
- `ai_messages`
- `ai_runs`
- `ai_run_steps`
- `ai_tool_invocations`
- `ai_approvals` si se decide separar de las aprobaciones generales
- `prompt_templates`
- `knowledge_documents`
- `knowledge_chunks` o metadatos del índice vectorial
- `automation_workflows`
- `automation_runs`
- `scheduled_jobs`

No almacenar secretos de proveedores en tablas de prompts o agentes. Los secretos viven en variables de entorno o un gestor de secretos.

### 11.11 Convenciones de datos

- Usar claves primarias internas estables; exponer UUID/ULID cuando se requieran identificadores públicos no secuenciales.
- Usar claves foráneas, índices y restricciones de integridad.
- Guardar montos como `DECIMAL`, especificando precisión y escala apropiadas.
- Registrar fechas de negocio de acuerdo con la zona horaria `America/Santiago` y almacenar timestamps de forma consistente.
- Definir estados y transiciones controladas; no permitir cambios arbitrarios de estado.
- Aplicar borrado lógico solo donde sea útil y compatible con retención/auditoría; no borrar historial financiero o clínico sin regla legal aprobada.
- En datos sensibles, usar controles de acceso y, cuando corresponda, cifrado a nivel de aplicación o almacenamiento.
- No usar RUT, correo electrónico ni teléfono como identificador interno principal.
- Definir normalización de teléfono y validación de correo sin asumir que su formato demuestra identidad.
- Registrar origen, autor y fecha de cambios relevantes.

---

## 12. API REST preliminar

Todas las rutas de negocio estarán bajo `/api/v1`. La lista es orientativa y se detallará en `API_SPEC.md`.

### 12.1 Autenticación y sesión

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`
- Endpoints de recuperación y cambio de contraseña según el mecanismo elegido.

### 12.2 Clientes y CRM

- `GET /api/v1/customers`
- `POST /api/v1/customers`
- `GET /api/v1/customers/{id}`
- `PATCH /api/v1/customers/{id}`
- `GET /api/v1/opportunities`
- `POST /api/v1/opportunities`
- `PATCH /api/v1/opportunities/{id}`

### 12.3 Servicios y cotizaciones

- `GET /api/v1/services`
- `POST /api/v1/services` — permiso de administración de catálogo
- `GET /api/v1/quotes`
- `POST /api/v1/quotes`
- `POST /api/v1/quotes/{id}/request-approval`
- `POST /api/v1/quotes/{id}/issue`
- `POST /api/v1/quotes/{id}/accept`

### 12.4 Agenda y operaciones

- `GET /api/v1/appointments`
- `POST /api/v1/appointments`
- `PATCH /api/v1/appointments/{id}`
- `GET /api/v1/service-orders`
- `POST /api/v1/service-orders`
- `POST /api/v1/service-orders/{id}/events`
- `POST /api/v1/service-orders/{id}/close`

### 12.5 Finanzas, inventario e informes

- `GET /api/v1/finance/summary`
- `GET /api/v1/finance/transactions`
- `POST /api/v1/finance/transactions` — permisos y validaciones apropiadas
- `GET /api/v1/inventory/items`
- `POST /api/v1/inventory/movements`
- `GET /api/v1/reports/{reportKey}`

### 12.6 Agentes y aprobaciones

- `POST /api/v1/ai/chat`
- `GET /api/v1/ai/runs/{id}`
- `GET /api/v1/ai/agents`
- `POST /api/v1/approvals/{id}/approve`
- `POST /api/v1/approvals/{id}/reject`
- `GET /api/v1/approvals/pending`

Las rutas de aprobación deben verificar el usuario aprobador, la vigencia, el ámbito de autoridad, la integridad de la acción y cualquier separación de funciones requerida.

### 12.7 Requisitos generales de API

- Validación de cada entrada.
- Paginación y filtros con límites.
- Respuestas de error consistentes.
- Códigos HTTP apropiados.
- Autorización por recurso y acción.
- Limitación de tasa en login, chat y endpoints sensibles.
- Idempotency key para operaciones que puedan duplicarse.
- Correlation ID para seguimiento de petición.
- No exponer stack traces ni secretos.
- Versionado de contratos.
- Pruebas automatizadas para permisos negativos.

---

## 13. Contrato de respuesta del orquestador

Las respuestas que puedan activar herramientas deben separarse de la conversación libre y representarse mediante una estructura validable.

Ejemplo conceptual:

```json
{
  "run_id": "identificador-opaco",
  "status": "SUCCEEDED",
  "user_summary": "Se preparó una propuesta de cotización para revisión.",
  "plan": [
    { "step": 1, "agent": "SALES_CRM", "status": "COMPLETED" },
    { "step": 2, "agent": "BUSINESS_INTELLIGENCE", "status": "COMPLETED" }
  ],
  "artifacts": [],
  "warnings": ["La cotización aún no ha sido enviada al cliente."],
  "pending_approvals": [],
  "sources": [],
  "next_actions": []
}
```

El esquema real se definirá y versionará. El texto generado por el modelo no debe ser suficiente por sí solo para ejecutar una acción; se necesita una llamada de herramienta validada por el backend.

---

## 14. Seguridad, privacidad y gestión de datos

### 14.1 Requisitos mínimos

- HTTPS obligatorio fuera del entorno local.
- Secretos separados del código y del repositorio.
- Entornos independientes: local, pruebas, staging y producción.
- Roles y permisos verificados en backend.
- Principio de privilegio mínimo.
- Protección contra inyección SQL, XSS, CSRF según modalidad, SSRF, subida de archivos maliciosos y abuso de API.
- Validación de tipo, tamaño y contenido de archivos.
- Almacenamiento privado de documentos sensibles.
- Cifrado de comunicaciones y protección de respaldos.
- Registro de acciones administrativas y accesos sensibles.
- Rotación de secretos y procedimiento de revocación.
- Actualización de dependencias y análisis de vulnerabilidades.
- Respuesta a incidentes y registro de brechas.
- Restauraciones de respaldo probadas, no solo respaldos generados.

### 14.2 Clasificación de información

Niveles propuestos:

- **Pública:** contenido comercial aprobado para difusión.
- **Interna:** procedimientos e información operativa no pública.
- **Confidencial:** contratos, información financiera, costos, información laboral y datos comerciales sensibles.
- **Sensible especial:** datos personales delicados y datos relativos a la salud; acceso altamente restringido.

La clasificación concreta de cada campo y documento se documentará en `DATA_GOVERNANCE_SPEC.md`.

### 14.3 Controles para IA

- Redactar o minimizar datos personales antes de invocar modelos cuando sea posible.
- Evitar enviar identificadores innecesarios al proveedor del modelo.
- Separar el contexto de cada usuario, cliente y sesión.
- Limitar el tamaño y número de documentos recuperados.
- No seguir instrucciones contenidas en documentos que pretendan anular políticas.
- Validar todos los argumentos de herramientas en el servidor.
- Aplicar allowlist de herramientas.
- Limitar llamadas, tiempo, memoria y coste por ejecución.
- Proteger secretos ante solicitudes de extracción.
- Registrar fallos y decisiones de seguridad sin duplicar información sensible.
- Evaluar las salidas antes de permitir efectos externos.

### 14.4 Obligaciones legales

El diseño deberá someterse a revisión de aplicabilidad jurídica antes de producción. Entre las áreas a evaluar están protección de datos personales, información sanitaria, derechos y deberes en la atención de salud, documentación clínica cuando proceda, seguridad laboral, relaciones de trabajo, tributación, documentos electrónicos y obligaciones contractuales.

Las normas, fechas de entrada en vigor, reglamentos, dictámenes y criterios de autoridad deben verificarse en fuentes oficiales vigentes. La lista legal del sistema será mantenible y no se tratará como un catálogo cerrado ni como asesoría legal definitiva.

### 14.5 Retención y eliminación

- Definir retención por tipo de dato y obligación aplicable.
- Establecer un flujo de solicitud de acceso, rectificación, exportación o eliminación cuando legalmente proceda.
- Conservar evidencia de acciones de eliminación autorizada.
- No permitir que una petición conversacional de IA elimine registros de manera directa.
- Definir plazos de conservación de logs de seguridad y auditoría.

---

## 15. Auditoría y observabilidad

Los eventos relevantes incluirán, cuando corresponda:

- Usuario o identidad técnica.
- Fecha y hora.
- Acción.
- Tipo e identificador del recurso.
- Resultado.
- Motivo o contexto operativo.
- Origen de la petición y correlation ID.
- Datos modificados, evitando almacenar valores sensibles innecesarios.
- Agente, modelo y versión que propusieron la acción.
- Herramienta invocada.
- Aprobación asociada.

Se deberán poder responder preguntas como:

- ¿Quién modificó un precio y cuándo?
- ¿Qué versión de una cotización se envió?
- ¿Qué usuario autorizó una acción propuesta por IA?
- ¿Qué datos se consultaron para un informe?
- ¿Qué versión del agente generó un borrador?
- ¿Falló una automatización y cuál fue la causa técnica?

Los logs no deben convertirse en una copia indiscriminada de fichas clínicas, credenciales o documentos privados.

---

## 16. Procesos de negocio prioritarios

### 16.1 Flujo comercial a servicio

1. Registrar contacto y necesidad.
2. Calificar oportunidad.
3. Seleccionar servicios del catálogo vigente.
4. Preparar cotización con versión y condiciones.
5. Validar costos, descuentos y autorizaciones.
6. Aprobar y emitir cotización.
7. Registrar aceptación del cliente.
8. Crear orden de servicio vinculada.
9. Programar fecha, profesional, ubicación y recursos.
10. Confirmar la atención.
11. Registrar realización, cancelación o incidencia.
12. Completar documentación y revisión profesional cuando aplique.
13. Registrar antecedentes administrativos de facturación/cobro conforme al flujo definido.
14. Realizar seguimiento y medir satisfacción/resultados cuando corresponda.

### 16.2 Flujo de control financiero

1. Registrar fuente y categoría del movimiento.
2. Relacionar documento, cliente/proveedor o servicio cuando corresponda.
3. Distinguir estado estimado, pendiente, confirmado y pagado.
4. Validar monto, fecha y responsable.
5. Conciliar con documentos y sistema contable externo cuando exista.
6. Actualizar reportes a partir de estados verificados.
7. Revisar variaciones y alertas.
8. Conservar evidencia y registro de cambios.

### 16.3 Flujo de reposición de insumos

1. Revisar existencias, lotes y reservas.
2. Estimar demanda para servicios programados.
3. Comparar con stock mínimo y tiempos de reposición configurados.
4. Generar propuesta con justificación.
5. Solicitar cotizaciones o validar proveedores según la política de compra.
6. Aprobar la solicitud.
7. Registrar recepción, lote y vencimiento cuando corresponda.
8. Actualizar existencias mediante movimiento auditado.

### 16.4 Flujo de informe apoyado por IA

1. El usuario selecciona un tipo de informe y periodo.
2. El backend valida los permisos y obtiene datos fuente.
3. Los cálculos numéricos se realizan con código determinista y probado.
4. La IA interpreta resultados y propone narrativa explicativa.
5. Se señalan datos faltantes, supuestos y anomalías.
6. El usuario revisa y aprueba.
7. El informe final se versiona y se registra.

---

## 17. Requisitos no funcionales

### 17.1 Fiabilidad

- Operaciones críticas consistentes y transaccionales.
- Reintentos controlados en tareas asincrónicas.
- Manejo de fallos del modelo sin perder la solicitud.
- Estados visibles para ejecuciones pendientes o fallidas.
- Idempotencia donde un reintento podría duplicar un efecto.

### 17.2 Rendimiento

- Paginar listas extensas.
- Evitar consultas N+1.
- Crear índices basados en patrones reales de consulta.
- Ejecutar informes pesados en cola cuando sea necesario.
- Establecer objetivos de rendimiento medibles tras recopilar una carga de referencia.
- El MVP no debe asumir volúmenes altos sin pruebas; el dimensionamiento de producción depende del uso real.

### 17.3 Usabilidad

- Interfaz en español.
- Formatos de fecha, hora y moneda de Chile.
- Formularios con mensajes de error accionables.
- Búsquedas y filtros coherentes.
- Vistas adaptadas a perfiles.
- Confirmaciones claras para acciones sensibles.
- Estado visible de tareas de IA y sus aprobaciones.

### 17.4 Mantenibilidad

- Formateo y linting automáticos.
- Pruebas en integración continua cuando se configure el repositorio.
- Migraciones versionadas.
- Documentación actualizada junto a cambios.
- Configuración por entorno.
- Control de versiones para prompts, fórmulas, protocolos y contratos API.

### 17.5 Accesibilidad

- Navegación completa por teclado.
- Etiquetas de formularios y mensajes de validación accesibles.
- Contraste suficiente.
- Evitar que un estado se comunique únicamente mediante color.
- Compatibilidad razonable con lector de pantalla para flujos clave.

---

## 18. Estructura de repositorio recomendada

Se recomienda monorepo inicial para simplificar el desarrollo y mantener las especificaciones cerca del código:

```text
ingeneria-jarvis/
├── README.md
├── INGENERIA_JARVIS_MASTER_SPEC.md
├── docs/
│   ├── PRODUCT_REQUIREMENTS.md
│   ├── SYSTEM_ARCHITECTURE.md
│   ├── DATABASE_SPEC.md
│   ├── LARAVEL_DATABASE_IMPLEMENTATION.md
│   ├── API_SPEC.md
│   ├── AGENT_CATALOG.md
│   ├── AI_ORCHESTRATION_SPEC.md
│   ├── SECURITY_PRIVACY_SPEC.md
│   ├── FRONTEND_DESIGN_SPEC.md
│   ├── TEST_PLAN.md
│   └── DEPLOYMENT_RUNBOOK.md
├── backend/                 # Laravel API
│   ├── app/
│   │   ├── Domain/
│   │   ├── Http/
│   │   ├── Policies/
│   │   ├── Services/
│   │   └── AI/
│   ├── database/
│   │   ├── migrations/
│   │   ├── seeders/
│   │   └── factories/
│   ├── routes/
│   └── tests/
├── frontend/                # React + Vite + TypeScript
│   ├── src/
│   │   ├── app/
│   │   ├── components/
│   │   ├── features/
│   │   ├── hooks/
│   │   ├── lib/
│   │   ├── routes/
│   │   └── types/
│   └── tests/
├── ai-service/              # Opcional: Python para tareas especializadas
│   ├── app/
│   ├── tests/
│   └── pyproject.toml
├── prompts/
│   ├── agents/
│   ├── system/
│   └── evaluations/
├── infra/
│   ├── local/
│   ├── staging/
│   └── production/
└── scripts/
```

Los nombres finales de directorios pueden ajustarse al proyecto existente. Si el repositorio ya existe, primero se inspecciona y documenta antes de reorganizar archivos.

---

## 19. Estrategia de pruebas

### 19.1 Pruebas backend

- Unitarias para cálculos, reglas de negocio y transiciones de estado.
- Integración para controladores, servicios, base de datos y colas.
- Autorización positiva y negativa por rol y recurso.
- Validación de datos y manejo de duplicados.
- Concurrencia en agenda e inventario.
- Idempotencia en solicitudes repetidas.
- Aprobaciones vencidas, rechazadas o ya consumidas.
- Pruebas de migración desde una base vacía.

### 19.2 Pruebas frontend

- Flujos de formularios.
- Manejo de sesión expirada.
- Estados de carga, error y permiso denegado.
- Navegación por roles.
- Diseño responsive y teclado.
- Confirmaciones de acciones sensibles.

### 19.3 Pruebas de IA

- Exactitud contra un conjunto de casos de evaluación.
- Selección apropiada de agentes.
- Cumplimiento de permisos.
- Resistencia a instrucciones maliciosas insertadas en documentos.
- Rechazo de herramientas no permitidas.
- Validación de salida estructurada.
- No invención de cifras cuando no hay datos.
- Transparencia sobre supuestos, incertidumbre y acciones pendientes.
- Fallback cuando el modelo no responde.
- Protección de datos sensibles.

### 19.4 Pruebas de seguridad

- Control de acceso entre roles.
- Intentos de acceso directo a recursos ajenos.
- Validación de subida y descarga de archivos.
- Limitación de tasa.
- Revisión de dependencias.
- Exposición accidental de secretos.
- Inyección de prompts y herramientas.
- Pruebas de restauración de respaldo.

---

## 20. Hoja de ruta de implementación

### Fase 0 — Descubrimiento y confirmación

Entregables:

- Inventario del entorno local y repositorio, si existe.
- Confirmación del nombre final del producto.
- Mapa de procesos actuales.
- Roles reales y matriz de permisos.
- Catálogo inicial de servicios, precios, recursos y documentos.
- Lista de integraciones deseadas y credenciales disponibles, sin compartir secretos en el chat.

**Criterio de salida:** alcance MVP aprobado y supuestos relevantes documentados.

### Fase 1 — Especificación técnica

Entregables:

- `PRODUCT_REQUIREMENTS.md`
- `SYSTEM_ARCHITECTURE.md`
- `DATABASE_SPEC.md`
- `SECURITY_PRIVACY_SPEC.md`
- `AGENT_CATALOG.md`
- `API_SPEC.md`

**Criterio de salida:** entidades, flujos críticos, permisos y contratos principales revisados antes de construir.

> Las fases 2, 3 y 4 forman el **MVP** (incrementos MVP-1, MVP-2 y MVP-3 de `DECISIONS.md` DEC-002).

### Fase 2 — Núcleo de aplicación (MVP-1)

Implementar:

- Laravel y MySQL.
- React + Vite + TypeScript.
- Login, roles y permisos.
- Auditoría inicial.
- Aprobaciones persistentes, idempotencia y folios internos.
- Configuración de empresa.
- Base de componentes de interfaz.
- Entornos y configuración local.

**Criterio de salida:** usuario autenticado puede entrar según su rol y una prueba demuestra que no puede acceder a recursos prohibidos.

### Fase 3 — MVP comercial-operativo (MVP-2)

Implementar:

- CRM.
- Catálogo de servicios.
- Cotizaciones.
- Agenda.
- Órdenes de servicio.
- Documentos comerciales.
- Reportes básicos de ventas y operación.

**Criterio de salida:** una oportunidad puede recorrer el flujo hasta una orden de servicio con trazabilidad y sin duplicar datos.

### Fase 4 — IA y automatización interna (MVP-3)

Implementar el orquestador y los agentes `SALES_CRM`, `OPERATIONS_COORDINATOR`, `BUSINESS_INTELLIGENCE`, `BUSINESS_MANAGER` y `AUTOMATION_COORDINATOR`, con herramientas R0–R2 sobre los datos del MVP, modelo local vía Ollama y automatizaciones internas (tareas, recordatorios internos, reportes programados). Las acciones R3 quedan como propuestas con aprobación.

**Criterio de salida:** los agentes generan propuestas correctas en un conjunto de pruebas, respetan permisos, no inventan cifras y no ejecutan efectos externos.

### Fase 5 — Finanzas e inventario iniciales

Implementar registros administrativos, categorización, reportes, proyecciones explícitamente estimadas, existencias y movimientos de inventario. Activar `FINANCE_ANALYST` (lectura/propuesta) e `INVENTORY_EQUIPMENT`.

**Criterio de salida:** cálculos probados, movimientos auditables y reportes que distinguen datos confirmados de proyecciones.

### Fase 6 — Comunicaciones externas

Integrar correo u otros canales solo tras configurar proveedor, consentimiento, plantillas, controles anti-duplicación y monitorización. A partir de aquí las herramientas R3 pueden ejecutarse tras aprobación.

**Criterio de salida:** existe registro de cada envío, resultado, fallo y preferencia de contacto aplicable.

### Fase 7 — Clínica y rendimiento deportivo

Analizar requisitos profesionales, documentales, técnicos y legales. Construir registros y fórmulas mediante protocolos aprobados y datos de prueba.

**Criterio de salida:** controles de acceso revisados, flujos de aprobación profesional y pruebas de trazabilidad completas antes de producción.

### Fase 8 — Preparación para producción

- Infraestructura endurecida.
- HTTPS y secretos protegidos.
- Copias de seguridad automáticas y restauración probada.
- Monitoreo, alertas y procedimiento de incidentes.
- Pruebas de carga y seguridad.
- Revisión normativa.
- Capacitación y manuales de usuario.

**Criterio de salida:** aprobación formal de dirección y responsables técnicos/funcionales.

---

## 21. Criterios generales de aceptación del producto

La plataforma no se considerará lista para uso productivo hasta comprobar, como mínimo:

1. Los perfiles pueden iniciar sesión y solo ven las funciones autorizadas.
2. Los permisos se validan en backend, incluso llamando directamente a la API.
3. Los servicios y precios pueden modificarse sin editar el código y mantienen historial cuando corresponda.
4. Una cotización tiene versión, estado, cálculo reproducible y registro de aprobación.
5. Una cotización aceptada puede originar una orden de servicio vinculada.
6. La agenda detecta conflictos de recursos de acuerdo con las reglas configuradas.
7. Los ingresos, egresos y proyecciones se distinguen con claridad.
8. Los movimientos de inventario preservan la trazabilidad.
9. Los documentos sensibles no son accesibles mediante enlaces públicos no autorizados.
10. Las ejecuciones de IA muestran estado, agente, advertencias y resultado.
11. Una solicitud sin datos suficientes no produce cifras inventadas.
12. Una acción de riesgo alto no se ejecuta sin la aprobación establecida.
13. El sistema funciona cuando el modelo no está disponible, degradando con mensajes claros.
14. Los registros de auditoría permiten reconstruir acciones relevantes sin exponer datos innecesarios.
15. Se ha probado la restauración desde una copia de seguridad.
16. Los flujos clínicos no se habilitan hasta completar revisión profesional, funcional y normativa.

---

## 22. Riesgos iniciales y mitigaciones

| Riesgo | Impacto | Mitigación inicial |
|---|---|---|
| Construir demasiados módulos antes de validar el MVP | Retrasos y complejidad | Implementación por fases y prioridades aprobadas |
| Respuestas incorrectas de IA | Decisiones erróneas | Datos fuente, esquemas, pruebas, revisión humana y límites de uso |
| Acceso indebido a información clínica | Alto impacto en privacidad | Separación de dominios, permisos mínimos, auditoría y revisión de seguridad |
| Duplicación de cotizaciones, citas o movimientos | Errores operativos/financieros | Idempotencia, restricciones e historial |
| Precios o costos desactualizados | Pérdida de margen | Versionado, propietario y fechas de vigencia |
| Dependencia de un modelo específico | Interrupción o costo excesivo | Capa de proveedores y pruebas de fallback |
| Pérdida o corrupción de datos | Interrupción grave | Backups, restauraciones verificadas y plan de recuperación |
| Acciones externas no autorizadas | Compromisos indeseados | Aprobaciones persistentes y validación backend |
| Normativa incompleta o desactualizada | Incumplimiento | Fuentes oficiales, responsables y revisión periódica |
| Exceso de datos en prompts y logs | Exposición de información | Minimización, redacción y controles de retención |

---

## 23. Decisiones pendientes

Los siguientes puntos deberán confirmarse antes de fijar decisiones irreversibles:

1. Nombre definitivo de la aplicación: INGENERIA JARVIS, ERGO JARVIS u otro. (Resuelto: el producto es para una sola empresa, `DECISIONS.md` DEC-001; el nombre visible sigue pendiente.)
2. Estado actual del repositorio y versión de herramientas instalada.
3. Despliegue previsto: VPS, nube administrada o infraestructura propia.
4. Cantidad de usuarios y concurrencia inicial estimada.
5. Modelo de autenticación y MFA disponible.
6. Proveedor de correo y canales de mensajería.
7. Sistema actual de facturación electrónica/DTE y posibilidad de integración.
8. Herramientas de calendario y disponibilidad que utiliza la empresa.
9. Catálogo aprobado de servicios, precios, costos y recursos.
10. Estructura real de roles y responsables de aprobación.
11. Alcance de los registros clínicos que efectivamente se mantendrán en la plataforma.
12. Protocolos profesionales aprobados para medicina deportiva.
13. Política de conservación, exportación y eliminación de información.
14. Requisitos de continuidad, respaldo y tiempo máximo tolerable de recuperación.

Si un dato no está definido, la implementación deberá señalarlo como pendiente y utilizar una configuración segura por defecto, sin inventar valores empresariales.

---

## 24. Convenciones de desarrollo

- Código, nombres de clases y columnas en inglés técnico coherente; interfaz, documentación funcional y mensajes al usuario en español.
- Evitar lógica de negocio crítica en frontend.
- Evitar prompts monolíticos: separar identidad del agente, herramientas, políticas, fuentes y ejemplos.
- Versionar migraciones y datos semilla.
- No colocar secretos, contraseñas, RUT reales ni datos clínicos en Git.
- Mantener `.env.example` con placeholders, nunca con credenciales verdaderas.
- Toda funcionalidad debe incluir validaciones y pruebas proporcionadas al riesgo.
- Documentar decisiones relevantes en `docs/decisions/` usando ADR cuando corresponda.
- Registrar cambios de esquema que afecten compatibilidad de datos.
- Revisar dependencias antes de adoptar paquetes nuevos.
- Utilizar datos sintéticos para demos y pruebas.
- Incluir procedimientos reproducibles para instalar, iniciar, probar, migrar y respaldar el sistema.

---

## 25. Instrucción maestra para agentes de programación

Cuando un asistente de programación reciba este documento como contexto, deberá:

1. Leer la especificación completa antes de proponer cambios de arquitectura.
2. Inspeccionar el repositorio existente antes de crear o reemplazar archivos.
3. Informar qué ya existe, qué falta y qué se modificará.
4. Trabajar en incrementos pequeños y comprobables.
5. Crear primero el documento derivado solicitado y después el código correspondiente.
6. No generar todas las tablas o todos los agentes de forma indiscriminada sin resolver dependencias.
7. Respetar la seguridad, las fronteras de dominio y los permisos definidos.
8. Ejecutar pruebas y reportar los resultados reales; nunca afirmar que una prueba pasó si no se ejecutó.
9. Señalar requisitos pendientes, supuestos y riesgos.
10. Actualizar la documentación cuando el código introduzca una decisión que cambie esta especificación.
11. No desplegar ni ejecutar comandos destructivos sin autorización expresa.
12. Evitar incluir credenciales o datos reales en archivos de ejemplo.

### Primer encargo recomendado para el asistente de programación

**Tarea 1:** inspeccionar el entorno y el repositorio si existe. No sobrescribir nada. Entregar un inventario del estado actual, versiones de runtime, estructura de carpetas, dependencias y problemas identificados.

**Tarea 2:** derivar `docs/PRODUCT_REQUIREMENTS.md`, identificando requisitos funcionales por módulo, prioridades MVP/P2/P3, actores, criterios de aceptación y dependencias.

**Tarea 3:** construir `docs/DATABASE_SPEC.md` y revisar el modelo con foco en la separación entre datos administrativos, comerciales y sanitarios.

**Tarea 4:** derivar `docs/AGENT_CATALOG.md` y `docs/AI_ORCHESTRATION_SPEC.md`, incluyendo esquemas de herramienta, niveles de riesgo y aprobaciones.

**Tarea 5:** implementar únicamente el núcleo aprobado, con pruebas y documentación reproducible.

---

## 26. Definición de éxito

INGENERIA JARVIS será exitoso cuando Ergo SaniTas SpA pueda realizar operaciones administrativas frecuentes de forma consistente, conocer el estado de sus clientes y servicios, comprender sus resultados financieros a partir de datos trazables, coordinar mejor la prestación de servicios y utilizar agentes de IA sin perder control sobre permisos, calidad, privacidad ni decisiones críticas.

La meta no es maximizar la cantidad de agentes. La meta es construir procesos confiables donde la automatización reduzca trabajo repetitivo, mejore la calidad de la información y deje las decisiones de responsabilidad humana en manos de las personas autorizadas.

---

**Fin de la especificación maestra v1.0.0.**
