# TIARE — Sistema visual (Material rosado)

**Versión:** 17-11-2026 (TD-016). Reemplaza el sistema "expediente" (TD-015) por instrucción del propietario: diseño más intuitivo, actual, paleta rosada y Material Design.
**Implementación:** MUI 6 (Material Design para React) con tema en `frontend/src/app/theme.ts`. Todas las vistas usan MUI. `frontend/src/styles/tiare.css` solo conserva las utilidades `.code`/`.num` y el dibujo de la línea de cierres del Resumen.

Componentes compartidos (`frontend/src/components/`): `PageHeader`, `FilterBar` (+ `SearchField`, `SelectField`), `ResultCard`, `DataTable` (filas clicables con teclado), `Drawer` (ficha lateral; encabezado de código o de nombre), `FormDialog` (formulario en diálogo, pantalla completa en móvil), `ListStates` (error, vacío, esqueletos, paginación), `DataBadges` (estado, procedencia, atribución).

> Se pidió "Angular Material". Como el frontend es React, el propietario eligió MUI, su equivalente para React (TD-016).

## Paleta (esquema Material 3 rosado)

| Rol | Hex | Uso |
|---|---|---|
| Primario (frambuesa) | `#984061` | Acciones principales, selección, enlaces |
| Contenedor primario | `#FFD9E2` / texto `#3E001D` | Ítem activo del menú, encabezado de fichas, chips "completado" |
| Secundario (rosa viejo) | `#74565F` | Elementos de apoyo |
| Terciario (damasco) | `#FFDCC1` / acento `#A04A12` | **Cierres en menos de 24 h**, cuota alta, hito próximo |
| Éxito (salvia) | `#3A6A4F` / `#BCEFCF` | Publicada, aceptada, dato oficial (contrapunto frío del rosado) |
| Error | `#BA1A1A` / `#FFDAD6` | Desierta, cancelada, revocada |
| Superficies (blush) | `#FFF8F8`, `#FCF0F2`, `#F6EAEC`, `#F1E4E6` | Fondo, menú lateral, campos y zonas secundarias |
| Texto | `#22191C` / `#524347` | Principal / secundario |
| Bordes | `#D5C2C6` | Tarjetas, tablas, campos |

Combinaciones: rosado + salvia (estados), rosado + damasco (urgencia), rosado oscuro `#3E001D` + rosa claro (portada de acceso).

## Tipografía

- **Plus Jakarta Sans** para toda la interfaz (títulos 700, botones 600 sin mayúsculas).
- **Archivo** condensado con numerales tabulares solo para códigos (`.code`) y montos (`.num`).

## Forma

- Botones tipo píldora (radio 20 px), tarjetas 16 px, campos 10 px, panel lateral 24 px.
- En `sx`, los radios se escriben en px (`'12px'`): un número se multiplica por el radio base del tema (12 px).

## Patrones de experiencia

- **Menú lateral** con íconos: fijo en escritorio, deslizable en móvil. Módulos planificados con la etiqueta "Pronto".
- **Barra superior** con búsqueda de Compra Ágil en forma de píldora y menú de cuenta (avatar).
- **Mercado Público** con pestañas con ícono: Compra Ágil, Licitaciones, Órdenes de compra, Organismos, Proveedores. Tarjeta de estado con conexión, última sincronización y barras de cuota V1/V2.
- **Resultados** como tarjetas clicables: código y estado arriba, título, organismo; monto y plazo a la derecha. Las que cierran en menos de 24 h llevan borde izquierdo damasco y chip con reloj.
- **Fichas** en panel lateral derecho con encabezado rosado, datos clave en recuadros de dos columnas, tablas compactas, línea de hitos con el próximo destacado.
- **Consultas a Mercado Público** en un formulario con fecha, estado, organismo y proveedor, y un resumen de la última consulta (si consumió cuota o no).
- **Estados**: esqueletos de carga, barra de progreso al refrescar, alertas con instrucción concreta, estados vacíos con ícono y una acción, avisos (snackbar) al sincronizar.
- **Resumen**: saludo, tres indicadores verificables (abiertas, cierran en 24 h, registradas), línea de cierres de 72 h y tabla de lo urgente.
- **Clientes, Proveedores y Catálogo**: tablas con fila clicable que abre la ficha lateral (contactos, categorías, cobertura), formularios en diálogo agrupados por secciones con ayuda bajo cada campo, selección múltiple con chips (categorías y cobertura), filtro de tipo con botones conmutables y lista de categorías a la izquierda en Catálogo, aviso de confirmación al guardar.
- **Módulos en desarrollo**: tarjeta explicativa con la fase planificada y botón para volver.

## Reglas que se mantienen

- Toda vista con datos oficiales termina con la atribución a ChileCompra y la hora de actualización.
- Etiquetas de procedencia: dato oficial, dato del cliente, estimación IA, dato demo.
- Foco visible (MUI), contraste AA en texto, diseño usable desde 375 px, `prefers-reduced-motion` respetado.
