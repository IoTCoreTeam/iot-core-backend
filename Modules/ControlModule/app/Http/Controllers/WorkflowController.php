<?php

namespace Modules\ControlModule\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Helpers\SystemLogHelper;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Modules\ControlModule\Jobs\RunWorkflowJob;
use Modules\ControlModule\Helpers\ApiResponse;
use Modules\ControlModule\Http\Requests\StoreWorkflowRequest;
use Modules\ControlModule\Http\Requests\UpdateWorkflowRequest;
use Modules\ControlModule\Models\Workflow;
use Modules\ControlModule\QueryBuilders\WorkflowQueryBuilder;
use Modules\ControlModule\Services\WorkflowRunService;
use Modules\ControlModule\Services\Workflows\WorkflowRunStateStore;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowQueryBuilder $workflowQueryBuilder,
        private readonly WorkflowRunService $workflowRunService,
        private readonly NotificationService $notificationService,
        private readonly WorkflowRunStateStore $workflowRunStateStore
    ) {}

    private function defaultDefinition(): array
    {
        return [
            'version' => 1,
            'nodes' => [],
            'edges' => [],
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        return $this->workflowQueryBuilder->paginate($request);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreWorkflowRequest $request)
    {
        $payload = $request->validated();
        if (array_key_exists('definition', $payload) && $payload['definition'] === null) {
            $payload['definition'] = $this->defaultDefinition();
        }
        $workflow = Workflow::create($payload);

        return ApiResponse::success($workflow->refresh(), 'Workflow created successfully', 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Workflow $workflow)
    {
        return ApiResponse::success($workflow, 'Workflow loaded successfully');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateWorkflowRequest $request, Workflow $workflow)
    {
        $payload = $request->validated();
        if (array_key_exists('definition', $payload) && $payload['definition'] === null) {
            $payload['definition'] = $this->defaultDefinition();
        }
        $workflow->update($payload);

        return ApiResponse::success($workflow->refresh(), 'Workflow updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Workflow $workflow)
    {
        $workflow->delete();

        return ApiResponse::success(null, 'Workflow deleted successfully');
    }

    /**
     * Execute a workflow.
     */
    public function run(Request $request, Workflow $workflow)
    {
        if ($workflow->status !== 'approved') {
            return ApiResponse::error('Only approved workflows can be run.', 422);
        }

        $actor = $request->user();
        $turnOffDevicesBeforeRun = $request->boolean('turn_off_devices_before_run', true);
        try {
            $run = $this->workflowRunStateStore->createRun((string) $workflow->id, $actor?->id);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        $runId = (string) ($run['run_id'] ?? '');
        RunWorkflowJob::dispatch(
            (string) $workflow->id,
            $actor?->id,
            $runId !== '' ? $runId : null,
            $turnOffDevicesBeforeRun
        );

        if ($actor) {
            $this->notificationService->notifyWorkflowAction($actor, $workflow,
                'workflow.run',
                ['workflow_id' => $workflow->id, 'queued' => true]
            );
        }

        SystemLogHelper::log('workflow.run.queued', 'Workflow execution queued', ['workflow_id' => $workflow->id]);

        return ApiResponse::success([
            'workflow_id' => $workflow->id,
            'run_id' => $runId !== '' ? $runId : null,
            'status' => 'queued',
        ], 'Workflow queued successfully', 202);
    }

    public function stop(Workflow $workflow)
    {
        $activeRunId = $this->workflowRunStateStore->getActiveRunId((string) $workflow->id);
        $this->workflowRunService->setRunId($activeRunId);

        try {
            $result = $this->workflowRunService->stop($workflow);
            if ($activeRunId) {
                $this->workflowRunStateStore->markStopped($activeRunId, 'Stopped by user request.');
            }
        } finally {
            $this->workflowRunService->setRunId(null);
        }

        return ApiResponse::success($result, 'Workflow devices stopped successfully');
    }

    /**
     * Stream workflow execution events via SSE.
     */
    public function runStream(Request $request, Workflow $workflow)
    {
        return ApiResponse::error('Workflow status stream is temporarily disabled.', 410);
    }
}
