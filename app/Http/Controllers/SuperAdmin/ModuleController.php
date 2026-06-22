<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    /**
     * Show modules overview.
     */
    public function index(Request $request)
    {
        return view('super-admin.modules.index');
    }

    /**
     * Show module settings.
     */
    public function settings(Request $request)
    {
        return view('super-admin.modules.settings');
    }
}
