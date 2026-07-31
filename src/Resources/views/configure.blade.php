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
