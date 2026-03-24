<?php

namespace Modules\ControlModule\Jobs;

use App\Helpers\SystemLogHelper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\ControlModule\Services\ControlUrlService;
use Modules\ControlModule\Services\WorkflowRunStateStore;

class ExecuteWorkflowOffCommandJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(
        public readonly string $controlUrlId,
        public readonly string $actionType,
        public readonly string $normalizedType,
        public readonly ?string $runId = null
    ) {}

    public function handle(
        ControlUrlService $controlUrlService,
        WorkflowRunStateStore $workflowRunStateStore
    ): void
    {
        $payload = [
            'action_type' => $this->actionType,
            'wait_for_response' => true,
            'response_timeout_ms' => max(
                1000,
                (int) config('services.node_server.control_response_timeout_ms', 15000)
            ),
        ];

        if ($this->normalizedType === 'analog') {
            $payload['value'] = 0;
        } else {
            $payload['state'] = 'off';
        }

        try {
            $controlUrlService->execute($this->controlUrlId, $payload);
            if ($this->runId) {
                $workflowRunStateStore->completePendingOffJob($this->runId, true, [
                    'control_url_id' => $this->controlUrlId,
                    'action_type' => $this->actionType,
                    'input_type' => $this->normalizedType,
                ]);
            }
            SystemLogHelper::log('workflow.action_off.delayed.executed', 'Delayed workflow off command executed', [
                'control_url_id' => $this->controlUrlId,
                'action_type' => $this->actionType,
                'normalized_type' => $this->normalizedType,
                'run_id' => $this->runId,
            ]);
        } catch (\Throwable $e) {
            if ($this->runId) {
                $workflowRunStateStore->completePendingOffJob($this->runId, false, [
                    'control_url_id' => $this->controlUrlId,
                    'action_type' => $this->actionType,
                    'input_type' => $this->normalizedType,
                    'error' => $e->getMessage(),
                ]);
            }
            SystemLogHelper::log(
                'workflow.action_off.delayed.failed',
                'Delayed workflow off command failed',
                [
                    'control_url_id' => $this->controlUrlId,
                    'action_type' => $this->actionType,
                    'normalized_type' => $this->normalizedType,
                    'run_id' => $this->runId,
                    'error' => $e->getMessage(),
                ],
                ['level' => 'error']
            );
        }
    }
}
