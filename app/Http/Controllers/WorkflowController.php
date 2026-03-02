<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkflowRequest;
use App\Http\Requests\UpdateWorkflowRequest;
use App\Models\Workflow;
use App\Queries\WorkflowQueryBuilder;
use App\Services\WorkflowRunService;
use Illuminate\Http\Request;
use Modules\ControlModule\Helpers\ApiResponse;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowQueryBuilder $workflowQueryBuilder,
        private readonly WorkflowRunService $workflowRunService
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
    public function run(Workflow $workflow)
    {
        try {
            $result = $this->workflowRunService->run($workflow);
            return ApiResponse::success($result, 'Workflow executed successfully');
        } catch (\Throwable $e) {
            $events = $this->workflowRunService->getEvents();
            $errors = empty($events) ? null : ['events' => $events];
            return ApiResponse::error($e->getMessage(), 400, $errors);
        }
    }
}
