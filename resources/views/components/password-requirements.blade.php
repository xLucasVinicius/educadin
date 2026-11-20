{{--
  Envolve o campo de senha e mostra os requisitos logo abaixo:
  vermelho enquanto não cumpridos, verde quando cumpridos.
  Só o campo de senha usa isto; a confirmação não precisa.
--}}
<div
    x-data="{
        pw: '',
        get rules() {
            return [
                { key: 'length', met: this.pw.length >= 8 },
                { key: 'upper', met: /\p{Lu}/u.test(this.pw) },
                { key: 'lower', met: /\p{Ll}/u.test(this.pw) },
                { key: 'number', met: /\p{N}/u.test(this.pw) },
                { key: 'symbol', met: /[\p{Z}\p{S}\p{P}]/u.test(this.pw) },
            ]
        },
    }"
    x-on:input="if ($event.target.matches('input')) pw = $event.target.value"
    x-on:password-cleared.window="pw = ''"
    class="flex flex-col gap-2"
>
    {{ $slot }}

    <ul class="flex flex-col gap-1 text-xs" data-test="password-requirements">
        @foreach ([
            'length' => __('At least 8 characters'),
            'upper' => __('One uppercase letter'),
            'lower' => __('One lowercase letter'),
            'number' => __('One number'),
            'symbol' => __('One special character (e.g. ! @ # $ %)'),
        ] as $key => $label)
            <li
                data-test="password-rule-{{ $key }}"
                x-bind:data-met="rules.find(r => r.key === '{{ $key }}').met ? 'true' : 'false'"
                x-bind:style="rules.find(r => r.key === '{{ $key }}').met ? 'color: #34d399' : 'color: #f87171'"
                class="flex items-center gap-1.5"
                style="color: #f87171"
            >
                <span class="inline-flex size-3.5 items-center justify-center">
                    <flux:icon.check x-show="rules.find(r => r.key === '{{ $key }}').met" variant="micro" class="size-3.5" />
                    <flux:icon.x-mark x-show="! rules.find(r => r.key === '{{ $key }}').met" variant="micro" class="size-3.5" />
                </span>
                <span>{{ $label }}</span>
            </li>
        @endforeach
    </ul>
</div>
