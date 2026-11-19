@props(['title' => null, 'noindex' => false])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        @if ($noindex)
            <meta name="robots" content="noindex, nofollow">
        @endif

        <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32 48x48">
        <link rel="apple-touch-icon" href="{{ asset('images/brand/apple-touch-icon.png') }}">
        <meta name="theme-color" content="#221812">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-cream-100 font-sans text-espresso-900 antialiased dark:bg-espresso-950 dark:text-cream-100">
        {{ $slot }}
    </body>
</html>
