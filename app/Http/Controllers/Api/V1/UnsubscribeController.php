<?php

namespace App\Http\Controllers\Api\V1;

use App\Contacts\ContactMethodValue;
use App\Campaigns\CampaignEventRecorder;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UnsubscribeController extends Controller
{
    public function __invoke(Request $request, string $enrollment)
    {
        if ($request->isMethod('GET')) {
            $action = htmlspecialchars($request->fullUrl(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Unsubscribe</title>'
                .'<body style="font-family:system-ui,sans-serif;max-width:36rem;margin:4rem auto;padding:1rem;color:#243047"><h1>Confirm unsubscribe</h1>'
                .'<p>Confirm that you no longer want to receive campaign email from Yaandu.</p><form method="post" action="'.$action.'">'
                .'<button style="padding:.7rem 1rem" type="submit">Unsubscribe</button></form></body></html>';

            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        DB::transaction(function () use ($enrollment): void {
            $record = DB::table('campaign_recipients')->where('id', $enrollment)->lockForUpdate()->first();
            if (! $record) return;
            $method = DB::table('contact_methods')->where('tenant_id', $record->tenant_id)->where('id', $record->contact_method_id)->first(['type', 'value']);
            if (! $method || $method->type !== 'email') return;

            $values = app(ContactMethodValue::class);
            $address = $values->decrypt($method->value);
            $hash = $values->fingerprint('email', $address);
            DB::table('suppression_lists')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'tenant_id' => $record->tenant_id, 'identifier_hash' => $hash,
                'identifier_type' => 'email', 'scope' => 'tenant', 'reason' => 'unsubscribe',
                'source' => 'campaign_unsubscribe', 'suppressed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $affectedEnrollments = DB::table('campaign_recipients')->where('tenant_id', $record->tenant_id)
                ->where('contact_method_id', $record->contact_method_id)->whereIn('status', ['pending', 'active'])->lockForUpdate()->get(['id', 'campaign_id']);
            DB::table('campaign_recipients')->where('tenant_id', $record->tenant_id)->where('contact_method_id', $record->contact_method_id)
                ->whereIn('status', ['pending', 'active'])->update([
                    'status' => 'unsubscribed', 'suppression_outcome' => 'unsubscribed', 'stop_reason' => 'unsubscribed',
                    'stopped_at' => now(), 'updated_at' => now(),
                ]);
            if (! $affectedEnrollments->contains('id', $record->id)) {
                $affectedEnrollments->push((object) ['id' => $record->id, 'campaign_id' => $record->campaign_id]);
            }
            foreach ($affectedEnrollments as $enrollment) {
                app(CampaignEventRecorder::class)->record($record->tenant_id, $enrollment->campaign_id, 'contact_unsubscribed',
                    'unsubscribe:'.$record->tenant_id.':'.$enrollment->id, $enrollment->id, null, ['channel' => 'email']);
                app(CampaignEventRecorder::class)->record($record->tenant_id, $enrollment->campaign_id, 'unsubscribed',
                    'unsubscribe-legacy:'.$record->tenant_id.':'.$enrollment->id, $enrollment->id, null, ['channel' => 'email']);
                app(\App\Campaigns\CampaignMessageStopper::class)->stopEnrollment($record->tenant_id, $enrollment->id,
                    'suppressed', 'Recipient unsubscribed.');
                app(\App\Orchestration\WorkflowService::class)->stopEnrollment($record->tenant_id, $enrollment->id, 'unsubscribe');
            }
        });

        return response()->noContent();
    }
}
