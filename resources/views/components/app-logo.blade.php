@props([
    'sidebar' => false,
])

@if($sidebar)
    <a {{ $attributes->class(['flex items-center gap-2.5 rounded-lg px-2 py-1']) }}>
        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-gold-500 text-zinc-900">
            <x-app-logo-icon class="size-5 fill-current" />
        </span>
        <span class="text-base font-bold tracking-tight text-white">Educa<span class="text-gold-500">Din</span></span>
    </a>
@else
    <flux:brand :name="config('app.name', 'EducaDin')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
        </x-slot>
    </flux:brand>
@endif
