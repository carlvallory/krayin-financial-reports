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

    public function debug()
    {
        $currentYear = date('Y');
        
        // REPLICATE THE MAIN QUERY EXACTLY
        $wonLeadsQuery = \Webkul\Lead\Models\Lead::query()
            ->join('lead_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_stages.id')
            ->where('lead_stages.code', 'won')
            ->whereYear('leads.closed_at', $currentYear);
            
        echo "<h1>Deep Debug</h1>";
        
        // 1. Check Total Revenue
        $queryClone = clone $wonLeadsQuery;
        echo "<h2>Total Revenue Query</h2>";
        echo "<p>SQL: " . $queryClone->toSql() . "</p>";
        echo "<p>Bindings: " . json_encode($queryClone->getBindings()) . "</p>";
        
        try {
            $sum = $queryClone->sum('leads.lead_value'); // Specify table to be safe
            echo "<p><strong>Result SUM: {$sum}</strong></p>";
        } catch (\Exception $e) {
            echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
        }
        
        // 2. Test without Year filter
        echo "<h2>Test: No Year Filter</h2>";
        $noYearQuery = \Webkul\Lead\Models\Lead::query()
            ->join('lead_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_stages.id')
            ->where('lead_stages.code', 'won');
        
        echo "<p>Count: " . $noYearQuery->count() . "</p>";

        // 3. Test without Code filter (Just Join)
        echo "<h2>Test: Just Join (No Code or Year)</h2>";
        $justJoinQuery = \Webkul\Lead\Models\Lead::query()
            ->join('lead_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_stages.id');
            
        echo "<p>Count: " . $justJoinQuery->count() . "</p>";

        // 4. Test Raw Join
        echo "<h2>Test: DB Facade Join</h2>";
        $rawCount = \DB::table('leads')
            ->join('lead_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_stages.id')
            ->where('lead_stages.code', 'won')
            ->count();
        echo "<p>Raw DB Count: " . $rawCount . "</p>";

        // 3. Check Monthly Sales (Chart)
        echo "<h2>Monthly Sales Query</h2>";
        try {
            $monthlySales = (clone $wonLeadsQuery)
                ->selectRaw('MONTH(leads.closed_at) as month, SUM(lead_value) as total')
                ->groupBy('month')
                ->pluck('total', 'month')
                ->toArray();
            echo "<pre>" . print_r($monthlySales, true) . "</pre>";
        } catch (\Exception $e) {
             echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
        }

        die();
    }

    public function index()
    {
        $currentYear = date('Y');
        
        $wonLeadsQuery = \Webkul\Lead\Models\Lead::query()
            ->join('lead_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_stages.id')
            ->where('lead_stages.code', 'won')
            ->whereYear('leads.closed_at', $currentYear);

        // KPI: Total Revenue This Year
        $totalRevenue = (clone $wonLeadsQuery)->sum('lead_value');

        // KPI: Total Won Leads Count
        $totalWonLeads = (clone $wonLeadsQuery)->count();

        // KPI: This Month Revenue
        $thisMonthRevenue = (clone $wonLeadsQuery)
            ->whereMonth('leads.closed_at', date('m'))
            ->sum('lead_value');

        // Chart Data: Monthly Sales
        $monthlySales = (clone $wonLeadsQuery)
            ->selectRaw('MONTH(leads.closed_at) as month, SUM(lead_value) as total')
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();

        // Prepare chart data array (1-12)
        $chartData = [];
        for ($i = 1; $i <= 12; $i++) {
            $chartData[] = $monthlySales[$i] ?? 0;
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
                    ->join('lead_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_stages.id')
                    ->join('products', 'lead_products.product_id', '=', 'products.id')
                    ->where('lead_stages.code', 'won') // Only won leads
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

        return view('krayin-financial-reports::index', compact('totalRevenue', 'totalWonLeads', 'thisMonthRevenue', 'chartData', 'recentLeads', 'customSections'));
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
            'sections' => 'required|array',
            'sections.*.title' => 'nullable|string',
            'sections.*.products' => 'nullable|array',
        ]);

        $code = 'krayin_financial_reports.settings.custom_sections';
        $value = json_encode($data['sections']);

        $config = \Webkul\Core\Models\CoreConfig::where('code', $code)->first();

        if ($config) {
            $config->value = $value;
            $config->save();
        } else {
            \Webkul\Core\Models\CoreConfig::create([
                'code' => $code,
                'value' => $value,
            ]);
        }

        session()->flash('success', 'Configuration saved successfully.');

        return redirect()->route('krayin.financial-reports.index');
    }
}
