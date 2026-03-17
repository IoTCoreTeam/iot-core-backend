<?php

namespace Modules\ControlModule\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Helpers\SystemLogHelper;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Modules\ControlModule\Helpers\ApiResponse;
use Modules\ControlModule\Models\Workflow;
use Modules\ControlModule\Services\WorkflowRunService;

class WorkflowStopController extends Controller
{
    public function __construct(
        private readonly WorkflowRunService $workflowRunService,
        private readonly NotificationService $notificationService
    ) {}

    /**
     * Stop all devices in a workflow.
     */
    public function stop(Request $request, Workflow $workflow)
    {
        $result = $this->workflowRunService->stop($workflow);
        $actor = $request->user();
        if ($actor) {
            $this->notificationService->notifyWorkflowAction(
                $actor,
                $workflow,
                'workflow.stop',
                ['workflow_id' => $workflow->id]
            );
        }

        SystemLogHelper::log(
            'workflow.stop.success',
            'Workflow devices stopped successfully',
            ['workflow_id' => $workflow->id]
        );

        return ApiResponse::success($result, 'Workflow devices stopped successfully');
    }
}
