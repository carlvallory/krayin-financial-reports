<?php

namespace CarlVallory\KrayinFinancialReports\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;

class FinancialReportController extends Controller
{
    use DispatchesJobs, ValidatesRequests;

    protected $leadRepository;

    public function __construct(\Webkul\Lead\Repositories\LeadRepository $leadRepository)
    {
        $this->leadRepository = $leadRepository;
    }

    public function index()
    {
        // 1. Get Won Stage ID (assuming 'won' is the code, or similar. Standard Krayin is 'won')
        // Actually, lead_value is on the lead. We need to filter by status or stage.
        // Krayin leads have 'closed_at' when won? Or just check stage code.
        
        // Let's get leads that are in a 'won' stage.
        // For simplicity in this iteration, we'll fetch all leads and filter, or use repository scopes if available.
        // Better: Use direct query for aggregation to be efficient.

        $currentYear = date('Y');
        
        // Helper to get won leads query
        $wonLeadsQuery = \Webkul\Lead\Models\Lead::query()
            ->leftJoin('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
            ->where('lead_pipeline_stages.code', 'won')
            ->whereYear('leads.created_at', $currentYear); // Or closed_at? Using created_at for now as sales date.

        // KPI: Total Revenue This Year
        $totalRevenue = (clone $wonLeadsQuery)->sum('lead_value');

        // KPI: Total Won Leads Count
        $totalWonLeads = (clone $wonLeadsQuery)->count();

        // KPI: This Month Revenue
        $thisMonthRevenue = (clone $wonLeadsQuery)
            ->whereMonth('leads.created_at', date('m'))
            ->sum('lead_value');

        // Chart Data: Monthly Sales
        $monthlySales = (clone $wonLeadsQuery)
            ->selectRaw('MONTH(leads.created_at) as month, SUM(lead_value) as total')
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
            ->orderBy('leads.created_at', 'desc')
            ->limit(5)
            ->get();

        return view('krayin-financial-reports::index', compact('totalRevenue', 'totalWonLeads', 'thisMonthRevenue', 'chartData', 'recentLeads'));
    }
}
