<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\TenantAccount;
use Illuminate\Http\Request;

class SuperAdminDashboardController extends Controller
{
    /**
     * Show the super admin dashboard.
     */
    public function index(Request $request)
    {
        try {
            $stats = [
                'totalTenants' => TenantAccount::count(),
                'activeTenants' => TenantAccount::where('status', 'active')->count(),
                'trialTenants' => TenantAccount::where('status', 'trial')->count(),
                'moduleWarnings' => 0, // TODO: Implement real data
                'packageSummary' => [],
                'recentAuditLogs' => [], // TODO: Implement real data
            ];
        } catch (\Throwable $exception) {
            $stats = [
                'totalTenants' => 0,
                'activeTenants' => 0,
                'trialTenants' => 0,
                'moduleWarnings' => 0,
                'packageSummary' => [],
                'recentAuditLogs' => [],
            ];
        }
        
        return view('super-admin.dashboard', $stats);
    }
}
