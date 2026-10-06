<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/*') && $request->user()) {
            $tenantId = $request->header('X-Tenant-ID');
            $membership = $tenantId ? $request->user()->tenants()
                ->whereKey($tenantId)
                ->wherePivot('status', 'active')
                ->where('tenants.status', 'active')
                ->first() : null;
            if (! $membership) abort(403, 'A valid tenant membership is required.');
            app()->instance('tenant.id', $membership->id);
            $request->attributes->set('tenant', $membership);
        }
        return $next($request);
    }
}
