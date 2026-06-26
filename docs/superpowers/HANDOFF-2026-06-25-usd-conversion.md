# Handoff — Conversión USD (BCP) — 2026-06-25

Contexto para continuar mañana. Sesión ejecutando el plan con **subagent-driven-development**.

## Qué es esto
Feature: mostrar los ingresos del reporte financiero también en **USD**, convertidos con la cotización oficial de cierre del **BCP** del **día del pedido**, vía un mirror local de tasas + backfill programado.

- **Spec:** `docs/superpowers/specs/2026-06-25-conversion-usd-bcp-design.md`
- **Plan (10 tareas TDD):** `docs/superpowers/plans/2026-06-25-conversion-usd-bcp.md`
- **Ledger de progreso:** `laravel-crm/.superpowers/sdd/progress.md` (scratch, git-ignored)

## Ramas de trabajo
Todo en `feat/usd-conversion-bcp` en los 4 repos:
- `laravel-crm` (branched de `2.1`)
- `packages/CarlVallory/KrayinWoocommerce` (de `main`)
- `packages/CarlVallory/KrayinNetValue` (de `feat/marketing-vs-admin-mapping` — DEPENDE de la migración branch_fields que vive ahí, no en main)
- `packages/CarlVallory/KrayinFinancialReports` (de `main`)

> Nota: los PRs #1 de marketing-vs-admin (plugin y net-value) siguen ABIERTOS sin mergear. El PR de NetValue para USD quedará apilado sobre ese.

## Decisiones de diseño (cerradas)
1. **Fuente:** BCP oficial (`source='bcp'`). Frankfurter descartado (no tiene PYG).
2. **Granularidad:** una tasa por día = cierre. Coincide con la referencial del BCP (promedio ponderado 8:30–13:00 de días hábiles; no hay finde/feriado).
3. **Arquitectura (Opción C):** mirror + conversión en `KrayinNetValue`; `KrayinFinancialReports` solo reporta. Dependencia reporte→datos.
4. **Conversión:** denormalizada en `leads.usd_rate`/`leads.total_usd`, por **backfill programado** (nightly + tras re-syncs). Sin listener en tiempo real.
5. **Tasa por pedido:** del día de `created_at` (fecha del pedido), con fallback al último día hábil previo.
6. **UI:** toggle Vue PYG/USD, arranca en PYG, no persiste.
7. **Endpoint REST:** diferido (YAGNI); el resolver interno queda detrás de interfaz limpia.

## Progreso: 2 de 10 tareas completas

| # | Tarea | Estado | Commits |
|---|---|---|---|
| T1 | Fix `created_at` (provider `booted`) | ✅ Spec+Calidad Approved | KrayinWoocommerce `6447ff0`, test laravel-crm `4cb8046a` |
| T2 | Mirror `exchange_rates` + modelo + `ExchangeRateResolver` en NetValue | ✅ Spec+Calidad Approved | KrayinNetValue `0761d4a`, FinancialReports `389d9ee` (empty), test laravel-crm `96c4a071` |
| T3 | `BcpNumberParser` (`6.719,39`→float) | ⬜ pendiente | — |
| T4 | `BcpHttpRateFetcher` + fixture | ⬜ pendiente | — |
| T5 | Comando `exchange-rates:backfill {year}` | ⬜ pendiente | — |
| T6 | Comando `exchange-rates:poll` | ⬜ pendiente | — |
| T7 | Comando `leads:backfill-usd {year}` | ⬜ pendiente | — |
| T8 | Scheduler (poll hábiles PM + backfill-usd nightly) | ⬜ pendiente | — |
| T9 | Agregados USD en el controller (incluye el WIP `lead_value→net_value`) | ⬜ pendiente | — |
| T10 | Toggle PYG/USD en la vista | ⬜ pendiente | — |

**Mañana se retoma en la Tarea 3.** Cada tarea: brief con código completo en el plan → implementer subagente (TDD) → review subagente (spec+calidad) → commit.

## Cosas a resolver / pendientes importantes
1. **Footgun de tests (decidir al retomar):** el suite de `laravel-crm` corre contra la **BD real** (no usa `RefreshDatabase`). El test del resolver hace `beforeEach` que **trunca `exchange_rates`** → si se corre contra una BD poblada, borra datos. Conviene configurar transacciones o sqlite de test antes de seguir sumando tests. **El usuario quiere decidir esto al retomar.**
2. **WIP sin commitear (en disco, NO perder):** `KrayinFinancialReports/src/Http/Controllers/FinancialReportController.php` tiene el cambio `lead_value→net_value` (6/6 líneas). Es propiedad de la **Tarea 9** y se commitea ahí con su test. No commitear suelto.
3. **Cambios pre-existentes en `laravel-crm` (NO son de esta sesión, dejados como están):** `.gitignore` (+`/packages/CarlVallory`,`/packages/Vallory`), `composer.json`/`composer.lock` (registran los paquetes carlvallory como `@dev`, php ^8.1, krayin/rest-api), untracked `config/l5-swagger.php`, `storage/api-docs/`, `.phpunit.cache/`. Revisar si deben commitearse aparte.
4. **Gitlink fastidioso:** `laravel-crm` tiene un gitlink staged `packages/Vallory/KrayinFormatter` (mode 160000, sin `.gitmodules`) que se coló en un commit y hubo que sacarlo. Usar SIEMPRE `git add <paths específicos>` en laravel-crm, nunca `git add -A`.
5. **Minors de review (p/ review final de rama):** test T1 usa `getModel()` en vez de `get_class($lead)`; falta test de guarda multi-moneda en el resolver; commit `389d9ee` vacío sin body.

## Detalles técnicos clave para T3–T10
- **BCP histórico anual:** página `https://www.bcp.gov.py/webapps/web/cotizacion/referencial-fluctuante/anual` — tabla HTML días(1-31)×meses(ENE..DIC), valores `6.719,39`, `ND` para días sin cotización, selector de año (form). Sin JSON/CSV. T4 parsea con DOMDocument contra un **fixture** guardado (test sin red vía `Http::fake()`).
- **Tests:** Pest 10, en `laravel-crm/tests/`, corren desde `/home/vallory/code/crm/laravel-crm` con `./vendor/bin/pest --filter=...`.
- **Quirks de la BD de test:** `lead_pipeline_stages` no tiene columnas timestamp; `repo->create()` de lead necesita `entity_type=>'leads'`. Stages: 1=new, 5=won, 6=lost.
- **Comandos:** registrar en `KrayinNetValueServiceProvider` con `$this->commands([...])` bajo `runningInConsole()`. Scheduler en `laravel-crm/app/Console/Kernel.php::schedule()`.
- **Resolver ya disponible:** `CarlVallory\KrayinNetValue\Services\ExchangeRateResolver::rateForDate(string|Carbon): ?float` (exacto → último día hábil previo → null).
- **Conversión:** `total_usd = net_value / usd_rate` (tasa = PYG por 1 USD).
