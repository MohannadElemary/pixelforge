<?php

namespace App\Repositories;

use App\Models\User;

class UserRepository
{
    public function create(array $attributes): User
    {
        return User::create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'password' => $attributes['password'],
        ]);
    }
}
