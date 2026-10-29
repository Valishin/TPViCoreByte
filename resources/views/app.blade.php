<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#c2410c">
    {{-- Tiempo real: la clave de Reverb es de cada instalación (los assets van compilados en la imagen). --}}
    <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
    <meta name="app-version" content="{{ config('app.version') }}{{ config('app.commit') ? ' · '.config('app.commit') : '' }}">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
