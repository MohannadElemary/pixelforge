<?php

namespace App\Services;

use App\Exceptions\InvalidCredentialsException;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Auth;

class AuthService
{
    public function __construct(private readonly UserRepository $users) {}

    public function register(array $input): void
    {
        Auth::login($this->users->create($input));
    }

    public function login(array $input): void
    {
        $credentials = ['email' => $input['email'], 'password' => $input['password']];

        if (! Auth::attempt($credentials, (bool) ($input['remember'] ?? false))) {
            throw new InvalidCredentialsException('These sign-in details do not match our records.');
        }
    }

    public function logout(): void
    {
        Auth::logout();
    }
}
