<?php

namespace App\Services;

use App\Http\Requests\UpdateuserRequest;
use App\Models\User;

class UserService
{
    public function findUserOrError(int|string $id): User
    {
        return User::findOrFail($id);
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
