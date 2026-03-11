<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Http\Requests\UpdateuserRequest;
use App\Helpers\ApiResponse;
use App\Helpers\SystemLogHelper;
use App\QueryBuilders\UserQueryBuilder;
use App\Services\NotificationService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private UserService $userService)
    {
    }

    public function index(Request $request)
    {
        if ($request->has('id')) {
            return UserQueryBuilder::buildQuery($request)->firstOrFail();
        }

        return UserQueryBuilder::fromRequest($request);
    }

    public function destroy(Request $request, $id)
    {
        try {
            $user = $this->userService->findUserOrError($id);
            if ($user instanceof JsonResponse) {return $user;}

            $actor = $request->user();
            $this->notificationService->notifyUserAction($actor, $user, 'user.delete', ['user_id' => $user->id]);

            $user->delete();
            SystemLogHelper::log('user.delete.success', 'User deleted successfully', ['user_id' => $user->id]);
            return ApiResponse::success(null, 'User deleted successfully');
        } catch (\Exception $e) {
            SystemLogHelper::log('user.delete.failed', 'Failed to delete user', ['user_id' => $id, 'error' => $e->getMessage()], ['level' => 'error']);
            return ApiResponse::error('Failed to delete user', 500, $e->getMessage());
        }
    }

    public function update(UpdateuserRequest $request, $id)
    {
        try {
            $user = $this->userService->findUserOrError($id);
            if ($user instanceof JsonResponse) {return $user;}
            $user = $this->userService->updateUserFromRequest($request, $user);

            SystemLogHelper::log('user.update.success', 'User updated successfully', ['user_id' => $user->id]);

            $actor = $request->user();
            $this->notificationService->notifyUserAction($actor, $user, 'user.update', ['user_id' => $user->id]);

            return ApiResponse::success($user, 'User updated successfully');
        } catch (\Exception $e) {
            SystemLogHelper::log('user.update.failed', 'Failed to update user', ['user_id' => $id, 'error' => $e->getMessage()], ['level' => 'error']);
            return ApiResponse::error('Failed to update user', 500, $e->getMessage());
        }
    }

    public function filter(Request $request)
    {
        try {
            $perPage = $request->integer('per_page', 20);
            $filters = $request->input('filters', []);

            $users = User::filterUsers($filters, $perPage);

            return response()->json($users);

        } catch (\Exception $e) {
            SystemLogHelper::log('user.filter.failed', 'Failed to filter users', ['error' => $e->getMessage()], ['level' => 'error']);
            return ApiResponse::error('Failed to filter users', 500, $e->getMessage());
        }
    }
}
