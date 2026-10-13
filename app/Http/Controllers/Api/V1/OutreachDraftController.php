<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Outreach\OutreachDraftService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class OutreachDraftController extends Controller
{
    public function index(string $company, OutreachDraftService $service)
    {
        return response()->json($service->workspace((string)app('tenant.id'),$company));
    }

    public function store(Request $request,string $company,OutreachDraftService $service)
    {
        $data=$request->validate(['finding_ids'=>['required','array','min:1','max:8'],'finding_ids.*'=>['required','uuid','distinct'],'tone'=>['sometimes','in:professional,consultative,concise']]);
        $key=substr((string)$request->header('Idempotency-Key',''),0,128);
        abort_if($key==='',422,'An Idempotency-Key header is required.');
        try {
            $draft=$service->generate((string)app('tenant.id'),$company,(string)$request->user()->id,$data['finding_ids'],$key,null,$data['tone']??'consultative');
            return response()->json($draft,201);
        } catch(ValidationException $error) { throw $error; }
        catch(\Throwable $error) {
            return response()->json(['message'=>'The outreach draft could not be generated. Try again or contact a workspace administrator.'],422);
        }
    }

    public function regenerate(Request $request,string $id,OutreachDraftService $service)
    {
        $tenant=(string)app('tenant.id');
        $previous=\Illuminate\Support\Facades\DB::table('outreach_drafts')->where('tenant_id',$tenant)->where('id',$id)->first();abort_unless($previous,404);
        $data=$request->validate(['finding_ids'=>['required','array','min:1','max:8'],'finding_ids.*'=>['required','uuid','distinct'],'tone'=>['sometimes','in:professional,consultative,concise']]);
        $key=substr((string)$request->header('Idempotency-Key',''),0,128);abort_if($key==='',422,'An Idempotency-Key header is required.');
        try { return response()->json($service->generate($tenant,$previous->company_id,(string)$request->user()->id,$data['finding_ids'],$key,$id,$data['tone']??'consultative'),201); }
        catch(ValidationException $error){throw $error;}
        catch(\Throwable $error){return response()->json(['message'=>'The outreach draft could not be regenerated. Try again or contact a workspace administrator.'],422);}
    }
}
