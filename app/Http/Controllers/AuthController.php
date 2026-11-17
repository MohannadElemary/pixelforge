<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidCredentialsException;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Services\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function show(Request $request): Response
    {
        Inertia::clearHistory();

        return Inertia::render('Auth', ['register' => $request->routeIs('register')]);
    }

    public function register(RegisterRequest $request): RedirectResponse
    {
        $this->auth->register($request->validated());
        $request->session()->regenerate();

        return redirect('/studio');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        try {
            $this->auth->login($request->validated());
        } catch (InvalidCredentialsException $exception) {
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }

        $request->session()->regenerate();

        return redirect()->intended('/studio');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->auth->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return redirect('/login');
    }
}
