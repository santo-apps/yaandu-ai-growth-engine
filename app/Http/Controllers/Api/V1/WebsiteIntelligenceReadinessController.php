<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\WebsiteIntelligence\WebsiteIntelligenceReadinessChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WebsiteIntelligenceReadinessController extends Controller
{
    public function __invoke(Request $request, WebsiteIntelligenceReadinessChecker $checker)
    {
        $tenant = (string) app('tenant.id');
        $role = DB::table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $request->user()->id)->where('status', 'active')->value('role');
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Website intelligence readiness requires an active tenant manager.');
        return response()->json($checker->check($tenant));
    }
}
