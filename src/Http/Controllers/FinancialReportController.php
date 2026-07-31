<?php

namespace CarlVallory\KrayinFinancialReports\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;

class FinancialReportController extends Controller
{
    use DispatchesJobs, ValidatesRequests;

    protected $leadRepository;
    protected $productRepository;

    public function __construct(
        \Webkul\Lead\Repositories\LeadRepository $leadRepository,
        \Webkul\Product\Repositories\ProductRepository $productRepository
    )
    {
        $this->leadRepository = $leadRepository;
        $this->productRepository = $productRepository;
    }



    public function index()
    {
        $currentYear = date('Y');
        
        $wonLeadsQuery = \Webkul\Lead\Models\Lead::query()
            ->join('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
            ->where('lead_pipeline_stages.code', 'won')
            ->whereYear('leads.closed_at', $currentYear);

        // KPI: Total Revenue This Year (Using Net Value)
        $totalRevenue = (clone $wonLeadsQuery)->sum('net_value');

        // KPI: Total Won Leads Count
        $totalWonLeads = (clone $wonLeadsQuery)->count();

        // KPI: This Month Revenue (Using Net Value)
        $thisMonthRevenue = (clone $wonLeadsQuery)
            ->whereMonth('leads.closed_at', date('m'))
            ->sum('net_value');

        // Chart Data: Monthly Sales (Using Net Value)
        $monthlySales = (clone $wonLeadsQuery)
            ->selectRaw('MONTH(leads.closed_at) as month, SUM(net_value) as total')
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();

        // Prepare chart data array (1-12)
        $chartData = [];
        for ($i = 1; $i <= 12; $i++) {
            $chartData[] = (float) ($monthlySales[$i] ?? 0);
        }

        // Table Data: Recent 5 Won Leads
        $recentLeads = (clone $wonLeadsQuery)
            ->select('leads.*') // Avoid ambiguity
            ->orderBy('leads.closed_at', 'desc')
            ->limit(5)
            ->get();

        // Custom Sections Logic
        $customSections = [];
        $configuration = core()->getConfigData('krayin_financial_reports.settings.custom_sections');
        
        if ($configuration) {
            // $configuration is expected to be an array of section configs
            // Ensure it's in the format we expect: [ 1 => ['title' => '...', 'products' => [...]], ... ]
            // Depending on how core()->getConfigData returns json/array
            
            // If it's a JSON string, decode it. If array, use as is.
            if (is_string($configuration)) {
                $configuration = json_decode($configuration, true);
            }

            foreach ($configuration as $key => $section) {
                if (empty($section['products'])) continue;
                
                $productIds = $section['products'];
                
                // Get sales for these products
                // leads -> lead_products
                // We need to join lead_products
                
                $sectionData = [];
                
                // Calculate total per product in this section
                $productsData = \Webkul\Lead\Models\Product::query()
                    ->select('lead_products.product_id', 'products.name', \DB::raw('SUM(lead_products.quantity) as total_qty'), \DB::raw('SUM(lead_products.price * lead_products.quantity) as total_amount'))
                    ->join('leads', 'lead_products.lead_id', '=', 'leads.id')
                    ->join('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
                    ->join('products', 'lead_products.product_id', '=', 'products.id')
                    ->where('lead_pipeline_stages.code', 'won') // Only won leads
                    ->whereIn('lead_products.product_id', $productIds)
                    ->whereYear('leads.closed_at', $currentYear)
                    ->groupBy('lead_products.product_id', 'products.name')
                    ->get();
                    
                $sectionTotalAmount = $productsData->sum('total_amount');
                $sectionTotalQty = $productsData->sum('total_qty');

                $customSections[] = [
                    'title' => $section['title'] ?? 'Section ' . $key,
                    'products' => $productsData,
                    'total_amount' => $sectionTotalAmount,
                    'total_qty' => $sectionTotalQty
                ];
            }
        }

        // KPIs en USD (Valor Neto convertido, denormalizado en leads.total_usd)
        $totalRevenueUsd = (float) (clone $wonLeadsQuery)->sum('total_usd');

        $thisMonthRevenueUsd = (float) (clone $wonLeadsQuery)
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

        return view('krayin-financial-reports::index', compact(
            'totalRevenue', 'totalWonLeads', 'thisMonthRevenue', 'chartData', 'recentLeads', 'customSections',
            'totalRevenueUsd', 'thisMonthRevenueUsd', 'chartDataUsd'
        ));
    }

    public function configure()
    {
        $products = $this->productRepository->all();
        
        // Load existing config
        $configuration = core()->getConfigData('krayin_financial_reports.settings.custom_sections');
         if (is_string($configuration)) {
            $configuration = json_decode($configuration, true);
        }
        
        // Ensure structure for 3 sections
        $sections = $configuration ?? [];
        for ($i = 1; $i <= 3; $i++) {
            if (!isset($sections[$i])) {
                $sections[$i] = ['title' => '', 'products' => []];
            }
        }

        return view('krayin-financial-reports::configure', compact('products', 'sections'));
    }

    public function storeConfiguration(\Illuminate\Http\Request $request)
    {
        $data = $request->validate([
            'sections'                => 'nullable|array',
            'sections.*.title'        => 'nullable|string',
            'sections.*.products'     => 'nullable|array',
            'product_tags'            => 'nullable|array',
            'product_tags.*.name'     => 'nullable|string',
            'product_tags.*.products' => 'nullable|array',
            'points_of_sale'                => 'nullable|array',
            'points_of_sale.*.wc_user_id'   => 'nullable',
            'points_of_sale.*.sucursal'     => 'nullable|string',
            'points_of_sale.*.merch_point'  => 'nullable|string',
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

        $this->saveConfig('krayin_financial_reports.settings.custom_sections', $data['sections'] ?? []);
        $this->saveConfig('krayin_financial_reports.settings.product_tags', $tags);
        $this->saveConfig('krayin_financial_reports.settings.points_of_sale', $pos);

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
}
