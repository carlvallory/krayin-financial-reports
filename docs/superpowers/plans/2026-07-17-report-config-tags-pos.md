# Configuración de Informes: Product Tags + Puntos de Venta — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar a la página `configure` de `KrayinFinancialReports` dos configuraciones nuevas —Product Tags (muchos-a-muchos producto↔tag) y Puntos de Venta (WC User ID → sucursal → punto de merch)— persistidas como JSON en `core_config`, sin romper el `custom_sections` existente.

**Architecture:** Se extiende `FinancialReportController` (`configure` carga las 3 configs; `storeConfiguration` valida y persiste las 3 en un solo POST) y se reorganiza `configure.blade.php` en tabs. Almacenamiento vía `Webkul\Core\Models\CoreConfig` (JSON), mismo patrón que el `custom_sections` actual. El consumo (dashboard) es otro sub-proyecto.

**Tech Stack:** Laravel (Krayin CRM), Blade + JS vanilla mínimo, `core_config`, Pest + DatabaseTransactions, MariaDB.

## Global Constraints

- **Namespace/paquete:** `CarlVallory\KrayinFinancialReports`. **NO tocar core/fork** (`packages/Webkul/*`, `config/*`, `vendor/*`).
- **Persistencia:** solo vía `Webkul\Core\Models\CoreConfig` (JSON). Nunca SQL manual.
- **Claves `core_config`:** `krayin_financial_reports.settings.product_tags` (objeto `{nombre: [productIdCRM,...]}`), `krayin_financial_reports.settings.points_of_sale` (lista `[{wc_user_id:int, sucursal:str, merch_point:str}]`). El `custom_sections` existente queda **intacto**.
- **product_id = del CRM** (los que agrega `lead_products.product_id`), elegidos de `ProductRepository`. Nunca IDs de WooCommerce a mano.
- **Muchos-a-muchos:** un `product_id` puede estar en varios tags.
- **Tests:** en `laravel-crm/tests/Feature/FinancialReports/`, `uses(DatabaseTransactions::class)`, guard `user` (`actingAs(User::find(1),'user')`). Correr con `./vendor/bin/pest` desde `laravel-crm/`.
- **Sin salarios.** Identidad MuCi si se agrega algo visual, manteniendo el estilo del admin de Krayin.

---

## Task 1: `storeConfiguration` persiste `product_tags`

**Files:**
- Modify: `packages/CarlVallory/KrayinFinancialReports/src/Http/Controllers/FinancialReportController.php` (`storeConfiguration` + helper `saveConfig`)
- Test: `laravel-crm/tests/Feature/FinancialReports/ConfigProductTagsTest.php`

**Interfaces:**
- Consumes: request field `product_tags` = `[ ['name'=>string, 'products'=>[int,...]], ... ]`.
- Produces: `core_config['krayin_financial_reports.settings.product_tags']` = JSON de `{ "<name>": [int,...] }`. Helper `saveConfig(string $code, $value): void` (usado también por Task 2).

- [ ] **Step 1: Escribir el test que falla** — `laravel-crm/tests/Feature/FinancialReports/ConfigProductTagsTest.php`

```php
<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Core\Models\CoreConfig;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
});

it('persiste product_tags como mapa nombre→ids, con un producto en varios tags', function () {
    $this->post(route('krayin.financial-reports.configure.store'), [
        'product_tags' => [
            ['name' => 'San Cosmos',            'products' => [12, 13]],
            ['name' => 'Programación Especial', 'products' => [13]], // 13 en dos tags (muchos-a-muchos)
            ['name' => '',                       'products' => [99]], // sin nombre → se descarta
        ],
    ])->assertRedirect(route('krayin.financial-reports.index'));

    $stored = json_decode(
        CoreConfig::where('code', 'krayin_financial_reports.settings.product_tags')->value('value'),
        true
    );

    expect($stored)->toBe([
        'San Cosmos'            => [12, 13],
        'Programación Especial' => [13],
    ]);
});

it('no rompe el custom_sections existente al guardar', function () {
    $this->post(route('krayin.financial-reports.configure.store'), [
        'sections'     => ['1' => ['title' => 'Merch', 'products' => [7]]],
        'product_tags' => [['name' => 'San Cosmos', 'products' => [1]]],
    ])->assertRedirect(route('krayin.financial-reports.index'));

    $sections = json_decode(
        CoreConfig::where('code', 'krayin_financial_reports.settings.custom_sections')->value('value'),
        true
    );

    expect($sections['1']['title'])->toBe('Merch');
    expect($sections['1']['products'])->toBe([7]);
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/FinancialReports/ConfigProductTagsTest.php -v`
Expected: FAIL — el `product_tags` no se persiste (la clave no existe → `$stored` es `null`), y el primer test falla en el `expect(...)->toBe(...)`.

- [ ] **Step 3: Reescribir `storeConfiguration` + agregar helper `saveConfig`** en `src/Http/Controllers/FinancialReportController.php`

Reemplazar el método `storeConfiguration` completo por:

```php
    public function storeConfiguration(\Illuminate\Http\Request $request)
    {
        $data = $request->validate([
            'sections'              => 'nullable|array',
            'sections.*.title'      => 'nullable|string',
            'sections.*.products'   => 'nullable|array',
            'product_tags'          => 'nullable|array',
            'product_tags.*.name'   => 'nullable|string',
            'product_tags.*.products' => 'nullable|array',
        ]);

        // Product tags → mapa {nombre: [productIds del CRM]}, descartando tags sin nombre.
        $tags = [];
        foreach ($data['product_tags'] ?? [] as $tag) {
            $name = trim($tag['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $tags[$name] = array_values(array_map('intval', $tag['products'] ?? []));
        }

        $this->saveConfig('krayin_financial_reports.settings.custom_sections', $data['sections'] ?? []);
        $this->saveConfig('krayin_financial_reports.settings.product_tags', $tags);

        session()->flash('success', 'Configuration saved successfully.');

        return redirect()->route('krayin.financial-reports.index');
    }

    protected function saveConfig(string $code, $value): void
    {
        \Webkul\Core\Models\CoreConfig::updateOrCreate(
            ['code' => $code],
            ['value' => json_encode($value)]
        );
    }
```

> Nota: `sections` pasa de `required` a `nullable` (se puede guardar tags sin tocar secciones). El `custom_sections` se sigue persistiendo con el mismo `code` y forma que antes.

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `./vendor/bin/pest tests/Feature/FinancialReports/ConfigProductTagsTest.php -v`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
cd packages/CarlVallory/KrayinFinancialReports
git add src/Http/Controllers/FinancialReportController.php
git commit -m "feat: persistir product_tags en configure (mapa nombre→ids, multi-tag)"
cd ../../../..
git add tests/Feature/FinancialReports/ConfigProductTagsTest.php
git commit -m "test: persistencia de product_tags en configure"
```

---

## Task 2: `storeConfiguration` persiste `points_of_sale`

**Files:**
- Modify: `packages/CarlVallory/KrayinFinancialReports/src/Http/Controllers/FinancialReportController.php` (`storeConfiguration`)
- Test: `laravel-crm/tests/Feature/FinancialReports/ConfigPointsOfSaleTest.php`

**Interfaces:**
- Consumes: request field `points_of_sale` = `[ ['wc_user_id'=>mixed, 'sucursal'=>string, 'merch_point'=>string], ... ]`.
- Produces: `core_config['krayin_financial_reports.settings.points_of_sale']` = JSON de `[ {"wc_user_id":int,"sucursal":str,"merch_point":str} ]`. Filas incompletas o con `wc_user_id` no entero se descartan; `wc_user_id` duplicado → rechaza el guardado (redirect back con error, sin persistir nada).

- [ ] **Step 1: Escribir el test que falla** — `laravel-crm/tests/Feature/FinancialReports/ConfigPointsOfSaleTest.php`

```php
<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Core\Models\CoreConfig;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
});

it('persiste points_of_sale y descarta filas incompletas o con wc_user_id no entero', function () {
    $this->post(route('krayin.financial-reports.configure.store'), [
        'points_of_sale' => [
            ['wc_user_id' => '729', 'sucursal' => 'San Cosmos', 'merch_point' => 'Giftshop'],
            ['wc_user_id' => '3',   'sucursal' => 'Tatakualab', 'merch_point' => 'Tatakuashop'],
            ['wc_user_id' => '',    'sucursal' => 'Sin ID',     'merch_point' => 'X'],          // incompleta → descartada
            ['wc_user_id' => 'abc', 'sucursal' => 'No entero',  'merch_point' => 'Y'],          // no entero → descartada
            ['wc_user_id' => '5',   'sucursal' => '',           'merch_point' => 'Z'],          // sin sucursal → descartada
        ],
    ])->assertRedirect(route('krayin.financial-reports.index'));

    $stored = json_decode(
        CoreConfig::where('code', 'krayin_financial_reports.settings.points_of_sale')->value('value'),
        true
    );

    expect($stored)->toBe([
        ['wc_user_id' => 729, 'sucursal' => 'San Cosmos', 'merch_point' => 'Giftshop'],
        ['wc_user_id' => 3,   'sucursal' => 'Tatakualab', 'merch_point' => 'Tatakuashop'],
    ]);
});

it('rechaza el guardado si hay wc_user_id duplicado y no persiste points_of_sale', function () {
    $response = $this->post(route('krayin.financial-reports.configure.store'), [
        'points_of_sale' => [
            ['wc_user_id' => '729', 'sucursal' => 'San Cosmos', 'merch_point' => 'Giftshop'],
            ['wc_user_id' => '729', 'sucursal' => 'Duplicado',  'merch_point' => 'Otro'],
        ],
    ]);

    $response->assertRedirect(); // vuelve atrás con error
    $response->assertSessionHas('error');

    expect(CoreConfig::where('code', 'krayin_financial_reports.settings.points_of_sale')->exists())
        ->toBeFalse();
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/FinancialReports/ConfigPointsOfSaleTest.php -v`
Expected: FAIL — `points_of_sale` no se procesa aún (clave inexistente → `$stored` null; no hay rechazo por duplicado).

- [ ] **Step 3: Extender `storeConfiguration` con el bloque de `points_of_sale`**

En `src/Http/Controllers/FinancialReportController.php`, dentro de `storeConfiguration`:

(a) Agregar a las reglas de `$request->validate([...])` (junto a las de `product_tags`):

```php
            'points_of_sale'                => 'nullable|array',
            'points_of_sale.*.wc_user_id'   => 'nullable',
            'points_of_sale.*.sucursal'     => 'nullable|string',
            'points_of_sale.*.merch_point'  => 'nullable|string',
```

(b) Después del bloque que arma `$tags` y ANTES de los `saveConfig(...)`, insertar:

```php
        // Puntos de venta → lista, descartando filas incompletas o con wc_user_id no entero.
        $pos  = [];
        $seen = [];
        foreach ($data['points_of_sale'] ?? [] as $row) {
            $wcUserId = $row['wc_user_id'] ?? '';
            $sucursal = trim($row['sucursal'] ?? '');
            $merch    = trim($row['merch_point'] ?? '');

            if ($sucursal === '' || $merch === '' || ! ctype_digit((string) $wcUserId)) {
                continue;
            }

            $wcUserId = (int) $wcUserId;

            if (in_array($wcUserId, $seen, true)) {
                return redirect()->back()->withInput()
                    ->with('error', 'Hay Puntos de Venta con el mismo WC User ID.');
            }

            $seen[] = $wcUserId;
            $pos[]  = ['wc_user_id' => $wcUserId, 'sucursal' => $sucursal, 'merch_point' => $merch];
        }
```

(c) Agregar el tercer `saveConfig` junto a los otros dos:

```php
        $this->saveConfig('krayin_financial_reports.settings.points_of_sale', $pos);
```

> El `return` temprano ante duplicado corre ANTES de cualquier `saveConfig`, así que un duplicado no persiste nada (ni tags ni sections ni POS) — el segundo test lo verifica sobre `points_of_sale`.

- [ ] **Step 4: Correr los tests (nuevo + Task 1) y verificar que pasan**

Run: `./vendor/bin/pest tests/Feature/FinancialReports/ConfigPointsOfSaleTest.php tests/Feature/FinancialReports/ConfigProductTagsTest.php -v`
Expected: PASS (2 + 2 tests).

- [ ] **Step 5: Commit**

```bash
cd packages/CarlVallory/KrayinFinancialReports
git add src/Http/Controllers/FinancialReportController.php
git commit -m "feat: persistir points_of_sale en configure (filtra incompletos, rechaza wc_user_id duplicado)"
cd ../../../..
git add tests/Feature/FinancialReports/ConfigPointsOfSaleTest.php
git commit -m "test: persistencia de points_of_sale en configure"
```

---

## Task 3: `configure()` carga las nuevas configs + vista con tabs

**Files:**
- Modify: `packages/CarlVallory/KrayinFinancialReports/src/Http/Controllers/FinancialReportController.php` (`configure`)
- Modify: `packages/CarlVallory/KrayinFinancialReports/src/Resources/views/configure.blade.php`
- Test: `laravel-crm/tests/Feature/FinancialReports/ConfigureViewTest.php`

**Interfaces:**
- Consumes: `core_config` de `product_tags` (objeto) y `points_of_sale` (lista); `ProductRepository->all()`.
- Produces: vista `krayin-financial-reports::configure` con tabs "Secciones", "Product Tags", "Puntos de Venta"; variables `$sections`, `$products`, `$productTags` (array), `$pointsOfSale` (array).

- [ ] **Step 1: Escribir el test que falla** — `laravel-crm/tests/Feature/FinancialReports/ConfigureViewTest.php`

```php
<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Core\Models\CoreConfig;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
});

it('la vista configure muestra los tabs de Product Tags y Puntos de Venta', function () {
    $response = $this->get(route('krayin.financial-reports.configure'));

    $response->assertOk();
    $response->assertSee('Product Tags', false);
    $response->assertSee('Puntos de Venta', false);
});

it('la vista configure precarga los tags y puntos de venta guardados', function () {
    CoreConfig::updateOrCreate(
        ['code' => 'krayin_financial_reports.settings.product_tags'],
        ['value' => json_encode(['San Cosmos' => [1, 2]])]
    );
    CoreConfig::updateOrCreate(
        ['code' => 'krayin_financial_reports.settings.points_of_sale'],
        ['value' => json_encode([['wc_user_id' => 729, 'sucursal' => 'San Cosmos', 'merch_point' => 'Giftshop']])]
    );

    $response = $this->get(route('krayin.financial-reports.configure'));

    $response->assertOk();
    $response->assertSee('San Cosmos', false);   // nombre del tag precargado
    $response->assertSee('Giftshop', false);      // merch_point precargado
    $response->assertSee('729', false);           // wc_user_id precargado
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/FinancialReports/ConfigureViewTest.php -v`
Expected: FAIL — la vista actual no tiene "Product Tags" ni "Puntos de Venta".

- [ ] **Step 3: Extender `configure()` para cargar las nuevas configs**

Reemplazar el método `configure()` en `src/Http/Controllers/FinancialReportController.php` por:

```php
    public function configure()
    {
        $products = $this->productRepository->all();

        $sections = core()->getConfigData('krayin_financial_reports.settings.custom_sections');
        if (is_string($sections)) {
            $sections = json_decode($sections, true);
        }
        $sections = $sections ?? [];
        for ($i = 1; $i <= 3; $i++) {
            if (! isset($sections[$i])) {
                $sections[$i] = ['title' => '', 'products' => []];
            }
        }

        $productTags = core()->getConfigData('krayin_financial_reports.settings.product_tags');
        if (is_string($productTags)) {
            $productTags = json_decode($productTags, true);
        }
        $productTags = $productTags ?: [];

        $pointsOfSale = core()->getConfigData('krayin_financial_reports.settings.points_of_sale');
        if (is_string($pointsOfSale)) {
            $pointsOfSale = json_decode($pointsOfSale, true);
        }
        $pointsOfSale = $pointsOfSale ?: [];

        return view('krayin-financial-reports::configure', compact('products', 'sections', 'productTags', 'pointsOfSale'));
    }
```

- [ ] **Step 4: Reescribir la vista con tabs** — `src/Resources/views/configure.blade.php`

```blade
<x-admin::layouts>
    <x-slot:title>
        Configurar Informes Financieros
    </x-slot>

    @php
        // Filas de tags: las guardadas + 3 blancas para agregar. "Quitar" = vaciar el nombre.
        $tagRows = [];
        foreach ($productTags as $name => $ids) {
            $tagRows[] = ['name' => $name, 'products' => $ids];
        }
        for ($i = 0; $i < 3; $i++) {
            $tagRows[] = ['name' => '', 'products' => []];
        }

        // Filas de POS: las guardadas + 3 blancas.
        $posRows = $pointsOfSale;
        for ($i = 0; $i < 3; $i++) {
            $posRows[] = ['wc_user_id' => '', 'sucursal' => '', 'merch_point' => ''];
        }
    @endphp

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap mb-5">
        <p class="text-2xl font-semibold dark:text-white">Configurar Informes</p>
        <div class="flex gap-x-2.5">
            <a href="{{ route('krayin.financial-reports.index') }}"
               class="transparent-button hover:bg-gray-200 dark:hover:bg-gray-800 dark:text-white">
                @lang('admin::app.common.cancel')
            </a>
            <button type="submit" form="configuration-form" class="primary-button">
                @lang('admin::app.common.save')
            </button>
        </div>
    </div>

    @if (session('error'))
        <div class="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    {{-- Tabs --}}
    <div class="mb-4 flex gap-2 border-b border-gray-200 dark:border-gray-800" id="config-tabs">
        <button type="button" data-tab="secciones"  class="config-tab px-4 py-2 text-sm font-semibold">Secciones</button>
        <button type="button" data-tab="tags"       class="config-tab px-4 py-2 text-sm font-semibold">Product Tags</button>
        <button type="button" data-tab="pos"        class="config-tab px-4 py-2 text-sm font-semibold">Puntos de Venta</button>
    </div>

    <form id="configuration-form"
          action="{{ route('krayin.financial-reports.configure.store') }}"
          method="POST" class="flex flex-col gap-4">
        @csrf

        {{-- TAB: Secciones (custom_sections existente) --}}
        <div class="config-panel" data-panel="secciones">
            @foreach ($sections as $key => $section)
                <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">Sección {{ $key }}</p>
                    <div class="mb-4">
                        <label class="mb-1.5 block text-xs font-semibold text-gray-800 dark:text-white">Título de la Sección</label>
                        <input type="text" name="sections[{{ $key }}][title]"
                               value="{{ old('sections.'.$key.'.title', $section['title']) }}"
                               class="w-full rounded border border-gray-200 px-3 py-2.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-gray-800 dark:text-white">Productos</label>
                        <select name="sections[{{ $key }}][products][]" multiple style="height:150px"
                                class="w-full rounded border border-gray-200 px-3 py-2.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}" {{ in_array($product->id, $section['products'] ?? []) ? 'selected' : '' }}>
                                    {{ $product->name }} ({{ $product->sku }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- TAB: Product Tags --}}
        <div class="config-panel hidden" data-panel="tags">
            <p class="mb-2 text-xs text-gray-500">Asigná productos del CRM a cada tag. Un producto puede estar en varios tags. Para quitar un tag, dejá su nombre vacío.</p>
            @foreach ($tagRows as $i => $row)
                <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <div class="mb-4">
                        <label class="mb-1.5 block text-xs font-semibold text-gray-800 dark:text-white">Nombre del Tag</label>
                        <input type="text" name="product_tags[{{ $i }}][name]" value="{{ $row['name'] }}"
                               placeholder="San Cosmos, Tatakualab, Programación Especial…"
                               class="w-full rounded border border-gray-200 px-3 py-2.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-gray-800 dark:text-white">Productos</label>
                        <select name="product_tags[{{ $i }}][products][]" multiple style="height:150px"
                                class="w-full rounded border border-gray-200 px-3 py-2.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}" {{ in_array($product->id, $row['products'] ?? []) ? 'selected' : '' }}>
                                    {{ $product->name }} ({{ $product->sku }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- TAB: Puntos de Venta --}}
        <div class="config-panel hidden" data-panel="pos">
            <p class="mb-2 text-xs text-gray-500">Mapeá cada caja (WC User ID de FooEvents POS) a su sucursal y su punto de merch. Para quitar una fila, vaciá sus campos.</p>
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="p-2 text-left">WC User ID</th>
                            <th class="p-2 text-left">Sucursal</th>
                            <th class="p-2 text-left">Punto de merch</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($posRows as $i => $row)
                            <tr>
                                <td class="p-2"><input type="text" name="points_of_sale[{{ $i }}][wc_user_id]" value="{{ $row['wc_user_id'] }}"
                                    class="w-full rounded border border-gray-200 px-2 py-1.5 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"></td>
                                <td class="p-2"><input type="text" name="points_of_sale[{{ $i }}][sucursal]" value="{{ $row['sucursal'] }}"
                                    class="w-full rounded border border-gray-200 px-2 py-1.5 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"></td>
                                <td class="p-2"><input type="text" name="points_of_sale[{{ $i }}][merch_point]" value="{{ $row['merch_point'] }}"
                                    class="w-full rounded border border-gray-200 px-2 py-1.5 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    {{-- Tabs mínimos, JS vanilla --}}
    <script>
        (function () {
            var tabs   = document.querySelectorAll('#config-tabs .config-tab');
            var panels = document.querySelectorAll('.config-panel');
            function show(name) {
                panels.forEach(function (p) { p.classList.toggle('hidden', p.dataset.panel !== name); });
                tabs.forEach(function (t) { t.classList.toggle('border-b-2', t.dataset.tab === name); });
            }
            tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.tab); }); });
            show('secciones');
        })();
    </script>
</x-admin::layouts>
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `./vendor/bin/pest tests/Feature/FinancialReports/ConfigureViewTest.php -v`
Expected: PASS (2 tests).

- [ ] **Step 6: Correr TODA la suite de FinancialReports + no-regresión general**

Run: `./vendor/bin/pest tests/Feature/FinancialReports/ -v`
Expected: PASS (2 + 2 + 2 = 6 tests). Luego `./vendor/bin/pest` completo para confirmar que el resto sigue verde.

- [ ] **Step 7: Commit**

```bash
cd packages/CarlVallory/KrayinFinancialReports
git add src/Http/Controllers/FinancialReportController.php src/Resources/views/configure.blade.php
git commit -m "feat: tabs de Product Tags y Puntos de Venta en configure (carga + UI)"
cd ../../../..
git add tests/Feature/FinancialReports/ConfigureViewTest.php
git commit -m "test: vista configure con tabs Product Tags y Puntos de Venta"
```

---

## Self-Review

**1. Spec coverage:**
- Tab Product Tags (crear tags, multi-select CRM, muchos-a-muchos) → Task 1 (persistencia) + Task 3 (UI). ✅
- Tab Puntos de Venta (WC User ID/sucursal/merch, expandible) → Task 2 (persistencia) + Task 3 (UI). ✅
- Persistencia JSON en `core_config`, un solo guardado, custom_sections intacto → Task 1 (saveConfig, sections nullable) + Task 2 (POS) + no-regresión test. ✅
- Carga de config existente → Task 3 (`configure()` + test de precarga). ✅
- Casos borde (tag sin nombre descartado, POS incompleto descartado, wc_user_id entero, duplicado rechazado, sin config → defaults) → Tasks 1/2/3 con tests. ✅
- "Entradas especiales" derivado → documentado en spec, fuera de este sub-proyecto (dashboard). ✅ (sin task, correcto)
- Solo paquete, sin core/fork → Global Constraints + rutas de archivos. ✅

**2. Placeholder scan:** sin TBD/TODO; todo el código está completo (controller, blade, JS, tests). ✅

**3. Type consistency:** `saveConfig(string,$value)` definido en Task 1, reutilizado en Task 2. Claves `core_config` idénticas en persistencia (Tasks 1/2) y carga (Task 3): `product_tags` (objeto `{name:[ids]}`), `points_of_sale` (lista `[{wc_user_id,sucursal,merch_point}]`). Nombres de campos de request (`product_tags[i][name|products]`, `points_of_sale[i][wc_user_id|sucursal|merch_point]`) idénticos entre la vista (Task 3) y el controller (Tasks 1/2). ✅

## Riesgos (verificar en ejecución)
- CSRF en tests: `VerifyCsrfToken` se saltea bajo `runningUnitTests()`, así que `$this->post(...)` no necesita token. Si el entorno lo exigiera, usar `withoutMiddleware(VerifyCsrfToken::class)`.
- Guard `user`: el usuario admin `id=1` existe por el seeder base de Krayin (mismo patrón que los tests de Fundraising). Si no existiera, sembrarlo en `beforeEach`.
- `sections` pasa de `required` a `nullable`: verificar que el guardado desde el tab Secciones (sin tags/POS) siga persistiendo igual (cubierto por el 2º test de Task 1).
