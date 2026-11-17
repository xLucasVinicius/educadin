<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'EducaDin') }} - Organize suas finanças</title>
        <meta name="description" content="Reúna contas e cartões, registre seus lançamentos e acompanhe seu desempenho financeiro mês a mês.">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="relative min-h-screen overflow-x-hidden bg-zinc-900 text-zinc-100 antialiased">

        {{-- Brilho dourado suave no topo --}}
        <div class="pointer-events-none absolute inset-x-0 top-0 h-[640px] bg-[radial-gradient(60%_60%_at_50%_0%,#f2a9002e,transparent)]"></div>

        {{-- ============ Cabeçalho ============ --}}
        <header class="sticky top-0 z-50 border-b border-white/10 bg-zinc-900/60 backdrop-blur-xl">
            <div class="mx-auto flex h-20 max-w-6xl items-center justify-between px-6">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                    <span class="flex size-9 items-center justify-center rounded-lg bg-gold-500 text-zinc-900">
                        <x-app-logo-icon class="size-6 fill-current" />
                    </span>
                    <span class="text-lg font-bold tracking-tight text-white">Educa<span class="text-gold-500">Din</span></span>
                </a>

                <nav class="flex items-center gap-2 text-sm font-medium">
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-lg bg-gold-500 px-4 py-2 font-semibold text-zinc-900 transition hover:bg-gold-400">
                            Ir para o painel
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-lg px-4 py-2 text-zinc-300 transition hover:text-white">
                            Entrar
                        </a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="rounded-lg bg-gold-500 px-4 py-2 font-semibold text-zinc-900 transition hover:bg-gold-400">
                                Criar conta
                            </a>
                        @endif
                    @endauth
                </nav>
            </div>
        </header>

        <main class="relative z-10">

            {{-- ============ Hero ============ --}}
            <section class="mx-auto max-w-4xl px-6 pt-14 pb-16 text-center sm:pt-20">
                <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3.5 py-1.5 text-xs font-medium text-zinc-300">
                    <span class="size-1.5 rounded-full bg-gold-500"></span>
                    Controle financeiro pessoal
                </span>

                <h1 class="mt-6 text-4xl font-bold tracking-tight text-white sm:text-6xl sm:leading-[1.05]">
                    Suas finanças,<br class="hidden sm:block">
                    <span class="whitespace-nowrap text-gold-500">sob controle.</span>
                </h1>

                <p class="mx-auto mt-6 max-w-2xl text-base text-zinc-400 sm:text-lg">
                    Reúna suas contas e cartões, registre cada lançamento e acompanhe seu desempenho mês a mês. Tudo em um só lugar, de forma simples.
                </p>

                <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    @auth
                        <a href="{{ route('dashboard') }}" class="w-full rounded-xl bg-gold-500 px-7 py-3 text-sm font-semibold text-zinc-900 shadow-lg shadow-gold-500/20 transition hover:bg-gold-400 sm:w-auto">
                            Ir para o painel
                        </a>
                    @else
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="w-full rounded-xl bg-gold-500 px-7 py-3 text-sm font-semibold text-zinc-900 shadow-lg shadow-gold-500/20 transition hover:bg-gold-400 sm:w-auto">
                                Começar agora
                            </a>
                        @endif
                        <a href="{{ route('login') }}" class="w-full rounded-xl border border-white/15 px-7 py-3 text-sm font-semibold text-white transition hover:bg-white/5 sm:w-auto">
                            Já tenho conta
                        </a>
                    @endauth
                </div>
            </section>

            {{-- ============ Prévia do painel (exemplo ilustrativo) ============ --}}
            <section class="mx-auto max-w-5xl px-6 pb-20">
                <div class="rounded-3xl border border-white/10 bg-zinc-800/50 p-3 shadow-2xl shadow-black/40 backdrop-blur sm:p-5">

                    {{-- Cartões de resumo --}}
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl border border-white/10 bg-zinc-900/80 p-5">
                            <p class="text-xs font-medium text-zinc-400">Saldo total</p>
                            <p class="mt-2 text-2xl font-bold text-white">R$ 12.480,50</p>
                            <p class="mt-1 text-xs font-medium text-emerald-400">+8,2% neste mês</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-zinc-900/80 p-5">
                            <p class="text-xs font-medium text-zinc-400">Receitas</p>
                            <p class="mt-2 text-2xl font-bold text-white">R$ 6.200,00</p>
                            <p class="mt-1 text-xs font-medium text-zinc-500">Novembro</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-zinc-900/80 p-5">
                            <p class="text-xs font-medium text-zinc-400">Despesas</p>
                            <p class="mt-2 text-2xl font-bold text-white">R$ 3.845,30</p>
                            <p class="mt-1 text-xs font-medium text-zinc-500">Novembro</p>
                        </div>
                    </div>

                    <div class="mt-3 grid gap-3 lg:grid-cols-5">

                        {{-- Gráfico de barras --}}
                        <div class="rounded-2xl border border-white/10 bg-zinc-900/80 p-5 lg:col-span-3">
                            <p class="text-sm font-semibold text-white">Desempenho mensal</p>
                            <p class="text-xs text-zinc-500">Saldo ao final de cada mês</p>

                            <div class="mt-6 flex h-36 items-end gap-3">
                                <div class="h-[45%] flex-1 rounded-t-md bg-gold-500/25"></div>
                                <div class="h-[58%] flex-1 rounded-t-md bg-gold-500/25"></div>
                                <div class="h-[40%] flex-1 rounded-t-md bg-gold-500/25"></div>
                                <div class="h-[72%] flex-1 rounded-t-md bg-gold-500/25"></div>
                                <div class="h-[64%] flex-1 rounded-t-md bg-gold-500/25"></div>
                                <div class="h-[92%] flex-1 rounded-t-md bg-gold-500"></div>
                            </div>
                            <div class="mt-2 flex gap-3 text-center text-[11px] text-zinc-500">
                                <span class="flex-1">Jun</span>
                                <span class="flex-1">Jul</span>
                                <span class="flex-1">Ago</span>
                                <span class="flex-1">Set</span>
                                <span class="flex-1">Out</span>
                                <span class="flex-1 font-semibold text-gold-400">Nov</span>
                            </div>
                        </div>

                        {{-- Últimos lançamentos --}}
                        <div class="rounded-2xl border border-white/10 bg-zinc-900/80 p-5 lg:col-span-2">
                            <p class="text-sm font-semibold text-white">Últimos lançamentos</p>

                            <ul class="mt-4 divide-y divide-white/5 text-sm">
                                <li class="flex items-center justify-between py-3">
                                    <div>
                                        <p class="font-medium text-zinc-100">Mercado</p>
                                        <p class="text-xs text-zinc-500">Cartão de crédito</p>
                                    </div>
                                    <span class="font-semibold text-red-400">- R$ 214,90</span>
                                </li>
                                <li class="flex items-center justify-between py-3">
                                    <div>
                                        <p class="font-medium text-zinc-100">Salário</p>
                                        <p class="text-xs text-zinc-500">Conta corrente</p>
                                    </div>
                                    <span class="font-semibold text-emerald-400">+ R$ 6.200,00</span>
                                </li>
                                <li class="flex items-center justify-between py-3">
                                    <div>
                                        <p class="font-medium text-zinc-100">Streaming</p>
                                        <p class="text-xs text-zinc-500">Cartão de crédito</p>
                                    </div>
                                    <span class="font-semibold text-red-400">- R$ 39,90</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <p class="mt-3 text-center text-xs text-zinc-600">Exemplo ilustrativo com dados fictícios.</p>
            </section>

            {{-- ============ Recursos ============ --}}
            <section class="mx-auto max-w-6xl px-6 pb-24">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-2xl font-bold tracking-tight text-white sm:text-3xl">O essencial para organizar seu dinheiro</h2>
                    <p class="mt-3 text-zinc-400">Sem complicação: o que você precisa para saber para onde o dinheiro vai.</p>
                </div>

                <div class="mt-12 grid gap-4 md:grid-cols-3">
                    <div class="rounded-2xl border border-white/10 bg-zinc-800/40 p-6">
                        <span class="flex size-11 items-center justify-center rounded-xl bg-gold-500/10 text-gold-400">
                            <flux:icon.credit-card variant="outline" class="size-6" />
                        </span>
                        <h3 class="mt-5 text-base font-semibold text-white">Contas e cartões</h3>
                        <p class="mt-2 text-sm leading-relaxed text-zinc-400">Cadastre suas contas e cartões e veja todos os saldos reunidos, sem precisar abrir vários aplicativos.</p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-zinc-800/40 p-6">
                        <span class="flex size-11 items-center justify-center rounded-xl bg-gold-500/10 text-gold-400">
                            <flux:icon.list-bullet variant="outline" class="size-6" />
                        </span>
                        <h3 class="mt-5 text-base font-semibold text-white">Lançamentos</h3>
                        <p class="mt-2 text-sm leading-relaxed text-zinc-400">Registre receitas e despesas em poucos cliques e mantenha o histórico organizado por conta e por período.</p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-zinc-800/40 p-6">
                        <span class="flex size-11 items-center justify-center rounded-xl bg-gold-500/10 text-gold-400">
                            <flux:icon.chart-bar variant="outline" class="size-6" />
                        </span>
                        <h3 class="mt-5 text-base font-semibold text-white">Desempenho</h3>
                        <p class="mt-2 text-sm leading-relaxed text-zinc-400">Gráficos mensais mostram como suas finanças evoluem e ajudam a identificar onde dá para economizar.</p>
                    </div>
                </div>
            </section>

            {{-- ============ Chamada final ============ --}}
            @guest
                @if (Route::has('register'))
                    <section class="mx-auto max-w-4xl px-6 pb-24">
                        <div class="rounded-3xl border border-gold-500/20 bg-gradient-to-b from-gold-500/10 to-transparent px-6 py-12 text-center sm:px-12">
                            <h2 class="text-2xl font-bold tracking-tight text-white sm:text-3xl">Pronto para organizar suas finanças?</h2>
                            <p class="mx-auto mt-3 max-w-xl text-zinc-400">Crie sua conta e comece a acompanhar seus gastos hoje mesmo.</p>
                            <a href="{{ route('register') }}" class="mt-7 inline-block rounded-xl bg-gold-500 px-7 py-3 text-sm font-semibold text-zinc-900 shadow-lg shadow-gold-500/20 transition hover:bg-gold-400">
                                Criar minha conta
                            </a>
                        </div>
                    </section>
                @endif
            @endguest
        </main>

        {{-- ============ Rodapé ============ --}}
        <footer class="relative z-10 border-t border-white/10">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-2 px-6 py-8 text-xs text-zinc-500 sm:flex-row">
                <span class="font-semibold text-zinc-300">Educa<span class="text-gold-500">Din</span></span>
                <span>&copy; {{ date('Y') }} EducaDin. Todos os direitos reservados.</span>
            </div>
        </footer>

    </body>
</html>
