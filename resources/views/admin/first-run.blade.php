<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Crear administrador · TPV</title>
    <style>
        :root { color-scheme: light dark; --bg: #f3f4f6; --card: #fff; --text: #111827; --muted: #4b5563; --line: #d1d5db; --accent: #ea580c; --err: #b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b0d12; --card: #161a22; --text: #f3f4f6; --muted: #9ca3af; --line: #374151; --err: #f87171; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--text); font: 16px/1.4 system-ui, sans-serif; }
        main { width: min(560px, 100%); background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 24px; }
        h1 { margin: 0 0 4px; font-size: 1.6rem; }
        h2 { margin: 24px 0 8px; font-size: 1.1rem; }
        p { margin: 0 0 16px; color: var(--muted); }
        label { display: block; margin: 12px 0 4px; font-weight: 600; }
        input { width: 100%; min-height: 48px; padding: 0 12px; border: 2px solid var(--line); border-radius: 10px; background: transparent; color: inherit; font-size: 1rem; }
        input:focus { outline: none; border-color: var(--accent); }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .error { margin-top: 4px; color: var(--err); font-weight: 600; }
        button { width: 100%; min-height: 56px; margin-top: 24px; border: 0; border-radius: 12px; background: var(--accent); color: #fff; font-size: 1.1rem; font-weight: 800; cursor: pointer; }
    </style>
</head>
<body>
<main>
    <div style="text-align:center;margin:0 0 16px">@include('partials.brand-mark', ['height' => 32])</div>
    <h1>Crear administrador</h1>
    <p>Primer acceso a la administración del TPV: crea el administrador y el encargado. Esta pantalla solo aparece
        mientras no hay ningún usuario.</p>
    <form method="post" action="{{ route('admin.first-run.store') }}" autocomplete="off">
        @csrf
        <h2>Administrador (entra en /admin)</h2>
        <label for="name">Nombre</label>
        <input id="name" name="name" value="{{ old('name') }}" required autofocus>
        @error('name')<div class="error" role="alert">{{ $message }}</div>@enderror
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required>
        @error('email')<div class="error" role="alert">{{ $message }}</div>@enderror
        <div class="row">
            <div>
                <label for="password">Contraseña (10 o más)</label>
                <input id="password" name="password" type="password" required autocomplete="new-password">
            </div>
            <div>
                <label for="password_confirmation">Repetir contraseña</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            </div>
        </div>
        @error('password')<div class="error" role="alert">{{ $message }}</div>@enderror

        <h2>Encargado (autoriza en la caja con su PIN)</h2>
        <label for="manager_name">Nombre</label>
        <input id="manager_name" name="manager_name" value="{{ old('manager_name', 'Encargado') }}" required>
        @error('manager_name')<div class="error" role="alert">{{ $message }}</div>@enderror
        <label for="manager_email">Email del encargado</label>
        <input id="manager_email" name="manager_email" type="email" value="{{ old('manager_email') }}" required>
        @error('manager_email')<div class="error" role="alert">{{ $message }}</div>@enderror
        <div class="row">
            <div>
                <label for="manager_pin">PIN (4 cifras)</label>
                <input id="manager_pin" name="manager_pin" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required>
            </div>
            <div>
                <label for="manager_pin_confirmation">Repetir PIN</label>
                <input id="manager_pin_confirmation" name="manager_pin_confirmation" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required>
            </div>
        </div>
        @error('manager_pin')<div class="error" role="alert">{{ $message }}</div>@enderror

        <button type="submit">Crear y empezar la puesta en marcha</button>
    </form>
</main>
</body>
</html>
