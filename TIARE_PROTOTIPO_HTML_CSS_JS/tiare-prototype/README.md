# TIARE — Prototipo web navegable

Maqueta funcional de interfaz para TIARE, startup de compras, abastecimiento y logística.

## Archivos

- `index.html`: estructura de la aplicación.
- `styles.css`: estilos, diseño adaptable y componentes visuales.
- `app.js`: navegación, datos demostrativos, filtros, formularios y simulación de acciones.

## Cómo ejecutar

1. Descarga y descomprime el paquete.
2. Abre `index.html` con Chrome, Edge o Firefox.
3. También puedes abrir la carpeta con Visual Studio Code y utilizar la extensión Live Server.

No requiere instalación de dependencias, compilación ni servidor backend.

## Módulos incluidos

- Resumen ejecutivo / dashboard.
- Solicitudes de compra.
- Cotizaciones y comparador.
- Monitor demostrativo de Mercado Público.
- Proveedores.
- Clientes / CRM.
- Logística y entregas.
- Agentes de IA.
- Analítica e informes.
- Configuración.

## Interacciones de la maqueta

- Navegación entre módulos.
- Búsqueda y filtros en tablas.
- Alta local de solicitudes y clientes.
- Alta demostrativa de proveedores.
- Modal de comparación de cotizaciones.
- Simulación de análisis de agentes y reportes.
- Persistencia local de solicitudes y clientes mediante `localStorage`.
- Diseño adaptable a escritorio, tablet y móvil.

## Límites importantes

Esta es una maqueta frontend. No está conectada a Laravel, MySQL, Mercado Público, correo, proveedores ni transportistas. Los registros y montos son ficticios. Las acciones se simulan en el navegador. No debe utilizarse para compras reales ni almacenar datos personales o comerciales reales.

## Siguiente etapa técnica

Integrar esta interfaz con una API Laravel versionada (`/api/v1`), autenticación, autorización por organización, auditoría, almacenamiento privado y servicios de dominio. La integración de Mercado Público debe respetar las condiciones oficiales de su API. Los agentes IA deben invocar herramientas controladas del backend y requerir aprobación humana en acciones sensibles.
