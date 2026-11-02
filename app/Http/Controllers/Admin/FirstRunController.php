<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LocalNetwork;
use App\Support\SetupCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Primer acceso a /admin: crear el administrador (y el encargado con su PIN)
 * con el código de configuración de un solo uso de install.sh. Solo mientras
 * no hay ningún usuario y desde el propio equipo, la red local o Tailscale;
 * si no, 404. Al terminar, el código deja de valer.
 */
class FirstRunController extends Controller
{
    public function show(Request $request): View
    {
        $this->guard($request);

        return view('admin.first-run');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)],
            'manager_name' => ['required', 'string', 'max:255'],
            'manager_email' => ['required', 'email', 'max:255', 'different:email'],
            'manager_pin' => ['required', 'digits:4', 'confirmed'],
        ], [], [
            'code' => 'código de configuración', 'name' => 'nombre', 'password' => 'contraseña',
            'manager_name' => 'nombre del encargado', 'manager_email' => 'email del encargado', 'manager_pin' => 'PIN del encargado',
        ]);
        if (! SetupCode::check($data['code'])) {
            return back()->withInput($request->except(['password', 'password_confirmation', 'manager_pin', 'manager_pin_confirmation']))
                ->withErrors(['code' => 'El código de configuración no es correcto. Está en /root/tpv-credenciales.txt del equipo.']);
        }

        $admin = DB::transaction(function () use ($data) {
            abort_if(User::query()->lockForUpdate()->exists(), 404);   // dos a la vez: solo el primero
            $admin = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
                'role' => UserRole::Admin, 'is_active' => true,
            ]);
            $admin->forceFill(['email_verified_at' => now()])->save();
            // El encargado autoriza en la caja con su PIN (el del admin no se usa allí).
            User::create([
                'name' => $data['manager_name'], 'email' => $data['manager_email'], 'role' => UserRole::Manager, 'pin' => $data['manager_pin'],
                'password' => Str::password(32), 'is_active' => true,
            ]);
            SetupCode::invalidate();

            return $admin;
        });

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        return redirect()->route('filament.admin.pages.puesta-en-marcha');
    }

    private function guard(Request $request): void
    {
        abort_unless(SetupCode::available() && LocalNetwork::allows($request->ip()), 404);
    }
}
