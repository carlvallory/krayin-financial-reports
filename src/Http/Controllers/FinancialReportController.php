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
        $stages = \Webkul\Lead\Models\Stage::all();
        $leadsCount = \Webkul\Lead\Models\Lead::count();
        $currentYear = date('Y');
        
        $wonStage = $stages->where('code', 'won')->first();
        
        $wonLeadsCount = 0;
        $wonLeadsThisYearCount = 0;
        $sampleLeads = [];
        
        if ($wonStage) {
            $wonLeadsCount = \Webkul\Lead\Models\Lead::where('lead_pipeline_stage_id', $wonStage->id)->count();
            
            $wonLeadsThisYearCount = \Webkul\Lead\Models\Lead::where('lead_pipeline_stage_id', $wonStage->id)
                ->whereYear('closed_at', $currentYear)
                ->count();
                
            $sampleLeads = \Webkul\Lead\Models\Lead::where('lead_pipeline_stage_id', $wonStage->id)
                ->whereYear('closed_at', $currentYear)
                ->limit(5)
                ->get();
        }
        
        // Check for leads with ANY closed_at this year
        $anyLeadsThisYear = \Webkul\Lead\Models\Lead::whereYear('closed_at', $currentYear)->count();

        echo "<h1>Debug Info</h1>";
        echo "<h2>Stages</h2>";
        echo "<ul>";
        foreach ($stages as $stage) {
            echo "<li>ID: {$stage->id} - Code: {$stage->code} - Name: {$stage->name}</li>";
        }
        echo "</ul>";
        
        echo "<h2>Leads Stats</h2>";
        echo "<p>Total Leads: {$leadsCount}</p>";
        echo "<p>Won Leads (All Time): {$wonLeadsCount}</p>";
        echo "<p>Won Leads ({$currentYear}): {$wonLeadsThisYearCount}</p>";
        echo "<p>Leads with closed_at in {$currentYear} (Any Stage): {$anyLeadsThisYear}</p>";

        if (count($sampleLeads) > 0) {
            echo "<h2>Sample Won Leads This Year</h2>";
            echo "<table border='1'><tr><th>ID</th><th>Title</th><th>Value</th><th>Closed At</th><th>Stage ID</th></tr>";
            foreach ($sampleLeads as $lead) {
                echo "<tr>";
                echo "<td>{$lead->id}</td>";
                echo "<td>{$lead->title}</td>";
                echo "<td>{$lead->lead_value}</td>";
                echo "<td>{$lead->closed_at}</td>";
                echo "<td>{$lead->lead_pipeline_stage_id}</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
             echo "<p>No won leads found for this year.</p>";
             // Show some leads that SHOULD match if user says there are incomes
             $potentialLeads = \Webkul\Lead\Models\Lead::orderBy('created_at', 'desc')->limit(5)->get();
             echo "<h2>Recent Leads (Potential Mismatch?)</h2>";
             echo "<table border='1'><tr><th>ID</th><th>Title</th><th>Value</th><th>Closed At</th><th>Stage ID</th></tr>";
             foreach ($potentialLeads as $lead) {
                echo "<tr>";
                echo "<td>{$lead->id}</td>";
                echo "<td>{$lead->title}</td>";
                echo "<td>{$lead->lead_value}</td>";
                echo "<td>{$lead->closed_at}</td>";
                echo "<td>{$lead->lead_pipeline_stage_id}</td>";
                echo "</tr>";
            }
            echo "</table>";
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
