<x-admin::layouts>
    <x-slot:title>
        Informes Financieros
    </x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap mb-5">
        <div class="grid gap-1.5">
            <p class="text-2xl font-semibold dark:text-white">
                Informes Financieros ({{ date('Y') }})
            </p>
        </div>
        <div class="flex gap-x-2.5">
            <a
                href="{{ route('krayin.financial-reports.configure') }}"
                class="primary-button"
            >
                Configurar Reportes
            </a>
        </div>
    </div>

    <div class="mt-3.5 flex gap-4 max-xl:flex-wrap">
        <div class="flex flex-1 flex-col gap-4 max-xl:flex-auto">
            
            <!-- KPI Cards -->
            <div class="flex gap-4 max-sm:flex-wrap">
                <!-- Revenue Stats -->
                <div class="relative flex flex-1 flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-center justify-between gap-2.5">
                        <p class="text-base font-semibold text-gray-600 dark:text-gray-300">Ingresos Totales (Año)</p>
                    </div>
                    <div class="flex items-center gap-1.5 overflow-hidden text-3xl font-bold text-gray-800 dark:text-white">
                        {{ core()->formatBasePrice($totalRevenue) }}
                    </div>
                </div>

                <!-- Won Leads Stats -->
                <div class="relative flex flex-1 flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-center justify-between gap-2.5">
                        <p class="text-base font-semibold text-gray-600 dark:text-gray-300">Ventas Ganadas</p>
                    </div>
                    <div class="flex items-center gap-1.5 overflow-hidden text-3xl font-bold text-gray-800 dark:text-white">
                        {{ $totalWonLeads }}
                    </div>
                </div>

                <!-- Monthly Stats -->
                <div class="relative flex flex-1 flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-center justify-between gap-2.5">
                        <p class="text-base font-semibold text-gray-600 dark:text-gray-300">Ingresos Este Mes</p>
                    </div>
                    <div class="flex items-center gap-1.5 overflow-hidden text-3xl font-bold text-gray-800 dark:text-white">
                        {{ core()->formatBasePrice($thisMonthRevenue) }}
                    </div>
                </div>
            </div>

            <!-- Chart Section -->
            <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">Ventas Mensuales</p>
                </div>
                <div class="p-4">
                    <canvas id="monthlySalesChart" style="width: 100%; height: 300px;"></canvas>
                </div>
            </div>

            <!-- Custom Sections -->
            @if(isset($customSections) && count($customSections) > 0)
                <div class="grid gap-4">
                    @foreach($customSections as $section)
                        <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                                <p class="text-base font-semibold text-gray-800 dark:text-white">{{ $section['title'] }}</p>
                                <div class="text-sm">
                                    <span class="mr-4 text-gray-600 dark:text-gray-300">Total Cantidad: <strong>{{ $section['total_qty'] }}</strong></span>
                                    <span class="text-gray-600 dark:text-gray-300">Total Valor: <strong>{{ core()->formatBasePrice($section['total_amount']) }}</strong></span>
                                </div>
                            </div>
                            <div class="p-4">
                                <div class="table">
                                    <table class="w-full text-left text-sm text-gray-600 dark:text-gray-300">
                                        <thead class="border-b border-gray-200 bg-gray-50 text-gray-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400">
                                            <tr>
                                                <th class="px-4 py-3 font-medium">Producto</th>
                                                <th class="px-4 py-3 font-medium">Cantidad Vendida</th>
                                                <th class="px-4 py-3 font-medium">Valor Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse($section['products'] as $product)
                                                <tr class="border-b border-gray-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950">
                                                    <td class="px-4 py-3">{{ $product->name }}</td>
                                                    <td class="px-4 py-3">{{ $product->total_qty }}</td>
                                                    <td class="px-4 py-3">{{ core()->formatBasePrice($product->total_amount) }}</td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="3" class="px-4 py-3 text-center">No hay ventas para estos productos en este periodo.</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <!-- Recent Won Leads Table -->
            <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">Últimas 5 Ventas</p>
                </div>
                <div class="p-4">
                    <div class="table">
                        <table class="w-full text-left text-sm text-gray-600 dark:text-gray-300">
                            <thead class="border-b border-gray-200 bg-gray-50 text-gray-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-3 font-medium">ID</th>
                                    <th class="px-4 py-3 font-medium">Título</th>
                                    <th class="px-4 py-3 font-medium">Valor</th>
                                    <th class="px-4 py-3 font-medium">Fecha Cierre</th>
                                    <th class="px-4 py-3 font-medium">Responsable</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentLeads as $lead)
                                    <tr class="border-b border-gray-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950">
                                        <td class="px-4 py-3">#{{ $lead->id }}</td>
                                        <td class="px-4 py-3">
                                            <a href="{{ route('admin.leads.view', $lead->id) }}" class="text-blue-600 hover:underline dark:text-blue-400">{{ $lead->title }}</a>
                                        </td>
                                        <td class="px-4 py-3">{{ core()->formatBasePrice($lead->lead_value) }}</td>
                                        <td class="px-4 py-3">{{ $lead->closed_at ? $lead->closed_at->format('d M Y') : '-' }}</td>
                                        <td class="px-4 py-3">{{ $lead->user ? $lead->user->name : '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-3 text-center">No hay ventas registradas este año.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                const ctx = document.getElementById('monthlySalesChart').getContext('2d');
                const monthlySalesChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'],
                        datasets: [{
                            label: 'Ventas ({{ core()->currencySymbol(core()->getBaseCurrencyCode()) }})',
                            data: @json($chartData),
                            backgroundColor: 'rgba(59, 130, 246, 0.5)', // Blue-500 equivalent with opacity
                            borderColor: 'rgba(59, 130, 246, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            });
        </script>
    @endpush
</x-admin::layouts>
