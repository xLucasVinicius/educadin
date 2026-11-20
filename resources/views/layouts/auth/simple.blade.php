<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="relative min-h-svh overflow-x-hidden bg-zinc-950 text-zinc-100 antialiased">

        {{-- Degradê dourado de fundo (fica parado atrás de tudo) --}}
        <div class="pointer-events-none fixed inset-0 bg-[radial-gradient(70%_55%_at_50%_0%,#f2a90038,transparent)]"></div>

        <main class="relative flex min-h-svh flex-col items-center justify-center gap-5 px-6 py-5 sm:gap-8 sm:p-10">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                <span class="flex size-9 items-center justify-center rounded-lg bg-gold-500 text-zinc-900">
                    <x-app-logo-icon class="size-6 fill-current" />
                </span>
                <span class="text-lg font-bold tracking-tight text-white">Educa<span class="text-gold-500">Din</span></span>
            </a>

            <div class="flex w-full max-w-sm flex-col gap-4 sm:gap-6">
                {{ $slot }}
            </div>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
