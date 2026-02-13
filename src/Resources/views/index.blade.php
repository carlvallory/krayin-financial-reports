@extends('admin::layouts.master')

@section('page_title')
    Informes Financieros
@endsection

@section('content-wrapper')
    <div class="content full-page">
        <div class="page-header">
            <div class="page-title">
                <h1>Informes Financieros ({{ date('Y') }})</h1>
            </div>
        </div>

        <div class="page-content">
            
            <!-- KPI Cards -->
            <div class="dashboard-stats">
                <div class="dashboard-card">
                    <div class="title">Ingresos Totales (Año)</div>
                    <div class="data text-success">{{ core()->formatBasePrice($totalRevenue) }}</div>
                </div>

                <div class="dashboard-card">
                    <div class="title">Ventas Ganadas</div>
                    <div class="data">{{ $totalWonLeads }}</div>
                </div>

                <div class="dashboard-card">
                    <div class="title">Ingresos Este Mes</div>
                    <div class="data text-primary">{{ core()->formatBasePrice($thisMonthRevenue) }}</div>
                </div>
            </div>

            <!-- Chart Section -->
            <div class="panel graph-widget">
                <div class="panel-header">
                    <h3>Ventas Mensuales</h3>
                </div>
                <div class="panel-body">
                    <canvas id="monthlySalesChart" style="width: 100%; height: 300px;"></canvas>
                </div>
            </div>

            <!-- Recent Won Leads Table -->
            <div class="panel">
                <div class="panel-header">
                    <h3>Últimas 5 Ventas</h3>
                </div>
                <div class="panel-body">
                    <div class="table">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Título</th>
                                    <th>Valor</th>
                                    <th>Fecha Cierre</th>
                                    <th>Responsable</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentLeads as $lead)
                                    <tr>
                                        <td>#{{ $lead->id }}</td>
                                        <td>
                                            <a href="{{ route('admin.leads.view', $lead->id) }}">{{ $lead->title }}</a>
                                        </td>
                                        <td>{{ core()->formatBasePrice($lead->lead_value) }}</td>
                                        <td>{{ $lead->created_at->format('d M Y') }}</td>
                                        <td>{{ $lead->user ? $lead->user->name : '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5">No hay ventas registradas este año.</td>
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
                            backgroundColor: 'rgba(75, 192, 192, 0.6)',
                            borderColor: 'rgba(75, 192, 192, 1)',
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
@endsection
