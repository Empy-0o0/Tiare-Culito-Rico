# TIARE — Procurement & Supply Platform

Plataforma de compras, abastecimiento y logística para empresas chilenas, con monitor de oportunidades públicas basado en la API oficial de Mercado Público (ChileCompra).

- `backend/` — Laravel 12 (API REST `/api/v1`, Sanctum, conector Mercado Público).
- `frontend/` — React 18 + Vite + TypeScript (SPA con el diseño del prototipo).
- `TIARE_PROTOTIPO_HTML_CSS_JS/` — prototipo original (referencia funcional/visual, no se modifica).
- `docs/` — arquitectura, decisiones, estado de implementación e integración Mercado Público.

## Requisitos

PHP 8.2+ (extensiones `pdo_mysql`, `pdo_sqlite`, `curl`, `mbstring`), Composer 2, Node 20+, MySQL 8 o MariaDB 10.4+ (opcional en desarrollo: SQLite).

## Puesta en marcha local

```bash
cd backend
composer install
cp .env.example .env          # luego completa .env (ver abajo)
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8000
```

```bash
cd frontend
npm install
npm run dev                   # http://localhost:5173 (proxy /api → :8000)
```

### Variables obligatorias en `backend/.env`

| Variable | Uso |
|---|---|
| `SEED_ADMIN_EMAIL`, `SEED_ADMIN_PASSWORD` | Administrador local creado por `migrate --seed` |
| `MERCADO_PUBLICO_ENABLED=true` | Habilita el conector |
| `MERCADO_PUBLICO_TICKET` | Ticket personal de ChileCompra. **Solo aquí**; nunca en Git, código, capturas ni chat |

Base de datos: crea `tiare` en MariaDB/MySQL (`CREATE DATABASE tiare CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`) y descomenta el bloque `DB_*` de `.env`. Las pruebas usan SQLite en memoria por defecto; para correrlas contra MariaDB crea `tiare_test` y ejecuta `DB_CONNECTION=mariadb DB_DATABASE=tiare_test php artisan test`.

### Sincronizar Compra Ágil

```bash
php artisan tiare:mp:sync-compra-agil                       # incremental (ttl_cambio_ms)
php artisan tiare:mp:sync-compra-agil --window=60           # últimos 60 min
php artisan tiare:mp:sync-compra-agil --full --estado=publicada --max-pages=150   # carga inicial, 22:00–07:00
php artisan tiare:mp:sync-compra-agil --full --estado=publicada --from-page=150   # retomar otra noche (cuota ~275/día)
```

Sincronización automática: `MERCADO_PUBLICO_SYNC_ENABLED=true` + `php artisan schedule:work` + `php artisan queue:work`.

## Pruebas

```bash
cd backend && php artisan test        # sin acceso a internet (Http::preventStrayRequests)
cd frontend && npm run lint && npm test && npm run build
```

## Reglas clave

Ver `CLAUDE.md`. En resumen: el navegador nunca llama a `mercadopublico.cl`; el ticket nunca sale del backend; los datos públicos se muestran con fuente (Dirección ChileCompra) y fecha de actualización; TIARE no presenta ofertas ni opera en el portal.
