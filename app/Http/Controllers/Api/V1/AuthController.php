<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function tenants(Request $request)
    {
        return $request->user()->tenants()->wherePivot('status', 'active')->where('tenants.status', 'active')
            ->get(['tenants.id', 'tenants.name', 'tenants.slug'])
            ->map(fn ($tenant) => [...$tenant->toArray(), 'role' => $tenant->pivot->role]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials, (bool) $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'The supplied credentials could not be verified.']);
        }
        $request->session()->regenerate();
        $tenants = $request->user()->tenants()->wherePivot('status', 'active')->where('tenants.status', 'active')->get(['tenants.id', 'tenants.name', 'tenants.slug'])
            ->map(fn ($tenant) => [...$tenant->toArray(), 'role' => $tenant->pivot->role]);
        return response()->json(['user' => $request->user()->only(['id', 'name', 'email']), 'tenants' => $tenants]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return response()->noContent();
    }
}
