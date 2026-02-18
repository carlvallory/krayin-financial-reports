<x-admin::layouts>
    <x-slot:title>
        Configurar Informes Financieros
    </x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap mb-5">
        <div class="grid gap-1.5">
            <p class="text-2xl font-semibold dark:text-white">
                Configurar Secciones Personalizadas
            </p>
        </div>
        <div class="flex gap-x-2.5">
            <a
                href="{{ route('krayin.financial-reports.index') }}"
                class="transparent-button hover:bg-gray-200 dark:hover:bg-gray-800 dark:text-white"
            >
                @lang('admin::app.common.cancel')
            </a>
            
            <button
                type="submit"
                form="configuration-form"
                class="primary-button"
            >
                @lang('admin::app.common.save')
            </button>
        </div>
    </div>

    <form
        id="configuration-form"
        action="{{ route('krayin.financial-reports.configure.store') }}"
        method="POST"
        class="mt-3.5 flex flex-col gap-4"
    >
        @csrf

        @foreach($sections as $key => $section)
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">Sección {{ $key }}</p>

                <div class="mb-4">
                    <label for="sections[{{ $key }}][title]" class="mb-1.5 block text-xs font-semibold leading-none text-gray-800 dark:text-white">
                        Título de la Sección
                    </label>
                    <input
                        type="text"
                        name="sections[{{ $key }}][title]"
                        id="sections[{{ $key }}][title]"
                        value="{{ old('sections.'.$key.'.title', $section['title']) }}"
                        class="w-full rounded border border-gray-200 px-3 py-2.5 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-blue-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-blue-600"
                    >
                </div>

                <div class="mb-4">
                    <label for="sections[{{ $key }}][products]" class="mb-1.5 block text-xs font-semibold leading-none text-gray-800 dark:text-white">
                        Productos
                    </label>
                    
                    <select
                        name="sections[{{ $key }}][products][]"
                        id="sections[{{ $key }}][products]"
                        class="w-full rounded border border-gray-200 px-3 py-2.5 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-blue-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-blue-600"
                        multiple
                        style="height: 150px;"
                    >
                        @foreach($products as $product)
                            <option
                                value="{{ $product->id }}"
                                {{ in_array($product->id, $section['products'] ?? []) ? 'selected' : '' }}
                            >
                                {{ $product->name }} ({{ $product->sku }})
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Mantén presionado Ctrl (Windows) o Command (Mac) para seleccionar múltiples productos.</p>
                </div>
            </div>
        @endforeach
    </form>
</x-admin::layouts>
