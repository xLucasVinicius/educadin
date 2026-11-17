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
                'border-accent bg-accent/10 text-white' => $item['id'] === $activeId,
                'border-zinc-800 text-zinc-400 hover:border-zinc-600 hover:text-white' => $item['id'] !== $activeId,
            ])
        >
            <span class="size-2.5 rounded-full {{ $item['dot'] }}"></span>
            {{ $item['name'] }}
        </button>
    @endforeach
</div>
