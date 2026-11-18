@props(['items', 'activeId'])

{{-- Faixa com todos os itens (contas ou cartões) para trocar de um para outro. --}}
<div class="flex shrink-0 flex-wrap items-center gap-2">
    @foreach ($items as $item)
        <button
            type="button"
            wire:key="chip-{{ $item['id'] }}"
            wire:click="select({{ $item['id'] }})"
            @class([
                'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition',
                'border-accent bg-accent/10 text-white' => $item['id'] === $activeId && empty($item['locked']),
                'border-red-700/70 bg-red-950/40 text-red-200' => $item['id'] === $activeId && ! empty($item['locked']),
                'border-zinc-800 text-zinc-400 hover:border-zinc-600 hover:text-white' => $item['id'] !== $activeId && empty($item['locked']),
                'border-red-950 text-zinc-500 opacity-70 hover:border-red-800' => $item['id'] !== $activeId && ! empty($item['locked']),
            ])
        >
            @if (! empty($item['locked']))
                <flux:icon.lock-closed class="size-3.5 text-red-400" />
            @else
                <span class="size-2.5 rounded-full {{ $item['dot'] }}"></span>
            @endif
            {{ $item['name'] }}
        </button>
    @endforeach
</div>
