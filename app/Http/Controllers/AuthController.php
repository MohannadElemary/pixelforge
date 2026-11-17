<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
class AuthController extends Controller
{
    public function show(Request $request) { return Inertia::render('Auth', ['register' => $request->routeIs('register')]); }
    public function register(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::min(10)]]);
        Auth::login(User::create($data));
        $request->session()->regenerate();
        return redirect('/studio');
    }
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (!Auth::attempt($data, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'These sign-in details do not match our records.']);
        }
        $request->session()->regenerate();
        return redirect()->intended('/studio');
    }
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
