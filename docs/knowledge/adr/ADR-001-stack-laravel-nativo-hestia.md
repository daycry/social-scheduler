---
id: ADR-001
titulo: Stack Laravel 13 monolito nativo en HestiaCP con cola en MariaDB/MySQL
estado: propuesta
fecha: 2026-10-19
iniciativa: 2026-10-19-social-scheduler
---

# ADR-001: Stack Laravel 13 monolito nativo en HestiaCP con cola en MariaDB/MySQL

## Contexto

El proyecto es greenfield y la spec dejó el stack sin fijar. El único entorno de despliegue es un VPS con HestiaCP: su Nginx ocupa 80/443 y emite TLS, ofrece PHP-FPM, MariaDB/MySQL (de serie), cron y backups, y no trae Redis ni Docker. La app necesita una cola fiable (reintentos, idempotencia, polling), scheduler persistente y un servidor MCP HTTP autenticado por usuario. Fuente: [`docs/roadmap/2026-10-19-social-scheduler/design.md`](../../roadmap/2026-10-19-social-scheduler/design.md) (opción O1, validada por el usuario el 2026-10-19).

## Decisión

Se construye un monolito Laravel 13 servido por el PHP-FPM de Hestia con su plantilla Laravel. Usa la MariaDB/MySQL gestionada por Hestia (mínimo MariaDB 10.6 o MySQL 8.0, por `SKIP LOCKED` en la cola) y la cola con driver `database`, sin Redis en v1. Los workers `queue:work` corren como unidades systemd (hay acceso root) y el scheduler usa el cron de Hestia (`schedule:run`). El frontend es Inertia + Vue. El MCP usa `laravel/mcp` (`Mcp::web` + `auth:sanctum` con API key personal) y deja OAuth 2.1 vía Passport como ampliación. La media va a R2 vía Flysystem S3.

## Alternativas descartadas

- **TypeScript full-stack (Next.js 16 + pg-boss + Drizzle + MCP TS SDK) tras proxy de Hestia** — más procesos que Hestia no gestiona, plantilla proxy a medida, más piezas que ensamblar (auth, ORM, auth del MCP) y coste relativo M frente a S. El usuario domina PHP/Laravel.
- **Variante Docker Compose tras proxy de Hestia** — añade un daemon que Hestia no gestiona sin resolver nada que systemd no resuelva a volumen personal.
- **Python (Django + Procrastinate/Celery + SDK `mcp`)** — misma forma operativa que la opción TS sin aportar un trade-off nuevo.
- **PostgreSQL** — valía igual, pero el usuario prefiere la MariaDB/MySQL que Hestia trae de serie; no se pierde nada que el proyecto necesite (se renuncia a RLS: el aislamiento es solo de aplicación).
- **Redis + Horizon en v1** — operar Redis aparte no compensa a decenas de publicaciones/semana; queda como ampliación.

## Consecuencias

Hestia gestiona web, TLS, cron, BD y backups, y la operación propia se reduce a las unidades systemd de los workers. Cola, reintentos, unicidad, cifrado de tokens y MCP salen del ecosistema oficial de Laravel. Cambiar de lenguaje después de C-01 obliga a reescribir. Lo portable es el esquema (migraciones Eloquent, portables a PostgreSQL) y el diseño de conectores. Queda condicionado a que `laravel/mcp` (1.0 desde 2026-09-14) se mantenga estable; el plan B es un servidor MCP aparte con el SDK oficial.

## Estado

`propuesta` — validar por la revisión de dos lentes o el usuario en la puerta. Pasa a `aceptada` cuando se valida; a `obsoleta` si una decisión posterior la reemplaza (enlaza aquí la que la sustituye, nunca se borra el rastro).
