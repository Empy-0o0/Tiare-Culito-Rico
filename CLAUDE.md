# CLAUDE.md — TIARE Procurement & Supply Platform

**Proyecto:** TIARE — Intelligent Procurement, Supply & Logistics  
**Empresa:** TIARE SpA (denominación legal y disponibilidad de marca pendientes de verificación)  
**País:** Chile  
**Idioma de interfaz y documentación:** Español  
**Zona horaria de negocio:** `America/Santiago`  
**Moneda base:** CLP  
**Versión del documento:** 1.0.0  
**Fecha de referencia de la integración Mercado Público:** 14-11-2026  
**Rol esperado de Claude:** arquitecto de software, líder técnico y desarrollador full-stack senior.

> Este archivo contiene las instrucciones de trabajo para estructurar e implementar TIARE. No es evidencia de que el software, las integraciones o las credenciales ya estén configurados. Debes inspeccionar el repositorio y comprobar cada implementación antes de declararla terminada.

---

# 1. Misión del proyecto

Construir una plataforma SaaS y de servicios administrados para empresas chilenas que necesitan profesionalizar o externalizar sus compras, abastecimiento, gestión de proveedores y logística. TIARE debe conectar el ciclo de adquisición desde la solicitud hasta la recepción, conciliación documental y análisis de resultados.

TIARE operará en dos mercados separados:

1. **Mercado privado:** procurement externalizado, abastecimiento recurrente, comparación de cotizaciones, gestión de proveedores, inventarios y logística para empresas.
2. **Ecosistema de compras públicas:** monitoreo de oportunidades, análisis de licitaciones y Compra Ágil, apoyo a proveedores que desean vender al Estado y servicios de consultoría/tecnología a compradores públicos mediante contratación válida.

Unidades comerciales:

- `TIARE PRIVATE`: compras y abastecimiento privados.
- `TIARE PUBLIC`: inteligencia de oportunidades y servicios relacionados con Mercado Público.
- `TIARE LOGISTICS`: coordinación de transporte, distribución, almacenamiento asociado y recepción.
- `TIARE TECHNOLOGY`: plataforma SaaS, automatización, analítica y agentes IA.

**Propuesta de valor:** ser el departamento externo de compras y abastecimiento de los clientes, apoyado por una plataforma que aporta trazabilidad, información y automatización.

## 1.1 Objetivos del producto

- Centralizar clientes, organizaciones, proveedores, productos, servicios y categorías.
- Administrar solicitudes internas de compra, cotizaciones, comparaciones y aprobaciones.
- Mantener historial de precios, condiciones, adjudicaciones y desempeño de proveedores.
- Detectar oportunidades públicas usando fuentes oficiales de Mercado Público.
- Relacionar oportunidades con el perfil, rubros, productos, regiones y capacidades de cada cliente.
- Coordinar órdenes, despachos, recepciones, incidencias y documentos.
- Producir indicadores verificables de ahorro, tiempos, cumplimiento y costo total.
- Incorporar agentes de IA con herramientas acotadas, permisos y revisión humana.
- Soportar múltiples empresas clientes con aislamiento estricto de información.

## 1.2 Principios que no se negocian

1. La aplicación debe funcionar primero como sistema de gestión; la IA es una capa de apoyo, no la fuente oficial de los datos.
2. Toda operación de negocio pasa por el backend y las reglas de dominio. El frontend no decide permisos ni estados oficiales.
3. No exponer credenciales, tickets, secretos, claves API ni datos privados en el navegador, repositorio, capturas o logs.
4. Separar estrictamente los procesos privados de los datos y procesos de compras públicas.
5. No permitir que TIARE asesore a comprador y proveedor contrapuestos en el mismo procedimiento de adquisición. Implementar registro y revisión de conflictos de interés.
6. La información recuperada desde Mercado Público debe distinguirse de los datos propios ingresados por TIARE o sus clientes.
7. Toda cifra calculada debe mostrar fuente, periodo, moneda, supuestos y fecha de actualización.
8. Los agentes no presentan ofertas, no adjudican, no autorizan pagos, no firman contratos ni comprometen fondos.
9. No inventar campos, rutas, filtros o capacidades de APIs externas. Consultar documentación oficial y validar con respuestas reales anonimizadas.
10. Implementar incrementalmente. No reescribir todo el prototipo ni cambiar dependencias sin una razón documentada.

---

# 2. Material de partida y documentos fuente

El usuario ya cuenta con una maqueta HTML/CSS/JavaScript y documentos funcionales anteriores. Antes de modificar el proyecto, inspecciona los archivos disponibles y su estado.

## 2.1 Prototipo existente

- `tiare-prototype/index.html`
- `tiare-prototype/styles.css`
- `tiare-prototype/app.js`
- `tiare-prototype/README.md`
- `TIARE_PROTOTIPO_HTML_CSS_JS.zip`

La maqueta contiene navegación, dashboard, solicitudes, cotizaciones, monitor demostrativo de Mercado Público, proveedores, CRM, logística, agentes IA e informes. Usa datos ficticios y almacenamiento `localStorage` para algunas interacciones.

**Instrucción:** conservarla como referencia funcional y visual. Inspeccionar su código, identificar componentes, navegación, estilos, datos y comportamiento. No reemplazarla por una interfaz genérica. Trasladar sus vistas gradualmente a React y validar que las funciones principales se mantengan.

## 2.2 Documentos previos del proyecto

Cuando estén disponibles en el repositorio, leerlos antes de crear modelos o endpoints:

- `PLAN_NEGOCIOS_TIARE_SPA.md` — modelo de negocio, unidades, segmentos, ingresos y roadmap.
- `PRODUCT_REQUIREMENTS.md` — requisitos funcionales y criterios de aceptación.
- `DATABASE_SPEC.md` — entidades y relaciones.
- `API_SPEC.md` — contratos de API interna.
- `AUTHORIZATION_MATRIX.md` — roles y permisos.
- `LARAVEL_DATABASE_IMPLEMENTATION.md` — guía de implementación de Laravel.

Si alguno no está disponible, dejar constancia en `docs/DECISIONS.md` y continuar con lo definido en este archivo. No afirmar que se leyó un archivo que no existe.

## 2.3 Jerarquía de decisiones

Si hay contradicciones:

1. Instrucciones explícitas más recientes del propietario del proyecto.
2. Este `CLAUDE.md` para el alcance TIARE.
3. Documentos de requisitos y arquitectura aprobados.
4. Código existente y convenciones del repositorio.
5. Supuestos técnicos del desarrollador.

Toda contradicción relevante debe registrarse en `docs/DECISIONS.md` con fecha, contexto, opciones, decisión y consecuencias.

---

# 3. Instrucciones de trabajo para Claude

Antes de programar:

1. Ejecuta un inventario de carpetas y archivos.
2. Revisa `git status`, ramas y commits recientes si existe Git.
3. Lee `package.json`, archivos de configuración, `composer.json`, `.env.example` y README si existen.
4. Determina si hay un frontend o backend ya inicializado. No asumas que existe React o Laravel solo por este documento.
5. Identifica funciones operativas del prototipo y anota qué se conservará, migrará o descartará.
6. Revisa la documentación oficial vigente de cada integración externa, en especial Mercado Público.
7. Presenta un plan breve por hitos y luego ejecuta el primer hito sin esperar una aprobación adicional para tareas reversibles y de bajo riesgo.

Durante el desarrollo:

- Trabaja por módulos pequeños y entregables verificables.
- No introduzcas una dependencia si la plataforma ya resuelve la necesidad.
- No actualices masivamente paquetes ni versiones por iniciativa propia.
- No borres datos, migraciones, archivos ni funciones sin respaldo y justificación.
- Crea migraciones reversibles cuando sea posible.
- Usa nombres consistentes en inglés para código y base de datos; interfaz, documentación funcional y mensajes en español.
- Usa tipos explícitos, validación de entrada, manejo de errores y pruebas.
- No dejes botones que aparenten ejecutar operaciones reales si solo son simulaciones: indicar claramente “demostración” o implementar la acción.
- Los datos de demo deben marcarse como ficticios y no mezclarse con datos reales.
- Mantén una lista de tareas y actualiza `docs/IMPLEMENTATION_STATUS.md` al terminar cada hito.

Al finalizar cada hito, informa:

- Archivos creados/modificados.
- Funcionalidades implementadas.
- Migraciones ejecutadas o pendientes.
- Comandos de pruebas ejecutados y resultado real.
- Variables de entorno necesarias.
- Riesgos, pendientes y siguiente hito.

No declares éxito si no ejecutaste las pruebas pertinentes. Si el entorno no permite ejecutar algo, indícalo explícitamente.

---

# 4. Arquitectura tecnológica objetivo

## 4.1 Arquitectura recomendada

Adoptar un monolito modular para el backend y una SPA desacoplada para el frontend. Esta estructura reduce complejidad y costo de operación en la etapa inicial, manteniendo la posibilidad de separar servicios en el futuro.

- **Frontend:** React + Vite + TypeScript.
- **Backend:** Laravel API, usando la versión compatible y soportada que exista en el entorno. Si ya hay una versión instalada, no actualizarla sin diagnóstico.
- **Base de datos:** MySQL 8 / InnoDB.
- **Autenticación:** Laravel Sanctum.
- **Colas:** comenzar con database queue; incorporar Redis si el volumen lo justifica.
- **Tareas programadas:** Laravel Scheduler.
- **Archivos:** almacenamiento privado local en desarrollo y storage compatible con S3 en producción cuando se requiera.
- **IA:** capa de servicios desacoplada; Ollama/Qwen como opción local futura. No acoplar reglas de negocio al proveedor/modelo.
- **Analítica:** consultas SQL agregadas al inicio; Python solo para procesos analíticos que lo justifiquen.
- **API interna:** REST versionada `/api/v1`.

## 4.2 Estructura de repositorio propuesta

Primero inspeccionar el repositorio actual. Si no existe una estructura definida, crear:

```text
TIARE/
├── CLAUDE.md
├── README.md
├── .gitignore
├── .env.example
├── docs/
│   ├── DECISIONS.md
│   ├── IMPLEMENTATION_STATUS.md
│   ├── ARCHITECTURE.md
│   ├── MERCADO_PUBLICO_API.md
│   ├── SECURITY.md
│   └── runbooks/
├── frontend/
│   ├── package.json
│   ├── src/
│   │   ├── app/
│   │   ├── components/
│   │   ├── features/
│   │   │   ├── dashboard/
│   │   │   ├── crm/
│   │   │   ├── procurement/
│   │   │   ├── suppliers/
│   │   │   ├── quotations/
│   │   │   ├── public-procurement/
│   │   │   ├── logistics/
│   │   │   ├── inventory/
│   │   │   ├── reports/
│   │   │   └── agents/
│   │   ├── services/
│   │   ├── hooks/
│   │   ├── types/
│   │   └── styles/
│   └── public/
├── backend/
│   ├── app/
│   │   ├── Domain/
│   │   ├── Http/
│   │   ├── Jobs/
│   │   ├── Models/
│   │   ├── Policies/
│   │   ├── Services/
│   │   └── Support/
│   ├── database/
│   │   ├── migrations/
│   │   ├── factories/
│   │   └── seeders/
│   ├── routes/
│   └── tests/
└── scripts/
```

No es obligatorio mover todo en un único paso. Si el prototipo ocupa la raíz, conservarlo en `prototype/` o una carpeta de referencia hasta validar la migración.

## 4.3 Reglas de arquitectura

- Controllers delgados; lógica en Actions/Services de dominio.
- Form Requests para validar entrada.
- API Resources para definir salidas y evitar exponer columnas internas.
- Policies y autorización por recurso en backend.
- DTOs/normalizadores para respuestas externas.
- Jobs para sincronización de Mercado Público y notificaciones.
- Eventos/listeners para notificaciones internas desacopladas.
- Transacciones DB para operaciones compuestas.
- Idempotencia en sincronización, creación de órdenes y procesos repetibles.
- No permitir consultas SQL arbitrarias generadas por agentes IA.

---

# 5. Módulos funcionales

## 5.1 Núcleo SaaS y multiempresa

- Empresas/tenants clientes.
- Usuarios, roles, permisos y pertenencia a empresa.
- Preferencias, sucursales, centros de costo y zonas de operación.
- Planes SaaS, suscripciones y límites de uso (preparar modelo; no integrar cobros hasta definir proveedor).
- Auditoría de acciones sensibles.

Toda consulta de datos privados debe validar el `tenant_id` en backend. Nunca confiar en `tenant_id` enviado por el navegador.

## 5.2 CRM

- Organizaciones y contactos.
- Clasificación: pyme, empresa mediana, proveedor del Estado, comprador, institución, operador logístico u otro.
- Oportunidades comerciales, etapas, responsable, fuente y próxima acción.
- Actividades, reuniones y notas.
- Historial de propuestas y contratos.

## 5.3 Catálogo y proveedores

- Productos/servicios y categorías normalizadas.
- Unidad de medida, especificaciones, marcas equivalentes y códigos cuando estén disponibles.
- Proveedores, RUT, datos comerciales, cobertura territorial y categorías.
- Certificaciones y documentos con vigencia.
- Evaluación de precio, calidad, cumplimiento, tiempo de entrega y reclamos.
- Historial de cotizaciones y desempeño.

No presentar un proveedor como “validado” sin criterios y evidencia definidos.

## 5.4 Procurement privado

Flujo mínimo:

1. Cliente crea una solicitud.
2. Responsable revisa necesidad y presupuesto.
3. TIARE busca proveedores y solicita cotizaciones.
4. Se registran respuestas y vigencia.
5. Motor compara costo total de adquisición (precio, IVA, despacho, plazo, calidad, garantía y condiciones).
6. Se genera recomendación explicable.
7. Usuario autorizado aprueba o rechaza.
8. Se genera orden de compra interna o instrucción al proveedor.
9. Se realiza seguimiento de entrega.
10. Se registra recepción conforme/incidencia.
11. Se relacionan factura y pago informado por el cliente.
12. Se calculan indicadores.

TIARE no financiará automáticamente la compra de mercadería del cliente. El pagador, titular de la factura, comprador contractual y responsable de recepción deben quedar claros en cada operación.

## 5.5 Mercado Público / TIARE PUBLIC

Debe ser un módulo de inteligencia y seguimiento con datos oficiales. La API pública documentada no debe interpretarse como autorización para automatizar la presentación de ofertas o ejecutar operaciones de comprador/proveedor en Mercado Público.

Funciones:

- Monitor de Compra Ágil.
- Monitor de licitaciones públicas y privadas, identificando su tipología.
- Consulta de órdenes de compra públicas.
- Consulta de organismos compradores.
- Consulta de proveedores públicos por RUT cuando la API lo soporte.
- Búsqueda por palabra, rubro, región, estado, entidad y fecha.
- Filtros guardados por empresa cliente.
- Matching preliminar con rubros y capacidades del cliente.
- Alertas de nuevas oportunidades, cambios y cierres.
- Ficha de oportunidad con fuentes, datos originales, última sincronización y enlace al portal.
- Análisis asistido de documentos que el usuario descargue legalmente y adjunte.
- Agenda de hitos y checklist documental.

No automatizar:

- Inicio de sesión en Mercado Público con credenciales del cliente.
- Uso o almacenamiento de ClaveÚnica.
- Envío o presentación de ofertas.
- Firma electrónica de declaraciones o contratos.
- Adjudicación, selección de proveedor o aceptación de órdenes.
- Cambios de estado en el portal.

La presentación de ofertas y demás acciones transaccionales quedan en el sistema oficial y bajo la responsabilidad de la persona/empresa autorizada.

## 5.6 Logística

- Despachos, rutas y entregas.
- Transportista y tipo de servicio.
- Estado, fecha planificada y real.
- Evidencia de entrega y recepción.
- Incidencias, devoluciones y logística inversa.
- Costos estimados y reales.
- Operadores aliados y capacidad propia.

Implementar primero coordinación con aliados; no diseñar una flota propia como requisito del MVP.

## 5.7 Inventario

- Productos, ubicaciones y bodegas.
- Movimientos de entrada/salida/ajuste.
- Stock mínimo y máximo.
- Lotes, series y vencimientos cuando aplique.
- Reservas y reposición.
- Historial y trazabilidad.

## 5.8 Agentes IA (posterior al flujo transaccional básico)

- Procurement Intelligence Agent.
- Tender Intelligence Agent.
- Supplier Intelligence Agent.
- Cost Intelligence Agent.
- Inventory Intelligence Agent.
- Logistics Intelligence Agent.
- Contract Intelligence Agent.
- Business Intelligence Agent.
- Compliance Intelligence Agent.
- Orquestador.

Los agentes reciben datos seleccionados mediante servicios internos; no consultan MySQL libremente ni guardan tokens externos. Toda recomendación de admisibilidad de licitación es preliminar y debe ser revisada por una persona competente.

---

# 6. Integración oficial con Mercado Público

Esta sección es prioritaria. Implementar un conector backend real, seguro, documentado y testeable. No realizar llamadas desde el navegador.

## 6.1 Fuentes oficiales que Claude debe revisar

1. Página oficial de API: https://www.chilecompra.cl/api/
2. Documentación específica de Compra Ágil API V2 enlazada desde la página oficial.
3. Diccionario de datos de Licitaciones enlazado desde la página oficial.
4. Diccionario de datos de Órdenes de Compra enlazado desde la página oficial.
5. Políticas y condiciones de uso visibles en la página oficial.
6. Mercado Público: https://www.mercadopublico.cl/

La página oficial consultada estaba actualizada al 05-11-2026. La documentación puede cambiar; revalidar endpoints, campos, cuotas y condiciones antes de producción.

## 6.2 Manejo del ticket

El propietario informó que dispone de un identificador con formato UUID que aparenta ser un ticket de acceso. **No copiar ese valor a este archivo, al código, a tests, a commits, a capturas ni a logs.** El valor real debe introducirse localmente en el `.env` del backend o en un gestor de secretos.

Variables propuestas:

```dotenv
MERCADO_PUBLICO_ENABLED=false
MERCADO_PUBLICO_TICKET=
MERCADO_PUBLICO_V2_BASE_URL=https://api2.mercadopublico.cl
MERCADO_PUBLICO_V1_BASE_URL=https://api.mercadopublico.cl/servicios/v1/publico
MERCADO_PUBLICO_V1_DAILY_BUDGET=9000
MERCADO_PUBLICO_SYNC_ENABLED=false
MERCADO_PUBLICO_SYNC_INTERVAL_MINUTES=30
MERCADO_PUBLICO_HTTP_TIMEOUT=20
MERCADO_PUBLICO_CACHE_MINUTES=10
```

`9000` es un límite operativo conservador configurable para la API legacy V1, no una cuota oficial garantizada. La política general oficial consultada informa un máximo de 10.000 solicitudes diarias por ticket para el servicio descrito allí. La guía V2 de Compra Ágil especifica cuota diaria por tipo de ticket y error HTTP 429; no asumir que la cuota V2 es idéntica a la de V1.

Reglas de secretos:

- Añadir `.env` a `.gitignore`.
- `.env.example` solo incluye valores vacíos o ficticios.
- Ticket exclusivamente en servidor.
- En API V2 se envía como header HTTP `ticket`.
- En endpoints legacy V1 la documentación actual muestra el ticket como parámetro GET `ticket`.
- Como V1 lo envía en query string, desactivar o redactar logging de URL completa y parámetros sensibles en cliente HTTP, reverse proxy, APM y excepciones.
- Nunca incluir el ticket en respuestas JSON hacia React.
- Nunca escribir el ticket en logs, trazas, mensajes de error o métricas.
- No compartir, replicar ni usar un ticket de otra persona sin verificar las condiciones de ChileCompra. El ticket se solicita con datos reales de una persona mediante el flujo oficial.
- El ticket compartido en una conversación debe considerarse expuesto a un canal adicional. Recomendar al titular consultar el procedimiento oficial para reemplazarlo/inutilizarlo si corresponde y usar un nuevo secreto local.

## 6.3 API Compra Ágil V2 — integración prioritaria

**Base URL oficial:** `https://api2.mercadopublico.cl`

**Autenticación:** header HTTP `ticket: <MERCADO_PUBLICO_TICKET>`.

No enviar el ticket como query parameter a esta API V2, según la guía oficial V2 consultada.

Endpoints principales:

```http
GET https://api2.mercadopublico.cl/v2/compra-agil
GET https://api2.mercadopublico.cl/v2/compra-agil/{codigo}
```

### Endpoint listado y búsqueda

Parámetros documentados:

- `ttl_cambio_ms`: cambios ocurridos dentro de una ventana expresada en milisegundos.
- `cambio_desde` y `cambio_hasta`: rango ISO-8601 de modificaciones; usar este par como alternativa a `ttl_cambio_ms`, no combinarlos.
- `publicado_desde` y `publicado_hasta`: rango ISO-8601 de publicación.
- `estado`: uno o varios valores separados por coma. La guía V2 documenta `publicada`, `cerrada`, `desierta`, `cancelada`, `proveedor_seleccionado`; `oc_emitida` aparece en el modelo pero la guía advierte que no se usa en la práctica.
- `region`: códigos de región de Chile, repetibles o separados como indique la documentación vigente.
- `id`: código exacto de Compra Ágil.
- `q`: palabras clave. `id` y `q` son mutuamente excluyentes.
- `tamano_pagina`: por defecto 15; máximo documentado 50.
- `numero_pagina`: empieza en 1.
- `ordenar_por`: `FechaUltimaModificacion` (por defecto) o `FechaPublicacion`.

Códigos de región documentados por ChileCompra:

| Código | Región |
|---:|---|
| 1 | Tarapacá |
| 2 | Antofagasta |
| 3 | Atacama |
| 4 | Coquimbo |
| 5 | Valparaíso |
| 6 | Libertador General Bernardo O'Higgins |
| 7 | Maule |
| 8 | Biobío |
| 9 | La Araucanía |
| 10 | Los Lagos |
| 11 | Aysén del General Carlos Ibáñez del Campo |
| 12 | Magallanes y de la Antártica Chilena |
| 13 | Metropolitana de Santiago |
| 14 | Los Ríos |
| 15 | Arica y Parinacota |
| 16 | Ñuble |

**Limitación importante:** el endpoint V2 de Compra Ágil no dispone del filtro `codigo_organismo`. Para un organismo específico, consultar por región y filtrar localmente por `institucion.rut` o `institucion.organismo_comprador`, tal como indica la guía oficial.

### Endpoint detalle

```http
GET https://api2.mercadopublico.cl/v2/compra-agil/{codigo}
```

El detalle puede incluir productos solicitados, proveedores que cotizan, valores, documentos, organismo, fechas, presupuesto y referencia a la orden de compra.

### Modelo de respuesta

Las respuestas exitosas documentadas tienen forma general:

```json
{
  "success": "OK",
  "trace": null,
  "payload": {},
  "errors": null
}
```

Para listado, `payload` incluye `items[]` y `paginacion` (`total_paginas`, `numero_pagina`, `tamano_pagina`, `total_resultados`). Validar propiedades opcionales antes de leerlas.

Campos que deben normalizarse cuando estén presentes:

- `codigo`, `nombre`, `descripcion`.
- `estado.codigo`, `estado.glosa`, `convocatoria.estado_convocatoria`.
- `fechas.fecha_publicacion`, `fechas.fecha_cierre`, `fechas.fecha_ultimo_cambio`.
- `institucion.organismo_comprador`, `institucion.rut`, `institucion.unidad_compra`, `institucion.region`, `institucion.nombre_region`.
- `presupuesto.tipo_presupuesto`, `presupuesto.moneda`, `presupuesto.presupuesto_estimado`, `presupuesto.monto_disponible`, `presupuesto.monto_disponible_clp`.
- `productos_solicitados[]`.
- `proveedores_cotizando[]`.
- `documentos[]`.
- `orden_compra.id_orden_compra`, `orden_compra.id_oc`, `orden_compra.codigo_orden_compra`.

**Advertencia sobre orden de compra:** la guía V2 indica que `orden_compra.id_orden_compra != null` es el indicador confiable de que se emitió una OC. `codigo_orden_compra` y `estado_orden_compra` pueden venir `null` incluso cuando existe una OC. No usar el estado `oc_emitida` como única condición.

### Errores y cuotas V2

Manejar, al menos:

- `400`: parámetros inválidos o combinaciones no permitidas.
- `401`: falta el header `ticket`.
- `403`: ticket inválido, inactivo o bloqueado.
- `404`: Compra Ágil no encontrada/no pública.
- `429`: cuota agotada; respetar `Retry-After` cuando esté presente y posponer la consulta. No reintentar inmediatamente.
- `500` y `503`: error temporal; aplicar reintentos limitados con backoff exponencial y jitter.

La guía V2 indica que la cuota diaria depende del tipo de ticket y se reinicia al cambiar el día calendario. No intentar eludir cuotas mediante tickets, IPs o cuentas múltiples.

## 6.4 API legacy V1 — licitaciones y órdenes de compra

**Base URL:** `https://api.mercadopublico.cl/servicios/v1/publico`

La documentación oficial consultada describe respuestas JSON, XML y JSONP. Preferir JSON.

### Licitaciones

```http
GET /licitaciones.json
```

URL completa base:

`https://api.mercadopublico.cl/servicios/v1/publico/licitaciones.json`

Consultas documentadas:

- por `codigo` de licitación;
- por `fecha` del día (`ddmmaaaa`);
- por `estado`;
- por `CodigoProveedor`;
- por `CodigoOrganismo`.

Para las consultas por fecha, formato exacto `ddmmaaaa`. El ticket se envía como query parameter `ticket` de acuerdo con los ejemplos V1 oficiales. Construir requests con el cliente HTTP de Laravel y parámetros, nunca concatenar strings manualmente.

Estados de licitación documentados:

| Código | Estado |
|---:|---|
| 5 | Publicada |
| 6 | Cerrada |
| 7 | Desierta |
| 8 | Adjudicada |
| 18 | Revocada |
| 19 | Suspendida |

Los tipos/códigos de licitación son catálogos cambiantes. No eliminar tipologías antiguas ni inferir equivalencias sin revisar la documentación y el dato recibido. Guardar el código fuente y una glosa normalizada independiente.

Documentación detalle enlazada por ChileCompra: `https://api.mercadopublico.cl/modules/Licitacion.aspx`.

### Órdenes de compra

```http
GET /ordenesdecompra.json
```

URL completa base:

`https://api.mercadopublico.cl/servicios/v1/publico/ordenesdecompra.json`

Consultas documentadas por:

- `codigo` de orden de compra;
- `fecha` (`ddmmaaaa`);
- `estado`;
- `CodigoOrganismo`;
- `CodigoProveedor`.

Estados documentados:

| Código | Estado |
|---:|---|
| 4 | Enviada a proveedor |
| 5 | En proceso |
| 6 | Aceptada |
| 9 | Cancelada |
| 12 | Recepción conforme |
| 13 | Pendiente de recepcionar |
| 14 | Recepcionada parcialmente |
| 15 | Recepción conforme incompleta |

La documentación de órdenes está en `https://api.mercadopublico.cl/modules/OrdenCompra.aspx`.

### Buscar proveedor por RUT

```http
GET /Publico/Empresas/BuscarProveedor
```

Parámetro documentado: `rutempresaproveedor`, con RUT formateado incluyendo puntos, guion y dígito verificador. Agregar `ticket` según el esquema V1.

### Buscar organismos compradores

```http
GET /Publico/Empresas/BuscarComprador
```

Retorna organismos compradores y sus códigos, según documentación oficial. Agregar `ticket` según el esquema V1.

### Condiciones V1

La página oficial consultada indica un límite de 10.000 solicitudes diarias por ticket para el servicio descrito en sus términos generales. Respetar ese límite y cualquier condición vigente. La página también recomienda programar cargas masivas entre 22:00 y 07:00. No asumir que esta cuota describe la API Compra Ágil V2; su guía tiene una sección de cuota propia.

## 6.5 Arquitectura del conector

Separar las APIs por adaptador. No crear un cliente monolítico con condicionales dispersos.

```text
backend/app/Services/MercadoPublico/
├── MercadoPublicoConfig.php
├── Http/
│   ├── MercadoPublicoV2Client.php
│   └── MercadoPublicoV1Client.php
├── Adapters/
│   ├── CompraAgilV2Adapter.php
│   ├── LicitacionesV1Adapter.php
│   ├── OrdenesCompraV1Adapter.php
│   ├── ProveedoresV1Adapter.php
│   └── CompradoresV1Adapter.php
├── DTO/
│   ├── CompraAgilDTO.php
│   ├── LicitacionDTO.php
│   ├── OrdenCompraDTO.php
│   └── ApiErrorDTO.php
├── Normalizers/
│   ├── CompraAgilNormalizer.php
│   ├── LicitacionNormalizer.php
│   └── OrdenCompraNormalizer.php
└── Sync/
    ├── MercadoPublicoSyncService.php
    └── MercadoPublicoQuotaService.php
```

### Reglas técnicas del cliente HTTP

- Usar `Illuminate\Support\Facades\Http` o un cliente PSR compatible.
- Configurar HTTPS, timeout y connect timeout.
- No deshabilitar verificación TLS/certificados.
- No seguir URLs arbitrarias incluidas en payloads externos. Para enlaces, permitir solo hosts oficiales esperados y validar esquema HTTPS.
- V1: redacción obligatoria del parámetro `ticket` en logs/trazas.
- V2: añadir header `ticket` solo desde backend.
- Validar `success`, `payload`, `errors` y código HTTP.
- Convertir respuestas a DTOs; no propagar directamente el payload externo como contrato estable del frontend.
- Mantener fixtures JSON anonimizados para pruebas.
- Nunca realizar requests reales desde test unitario.

## 6.6 Sincronización e idempotencia

- Crear `mercado_publico_sync_runs` y `mercado_publico_sync_state`.
- Registrar proveedor de API, endpoint, filtros, hora de inicio/fin, estado, páginas, solicitudes usadas y resumen del error sin ticket.
- Para Compra Ágil usar `fecha_ultimo_cambio` y sincronización incremental con una ventana de solapamiento pequeña configurable. Eliminar duplicados mediante clave externa `codigo` y actualizar cuando cambie `fecha_ultimo_cambio` o el hash del payload.
- Persistir `source_updated_at`, `last_fetched_at`, `source_api`, `source_api_version` y `payload_hash`.
- Recorrer paginación con `numero_pagina` hasta `total_paginas`; tamaño máximo 50 para Compra Ágil V2 según guía vigente.
- Limitar concurrencia. Una consulta por proceso/ventana en curso salvo que se justifique.
- Respetar cuota, `Retry-After` y ventanas de carga masiva.
- No usar polling agresivo. La frecuencia debe configurarse por entorno y por producto/API.
- Evitar pérdida de datos si la sincronización falla a mitad de camino: checkpoints y upsert transaccional por lote.

## 6.7 Atribución y frescura de la información

Toda ficha o exportación que reproduzca información pública de Mercado Público debe incluir, como mínimo:

- Fuente: Dirección ChileCompra / Mercado Público.
- Fecha y hora de consulta/actualización.
- Identificador/código del proceso.
- Enlace al registro oficial cuando exista.

La página de políticas indica que al publicar información obtenida sin modificarla debe señalarse claramente que la fuente es ChileCompra. No presentar datos cacheados como información en tiempo real: mostrar `last_fetched_at` y estado de sincronización.

---

# 7. Diseño de base de datos inicial

Claude debe comparar estas tablas con `DATABASE_SPEC.md` antes de crear migraciones y evitar duplicaciones.

## 7.1 Núcleo

- `tenants`
- `users`
- `roles`, `permissions`, pivotes RBAC
- `tenant_user`
- `audit_logs`
- `documents`
- `categories`
- `products`
- `units_of_measure`

## 7.2 CRM y procurement privado

- `organizations`
- `contacts`
- `customers`
- `suppliers`
- `supplier_categories`
- `supplier_documents`
- `supplier_performance_records`
- `purchase_requests`
- `purchase_request_items`
- `quotation_requests`
- `supplier_quotations`
- `supplier_quotation_items`
- `quotation_comparisons`
- `purchase_approvals`
- `internal_purchase_orders`
- `goods_receipts`
- `goods_receipt_items`
- `invoices` (registro documental/administrativo; no reemplaza software contable o tributario)

## 7.3 Mercado Público

- `public_procurement_opportunities`: registro normalizado común para licitaciones/Compra Ágil; usar `source_type` para distinguir `compra_agil`, `licitacion` y otras fuentes confirmadas.
- `public_procurement_raw_payloads`: JSON original, acceso limitado y retención definida.
- `public_purchase_orders`: órdenes externas consultadas desde la API; no confundir con `internal_purchase_orders`.
- `public_buyers`: organismos compradores.
- `public_suppliers`: proveedores consultados desde la fuente pública.
- `public_opportunity_items`: productos/servicios solicitados en cada Compra Ágil si están disponibles.
- `public_opportunity_documents`: metadatos de documentos retornados por API.
- `public_opportunity_matches`: vínculo entre oportunidad y tenant/cliente con motivo del match.
- `saved_public_searches`: criterios configurables por cliente.
- `public_alerts`: alerta, destinatarios, fecha, estado y referencia al proceso.
- `mercado_publico_sync_runs`
- `mercado_publico_sync_state`
- `mercado_publico_api_usage`

Índices/constraints mínimos:

- Unique `(source_system, external_code)` para procesos externos.
- Unique para código externo de OC cuando sea conocido.
- Índices por estado, fecha de publicación, fecha de cierre, última modificación, región y comprador.
- `tenant_id` indexado en toda tabla de datos privados multiempresa.
- No almacenar ticket en ninguna tabla.
- No exigir que campos opcionales de una API estén siempre presentes.

## 7.4 Logística e inventario

- `warehouses`
- `inventory_items`
- `inventory_movements`
- `stock_balances` o vista/materialización coherente
- `shipments`
- `shipment_events`
- `carriers`
- `delivery_evidence`
- `logistics_incidents`

## 7.5 Reglas de datos

- Montos con `DECIMAL`, nunca `FLOAT`.
- Moneda explícita en montos; CLP predeterminado, pero no forzar CLP para fuentes extranjeras.
- Fechas de API conservadas en UTC y presentadas en `America/Santiago`.
- Guardar códigos originales y glosas normalizadas por separado.
- Payloads externos pueden contener valores inesperados: validar y normalizar, no confiar ciegamente.
- Soft delete solo donde sea legal y funcionalmente apropiado; los movimientos contables/logísticos y auditoría deben ser inmutables o corregirse con asientos/eventos compensatorios.

---

# 8. API interna de TIARE

Todas las rutas internas protegidas bajo `/api/v1`; autenticación Sanctum. Respuestas coherentes, validación y autorización por tenant.

Endpoints iniciales propuestos:

```text
GET    /api/v1/dashboard/summary
GET    /api/v1/customers
POST   /api/v1/customers
GET    /api/v1/suppliers
POST   /api/v1/suppliers
GET    /api/v1/products
POST   /api/v1/purchase-requests
GET    /api/v1/purchase-requests
GET    /api/v1/purchase-requests/{id}
POST   /api/v1/purchase-requests/{id}/submit
POST   /api/v1/purchase-requests/{id}/approve
POST   /api/v1/purchase-requests/{id}/reject
POST   /api/v1/quotation-requests
POST   /api/v1/quotations
GET    /api/v1/quotation-comparisons/{id}
POST   /api/v1/internal-purchase-orders
GET    /api/v1/shipments

GET    /api/v1/public-procurement/compra-agil
GET    /api/v1/public-procurement/compra-agil/{codigo}
GET    /api/v1/public-procurement/tenders
GET    /api/v1/public-procurement/tenders/{codigo}
GET    /api/v1/public-procurement/purchase-orders
GET    /api/v1/public-procurement/purchase-orders/{codigo}
GET    /api/v1/public-procurement/buyers
GET    /api/v1/public-procurement/suppliers/lookup
GET    /api/v1/public-procurement/saved-searches
POST   /api/v1/public-procurement/saved-searches
POST   /api/v1/public-procurement/saved-searches/{id}/run
GET    /api/v1/public-procurement/alerts
POST   /api/v1/public-procurement/sync (solo rol autorizado)
GET    /api/v1/public-procurement/sync-status
```

Estas rutas son contratos propuestos: validar query parameters, filtros y estructura exacta antes de implementarlas. La UI nunca debe llamar directamente a los dominios `api.mercadopublico.cl` o `api2.mercadopublico.cl`.

---

# 9. Roles, permisos y aislamiento

Roles iniciales sugeridos:

- `platform_super_admin`: configuración global y soporte excepcional.
- `tiare_admin`: administración operativa de TIARE.
- `procurement_manager`: gestión de compras.
- `public_procurement_analyst`: análisis de datos públicos y oportunidades.
- `supplier_manager`: gestión de proveedores.
- `logistics_operator`: operaciones logísticas.
- `finance_analyst`: lectura y registro financiero dentro de su alcance.
- `tenant_admin`: administración de una empresa cliente.
- `tenant_procurement_user`: solicitudes y seguimiento de compras propias.
- `tenant_approver`: aprobación de solicitudes dentro de límites asignados.
- `tenant_read_only`: consulta.

Corregir nombres mal acentuados/incorrectos antes de implementar. Los roles de plataforma y los roles de tenant deben distinguirse.

Permisos base:

- `public_procurement.read` y `public_procurement.sync` separados.
- `purchase_requests.create`, `.read`, `.submit`, `.approve`, `.reject`.
- `quotations.create`, `.read`, `.compare`.
- `purchase_orders.create`, `.read`, `.approve`, `.cancel`.
- `suppliers.read`, `.create`, `.update`, `.evaluate`.
- `shipments.read`, `.update`, `.confirm_delivery`.
- `reports.read`, `.export`.

Toda aprobación debe registrar usuario, tenant, fecha, recurso, resultado, comentario y versión del recurso aprobado. Un agente no puede aprobar su propia recomendación.

---

# 10. Agentes de inteligencia artificial

Implementarlos después de que existan datos, roles, auditoría y flujos deterministas.

## 10.1 Diseño

Cada agente debe declarar:

- ID, nombre y versión del prompt.
- Objetivo y alcance.
- Datos de entrada autorizados.
- Herramientas (tool contracts) disponibles.
- Permisos requeridos.
- Riesgo de sus acciones.
- Formato de salida estructurado.
- Política de incertidumbre y citación de fuentes.
- Casos de prueba y límites.

## 10.2 Agentes MVP

1. `tender_intelligence_agent`: clasifica oportunidades, resume requisitos observables, extrae fechas y genera checklist preliminar.
2. `procurement_intelligence_agent`: propone categorías/proveedores para una solicitud privada.
3. `cost_intelligence_agent`: compara costo total con datos equivalentes y supuestos visibles.
4. `supplier_intelligence_agent`: resume antecedentes y desempeño disponibles, sin inventar certificaciones ni solvencia.
5. `logistics_intelligence_agent`: propone rutas o modalidades según datos autorizados.
6. `orchestrator_agent`: delega tareas y consolida resultados.

## 10.3 Restricciones

- No crear agentes autónomos con acceso SQL.
- No permitir ejecución arbitraria de código, shell o URLs no permitidas.
- No enviar secretos ni payloads completos innecesarios a modelos externos.
- No generar una declaración legal definitiva sobre admisibilidad de licitación.
- No presentar oferta ni confirmar compra.
- Toda acción de escritura requiere endpoint/servicio autorizado y permiso del usuario.
- Acciones sensibles requieren confirmación humana persistida.
- Registrar prompt version, modelo, herramienta, resultado, latencia y usuario iniciador, evitando guardar datos personales innecesarios.

---

# 11. Seguridad, privacidad y cumplimiento

- Autenticación segura y política de contraseñas adecuada.
- MFA para cuentas administrativas cuando sea viable.
- RBAC más políticas por tenant y recurso.
- Rate limiting para API interna.
- Validación de archivos, MIME type, tamaño y malware scanning cuando se suban adjuntos.
- Protección CSRF/XSS/SQL injection/SSRF.
- CORS limitado a dominios autorizados.
- HTTPS obligatorio fuera de local.
- Cifrado de backups y secretos.
- Logs con redacción de ticket, tokens, contraseñas y datos de negocio sensibles.
- Auditoría de accesos y modificaciones sensibles.
- Plan de respaldo y restauración probado.
- Política de retención y eliminación de datos.
- Contratos con clientes que definan rol de TIARE, instrucciones, confidencialidad y tratamiento de datos.
- Revisar normativa chilena vigente antes de producción, incluyendo protección de datos personales, normativa laboral/tributaria y reglas de compras públicas.

No almacenar ClaveÚnica de clientes ni contraseñas de Mercado Público. El sistema se limita a información pública consultable por API y documentos que el cliente entregue legítimamente.

---

# 12. Interfaz y experiencia de usuario

Conservar como inspiración la maqueta existente. Diseño:

- Profesional, SaaS B2B, responsive.
- Sidebar con módulos; header con búsqueda, notificaciones y usuario.
- Dashboard con KPI, solicitudes recientes, oportunidades públicas y entregas.
- Tablas con búsqueda, filtros, orden, paginación y estados claros.
- Formularios con validación y mensajes accesibles.
- Fichas de oportunidad que muestran estado, cierre, presupuesto, comprador, región, artículos, documentos y fuente.
- Etiquetas visuales que distingan `dato oficial`, `dato ingresado por cliente`, `estimación IA` y `dato demo`.
- Mostrar siempre fecha de última sincronización para datos externos.
- Estados vacíos, carga, error, sin conexión y cuota API agotada.

No mostrar métricas ficticias como datos reales. En desarrollo usar banner `DATOS DEMOSTRATIVOS`.

---

# 13. Plan de ejecución por fases

## Fase 0 — Auditoría del proyecto

- Inspeccionar el prototipo y archivos de especificación.
- Crear mapa de pantallas y componentes.
- Identificar interacciones existentes.
- Revisar estado de Git y dependencias.
- Crear `docs/IMPLEMENTATION_STATUS.md` y `docs/DECISIONS.md`.

**Criterio de salida:** inventario completo y plan aprobado por consistencia con este documento. No borrar el prototipo original.

## Fase 1 — Estructura y autenticación

- Inicializar o adaptar frontend React/Vite/TypeScript.
- Inicializar o adaptar Laravel API.
- Configurar conexión MySQL, Sanctum, CORS, `.env.example` y health check.
- Crear tenants, usuarios, roles/permisos y auditoría mínima.
- Tests de aislamiento entre tenants.

**Criterio de salida:** login, logout, usuario actual, autorización de rutas y separación de empresas probados.

## Fase 2 — CRM, proveedores y catálogo

- CRUD de clientes, contactos, proveedores, productos y categorías.
- Validación de RUT chileno cuando aplique.
- Búsqueda, filtros, paginación y formularios.
- Tests de API y UI.

## Fase 3 — Compras privadas

- Solicitudes de compra e ítems.
- Solicitudes de cotización y respuestas.
- Comparador de costo total.
- Flujos de aprobación y órdenes internas.
- Recepción e incidencias.
- Auditoría de cambios y estados.

## Fase 4 — Mercado Público API

Orden de integración:

1. Configuración del ticket en backend sin exponerlo.
2. Health check controlado del conector.
3. Compra Ágil V2: listado filtrado, detalle y paginación.
4. Persistencia normalizada e incremental.
5. Monitor React con búsqueda y filtros.
6. Licitaciones V1 y detalle según la documentación oficial.
7. Órdenes de compra V1.
8. Lookup de organismos y proveedores.
9. Búsquedas guardadas y matching por cliente.
10. Alertas y panel de consumo/cuota.

Usar fixtures para desarrollo. Probar una consulta real solo cuando el propietario haya configurado el ticket localmente y el entorno tenga permiso de red. No copiar el ticket en la salida de Claude.

## Fase 5 — Logística e inventario

- Despachos, eventos, transportistas y recepción.
- Inventario básico y movimientos.
- Alertas de incidencias y reposición.

## Fase 6 — Agentes IA y automatización

- Primer agente de análisis de oportunidad pública.
- Agente de comparación privada.
- Orquestador con ejecución controlada.
- Aprobaciones humanas y auditoría.
- Evaluación de calidad de resultados con casos reales anonimizados.

## Fase 7 — Producción

- Hardening de seguridad.
- Monitoreo, logs y alertas.
- Backups y restauración.
- Política de privacidad y términos.
- Pruebas de carga y resiliencia.
- Manuales de operación y soporte.
- Despliegue staging antes de producción.

---

# 14. Pruebas y criterios de aceptación

## 14.1 Mercado Público

- Ticket nunca aparece en HTML, JavaScript, localStorage, cookies del navegador, JSON de respuesta o logs.
- Compra Ágil V2 manda `ticket` en header.
- V1 manda `ticket` como parámetro requerido de acuerdo con su API, con redacción de logs.
- `id` y `q` no pueden enviarse juntos a Compra Ágil V2.
- `tamano_pagina` no supera 50.
- Paginación se detiene en `total_paginas`.
- Fechas V1 se formatean `ddmmaaaa`.
- Errores 400/401/403/404/429/500/503 se interpretan correctamente.
- 429 respeta `Retry-After` cuando se informa y no crea un ciclo de reintentos.
- Los registros sincronizados son idempotentes.
- El estado `oc_emitida` no se toma como única prueba de emisión de OC.
- `id_orden_compra` se considera el indicador documentado de OC asociada cuando no es null.
- La UI muestra fuente y última actualización.
- Pruebas unitarias no llaman a internet.

## 14.2 Compras privadas

- Usuario de tenant A no puede consultar/editar información de tenant B.
- Un aprobador solo aprueba solicitudes de su ámbito y límites.
- Una cotización vencida queda identificada.
- Comparador usa moneda e impuestos consistentes.
- Toda recomendación conserva su metodología y valores de entrada.
- Cambios de estado quedan auditados.

## 14.3 Seguridad

- Pruebas positivas y negativas de cada Policy.
- Validación de IDs y tenant para prevenir IDOR.
- Rate limiting y validación de uploads.
- No hay secretos en Git ni logs.
- Backups se pueden restaurar en un ambiente de prueba.

## 14.4 Calidad del código

- Linter y typecheck pasan.
- Tests de backend relevantes pasan.
- Build frontend pasa.
- Migraciones pueden ejecutarse desde una base vacía.
- README permite levantar localmente el sistema.
- Errores no revelan stack traces ni secretos en producción.

---

# 15. Instrucciones para comenzar ahora

Claude debe ejecutar la siguiente secuencia sin saltarse la inspección inicial:

### Tarea A — Auditar

1. Revisar la carpeta actual y localizar el prototipo.
2. Leer `index.html`, `styles.css`, `app.js` y `README.md`.
3. Leer los documentos funcionales disponibles.
4. Inspeccionar `git status` y dependencias.
5. Crear inventario en `docs/IMPLEMENTATION_STATUS.md`.
6. Crear `docs/DECISIONS.md` con decisiones abiertas.

### Tarea B — Diseñar el sistema

1. Definir arquitectura final según lo encontrado, respetando el stack objetivo.
2. Definir estructura de módulos y rutas.
3. Definir migraciones y modelos iniciales, cotejando `DATABASE_SPEC.md`.
4. Elaborar diagrama de flujo de Compra Ágil V2 y legacy V1.
5. Crear `docs/MERCADO_PUBLICO_API.md` con endpoints, autenticación, filtros, mapeos, estados, cuotas y errores.

### Tarea C — Implementar primer incremento

Prioridad de implementación:

1. Esqueleto frontend/backend y conexión MySQL.
2. Autenticación y multiempresa.
3. CRM, proveedores y catálogo.
4. Compras privadas: solicitud, cotización, comparación y aprobación.
5. Conector Compra Ágil V2.
6. Monitor de oportunidades.

No desarrollar todos los agentes ni módulos financieros avanzados antes de validar el flujo principal.

### Tarea D — Reportar

Entregar resumen del estado real, pruebas realizadas, archivos modificados, bloqueos y siguiente tarea. No pedir al usuario credenciales en el chat; indicar cómo configurarlas localmente en `.env`.

---

# 16. Referencias oficiales Mercado Público

- API Mercado Público (ChileCompra): https://www.chilecompra.cl/api/
- Portal Mercado Público: https://www.mercadopublico.cl/
- API Compra Ágil V2, guía oficial enlazada desde ChileCompra: `https://www.chilecompra.cl/wp-content/uploads/2026/05/Documentacion_API_Compra_Agil-2-1.pdf`
- Diccionario API Licitaciones, enlazado desde ChileCompra: revisar enlace vigente en `https://www.chilecompra.cl/api/`.
- Diccionario API Órdenes de Compra, enlazado desde ChileCompra: revisar enlace vigente en `https://www.chilecompra.cl/api/`.
- Endpoint legacy Licitaciones: `https://api.mercadopublico.cl/servicios/v1/publico/licitaciones.json`
- Endpoint legacy Órdenes de Compra: `https://api.mercadopublico.cl/servicios/v1/publico/ordenesdecompra.json`
- Endpoint Compra Ágil V2: `https://api2.mercadopublico.cl/v2/compra-agil`

**Regla final:** la página/API de ChileCompra es la fuente de verdad para la integración. Si la documentación cambia, actualizar `docs/MERCADO_PUBLICO_API.md`, tests y este archivo antes de asumir el comportamiento nuevo.
