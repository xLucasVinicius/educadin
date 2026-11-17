@props(['model', 'label' => null, 'description' => null])

{{--
    Campo de valor em reais com máscara automática (estilo caixa eletrônico).
    Uso: <x-money-input model="initialBalance" :label="__('Initial balance')" />
    A propriedade do Livewire guarda o texto formatado ("1.234,56" ou "-50,00").
    A lógica fica num invólucro próprio para não depender de onde o Flux
    coloca os atributos do <input>.
--}}
<div
    x-data="{
        model: @js($model),
        field() {
            return this.$root.querySelector('input');
        },
        format(raw) {
            const negative = String(raw ?? '').includes('-');
            let digits = String(raw ?? '').replace(/\D/g, '').slice(0, 13).padStart(3, '0');
            const cents = digits.slice(-2);
            const reais = digits.slice(0, -2)
                .replace(/^0+(?=\d)/, '')
                .replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            return (negative ? '-' : '') + reais + ',' + cents;
        },
        show(value) {
            const input = this.field();
            if (! input) return;
            const formatted = this.format(value);
            if (input.value !== formatted) input.value = formatted;
        },
        guard(event) {
            if (event.target.tagName !== 'INPUT') return;
            if (event.data && ! /^[0-9-]+$/.test(event.data)) event.preventDefault();
        },
        typed(event) {
            if (event.target.tagName !== 'INPUT') return;
            const formatted = this.format(event.target.value);
            event.target.value = formatted;
            this.$wire.$set(this.model, formatted, false);
        },
    }"
    x-init="show($wire.$get(model))"
    x-effect="show($wire.$get(model))"
    x-on:beforeinput="guard($event)"
    x-on:input="typed($event)"
>
    <flux:field>
        @if ($label)
            <flux:label>{{ $label }}</flux:label>
        @endif

        @if ($description)
            <flux:description>{{ $description }}</flux:description>
        @endif

        <flux:input.group>
            <flux:input.group.prefix>R$</flux:input.group.prefix>
            <flux:input
                type="text"
                inputmode="decimal"
                autocomplete="off"
                :name="$model"
            />
        </flux:input.group>

        <flux:error :name="$model" />
    </flux:field>
</div>
