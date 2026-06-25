# Conversión USD en Reportes Financieros (cotización BCP) — Diseño

**Fecha:** 2026-06-25
**Estado:** Aprobado, pendiente de plan de implementación
**Repos afectados:** `KrayinFinancialReports` (principal), `KrayinWoocommerce` (fix prerequisito)

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
| UI | **Toggle Vue PYG/USD**; arranca en PYG; no persiste | YAGNI |

## 4. Prerequisito: fix del bug de `created_at` (orden de providers)

**Sin este fix, la conversión retroactiva por fecha de pedido no funciona.**

- **Causa raíz:** `KrayinWoocommerceServiceProvider` registra el modelo custom `Lead` (que tiene `created_at` en `$fillable`) vía `concord->registerModel(...)` dentro de `boot()`. El módulo `Webkul\Lead` bootea después y re-registra el modelo base (sin `created_at` fillable), pisando el binding custom. Resultado: Eloquent sobrescribe `created_at` con `now()` (fecha de sync) en vez de respetar la fecha del pedido que envía el plugin.
- **Verificado empíricamente** el 2026-06-25: con el modelo base, `created_at` enviado se pisa; re-registrando el custom post-boot, se preserva.
- **Fix:** en `KrayinWoocommerceServiceProvider`, envolver el `registerModel` en `$this->app->booted(function () { ... })` para que corra después de todos los providers y gane el binding custom.
- **Beneficio adicional:** arregla los gráficos del pipeline, que hoy usan la fecha de sincronización en vez de la del pedido.

## 5. Componentes

### 5.1 Mirror local de tasas (`KrayinFinancialReports`)

- **Tabla `exchange_rates`** (migración ya escrita, sin cambios): `date` único, `currency_from='USD'`, `currency_to='PYG'`, `rate` (cierre del día), `source` (`manual`/`bcp`), timestamps.
- **Servicio fetcher BCP**: encapsula la consulta a la web del BCP y devuelve la cotización referencial de cierre. Aislado detrás de una interfaz para poder testear/cambiar fuente.
- **Comando `exchange-rates:poll`**: corre **solo días hábiles, por la tarde** (después de 13:00). Hace **upsert por fecha** con la última lectura exitosa. Programado en el scheduler 2–4×/día como **reintento por resiliencia** (si el BCP está caído en un horario). No pollea finde/feriado.
- **Comando `exchange-rates:backfill {year}`**: carga única del histórico del año desde la página de Histórico Anual del BCP. Idempotente (upsert por fecha).
- **`ExchangeRate::getRateForDate($date)`** (mejorar el actual, que solo hace match exacto): cascada **fecha exacta → último día hábil previo disponible**. `getLatestRate()` se mantiene.

### 5.2 Conversión denormalizada

- **Listener** en `lead.create.after` / `lead.update.after` (mismos hooks que usa `KrayinNetValue`): cuando hay `net_value` y `created_at`, calcula `usd_rate = getRateForDate(date(created_at))` y `total_usd = net_value / usd_rate`, y los persiste en las columnas existentes `leads.usd_rate` y `leads.total_usd`. Siempre lee del mirror local.
  - **Ubicación:** el listener vive en `KrayinFinancialReports` (junto al mirror `exchange_rates` y al resolver `getRateForDate`). **Dependencia de orden:** debe ejecutarse **después** de que `net_value` ya esté persistido en el lead (lo puebla el `LeadSaveListener` de `KrayinNetValue` desde el EAV `custom_net_value`). El plan debe garantizar ese orden (prioridad de listeners o lectura directa del valor neto).
- **Comando `leads:backfill-usd {year}`**: recalcula `usd_rate`/`total_usd` para los leads ganados (`stage='won'`) del año. Idempotente. **Soporta el re-sync masivo** porque solo lee del mirror local.
- **Edge case:** lead cuya fecha de pedido aún no tiene tasa en el mirror (p. ej. pedido de hoy antes de que corra el poll de la tarde) → usa fallback al último día hábil; una corrida posterior de `leads:backfill-usd` lo reajusta cuando exista la tasa del día.

### 5.3 Reporte + UI (`KrayinFinancialReports`)

- **`FinancialReportController`**: además de los agregados PYG actuales (que ya usan `net_value` — cambio `lead_value→net_value` ya hecho y se incluye), agrega los equivalentes USD: `SUM(total_usd)` anual, USD del mes, y serie mensual USD. Pasa ambas monedas a la vista.
- **`index.blade.php`**: **toggle Vue PYG/USD** que alterna KPIs, chart (`v-monthly-sales-chart`) y tablas entre los valores PYG (`net_value`/`total_amount`) y USD (`total_usd`). Estado local en Vue, arranca en PYG, no persiste.

## 6. Flujo de datos

```
BCP (web)
  └─ exchange-rates:poll (días hábiles PM, reintentos)  ─┐
  └─ exchange-rates:backfill {year} (histórico anual)   ─┴─> exchange_rates (mirror local)
                                                                   │
Plugin WC → lead (created_at = fecha pedido, gracias al fix §4)    │
                                                                   ▼
            listener lead.create/update.after ── getRateForDate(created_at) ──> usd_rate, total_usd en el lead
            leads:backfill-usd {year} (retroactivo / re-sync) ─────┘
                                                                   │
                                                                   ▼
            FinancialReportController (SUM net_value / SUM total_usd) ──> index.blade (toggle PYG/USD)
```

## 7. Manejo de errores

- **BCP no disponible** en una corrida del poll: se loguea y se reintenta en la siguiente corrida del día (resiliencia por diseño). No afecta sync ni reportes (leen del mirror).
- **Fecha sin tasa** al convertir: fallback a último día hábil previo. Nunca deja un lead ganado sin `total_usd` mientras exista al menos una tasa previa en el mirror.
- **Mirror vacío** (antes del primer backfill): `getRateForDate` devuelve null → el listener no escribe `total_usd` (queda null); `leads:backfill-usd` lo completa una vez cargado el histórico.

## 8. Testing

- **Unit:** `getRateForDate` (match exacto, fallback a hábil previo, mirror vacío); cálculo `total_usd = net_value / rate`.
- **Unit:** parser del fetcher BCP contra HTML/respuesta de ejemplo (fixture), sin red.
- **Integración:** fix de `created_at` — crear lead vía repo con `created_at` pasado y verificar que se preserva (regresión del bug §4).
- **Integración:** `leads:backfill-usd` recalcula correctamente sobre un set de leads + tasas de prueba.
- **Idempotencia:** correr `exchange-rates:backfill` y `leads:backfill-usd` dos veces no duplica ni cambia resultados.

## 9. Fuera de alcance (YAGNI)

- Persistencia de la moneda elegida en el toggle.
- Distinción AM/PM (no existe en la fuente oficial).
- Otras monedas además de USD/PYG.
- Poblar `branch`/`sale_variation`/`order_type` (columnas de branch_fields que el plugin aún no envía) — no son parte de este feature.
- Integración de fuentes alternativas (Frankfurter, etc.) — descartadas (Frankfurter no tiene PYG).
