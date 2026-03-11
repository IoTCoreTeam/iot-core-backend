<?php

namespace App\Services;

use App\Helpers\ApiResponse;
use App\Http\Requests\UpdateuserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserService
{
    public function findUserOrError(int|string $id): User|JsonResponse
    {
        $user = User::find($id);
        if (! $user) {return ApiResponse::error('User not found', 404);}

        return $user;
    }

    public function updateUserFromRequest(UpdateuserRequest $request, User $user): User
    {
        $data = $request->validated();

        if (! array_key_exists('password', $data)) {
            unset($data['password']);
        }

        $user->fill($data);
        $user->save();

        return $user;
    }
}
