<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CompanyHumanSalesReviewController extends Controller
{
    private const DECISIONS = ['pursue', 'hold', 'reject', 'needs_more_evidence'];

    public function show(string $company)
    {
        $tenant = (string) app('tenant.id');
        $this->company($tenant, $company);

        return response()->json(['review' => $this->review($tenant, $company)]);
    }

    public function update(Request $request, string $company)
    {
        $tenant = (string) app('tenant.id');
        $this->company($tenant, $company);
        $role = DB::table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $request->user()->id)
            ->where('status', 'active')->value('role');
        abort_unless(in_array($role, ['owner', 'admin', 'manager', 'salesperson', 'sales_rep', 'member'], true), 403,
            'An active tenant salesperson or manager role is required.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(self::DECISIONS)],
            'selected_service_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        if (! empty($data['selected_service_id'])) {
            abort_unless(DB::table('tenant_services')->where('tenant_id', $tenant)->where('id', $data['selected_service_id'])
                ->where('active', true)->exists(), 422, 'Select an active service belonging to this tenant.');
        }

        DB::transaction(function () use ($request, $data, $tenant, $company): void {
            DB::table('companies')->where('tenant_id', $tenant)->where('id', $company)->lockForUpdate()->first();
            $existing = DB::table('company_human_sales_reviews')->where('tenant_id', $tenant)->where('company_id', $company)->first();
            $reviewId = $existing?->id ?? (string) Str::uuid();
            $now = now();
            $values = ['reviewer_id' => $request->user()->id, 'decision' => $data['decision'],
                'selected_service_id' => $data['selected_service_id'] ?? null, 'notes' => $data['notes'] ?? null,
                'reviewed_at' => $now, 'updated_at' => $now];
            if ($existing) DB::table('company_human_sales_reviews')->where('tenant_id', $tenant)->where('company_id', $company)->update($values);
            else DB::table('company_human_sales_reviews')->insert($values + [
                'id' => $reviewId, 'tenant_id' => $tenant, 'company_id' => $company, 'created_at' => $now,
            ]);
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'actor_user_id' => $request->user()->id,
                'action' => 'human_sales_review_saved', 'subject_type' => 'company_human_sales_review', 'subject_id' => $reviewId,
                'request_id' => $request->header('X-Request-ID'),
                'metadata' => json_encode(['company_id' => $company, 'decision' => $data['decision'],
                    'selected_service_id' => $data['selected_service_id'] ?? null], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        });

        return response()->json(['review' => $this->review($tenant, $company)]);
    }

    private function company(string $tenant, string $company): object
    {
        $record = DB::table('companies')->where('tenant_id', $tenant)->where('id', $company)
            ->where('status', '!=', 'discovery_candidate')->first();
        abort_unless($record, 404);
        return $record;
    }

    private function review(string $tenant, string $company): ?object
    {
        return DB::table('company_human_sales_reviews as review')->where('review.tenant_id', $tenant)->where('review.company_id', $company)
            ->leftJoin('users', 'users.id', '=', 'review.reviewer_id')
            ->leftJoin('tenant_services as service', function ($join): void {
                $join->on('service.id', '=', 'review.selected_service_id')->on('service.tenant_id', '=', 'review.tenant_id');
            })->first(['review.decision', 'review.selected_service_id', 'review.notes', 'review.reviewed_at', 'users.name as reviewer_name', 'service.name as service_name']);
    }
}
