<?php

namespace Modules\ControlModule\Jobs;

use App\Helpers\SystemLogHelper;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\ControlModule\Models\Workflow;
use Modules\ControlModule\Services\WorkflowRunStateStore;
use Modules\ControlModule\Services\WorkflowRunService;

class RunWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(
        public readonly string $workflowId,
        public readonly ?int $actorId = null,
        public readonly ?string $runId = null
    ) {}

    public function handle(
        WorkflowRunService $workflowRunService,
        WorkflowRunStateStore $workflowRunStateStore
    ): void
    {
        if ($this->runId) {
            $runId = $this->runId;
            $workflowRunStateStore->markRunning($this->runId);
            $workflowRunService->setRunId($runId);
            $workflowRunService->setEventCallback(function (array $event) use ($workflowRunStateStore, $runId): void {
                $workflowRunStateStore->appendEvent($runId, $event);
            });
        }

        $workflow = Workflow::find($this->workflowId);
        if (! $workflow) {
            SystemLogHelper::log('workflow.run.job_skipped', 'Workflow run job skipped because workflow was not found', [
                'workflow_id' => $this->workflowId,
            ], ['level' => 'warning']);
            if ($this->runId) {
                $workflowRunStateStore->markFailed($this->runId, 'Workflow not found.');
            }
            return;
        }

        $actor = $this->actorId ? User::find($this->actorId) : null;
        try {
            $result = $workflowRunService->run($workflow, $actor);
            if ($this->runId) {
                $workflowRunStateStore->markMainFinished($this->runId, $result);
            }
        } catch (\Throwable $e) {
            if ($this->runId) {
                $workflowRunStateStore->markFailed($this->runId, $e->getMessage());
            }
            SystemLogHelper::log(
                'workflow.run.job_failed',
                'Workflow run job failed',
                ['workflow_id' => $this->workflowId, 'error' => $e->getMessage()],
                ['level' => 'error']
            );
            // Business failures are already captured in run state and notifications.
            // Skip rethrow to avoid noisy failed_jobs for expected device-side failures.
        } finally {
            $workflowRunService->setRunId(null);
            $workflowRunService->setEventCallback(null);
        }
    }
}
