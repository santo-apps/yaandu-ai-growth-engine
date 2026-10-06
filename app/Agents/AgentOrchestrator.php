<?php

namespace App\Agents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use App\AI\JsonSchemaValidator;
use Illuminate\Support\Str;
use RuntimeException;

final class AgentOrchestrator
{
    private readonly JsonSchemaValidator $schemaValidator;

    /** @param iterable<AgentInterface> $agents */
    public function __construct(private readonly iterable $agents)
    {
        $this->schemaValidator = new JsonSchemaValidator();
    }

    public function run(string $name, string $tenantId, array $input, ?string $actorId = null, ?string $existingRunId = null): AgentResult
    {
        $agent = collect(is_array($this->agents) ? $this->agents : iterator_to_array($this->agents))->first(fn (AgentInterface $candidate) => $candidate->name() === $name);
        if (! $agent) throw new RuntimeException("Agent [{$name}] is not enabled.");
        $this->schemaValidator->validate($input, $agent->inputSchema());
        $encodedInput = json_encode($input, JSON_THROW_ON_ERROR);
        $inputHash = hash('sha256', $encodedInput);
        app(\App\Orchestration\WorkflowService::class)->assertAgentExecutionAllowed($tenantId, $input);
        $runId = $existingRunId ?? (string) Str::uuid();
        $correlationId = null;
        if ($existingRunId) {
            $existingRun = DB::table('agent_runs')->where('id', $runId)->where('tenant_id', $tenantId)->where('agent_key', $name)->first();
            if (! $existingRun) throw new RuntimeException('Agent run not found in this tenant.');
            $correlationId = $existingRun->correlation_id;
            if (in_array($existingRun->status, ['succeeded', 'cancelled'], true)) throw new RuntimeException('A completed agent run cannot be executed again.');
            if ($existingRun->input_ciphertext !== null && ! hash_equals((string) $existingRun->input_hash, $inputHash)) {
                throw new RuntimeException('Agent run input does not match the persisted request.');
            }
            if ($existingRun->input_ciphertext === null && ! in_array($existingRun->status, ['queued', 'running'], true)) {
                throw new RuntimeException('A failed agent run without a protected input snapshot cannot be retried.');
            }
            DB::table('agent_runs')->where('id', $runId)->where('tenant_id', $tenantId)->update([
                'status' => 'running', 'input_hash' => $inputHash, 'input_ciphertext' => Crypt::encryptString($encodedInput), 'error_code' => null, 'error_summary' => null, 'started_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            $correlationId = (string) Str::uuid();
            DB::table('agent_runs')->insert(['id' => $runId, 'tenant_id' => $tenantId, 'agent_key' => $name,
                'status' => 'running', 'requested_by' => $actorId, 'input_hash' => $inputHash, 'input_ciphertext' => Crypt::encryptString($encodedInput),
                'correlation_id' => $correlationId,
                'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        if (! $correlationId) {
            $correlationId = (string) Str::uuid();
            DB::table('agent_runs')->where('id', $runId)->where('tenant_id', $tenantId)->update(['correlation_id' => $correlationId]);
        }
        $this->event($runId, $tenantId, 'started', ['agent' => $name, 'correlation_id' => $correlationId]);
        $this->workflowEvent($tenantId, $runId, $name, $input, 'started');
        try {
            $result = $agent->execute(new AgentContext($tenantId, $runId, $actorId, $correlationId), $input);
            $this->schemaValidator->validate($result->data, $agent->outputSchema());
            DB::table('agent_runs')->where('id', $runId)->where('tenant_id', $tenantId)->update(['status' => 'succeeded', 'error_code' => null, 'error_summary' => null, 'output_hash' => hash('sha256', json_encode($result->data)), 'summary' => $result->summary, 'finished_at' => now(), 'updated_at' => now()]);
            $this->event($runId, $tenantId, 'completed', ['summary' => $result->summary, 'correlation_id' => $correlationId]);
            $this->workflowEvent($tenantId, $runId, $name, $input, 'completed', $result->summary, $result->data);
            return $result;
        } catch (\Throwable $error) {
            $failure = AgentFailure::from($error, $correlationId);
            DB::table('agent_runs')->where('id', $runId)->where('tenant_id', $tenantId)->update(['status' => 'failed', 'error_code' => $failure->code, 'error_summary' => $failure->message, 'finished_at' => now(), 'updated_at' => now()]);
            $this->event($runId, $tenantId, 'failed', ['error_code' => $failure->code, 'safe_message' => $failure->message, 'correlation_id' => $correlationId]);
            $this->workflowEvent($tenantId, $runId, $name, $input, 'failed', null, [], $failure->code);
            throw $error;
        }
    }

    private function workflowEvent(string $tenantId, string $runId, string $name, array $input, string $phase, ?string $summary = null, array $output = [], ?string $failureCode = null): void
    {
        try {
            app(\App\Orchestration\WorkflowService::class)->recordAgentRun($tenantId, $runId, $name, $input, $phase, $summary, $output, $failureCode);
        } catch (\Throwable) {
            \Illuminate\Support\Facades\Log::warning('Workflow event bridge could not append an agent event.', ['tenant_id' => $tenantId, 'agent_run_id' => $runId, 'phase' => $phase]);
        }
    }

    private function event(string $runId, string $tenantId, string $key, array $payload): void
    {
        $sequence = (int) DB::table('agent_events')->where('tenant_id', $tenantId)->where('agent_run_id', $runId)->max('sequence') + 1;
        DB::table('agent_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'agent_run_id' => $runId,
            'sequence' => $sequence, 'event_key' => $key, 'payload' => json_encode($payload), 'created_at' => now()]);
    }
}
