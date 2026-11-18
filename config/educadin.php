<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Donos do sistema
    |--------------------------------------------------------------------------
    |
    | E-mails (separados por vírgula, em ADMIN_EMAILS no .env) que são donos do
    | sistema: sempre têm acesso ao painel de admin e são os únicos que podem
    | dar ou tirar o acesso de admin de outras pessoas. O e-mail precisa estar
    | verificado para valer.
    |
    */

    'owner_emails' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('ADMIN_EMAILS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Planos
    |--------------------------------------------------------------------------
    |
    | Conteúdo da tela "Planos". POR ENQUANTO SÃO INFORMAÇÕES FICTÍCIAS: as
    | vantagens e os preços ainda vão ser definidos. Para atualizar, basta
    | editar os textos abaixo (nenhuma outra parte do app precisa mudar).
    |
    | - free.features: o que o plano Gratuito oferece.
    | - premium.features: o que só o Premium desbloqueia (além do Gratuito).
    |
    */

    'plans' => [
        'free' => [
            'name' => 'Gratuito',
            'price' => 'R$ 0',
            'period' => 'para sempre',
            'description' => 'O essencial para organizar suas finanças.',
            'limits' => ['accounts' => 3, 'cards' => 2, 'recurring' => 3, 'installments' => 12, 'history' => false],
            'features' => [
                'Até 3 contas',
                'Até 2 cartões',
                'Lançamentos de receitas e despesas',
                'Até 3 lançamentos recorrentes ativos',
                'Compras parceladas em até 12x',
                'Fatura do cartão e transferências entre contas',
                'Painel com resumo do mês',
                'Desempenho do mês e do ano atuais',
            ],
        ],
        'premium' => [
            'name' => 'Premium',
            'price' => 'R$ 19,90',
            'period' => 'por mês',
            'description' => 'Sem limites e com ferramentas extras.',
            'limits' => ['accounts' => null, 'cards' => null, 'recurring' => null, 'installments' => 24, 'history' => true],
            'features' => [
                'Contas e cartões ilimitados',
                'Lançamentos recorrentes ilimitados',
                'Compras parceladas em até 24x',
                'Desempenho com todo o histórico',
                'Em breve: metas e orçamento por categoria',
                'Em breve: exportar lançamentos para planilha',
            ],
        ],
    ],

];
