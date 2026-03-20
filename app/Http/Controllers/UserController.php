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
        $user = $this->userService->findUserOrError($id);

        $actor = $request->user();
        $this->notificationService->notifyUserAction($actor, $user, 'user.delete', ['user_id' => $user->id]);

        $user->delete();
        SystemLogHelper::log('user.delete.success', 'User deleted successfully', ['user_id' => $user->id]);

        return ApiResponse::success(null, 'User deleted successfully');
    }

    public function update(UpdateuserRequest $request, $id)
    {
        $user = $this->userService->findUserOrError($id);
        $user = $this->userService->updateUserFromRequest($request, $user);

        SystemLogHelper::log('user.update.success', 'User updated successfully', ['user_id' => $user->id]);

        $actor = $request->user();
        $this->notificationService->notifyUserAction($actor, $user, 'user.update', ['user_id' => $user->id]);

        return ApiResponse::success($user, 'User updated successfully');
    }

    public function filter(Request $request)
    {
        $perPage = $request->integer('per_page', 20);
        $filters = $request->input('filters', []);

        $users = User::filterUsers($filters, $perPage);

        return response()->json($users);
    }

    public function countByRole()
    {
        return ApiResponse::success(
            UserQueryBuilder::countByRole(),
            'User role counts fetched successfully'
        );
    }
}
