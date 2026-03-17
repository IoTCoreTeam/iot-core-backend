<?php

namespace Modules\ControlModule\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Helpers\SystemLogHelper;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Modules\ControlModule\Helpers\ApiResponse;
use Modules\ControlModule\Http\Requests\StoreWorkflowRequest;
use Modules\ControlModule\Http\Requests\UpdateWorkflowRequest;
use Modules\ControlModule\Models\Workflow;
use Modules\ControlModule\QueryBuilders\WorkflowQueryBuilder;
use Modules\ControlModule\Services\WorkflowRunService;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowQueryBuilder $workflowQueryBuilder,
        private readonly WorkflowRunService $workflowRunService,
        private readonly NotificationService $notificationService
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
        $result = $this->workflowRunService->run($workflow);
        $actor = $request->user();
        if ($actor) {
            $this->notificationService->notifyWorkflowAction(
                $actor,
                $workflow,
                'workflow.run',
                ['workflow_id' => $workflow->id]
            );
        }

        SystemLogHelper::log(
            'workflow.run.success',
            'Workflow executed successfully',
            ['workflow_id' => $workflow->id]
        );

        return ApiResponse::success($result, 'Workflow executed successfully');
    }

    public function stop(Workflow $workflow)
    {
        $result = $this->workflowRunService->stop($workflow);

        return ApiResponse::success($result, 'Workflow devices stopped successfully');
    }

    /**
     * Stream workflow execution events via SSE.
     */
    public function runStream(Workflow $workflow)
    {
        return response()->stream(function () use ($workflow) {
            $sendEvent = function (string $event, $data): void {
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data) . "\n\n";
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                if (function_exists('flush')) {
                    @flush();
                }
            };

            $sendEvent('ready', ['connected' => true]);

            $this->workflowRunService->setEventCallback(function (array $event) use ($sendEvent) {
                $sendEvent('workflow-event', $event);
            });

            try {
                $result = $this->workflowRunService->run($workflow);
                $sendEvent('workflow-complete', $result);
            } catch (\Throwable $e) {
                $sendEvent('workflow-error', [
                    'message' => $e->getMessage(),
                    'events' => $this->workflowRunService->getEvents(),
                ]);
            } finally {
                $this->workflowRunService->setEventCallback(null);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
