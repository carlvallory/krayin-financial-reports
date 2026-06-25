# Conversión USD en Reportes Financieros (cotización BCP) — Diseño

**Fecha:** 2026-06-25
**Estado:** Aprobado, pendiente de plan de implementación
**Repos afectados:** `KrayinNetValue` (mirror de tasas + conversión), `KrayinFinancialReports` (reporte/UI), `KrayinWoocommerce` (fix prerequisito)

> **Nota de arquitectura (decidido 2026-06-25):** el mirror de tasas y la conversión viven en `KrayinNetValue` (capa de datos, dueña de las columnas `net_value`/`usd_rate`/`total_usd` y del `LeadSaveListener`). `KrayinFinancialReports` queda como capa de reporte que solo lee `total_usd` ya poblada. Dirección de dependencia: reporte → datos. La conversión se hace por **backfill programado**, no por listener en tiempo real.

---

## 1. Problema y objetivo

El reporte financiero (`KrayinFinancialReports`) muestra ingresos solo en guaraníes (PYG). Se necesita mostrar también el equivalente en **dólares (USD)**, con dos requisitos clave:

1. **Conversión retroactiva** de todos los leads/pedidos del año en curso.
2. La tasa aplicada a cada pedido debe ser la del **día en que se realizó el pedido** (no la del día en que se sincronizó al CRM), porque un pedido puede sincronizarse días después por errores o re-sincronizaciones masivas.

La fuente de tasas es la **cotización referencial oficial del Banco Central del Paraguay (BCP)**.

## 2. Restricciones confirmadas (BCP)

Verificado contra la documentación del BCP (2026-06-25):

- La **cotización referencial** del USD/PYG es el **promedio ponderado de las operaciones interbancarias entre las 8:30 y las 13:00 hs de cada día hábil**.
- Es un **único valor por día hábil** — no existe distinción AM/PM en la fuente oficial.
- **No hay cotización los fines de semana ni feriados** (no por falla, sino por diseño: no hay operaciones).
- La referencial se finaliza **después de las 13:00 hs**.
- Existe una página de **Histórico Anual** de la referencial fluctuante, apta para backfill del año.

Fuentes:
- https://www.bcp.gov.py/en/tipo-de-cambio
- https://www.bcp.gov.py/webapps/web/cotizacion/monedas
- https://www.bcp.gov.py/webapps/web/cotizacion/referencial-fluctuante/anual

## 3. Decisiones de diseño

| Tema | Decisión | Motivo |
|------|----------|--------|
| Fuente de tasas | BCP oficial (`source='bcp'`) | Auditable/defendible para reporte financiero; única fuente correcta de PYG |
| Granularidad | Un valor por fecha (cierre del día) | Coincide con cómo publica el BCP; sin AM/PM |
| Origen de datos | **Mirror local** en BD, nunca consulta en vivo al sincronizar | Desacopla el sync de la disponibilidad del BCP; soporta re-sync masivo sin saturar/depender del BCP |
| Tasa por pedido | Tasa de cierre del **día del pedido** (`created_at`) | Requisito del negocio |
| Fallback finde/feriado | **Último día hábil previo** | Coincide con el comportamiento del BCP |
| Conversión | **Denormalizada**: se guarda `usd_rate` y `total_usd` en el lead | Reutiliza columnas existentes; centraliza el fallback; reporte trivial y rápido |
| Ubicación | Mirror + conversión en **`KrayinNetValue`**; reporte en **`KrayinFinancialReports`** | NetValue es dueño de las columnas USD; dependencia reporte→datos (no al revés) |
| Timing | **Backfill programado** (nightly) + tras re-syncs; sin listener USD en tiempo real | La tasa del día recién existe a la tarde; consistencia eventual alcanza para el reporte y evita orden de listeners |
| UI | **Toggle Vue PYG/USD**; arranca en PYG; no persiste | YAGNI |

## 4. Prerequisito: fix del bug de `created_at` (orden de providers)

**Sin este fix, la conversión retroactiva por fecha de pedido no funciona.**

- **Causa raíz:** `KrayinWoocommerceServiceProvider` registra el modelo custom `Lead` (que tiene `created_at` en `$fillable`) vía `concord->registerModel(...)` dentro de `boot()`. El módulo `Webkul\Lead` bootea después y re-registra el modelo base (sin `created_at` fillable), pisando el binding custom. Resultado: Eloquent sobrescribe `created_at` con `now()` (fecha de sync) en vez de respetar la fecha del pedido que envía el plugin.
- **Verificado empíricamente** el 2026-06-25: con el modelo base, `created_at` enviado se pisa; re-registrando el custom post-boot, se preserva.
- **Fix:** en `KrayinWoocommerceServiceProvider`, envolver el `registerModel` en `$this->app->booted(function () { ... })` para que corra después de todos los providers y gane el binding custom.
- **Beneficio adicional:** arregla los gráficos del pipeline, que hoy usan la fecha de sincronización en vez de la del pedido.

## 5. Componentes

### 5.1 Mirror local de tasas (`KrayinNetValue`)

> La migración `create_exchange_rates_table`, el modelo `ExchangeRate`, el fetcher, los comandos y el resolver se ubican en **`KrayinNetValue`**. (Hoy están como WIP en `KrayinFinancialReports`; el plan los mueve a `KrayinNetValue` antes de commitear, ya que ese WIP aún no está versionado.)

- **Tabla `exchange_rates`** (migración ya escrita, sin cambios de schema): `date` único, `currency_from='USD'`, `currency_to='PYG'`, `rate` (cierre del día), `source` (`manual`/`bcp`), timestamps.
- **Servicio fetcher BCP**: encapsula la consulta a la web del BCP y devuelve la cotización referencial de cierre. Aislado detrás de una interfaz para poder testear/cambiar fuente.
- **Comando `exchange-rates:poll`**: corre **solo días hábiles, por la tarde** (después de 13:00). Hace **upsert por fecha** con la última lectura exitosa. Programado en el scheduler 2–4×/día como **reintento por resiliencia** (si el BCP está caído en un horario). No pollea finde/feriado.
- **Comando `exchange-rates:backfill {year}`**: carga única del histórico del año desde la página de Histórico Anual del BCP. Idempotente (upsert por fecha).
- **`ExchangeRate::getRateForDate($date)`** (mejorar el actual, que solo hace match exacto): cascada **fecha exacta → último día hábil previo disponible**. `getLatestRate()` se mantiene.

### 5.2 Conversión denormalizada por backfill programado (`KrayinNetValue`)

- **Comando `leads:backfill-usd {year}`**: para cada lead ganado (`stage='won'`) del año, calcula `usd_rate = getRateForDate(date(created_at))` y `total_usd = net_value / usd_rate`, y los persiste en `leads.usd_rate` / `leads.total_usd`. Idempotente. **Siempre lee del mirror local** → soporta el re-sync masivo sin tocar el BCP.
- **Programación:** se agenda **nightly** en el scheduler. Así un lead sincronizado hoy obtiene su `total_usd` en la corrida siguiente, cuando ya existe la tasa de cierre del día del pedido. Tras un re-sync masivo se puede correr el comando a mano para reflejar todo enseguida.
- **Sin listener de USD en tiempo real.** El `LeadSaveListener` existente de `KrayinNetValue` se mantiene tal cual (mapea EAV→columna, incluido el mapeo dormido de `usd_rate`/`total_usd` por si el plugin algún día los enviara). La conversión la hace exclusivamente el backfill.
- **Edge case:** lead cuya fecha de pedido aún no tiene tasa en el mirror → la corrida nightly usa fallback al último día hábil; cuando exista la tasa exacta, una corrida posterior la reajusta.

### 5.3 Reporte + UI (`KrayinFinancialReports`)

- **`FinancialReportController`**: además de los agregados PYG actuales (que ya usan `net_value` — cambio `lead_value→net_value` ya hecho y se incluye), agrega los equivalentes USD: `SUM(total_usd)` anual, USD del mes, y serie mensual USD. Pasa ambas monedas a la vista.
- **`index.blade.php`**: **toggle Vue PYG/USD** que alterna KPIs, chart (`v-monthly-sales-chart`) y tablas entre los valores PYG (`net_value`/`total_amount`) y USD (`total_usd`). Estado local en Vue, arranca en PYG, no persiste.

## 6. Flujo de datos

```
BCP (web)
  └─ exchange-rates:poll (días hábiles PM, reintentos)  ─┐
  └─ exchange-rates:backfill {year} (histórico anual)   ─┴─> exchange_rates (mirror local)
                                                                   │
Plugin WC → lead (created_at = fecha pedido, gracias al fix §4; net_value vía LeadSaveListener)
                                                                   │
   [KrayinNetValue] leads:backfill-usd {year} (nightly + tras re-sync)
        └─ getRateForDate(created_at) → usd_rate, total_usd en el lead
                                                                   │
                                                                   ▼
   [KrayinFinancialReports] FinancialReportController (SUM net_value / SUM total_usd) ──> index.blade (toggle PYG/USD)
```

## 7. Manejo de errores

- **BCP no disponible** en una corrida del poll: se loguea y se reintenta en la siguiente corrida del día (resiliencia por diseño). No afecta sync ni reportes (leen del mirror).
- **Fecha sin tasa** al convertir: fallback a último día hábil previo. Nunca deja un lead ganado sin `total_usd` mientras exista al menos una tasa previa en el mirror.
- **Mirror vacío** (antes del primer backfill de tasas): `getRateForDate` devuelve null → `leads:backfill-usd` deja `total_usd` en null y lo completa en una corrida posterior, una vez cargado el histórico.

## 8. Testing

- **Unit:** `getRateForDate` (match exacto, fallback a hábil previo, mirror vacío); cálculo `total_usd = net_value / rate`.
- **Unit:** parser del fetcher BCP contra HTML/respuesta de ejemplo (fixture), sin red.
- **Integración:** fix de `created_at` — crear lead vía repo con `created_at` pasado y verificar que se preserva (regresión del bug §4).
- **Integración:** `leads:backfill-usd` recalcula correctamente sobre un set de leads + tasas de prueba.
- **Idempotencia:** correr `exchange-rates:backfill` y `leads:backfill-usd` dos veces no duplica ni cambia resultados.

## 9. Fuera de alcance (YAGNI)

- **Endpoint REST de tasas.** Decidido 2026-06-25: el único consumidor inicial es el propio CRM, que accede al mirror **internamente** vía el resolver `getRateForDate()` (sin HTTP). El endpoint REST (GET por fecha / latest / rango, autenticado con Bearer como el resto del REST API de Krayin) se agregará cuando exista un consumidor **externo** real (plugin u otros sistemas internos). Para que sumarlo sea trivial, el resolver/servicio de tasas debe quedar detrás de una **interfaz limpia y desacoplada** en `KrayinNetValue` (un controller REST futuro solo la envuelve).
- Persistencia de la moneda elegida en el toggle.
- Distinción AM/PM (no existe en la fuente oficial).
- Otras monedas además de USD/PYG.
- Conversión en tiempo real al recibir el lead (listener) — descartada: la tasa de cierre del día recién existe a la tarde; se usa backfill programado.
- Poblar `branch`/`sale_variation`/`order_type` (columnas de branch_fields que el plugin aún no envía) — no son parte de este feature.
- Integración de fuentes alternativas (Frankfurter, etc.) — descartadas (Frankfurter no tiene PYG).
