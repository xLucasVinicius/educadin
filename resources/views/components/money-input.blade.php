@props(['model', 'label' => null, 'description' => null, 'allowNegative' => false])

{{--
    Campo de valor em reais com máscara automática (estilo caixa eletrônico).
    Uso: <x-money-input model="initialBalance" :label="__('Initial balance')" />
    Por padrão só aceita valores positivos; passe :allow-negative="true" para
    permitir o sinal de menos (ex.: saldo inicial de uma conta).
    O campo exibe "R$ 1.234,56" / "R$ -50,00", mas a propriedade do Livewire
    guarda só o número formatado ("1.234,56" ou "-50,00").
    A lógica fica num invólucro próprio para não depender de onde o Flux
    coloca os atributos do <input>.
--}}
<div
    x-data="{
        model: @js($model),
        negative: @js($allowNegative),
        field() {
            return this.$root.querySelector('input');
        },
        plain(raw) {
            const negative = this.negative && String(raw ?? '').includes('-');
            const digits = String(raw ?? '').replace(/\D/g, '').slice(0, 13).padStart(3, '0');
            const cents = digits.slice(-2);
            const reais = digits.slice(0, -2)
                .replace(/^0+(?=\d)/, '')
                .replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            return (negative ? '-' : '') + reais + ',' + cents;
        },
        display(raw) {
            return 'R$ ' + this.plain(raw);
        },
        show(value) {
            const input = this.field();
            if (! input) return;
            const shown = this.display(value);
            if (input.value !== shown) input.value = shown;
        },
        guard(event) {
            if (event.target.tagName !== 'INPUT') return;
            const allowed = this.negative ? /^[0-9-]+$/ : /^[0-9]+$/;
            if (event.data && ! allowed.test(event.data)) event.preventDefault();
        },
        typed(event) {
            if (event.target.tagName !== 'INPUT') return;
            event.target.value = this.display(event.target.value);
            this.$wire.$set(this.model, this.plain(event.target.value), false);
        },
    }"
    x-init="show($wire.$get(model))"
    x-effect="show($wire.$get(model))"
    x-on:beforeinput="guard($event)"
    x-on:input="typed($event)"
>
    <flux:input
        type="text"
        inputmode="decimal"
        autocomplete="off"
        :name="$model"
        :label="$label"
        :description="$description"
    />
</div>
