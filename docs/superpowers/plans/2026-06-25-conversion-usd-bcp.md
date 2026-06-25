# Conversión USD en Reportes Financieros (cotización BCP) — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar los ingresos del reporte financiero también en USD, convertidos con la cotización oficial de cierre del BCP del día del pedido, a partir de un mirror local de tasas y un backfill programado.

**Architecture:** Un mirror local de tasas BCP (`exchange_rates`) en `KrayinNetValue` con su resolver y comandos (`poll`, `backfill`); la conversión a USD se denormaliza en `leads.usd_rate`/`leads.total_usd` mediante un comando `leads:backfill-usd` programado. `KrayinFinancialReports` solo lee `total_usd` y agrega un toggle PYG/USD. Prerequisito: arreglar el orden de providers para que el lead conserve `created_at` = fecha del pedido.

**Tech Stack:** PHP 8.2+, Laravel 11 (Krayin CRM), Eloquent, Pest 10, Laravel HTTP client, Vue 3 + Chart.js (ya presente en la vista).

## Global Constraints

- PHP 8.2+, PSR-12.
- No tocar el core de Krayin (`packages/Webkul`, `vendor/krayin`). Todo en paquetes propios.
- Namespaces: `CarlVallory\KrayinNetValue\`, `CarlVallory\KrayinFinancialReports\`. WordPress: prefijo `muci_` (no aplica aquí).
- Tests con Pest, en `laravel-crm/tests/Unit` y `laravel-crm/tests/Feature`, corren desde `/home/vallory/code/crm/laravel-crm`.
- Commitear cada tarea en el repo del paquete correspondiente (todos tienen git, aun sin remote).
- Tabla `exchange_rates`: `currency_from='USD'`, `currency_to='PYG'`, una fila por `date`, `source='bcp'`.
- Tasa = PYG por 1 USD. Conversión: `total_usd = net_value / rate`.
- Fallback de fecha: última fecha disponible `<=` la pedida (= último día hábil previo, porque el mirror solo guarda días hábiles).
- Paleta/fuente de marca (si se genera UI nueva con estilos): `#F17DB1 #00B26B #000000 #6950A1 #F37043`, fuente Poppins Bold. (El toggle reusa los estilos existentes de la vista.)

---

### Task 1: Fix prerequisito — preservar `created_at` (orden de providers)

**Repo:** `laravel-crm/packages/CarlVallory/KrayinWoocommerce`

**Files:**
- Modify: `packages/CarlVallory/KrayinWoocommerce/src/Providers/KrayinWoocommerceServiceProvider.php`
- Test: `laravel-crm/tests/Feature/CreatedAtPreservationTest.php`

**Interfaces:**
- Consumes: modelo custom `CarlVallory\KrayinWoocommerce\Models\Lead` (ya existe, tiene `created_at` en `$fillable`).
- Produces: tras el fix, `app(\Webkul\Lead\Contracts\Lead::class)` resuelve al modelo custom y `LeadRepository->create([...,'created_at'=>X])` preserva `X`.

- [ ] **Step 1: Escribir el test que falla**

Crear `laravel-crm/tests/Feature/CreatedAtPreservationTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;
use Webkul\Lead\Repositories\LeadRepository;

it('preserva el created_at enviado al crear un lead', function () {
    // Pipeline + stage mínimos para el create del repo
    DB::table('lead_pipelines')->updateOrInsert(['id' => 1], [
        'name' => 'Default', 'is_default' => 1, 'rotten_days' => 30,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 1], [
        'code' => 'new', 'name' => 'New', 'lead_pipeline_id' => 1, 'sort_order' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $repo = app(LeadRepository::class);

    expect(get_class($repo->getModel()))
        ->toBe(\CarlVallory\KrayinWoocommerce\Models\Lead::class);

    $past = '2026-01-15 10:30:00';
    $lead = $repo->create([
        'title' => 'TEST created_at', 'lead_value' => 100000, 'status' => 1,
        'lead_pipeline_stage_id' => 1, 'lead_pipeline_id' => 1, 'user_id' => 1,
        'created_at' => $past,
    ]);

    expect($lead->fresh()->created_at->format('Y-m-d H:i:s'))->toBe($past);
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=CreatedAtPreservation`
Expected: FAIL — el modelo resuelto es `Webkul\Lead\Models\Lead` (base) y/o el `created_at` queda con `now()`.

> Fallback si el setup de BD de test es inviable en el entorno: reproducir con tinker (ya verificado el 2026-06-25): registrar el binding post-boot y comprobar que `created_at` se preserva. El test queda igualmente como regresión.

- [ ] **Step 3: Aplicar el fix (registrar el binding después de todos los providers)**

Reemplazar el método `boot()` de `KrayinWoocommerceServiceProvider`:

```php
    public function boot()
    {
        // El módulo Webkul\Lead registra el modelo base en su boot() y, por orden de
        // providers, pisa nuestro modelo custom. Registramos en app->booted() para correr
        // DESPUÉS de todos los providers y ganar el binding (preserva created_at = fecha pedido).
        $this->app->booted(function () {
            $this->app->make('concord')->registerModel(
                \Webkul\Lead\Contracts\Lead::class,
                \CarlVallory\KrayinWoocommerce\Models\Lead::class
            );
        });
    }
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=CreatedAtPreservation`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /home/vallory/code/crm/laravel-crm/packages/CarlVallory/KrayinWoocommerce
git add src/Providers/KrayinWoocommerceServiceProvider.php
git commit -m "fix: registrar modelo Lead custom en app->booted() para preservar created_at (fecha del pedido)"
```

(El test vive en el repo `laravel-crm`; commitearlo aparte: `cd /home/vallory/code/crm/laravel-crm && git add tests/Feature/CreatedAtPreservationTest.php && git commit -m "test: regresión de preservación de created_at en leads"`.)

---

### Task 2: Relocar `exchange_rates` + modelo a KrayinNetValue y agregar el resolver

**Repo:** `laravel-crm/packages/CarlVallory/KrayinNetValue` (origen del WIP: `KrayinFinancialReports`)

**Files:**
- Create: `KrayinNetValue/src/Database/Migrations/2026_04_10_140100_create_exchange_rates_table.php`
- Create: `KrayinNetValue/src/Models/ExchangeRate.php`
- Create: `KrayinNetValue/src/Services/ExchangeRateResolver.php`
- Delete (WIP untracked en FinancialReports): `KrayinFinancialReports/src/Database/Migrations/2026_04_10_140100_create_exchange_rates_table.php`, `KrayinFinancialReports/src/Models/ExchangeRate.php`
- Test: `laravel-crm/tests/Feature/ExchangeRateResolverTest.php`

**Interfaces:**
- Produces:
  - `CarlVallory\KrayinNetValue\Models\ExchangeRate` (tabla `exchange_rates`).
  - `CarlVallory\KrayinNetValue\Services\ExchangeRateResolver::rateForDate(string|\Carbon\Carbon $date): ?float` — devuelve la tasa de cierre USD/PYG de esa fecha o, si no hay, la de la última fecha disponible anterior; `null` si el mirror está vacío hasta esa fecha.

- [ ] **Step 1: Mover la migración a KrayinNetValue**

Crear `KrayinNetValue/src/Database/Migrations/2026_04_10_140100_create_exchange_rates_table.php` (mismo schema del WIP):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('currency_from', 3)->default('USD');
            $table->string('currency_to', 3)->default('PYG');
            $table->decimal('rate', 16, 4);
            $table->string('source', 50)->default('manual'); // manual, bcp
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
```

Borrar el WIP en FinancialReports:
```bash
rm /home/vallory/code/crm/laravel-crm/packages/CarlVallory/KrayinFinancialReports/src/Database/Migrations/2026_04_10_140100_create_exchange_rates_table.php
rm /home/vallory/code/crm/laravel-crm/packages/CarlVallory/KrayinFinancialReports/src/Models/ExchangeRate.php
```

- [ ] **Step 2: Crear el modelo en KrayinNetValue**

Crear `KrayinNetValue/src/Models/ExchangeRate.php`:

```php
<?php

namespace CarlVallory\KrayinNetValue\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $table = 'exchange_rates';

    protected $fillable = ['date', 'currency_from', 'currency_to', 'rate', 'source'];

    protected $casts = [
        'date' => 'date',
        'rate' => 'decimal:4',
    ];
}
```

- [ ] **Step 3: Escribir el test del resolver (falla)**

Crear `laravel-crm/tests/Feature/ExchangeRateResolverTest.php`:

```php
<?php

use CarlVallory\KrayinNetValue\Models\ExchangeRate;
use CarlVallory\KrayinNetValue\Services\ExchangeRateResolver;

beforeEach(function () {
    ExchangeRate::query()->delete();
    ExchangeRate::create(['date' => '2026-01-02', 'rate' => 6719.39, 'source' => 'bcp']);
    ExchangeRate::create(['date' => '2026-01-05', 'rate' => 6800.00, 'source' => 'bcp']);
});

it('devuelve la tasa exacta si existe', function () {
    expect(app(ExchangeRateResolver::class)->rateForDate('2026-01-05'))->toBe(6800.0);
});

it('cae a la última fecha previa disponible (finde/feriado)', function () {
    // 2026-01-03 (sábado) y 2026-01-04 (domingo) no existen → usa 2026-01-02
    expect(app(ExchangeRateResolver::class)->rateForDate('2026-01-04'))->toBe(6719.39);
});

it('devuelve null si no hay tasa hasta esa fecha', function () {
    expect(app(ExchangeRateResolver::class)->rateForDate('2025-12-31'))->toBeNull();
});
```

- [ ] **Step 4: Correr y verificar que falla**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=ExchangeRateResolver`
Expected: FAIL — `ExchangeRateResolver` no existe.

- [ ] **Step 5: Implementar el resolver**

Crear `KrayinNetValue/src/Services/ExchangeRateResolver.php`:

```php
<?php

namespace CarlVallory\KrayinNetValue\Services;

use Carbon\Carbon;
use CarlVallory\KrayinNetValue\Models\ExchangeRate;

class ExchangeRateResolver
{
    /**
     * Tasa de cierre USD/PYG de la fecha dada, o la de la última fecha
     * disponible anterior (último día hábil previo). null si no hay ninguna.
     */
    public function rateForDate(string|Carbon $date): ?float
    {
        $target = Carbon::parse($date)->toDateString();

        $row = ExchangeRate::query()
            ->where('currency_from', 'USD')
            ->where('currency_to', 'PYG')
            ->where('date', '<=', $target)
            ->orderByDesc('date')
            ->first();

        return $row ? (float) $row->rate : null;
    }
}
```

- [ ] **Step 6: Correr y verificar que pasa**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=ExchangeRateResolver`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
cd /home/vallory/code/crm/laravel-crm/packages/CarlVallory/KrayinNetValue
git add src/Database/Migrations/2026_04_10_140100_create_exchange_rates_table.php src/Models/ExchangeRate.php src/Services/ExchangeRateResolver.php
git commit -m "feat: mirror de tasas exchange_rates + ExchangeRateResolver con fallback a último día hábil"
cd /home/vallory/code/crm/laravel-crm/packages/CarlVallory/KrayinFinancialReports
git add -A src/Database src/Models
git commit -m "refactor: mover exchange_rates y ExchangeRate a KrayinNetValue (Opción C)"
cd /home/vallory/code/crm/laravel-crm && git add tests/Feature/ExchangeRateResolverTest.php && git commit -m "test: ExchangeRateResolver (exacto, fallback, vacío)"
```

---

### Task 3: Parser del formato numérico BCP

**Repo:** `KrayinNetValue`

**Files:**
- Create: `KrayinNetValue/src/Services/Bcp/BcpNumberParser.php`
- Test: `laravel-crm/tests/Unit/BcpNumberParserTest.php`

**Interfaces:**
- Produces: `CarlVallory\KrayinNetValue\Services\Bcp\BcpNumberParser::parse(string $raw): ?float` — `"6.719,39" → 6719.39`, `"ND"`/`""` → `null`.

- [ ] **Step 1: Escribir el test (falla)**

Crear `laravel-crm/tests/Unit/BcpNumberParserTest.php`:

```php
<?php

use CarlVallory\KrayinNetValue\Services\Bcp\BcpNumberParser;

it('parsea el formato paraguayo a float', function () {
    expect(BcpNumberParser::parse('6.719,39'))->toBe(6719.39);
    expect(BcpNumberParser::parse('6.011,46'))->toBe(6011.46);
    expect(BcpNumberParser::parse('  7.000,00 '))->toBe(7000.0);
});

it('devuelve null para ND o vacío', function () {
    expect(BcpNumberParser::parse('ND'))->toBeNull();
    expect(BcpNumberParser::parse(''))->toBeNull();
    expect(BcpNumberParser::parse('  '))->toBeNull();
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=BcpNumberParser`
Expected: FAIL — clase no existe.

- [ ] **Step 3: Implementar**

Crear `KrayinNetValue/src/Services/Bcp/BcpNumberParser.php`:

```php
<?php

namespace CarlVallory\KrayinNetValue\Services\Bcp;

class BcpNumberParser
{
    /** "6.719,39" → 6719.39 ; "ND"/"" → null */
    public static function parse(string $raw): ?float
    {
        $raw = trim($raw);

        if ($raw === '' || strtoupper($raw) === 'ND') {
            return null;
        }

        $normalized = str_replace(['.', ','], ['', '.'], $raw);

        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=BcpNumberParser`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /home/vallory/code/crm/laravel-crm/packages/CarlVallory/KrayinNetValue
git add src/Services/Bcp/BcpNumberParser.php
cd /home/vallory/code/crm/laravel-crm && git add tests/Unit/BcpNumberParserTest.php
git -C packages/CarlVallory/KrayinNetValue commit -m "feat: BcpNumberParser (formato 6.719,39 → float)"
git commit -m "test: BcpNumberParser"
```

---

### Task 4: Fetcher BCP (interfaz + implementación HTTP)

**Repo:** `KrayinNetValue`

**Files:**
- Create: `KrayinNetValue/src/Services/Bcp/BcpRateFetcher.php` (contrato/interface)
- Create: `KrayinNetValue/src/Services/Bcp/BcpHttpRateFetcher.php` (implementación)
- Test: `laravel-crm/tests/Feature/BcpHttpRateFetcherTest.php`
- Fixture: `laravel-crm/tests/Fixtures/bcp_anual_2026.html`

**Interfaces:**
- Consumes: `BcpNumberParser::parse()` (Task 3).
- Produces:
  - `CarlVallory\KrayinNetValue\Services\Bcp\BcpRateFetcher` (interface):
    - `fetchYear(int $year): array` → `['YYYY-MM-DD' => float, ...]` solo días con cotización.
    - `fetchLatest(): ?array` → `['date' => 'YYYY-MM-DD', 'rate' => float]` o `null`.
  - `BcpHttpRateFetcher implements BcpRateFetcher`.

- [ ] **Step 1: Guardar un fixture real de la página anual**

Descargar una vez el HTML de la página anual del BCP y guardarlo como fixture (para tests sin red):

```bash
mkdir -p /home/vallory/code/crm/laravel-crm/tests/Fixtures
curl -s 'https://www.bcp.gov.py/webapps/web/cotizacion/referencial-fluctuante/anual' \
  -o /home/vallory/code/crm/laravel-crm/tests/Fixtures/bcp_anual_2026.html
```

> Durante la implementación: inspeccionar el HTML guardado para confirmar (a) los parámetros del formulario del selector de año y de Compra/Venta, y (b) la estructura de la tabla (filas = días 1-31, columnas = meses ENE..DIC). Usar la columna **Venta** (la que se usa para valorizar ingresos en PYG a USD). Ajustar los selectores del parser del Step 4 contra este fixture.

- [ ] **Step 2: Definir la interface**

Crear `KrayinNetValue/src/Services/Bcp/BcpRateFetcher.php`:

```php
<?php

namespace CarlVallory\KrayinNetValue\Services\Bcp;

interface BcpRateFetcher
{
    /** @return array<string,float> mapa 'YYYY-MM-DD' => tasa, solo días con cotización */
    public function fetchYear(int $year): array;

    /** @return array{date:string,rate:float}|null última cotización disponible */
    public function fetchLatest(): ?array;
}
```

- [ ] **Step 3: Escribir el test contra el fixture (falla)**

Crear `laravel-crm/tests/Feature/BcpHttpRateFetcherTest.php`:

```php
<?php

use CarlVallory\KrayinNetValue\Services\Bcp\BcpHttpRateFetcher;
use CarlVallory\KrayinNetValue\Services\Bcp\BcpRateFetcher;
use Illuminate\Support\Facades\Http;

it('parsea el año desde el HTML del BCP a un mapa fecha→tasa', function () {
    $html = file_get_contents(base_path('tests/Fixtures/bcp_anual_2026.html'));
    Http::fake(['*' => Http::response($html, 200)]);

    $fetcher = app(BcpRateFetcher::class);
    expect($fetcher)->toBeInstanceOf(BcpHttpRateFetcher::class);

    $rates = $fetcher->fetchYear(2026);

    // Días con cotización presentes y parseados; "ND" excluidos.
    expect($rates)->toBeArray()->not->toBeEmpty();
    expect($rates['2026-01-02'])->toBe(6719.39); // ajustar al valor real del fixture
    expect($rates)->not->toHaveKey('2026-04-04'); // ND en el fixture
    foreach ($rates as $date => $rate) {
        expect($date)->toMatch('/^\d{4}-\d{2}-\d{2}$/');
        expect($rate)->toBeFloat();
    }
});
```

> Los valores exactos (`2026-01-02`, `2026-04-04`) se ajustan a lo que contenga el fixture real tras el Step 1.

- [ ] **Step 4: Implementar el fetcher HTTP**

Crear `KrayinNetValue/src/Services/Bcp/BcpHttpRateFetcher.php`. Parseo con `DOMDocument`/`DOMXPath` (sin dependencias nuevas):

```php
<?php

namespace CarlVallory\KrayinNetValue\Services\Bcp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BcpHttpRateFetcher implements BcpRateFetcher
{
    private const URL_ANUAL = 'https://www.bcp.gov.py/webapps/web/cotizacion/referencial-fluctuante/anual';

    public function fetchYear(int $year): array
    {
        $response = Http::asForm()->timeout(30)->post(self::URL_ANUAL, [
            // Confirmar nombres reales de campos contra el fixture (Step 1):
            'anho'  => $year,
            'tipo'  => 'venta',
        ]);

        if (! $response->successful()) {
            Log::warning('BCP fetchYear falló', ['year' => $year, 'status' => $response->status()]);
            return [];
        }

        return $this->parseAnnualTable($response->body(), $year);
    }

    public function fetchLatest(): ?array
    {
        $year  = (int) date('Y');
        $rates = $this->fetchYear($year);

        if (empty($rates)) {
            return null;
        }

        $lastDate = array_key_last($rates);
        return ['date' => $lastDate, 'rate' => $rates[$lastDate]];
    }

    /** Tabla días(1-31) × meses(ENE..DIC) → ['YYYY-MM-DD'=>float] */
    private function parseAnnualTable(string $html, int $year): array
    {
        $rates  = [];
        $dom    = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath  = new \DOMXPath($dom);

        // Selector a confirmar contra el fixture: filas de la tabla de cotizaciones.
        $rows = $xpath->query("//table[contains(@class,'cotizacion') or contains(@id,'cotizacion')]//tr");

        foreach ($rows as $rowIndex => $row) {
            $cells = $xpath->query('.//td', $row);
            if ($cells->length === 0) {
                continue; // header
            }
            $day = (int) trim($cells->item(0)->textContent);
            if ($day < 1 || $day > 31) {
                continue;
            }
            for ($month = 1; $month <= 12; $month++) {
                $cell = $cells->item($month);
                if (! $cell) {
                    continue;
                }
                $value = BcpNumberParser::parse($cell->textContent);
                if ($value === null) {
                    continue; // ND o vacío
                }
                if (! checkdate($month, $day, $year)) {
                    continue;
                }
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $rates[$date] = $value;
            }
        }

        ksort($rates);
        return $rates;
    }
}
```

- [ ] **Step 5: Bindear la interface a la implementación en el provider**

En `KrayinNetValueServiceProvider`, agregar al inicio de `boot()` (o en un `register()`):

```php
        $this->app->bind(
            \CarlVallory\KrayinNetValue\Services\Bcp\BcpRateFetcher::class,
            \CarlVallory\KrayinNetValue\Services\Bcp\BcpHttpRateFetcher::class
        );
```

- [ ] **Step 6: Correr el test y ajustar selectores hasta que pase**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=BcpHttpRateFetcher`
Expected: PASS. Si falla, ajustar el XPath/índices del parser contra el fixture real y los valores esperados del test.

- [ ] **Step 7: Commit**

```bash
cd /home/vallory/code/crm/laravel-crm/packages/CarlVallory/KrayinNetValue
git add src/Services/Bcp/BcpRateFetcher.php src/Services/Bcp/BcpHttpRateFetcher.php src/Providers/KrayinNetValueServiceProvider.php
git commit -m "feat: BcpHttpRateFetcher (parseo del histórico anual del BCP) + binding"
cd /home/vallory/code/crm/laravel-crm
git add tests/Feature/BcpHttpRateFetcherTest.php tests/Fixtures/bcp_anual_2026.html
git commit -m "test: BcpHttpRateFetcher contra fixture del BCP"
```

---

### Task 5: Comando `exchange-rates:backfill {year}`

**Repo:** `KrayinNetValue`

**Files:**
- Create: `KrayinNetValue/src/Console/Commands/BackfillExchangeRates.php`
- Test: `laravel-crm/tests/Feature/BackfillExchangeRatesCommandTest.php`

**Interfaces:**
- Consumes: `BcpRateFetcher::fetchYear()` (Task 4), `ExchangeRate` (Task 2).
- Produces: comando artisan `exchange-rates:backfill {year}` que hace upsert por fecha (`source='bcp'`), idempotente.

- [ ] **Step 1: Escribir el test (falla)**

Crear `laravel-crm/tests/Feature/BackfillExchangeRatesCommandTest.php`:

```php
<?php

use CarlVallory\KrayinNetValue\Models\ExchangeRate;
use CarlVallory\KrayinNetValue\Services\Bcp\BcpRateFetcher;

beforeEach(function () {
    ExchangeRate::query()->delete();

    $fake = Mockery::mock(BcpRateFetcher::class);
    $fake->shouldReceive('fetchYear')->with(2026)->andReturn([
        '2026-01-02' => 6719.39,
        '2026-01-05' => 6800.00,
    ]);
    app()->instance(BcpRateFetcher::class, $fake);
});

it('carga las tasas del año en exchange_rates', function () {
    $this->artisan('exchange-rates:backfill', ['year' => 2026])->assertSuccessful();

    expect(ExchangeRate::count())->toBe(2);
    expect((float) ExchangeRate::where('date', '2026-01-02')->first()->rate)->toBe(6719.39);
});

it('es idempotente (correr dos veces no duplica)', function () {
    $this->artisan('exchange-rates:backfill', ['year' => 2026]);
    $this->artisan('exchange-rates:backfill', ['year' => 2026]);

    expect(ExchangeRate::count())->toBe(2);
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=BackfillExchangeRatesCommand`
Expected: FAIL — comando no existe.

- [ ] **Step 3: Implementar el comando**

Crear `KrayinNetValue/src/Console/Commands/BackfillExchangeRates.php`:

```php
<?php

namespace CarlVallory\KrayinNetValue\Console\Commands;

use CarlVallory\KrayinNetValue\Models\ExchangeRate;
use CarlVallory\KrayinNetValue\Services\Bcp\BcpRateFetcher;
use Illuminate\Console\Command;

class BackfillExchangeRates extends Command
{
    protected $signature = 'exchange-rates:backfill {year}';

    protected $description = 'Carga el histórico anual de cotizaciones USD/PYG del BCP en exchange_rates';

    public function handle(BcpRateFetcher $fetcher): int
    {
        $year  = (int) $this->argument('year');
        $rates = $fetcher->fetchYear($year);

        if (empty($rates)) {
            $this->warn("Sin tasas para {$year} (¿BCP no disponible?).");
            return self::SUCCESS;
        }

        foreach ($rates as $date => $rate) {
            ExchangeRate::updateOrCreate(
                ['date' => $date, 'currency_from' => 'USD', 'currency_to' => 'PYG'],
                ['rate' => $rate, 'source' => 'bcp']
            );
        }

        $this->info(count($rates) . " tasas cargadas para {$year}.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Registrar el comando en el provider**

En `KrayinNetValueServiceProvider::boot()` agregar:

```php
        if ($this->app->runningInConsole()) {
            $this->commands([
                \CarlVallory\KrayinNetValue\Console\Commands\BackfillExchangeRates::class,
            ]);
        }
```

- [ ] **Step 5: Correr y verificar que pasa**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=BackfillExchangeRatesCommand`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
cd /home/vallory/code/crm/laravel-crm/packages/CarlVallory/KrayinNetValue
git add src/Console/Commands/BackfillExchangeRates.php src/Providers/KrayinNetValueServiceProvider.php
git commit -m "feat: comando exchange-rates:backfill {year} (idempotente)"
cd /home/vallory/code/crm/laravel-crm && git add tests/Feature/BackfillExchangeRatesCommandTest.php && git commit -m "test: comando exchange-rates:backfill"
```

---

### Task 6: Comando `exchange-rates:poll`

**Repo:** `KrayinNetValue`

**Files:**
- Create: `KrayinNetValue/src/Console/Commands/PollExchangeRate.php`
- Test: `laravel-crm/tests/Feature/PollExchangeRateCommandTest.php`

**Interfaces:**
- Consumes: `BcpRateFetcher::fetchLatest()` (Task 4), `ExchangeRate` (Task 2).
- Produces: comando `exchange-rates:poll` que hace upsert de la última cotización por su fecha. Tolera BCP no disponible (no falla).

- [ ] **Step 1: Escribir el test (falla)**

Crear `laravel-crm/tests/Feature/PollExchangeRateCommandTest.php`:

```php
<?php

use CarlVallory\KrayinNetValue\Models\ExchangeRate;
use CarlVallory\KrayinNetValue\Services\Bcp\BcpRateFetcher;

beforeEach(fn () => ExchangeRate::query()->delete());

it('guarda la última cotización disponible', function () {
    $fake = Mockery::mock(BcpRateFetcher::class);
    $fake->shouldReceive('fetchLatest')->andReturn(['date' => '2026-06-25', 'rate' => 7100.00]);
    app()->instance(BcpRateFetcher::class, $fake);

    $this->artisan('exchange-rates:poll')->assertSuccessful();

    expect((float) ExchangeRate::where('date', '2026-06-25')->first()->rate)->toBe(7100.0);
});

it('no falla si el BCP no devuelve datos', function () {
    $fake = Mockery::mock(BcpRateFetcher::class);
    $fake->shouldReceive('fetchLatest')->andReturnNull();
    app()->instance(BcpRateFetcher::class, $fake);

    $this->artisan('exchange-rates:poll')->assertSuccessful();
    expect(ExchangeRate::count())->toBe(0);
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=PollExchangeRateCommand`
Expected: FAIL — comando no existe.

- [ ] **Step 3: Implementar el comando**

Crear `KrayinNetValue/src/Console/Commands/PollExchangeRate.php`:

```php
<?php

namespace CarlVallory\KrayinNetValue\Console\Commands;

use CarlVallory\KrayinNetValue\Models\ExchangeRate;
use CarlVallory\KrayinNetValue\Services\Bcp\BcpRateFetcher;
use Illuminate\Console\Command;

class PollExchangeRate extends Command
{
    protected $signature = 'exchange-rates:poll';

    protected $description = 'Consulta al BCP la última cotización USD/PYG y la guarda (resiliente, reintentable)';

    public function handle(BcpRateFetcher $fetcher): int
    {
        $latest = $fetcher->fetchLatest();

        if ($latest === null) {
            $this->warn('BCP sin datos en este momento; se reintentará en la próxima corrida.');
            return self::SUCCESS;
        }

        ExchangeRate::updateOrCreate(
            ['date' => $latest['date'], 'currency_from' => 'USD', 'currency_to' => 'PYG'],
            ['rate' => $latest['rate'], 'source' => 'bcp']
        );

        $this->info("Cotización {$latest['date']}: {$latest['rate']} guardada.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Registrar el comando en el provider**

Agregar `\CarlVallory\KrayinNetValue\Console\Commands\PollExchangeRate::class` al array `$this->commands([...])` del provider (junto al de Task 5).

- [ ] **Step 5: Correr y verificar que pasa**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=PollExchangeRateCommand`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
cd /home/vallory/code/crm/laravel-crm/packages/CarlVallory/KrayinNetValue
git add src/Console/Commands/PollExchangeRate.php src/Providers/KrayinNetValueServiceProvider.php
git commit -m "feat: comando exchange-rates:poll (resiliente)"
cd /home/vallory/code/crm/laravel-crm && git add tests/Feature/PollExchangeRateCommandTest.php && git commit -m "test: comando exchange-rates:poll"
```

---

### Task 7: Comando `leads:backfill-usd {year}` (conversión denormalizada)

**Repo:** `KrayinNetValue`

**Files:**
- Create: `KrayinNetValue/src/Console/Commands/BackfillLeadsUsd.php`
- Test: `laravel-crm/tests/Feature/BackfillLeadsUsdCommandTest.php`

**Interfaces:**
- Consumes: `ExchangeRateResolver::rateForDate()` (Task 2). Lee `leads.net_value`, `leads.created_at`; escribe `leads.usd_rate`, `leads.total_usd`. Filtra leads ganados (join `lead_pipeline_stages.code='won'`).
- Produces: comando `leads:backfill-usd {year}` idempotente; convierte los leads ganados cuyo `created_at` cae en `{year}`.

- [ ] **Step 1: Escribir el test (falla)**

Crear `laravel-crm/tests/Feature/BackfillLeadsUsdCommandTest.php`:

```php
<?php

use CarlVallory\KrayinNetValue\Models\ExchangeRate;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    ExchangeRate::query()->delete();
    ExchangeRate::create(['date' => '2026-01-02', 'rate' => 7000.00, 'source' => 'bcp']);

    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 1], [
        'code' => 'new', 'name' => 'New', 'lead_pipeline_id' => 1, 'sort_order' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

function insertLead(array $attrs): int
{
    return DB::table('leads')->insertGetId(array_merge([
        'title' => 'L', 'lead_value' => 0, 'net_value' => 0, 'status' => 1,
        'lead_pipeline_id' => 1, 'created_at' => '2026-01-04 09:00:00', 'updated_at' => now(),
    ], $attrs));
}

it('calcula usd_rate y total_usd para leads ganados usando la tasa del día del pedido', function () {
    // pedido del domingo 2026-01-04 → usa cierre del 2026-01-02 (7000)
    $id = insertLead(['lead_pipeline_stage_id' => 5, 'net_value' => 7_000_000]);

    $this->artisan('leads:backfill-usd', ['year' => 2026])->assertSuccessful();

    $lead = DB::table('leads')->find($id);
    expect((float) $lead->usd_rate)->toBe(7000.0);
    expect((float) $lead->total_usd)->toBe(1000.0); // 7.000.000 / 7000
});

it('ignora leads no ganados', function () {
    $id = insertLead(['lead_pipeline_stage_id' => 1, 'net_value' => 7_000_000]);

    $this->artisan('leads:backfill-usd', ['year' => 2026])->assertSuccessful();

    expect(DB::table('leads')->find($id)->total_usd)->toBeNull();
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=BackfillLeadsUsdCommand`
Expected: FAIL — comando no existe.

- [ ] **Step 3: Implementar el comando**

Crear `KrayinNetValue/src/Console/Commands/BackfillLeadsUsd.php`:

```php
<?php

namespace CarlVallory\KrayinNetValue\Console\Commands;

use CarlVallory\KrayinNetValue\Services\ExchangeRateResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillLeadsUsd extends Command
{
    protected $signature = 'leads:backfill-usd {year}';

    protected $description = 'Recalcula usd_rate/total_usd de los leads ganados del año usando la tasa de cierre del día del pedido';

    public function handle(ExchangeRateResolver $resolver): int
    {
        $year = (int) $this->argument('year');

        $leads = DB::table('leads')
            ->join('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
            ->where('lead_pipeline_stages.code', 'won')
            ->whereYear('leads.created_at', $year)
            ->whereNotNull('leads.net_value')
            ->select('leads.id', 'leads.net_value', 'leads.created_at')
            ->get();

        $converted = 0;

        foreach ($leads as $lead) {
            $rate = $resolver->rateForDate($lead->created_at);
            if ($rate === null || (float) $rate == 0.0) {
                continue; // sin tasa hasta esa fecha; se reintenta en otra corrida
            }

            DB::table('leads')->where('id', $lead->id)->update([
                'usd_rate'  => $rate,
                'total_usd' => round(((float) $lead->net_value) / $rate, 4),
            ]);
            $converted++;
        }

        $this->info("{$converted} leads convertidos a USD para {$year}.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Registrar el comando en el provider**

Agregar `\CarlVallory\KrayinNetValue\Console\Commands\BackfillLeadsUsd::class` al array `$this->commands([...])`.

- [ ] **Step 5: Correr y verificar que pasa**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=BackfillLeadsUsdCommand`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
cd /home/vallory/code/crm/laravel-crm/packages/CarlVallory/KrayinNetValue
git add src/Console/Commands/BackfillLeadsUsd.php src/Providers/KrayinNetValueServiceProvider.php
git commit -m "feat: comando leads:backfill-usd {year} (conversión denormalizada por fecha de pedido)"
cd /home/vallory/code/crm/laravel-crm && git add tests/Feature/BackfillLeadsUsdCommandTest.php && git commit -m "test: comando leads:backfill-usd"
```

---

### Task 8: Programar los comandos en el scheduler

**Repo:** `laravel-crm`

**Files:**
- Modify: `laravel-crm/app/Console/Kernel.php:schedule()`

**Interfaces:**
- Consumes: comandos `exchange-rates:poll` (Task 6), `leads:backfill-usd` (Task 7).

- [ ] **Step 1: Agregar las entradas al scheduler**

En `app/Console/Kernel.php`, dentro de `schedule(Schedule $schedule)`, agregar (debajo de la línea existente de `inbound-emails:process`):

```php
        // Poll de la cotización BCP: solo días hábiles, a la tarde (la referencial
        // cierra después de las 13:00). Tres corridas como reintento por resiliencia.
        $schedule->command('exchange-rates:poll')->weekdays()->at('14:00');
        $schedule->command('exchange-rates:poll')->weekdays()->at('16:00');
        $schedule->command('exchange-rates:poll')->weekdays()->at('18:00');

        // Conversión USD de los leads del año en curso, todas las noches.
        $schedule->command('leads:backfill-usd ' . date('Y'))->dailyAt('02:00');
```

- [ ] **Step 2: Verificar que el scheduler lista las tareas**

Run: `cd /home/vallory/code/crm/laravel-crm && php artisan schedule:list`
Expected: aparecen `exchange-rates:poll` (3 horarios, weekdays) y `leads:backfill-usd`.

- [ ] **Step 3: Commit**

```bash
cd /home/vallory/code/crm/laravel-crm
git add app/Console/Kernel.php
git commit -m "feat: programar exchange-rates:poll (hábiles PM) y leads:backfill-usd (nightly)"
```

---

### Task 9: Agregados USD en el FinancialReportController

**Repo:** `KrayinFinancialReports`

**Files:**
- Modify: `KrayinFinancialReports/src/Http/Controllers/FinancialReportController.php:index()`
- Test: `laravel-crm/tests/Feature/FinancialReportUsdTest.php`

**Interfaces:**
- Consumes: columnas `leads.net_value`, `leads.total_usd` (pobladas por Task 7).
- Produces: la vista recibe, además de los PYG actuales: `totalRevenueUsd`, `thisMonthRevenueUsd`, `chartDataUsd` (array de 12 floats).

- [ ] **Step 1: Escribir el test (falla)**

Crear `laravel-crm/tests/Feature/FinancialReportUsdTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $year = date('Y');
    DB::table('leads')->insert([
        'title' => 'L1', 'lead_value' => 0, 'net_value' => 7_000_000, 'total_usd' => 1000,
        'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
        'created_at' => "{$year}-01-04 09:00:00", 'closed_at' => "{$year}-01-04 09:00:00",
        'updated_at' => now(),
    ]);
});

it('expone los agregados USD a la vista', function () {
    $this->actingAs(\Webkul\User\Models\User::first() ?? \Webkul\User\Models\User::factory()->create());

    $response = $this->get(route('krayin.financial-reports.index'));

    $response->assertOk();
    $response->assertViewHas('totalRevenueUsd', 1000.0);
    $response->assertViewHas('chartDataUsd');
});
```

> Si no hay factory de `User` en el core, sembrar un admin con `DB::table('users')->insert([...])` y `actingAs` por id. Ajustar al patrón de auth de Krayin del entorno.

- [ ] **Step 2: Correr y verificar que falla**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=FinancialReportUsd`
Expected: FAIL — la vista no recibe `totalRevenueUsd`.

- [ ] **Step 3: Implementar los agregados USD**

En `FinancialReportController::index()`, después de los cálculos PYG existentes y antes del `return view(...)`, agregar:

```php
        // KPIs en USD (Valor Neto convertido, denormalizado en leads.total_usd)
        $totalRevenueUsd = (clone $wonLeadsQuery)->sum('total_usd');

        $thisMonthRevenueUsd = (clone $wonLeadsQuery)
            ->whereMonth('leads.closed_at', date('m'))
            ->sum('total_usd');

        $monthlySalesUsd = (clone $wonLeadsQuery)
            ->selectRaw('MONTH(leads.closed_at) as month, SUM(total_usd) as total')
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();

        $chartDataUsd = [];
        for ($i = 1; $i <= 12; $i++) {
            $chartDataUsd[] = (float) ($monthlySalesUsd[$i] ?? 0);
        }
```

Y extender el `compact(...)` del `return view('krayin-financial-reports::index', ...)`:

```php
        return view('krayin-financial-reports::index', compact(
            'totalRevenue', 'totalWonLeads', 'thisMonthRevenue', 'chartData', 'recentLeads', 'customSections',
            'totalRevenueUsd', 'thisMonthRevenueUsd', 'chartDataUsd'
        ));
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `cd /home/vallory/code/crm/laravel-crm && ./vendor/bin/pest --filter=FinancialReportUsd`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /home/vallory/code/crm/laravel-crm/packages/CarlVallory/KrayinFinancialReports
git add src/Http/Controllers/FinancialReportController.php
git commit -m "feat: agregados USD (total/mes/serie mensual) en el reporte financiero"
cd /home/vallory/code/crm/laravel-crm && git add tests/Feature/FinancialReportUsdTest.php && git commit -m "test: agregados USD del reporte financiero"
```

> Este commit del controller incluye el cambio ya presente `lead_value → net_value` (WIP previo) en los KPIs PYG.

---

### Task 10: Toggle PYG/USD en la vista

**Repo:** `KrayinFinancialReports`

**Files:**
- Modify: `KrayinFinancialReports/src/Resources/views/index.blade.php`

**Interfaces:**
- Consumes: variables de vista `totalRevenue`, `thisMonthRevenue`, `chartData` (PYG) y `totalRevenueUsd`, `thisMonthRevenueUsd`, `chartDataUsd` (USD) de Task 9.

- [ ] **Step 1: Envolver el reporte en un componente Vue con estado de moneda**

Al inicio del contenido (dentro del layout), agregar un toggle y exponer ambos sets de datos. Encima del bloque de KPI cards, insertar:

```blade
    <div class="flex items-center gap-2 mb-3">
        <button onclick="window.dispatchEvent(new CustomEvent('set-currency',{detail:'PYG'}))"
                class="secondary-button">PYG</button>
        <button onclick="window.dispatchEvent(new CustomEvent('set-currency',{detail:'USD'}))"
                class="secondary-button">USD</button>
        <span class="text-sm text-gray-500">Moneda: <span id="currency-label">PYG</span></span>
    </div>
```

- [ ] **Step 2: Reemplazar los valores fijos de los KPI por valores reactivos**

Cambiar los KPI de ingresos para que muestren PYG o USD según la moneda. Reemplazar el contenido de la card "Ingresos Totales (Año)":

```blade
                    <div class="flex items-center gap-1.5 overflow-hidden text-3xl font-bold text-gray-800 dark:text-white">
                        <span data-pyg>{{ core()->formatBasePrice($totalRevenue) }}</span>
                        <span data-usd style="display:none">USD {{ number_format($totalRevenueUsd, 2) }}</span>
                    </div>
```

Y análogo para "Ingresos Este Mes":

```blade
                    <div class="flex items-center gap-1.5 overflow-hidden text-3xl font-bold text-gray-800 dark:text-white">
                        <span data-pyg>{{ core()->formatBasePrice($thisMonthRevenue) }}</span>
                        <span data-usd style="display:none">USD {{ number_format($thisMonthRevenueUsd, 2) }}</span>
                    </div>
```

- [ ] **Step 3: Hacer el chart reactivo y manejar el toggle**

En el `@push('scripts')`, pasar ambos datasets al componente y reaccionar al evento. Reemplazar el bloque `data()` del componente `v-monthly-sales-chart` y agregar el manejo del evento:

```javascript
                data() {
                    return {
                        chart: undefined,
                        chartDataPyg: @json($chartData),
                        chartDataUsd: @json($chartDataUsd),
                        currency: 'PYG',
                    }
                },

                mounted() {
                    this.prepare();
                    window.addEventListener('set-currency', (e) => {
                        this.currency = e.detail;
                        document.getElementById('currency-label').textContent = e.detail;
                        document.querySelectorAll('[data-pyg]').forEach(el => el.style.display = e.detail === 'PYG' ? '' : 'none');
                        document.querySelectorAll('[data-usd]').forEach(el => el.style.display = e.detail === 'USD' ? '' : 'none');
                        this.chart.data.datasets[0].data = e.detail === 'USD' ? this.chartDataUsd : this.chartDataPyg;
                        this.chart.data.datasets[0].label = 'Ventas (' + e.detail + ')';
                        this.chart.update();
                    });
                },
```

- [ ] **Step 4: Verificación manual en el navegador**

Run: levantar el CRM y abrir el reporte financiero (`/admin/.../financial-reports`). Verificar que el toggle PYG/USD cambia los KPIs de ingresos y el dataset del chart, y que el label de moneda se actualiza. (Usar la skill `run` / chrome-devtools si está disponible.)
Expected: al pulsar USD, los ingresos muestran el equivalente en dólares y el chart cambia de serie; al pulsar PYG, vuelve a guaraníes.

- [ ] **Step 5: Commit**

```bash
cd /home/vallory/code/crm/laravel-crm/packages/CarlVallory/KrayinFinancialReports
git add src/Resources/views/index.blade.php
git commit -m "feat: toggle PYG/USD en el reporte financiero (KPIs + chart)"
```

---

## Self-Review

**1. Spec coverage:**
- §4 fix `created_at` → Task 1. ✔
- §5.1 mirror (tabla, fetcher, poll, backfill, resolver) → Tasks 2, 3, 4, 5, 6. ✔
- §5.2 conversión por backfill programado → Tasks 7, 8. ✔
- §5.3 reporte + toggle → Tasks 9, 10. ✔
- §5 cambio `lead_value→net_value` → incluido en commit de Task 9. ✔
- §7 manejo de errores (BCP caído, fecha sin tasa, mirror vacío) → cubierto en Tasks 5/6 (tolerancia) y 7 (skip sin tasa). ✔
- §8 testing (resolver, parser fixture, regresión created_at, backfill-usd, idempotencia) → Tasks 1-7. ✔
- §9 endpoint REST diferido → no se implementa (correcto); resolver detrás de interfaz limpia (`ExchangeRateResolver`). ✔

**2. Placeholder scan:** Sin "TBD"/"TODO". Los valores del fixture BCP (Task 4) y el patrón de auth (Task 9) tienen instrucción explícita de ajuste contra el entorno real — no son placeholders de lógica.

**3. Type consistency:**
- `ExchangeRateResolver::rateForDate(string|Carbon): ?float` — definido en Task 2, usado igual en Task 7. ✔
- `BcpRateFetcher::fetchYear(int): array` / `fetchLatest(): ?array` — Task 4, usados en Tasks 5/6. ✔
- `BcpNumberParser::parse(string): ?float` — Task 3, usado en Task 4. ✔
- Variables de vista `totalRevenueUsd`/`thisMonthRevenueUsd`/`chartDataUsd` — Task 9, consumidas en Task 10. ✔

## Notas de orden y dependencias

- **Task 1** es independiente y puede ir primera (desbloquea la fecha de pedido correcta).
- **Task 2** debe ir antes de 3-7 (provee modelo + resolver).
- **Task 4** depende de 3; **5 y 6** dependen de 4; **7** depende de 2.
- **8** depende de 6 y 7; **9** antes de **10**.
- **Task 9/10** (reporte) pueden mostrarse antes de tener datos reales, pero los KPIs USD recién tienen valores tras correr `exchange-rates:backfill` + `leads:backfill-usd` en un entorno con leads.
