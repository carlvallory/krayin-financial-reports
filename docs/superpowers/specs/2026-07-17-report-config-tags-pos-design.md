# Diseño — Configuración de Informes: Product Tags + Puntos de Venta (Tareas 2.0, Sub-proyecto A)

- **Fecha:** 2026-07-17
- **Estado:** Aprobado (brainstorming). Pendiente: plan de implementación.
- **Paquete:** `carlvallory/krayin-financial-reports` (extiende la página `configure` existente).
- **Contexto:** primer sub-proyecto del bloque **Dashboard "Tareas 2.0"** (ROADMAP §C). Es la **fundación de configuración** que después consumirá el dashboard (Sub-proyecto C) y que complementa la captura del plugin (Sub-proyecto B).

---

## 1. Contexto y objetivo

El dashboard de Tareas 2.0 necesita clasificar ventas por **sucursal/venue** (San Cosmos, Tatakualab, entradas especiales) y por **punto de venta de merch** (Giftshop, Tatakuashop). Esa clasificación depende de dos mapeos que hoy no existen en el CRM:

1. **Product Tags:** qué productos pertenecen a cada venue/categoría (San Cosmos, Tatakualab, Programación Especial, Ciencia a Cielo Abierto, futuros…).
2. **Puntos de Venta:** qué caja física (`_fooeventspos_user_id` de FooEvents POS) corresponde a qué sucursal y a qué punto de merch.

Este sub-proyecto entrega **solo la configuración** (UI + persistencia). El consumo (agrupaciones del dashboard) es el Sub-proyecto C.

**Estado actual (verificado en código):** la página `configure` de `KrayinFinancialReports` ya guarda un `custom_sections` (título → lista de `product_id` del CRM) en `core_config`, y el `index` agrega `lead_products` por esos IDs. Product Tags es la **generalización** de ese patrón; Puntos de Venta es nuevo.

## 2. Alcance

### Dentro del alcance
- Tab **"Product Tags"** en la página `configure`: crear tags de nombre libre y asignarles productos del CRM (multi-select buscable por nombre + SKU). **Muchos-a-muchos** (un producto puede estar en varios tags).
- Tab **"Puntos de Venta"** en la página `configure`: filas configurables `{ WC User ID, Sucursal, Punto de merch }`, expandible a futuras cajas.
- Persistencia en `core_config` (JSON), un solo guardado que cubre las 3 configs (custom_sections + product_tags + points_of_sale).
- Carga de la config existente al abrir la página.
- Todo en el paquete `KrayinFinancialReports`; **no se toca el core/fork**.

### Fuera del alcance (otros sub-proyectos)
- **Consumo** de estos mapeos en el dashboard (agrupación por sucursal/variación, merch por punto de venta, top-sellers, KPIs) → Sub-proyecto C.
- **Captura estructurada desde el plugin** (`branch`/`sale_variation`/`order_type`) → Sub-proyecto B.
- Migrar el `index` actual de `custom_sections` a Product Tags → Sub-proyecto C (acá `custom_sections` queda intacto y funcionando).
- Tablas dedicadas / modelos Eloquent para tags o POS (se eligió JSON en `core_config`, YAGNI para el volumen).
- Definir en el CRM cómo el plugin valúa los leads (`price_calc_model`/`deduct_gateway_fee`) → ROADMAP C.4 P2, más adelante.

## 3. Arquitectura y almacenamiento

Todo dentro de `KrayinFinancialReports`. La página `configure` pasa a ser **tabbed**: Custom Sections (existente) + Product Tags + Puntos de Venta. Un único formulario, un único botón "Guardar" que persiste las tres claves.

**Claves `core_config` (JSON):**

| Clave | Forma | Ejemplo |
|-------|-------|---------|
| `krayin_financial_reports.settings.custom_sections` | (existente, intacta) `{ "1": {"title": "...", "products": [id,...]}, ... }` | — |
| `krayin_financial_reports.settings.product_tags` | `{ "<tagName>": [crmProductId, ...], ... }` | `{ "San Cosmos": [12, 13], "Tatakualab": [40], "Ciencia a Cielo Abierto": [55] }` |
| `krayin_financial_reports.settings.points_of_sale` | `[ { "wc_user_id": <int>, "sucursal": "<str>", "merch_point": "<str>" }, ... ]` | `[ {"wc_user_id":729,"sucursal":"San Cosmos","merch_point":"Giftshop"}, {"wc_user_id":3,"sucursal":"Tatakualab","merch_point":"Tatakuashop"} ]` |

**Notas de modelo:**
- **Product Tags** guarda `product_id` del **CRM** (los que agrega `lead_products.product_id`), elegidos de la lista del `ProductRepository`. No se escriben IDs de WooCommerce a mano.
- **Muchos-a-muchos**: un mismo `product_id` puede aparecer en varias listas de tags.
- **"Entradas especiales"** NO es un tag que se asigna: es **derivado** en el dashboard (Sub-proyecto C) = tickets FooEvents sin tag "San Cosmos" ni "Tatakualab". Se documenta acá pero no se implementa la derivación en este sub-proyecto.
- **Puntos de Venta**: `wc_user_id` nulo (venta web) = "Online" y el user Admin (p. ej. `2`) = excluido → se resuelven en el dashboard (Sub-proyecto C), no en esta config.

## 4. Persistencia mediante `core_config` (patrón del paquete)

El guardado sigue el patrón ya presente en `storeConfiguration()`: `Webkul\Core\Models\CoreConfig::updateOrCreate(['code'=>$code], ['value'=>json_encode($value)])` por cada clave. La lectura sigue el patrón de `configure()`/`index()`: `core()->getConfigData($code)` + `json_decode` si viene string.

> **Regla dura:** cambios de datos solo por este mecanismo del paquete; nunca SQL manual ni edición del core/fork.

## 5. Flujo de datos

```
Admin → Informes → Configurar → tabs
   ├── Product Tags:  nombre de tag + multi-select de productos (CRM)
   └── Puntos de Venta: filas {WC User ID, Sucursal, Punto de merch}
        ↓ (Guardar → un POST)
   storeConfiguration() valida y persiste las 3 claves en core_config (JSON)
        ↓
   Sub-proyecto C (dashboard) lee product_tags + points_of_sale para clasificar y agrupar
```

## 6. Controller, rutas y vista

- **`FinancialReportController@configure`**: además de `products` + `custom_sections`, carga `product_tags` (default `{}`) y `points_of_sale` (default `[]`) y los pasa a la vista.
- **`FinancialReportController@storeConfiguration`**: se extiende para validar y persistir también `product_tags` y `points_of_sale`, además del `custom_sections` actual (que se mantiene). Un solo POST a la ruta existente `krayin.financial-reports.configure.store`. Sin rutas nuevas.
- **Vista `configure.blade.php`**: se reorganiza en tabs. El tab Product Tags reusa el listado de productos (nombre + SKU) para el multi-select; el tab Puntos de Venta es una tabla con filas agregables/eliminables (JS mínimo, sin framework). Identidad MuCi si aplica (Poppins Bold, paleta) — es UI interna, se mantiene el estilo del admin de Krayin.

## 7. Casos borde / validación

- **Product Tags:**
  - Nombre de tag vacío → esa entrada se ignora al guardar (no se persiste tag sin nombre).
  - Tag sin productos → se permite (tag vacío válido; el dashboard simplemente no clasifica nada bajo él).
  - Nombres de tag duplicados → se normaliza a "último gana" (clave de objeto JSON única) — documentado; la UI puede advertir.
  - `product_id` inexistente → se persiste igual (no rompe; el join en el dashboard simplemente no matchea).
- **Puntos de Venta:**
  - `wc_user_id` debe ser entero; `sucursal` y `merch_point` requeridos por fila; filas incompletas se descartan al guardar.
  - `wc_user_id` duplicado → validación rechaza (una caja = una fila).
- **Sin config previa** → defaults (`{}` / `[]`); la página abre vacía sin error.

## 8. Testing (Pest + DatabaseTransactions, guard `user`)

- `storeConfiguration()` persiste `product_tags` como JSON correcto, incluyendo **un product_id en más de un tag** (multi-tag).
- `storeConfiguration()` persiste `points_of_sale` como JSON (lista de objetos con los 3 campos).
- `storeConfiguration()` descarta tags sin nombre y filas de POS incompletas; rechaza `wc_user_id` no entero / duplicado.
- `configure()` carga la config existente (tags + POS) y la expone a la vista.
- **No-regresión:** `custom_sections` se sigue guardando y leyendo igual que antes; el `index` no se ve afectado.

## 9. Referencias

- Molde de persistencia/lectura: `src/Http/Controllers/FinancialReportController.php` (`configure`, `storeConfiguration`, `index` con `custom_sections`).
- Vista base: `src/Resources/views/configure.blade.php`.
- Decisiones de dominio (sucursal por `_fooeventspos_user_id` 729/3/null; ticket vs merch por `WooCommerceEventsEvent=='Event'`): `CRM-Dashboard-04-10-2026.md`.
- Estado del bloque: `ROADMAP.md` §C (C.4 = este sub-proyecto).
