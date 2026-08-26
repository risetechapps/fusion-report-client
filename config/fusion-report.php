<?php

return [
    'api_key' => env('FUSION_REPORT_API_KEY'),

    'log_generations' => env('FUSION_REPORT_LOG_GENERATIONS', true),

    'webhook' => [
        'url'    => env('FUSION_REPORT_WEBHOOK_URL'),
        'secret' => env('FUSION_REPORT_WEBHOOK_SECRET'),

        /*
         * Exige assinatura HMAC válida no callback.
         *
         * Com true (padrão) e 'secret' vazio, a rota recusa a requisição em vez
         * de aceitar payload não assinado — sem isso, qualquer POST conseguiria
         * alterar status e URLs de download das gerações.
         *
         * Só desligue em desenvolvimento local, ciente de que a rota fica
         * aberta a quem alcançar a aplicação.
         */
        'require_signature' => env('FUSION_REPORT_WEBHOOK_REQUIRE_SIGNATURE', true),

        /*
         * Path e middleware da rota que recebe o callback do servidor.
         * O package é agnóstico de contexto (tenancy, auth, etc.): a app
         * hospedeira injeta aqui o middleware que estabelece o contexto
         * necessário ANTES do WebhookController rodar — por exemplo, um
         * middleware que lê o tenant_id da URL e inicializa o tenant, de
         * modo que a consulta da geração já caia no banco correto.
         */
        'path'       => env('FUSION_REPORT_WEBHOOK_PATH', '/fusion/webhook'),
        'middleware' => ['api'],

        /*
         * Processa o callback numa fila em vez de dentro da requisição.
         *
         * O servidor concede 15s para a entrega e reentrega até 3 vezes se não
         * receber resposta. Inline, um onWebhookReceived() que manda e-mail ou
         * sobe arquivo passa desse limite com facilidade: o trabalho é feito,
         * mas o servidor registra falha e reentrega.
         *
         * false (padrão) processa inline. true usa a fila padrão; uma string
         * usa a fila com esse nome — ex.: 'webhooks'.
         *
         * Requer worker rodando. Ligue somente com fila configurada, senão os
         * callbacks ficam parados. O contexto (tenancy, etc.) estabelecido pelo
         * middleware precisa alcançar o job — ver a seção "Webhook" no README.
         */
        'queue' => env('FUSION_REPORT_WEBHOOK_QUEUE', false),

        /*
         * Minutos que o package lembra de um callback já processado, no cache.
         *
         * O servidor reentrega até 3 vezes (backoff 10s/60s/180s), então o
         * padrão de 24h cobre a janela de retry com folga. Serve para não
         * repetir onWebhookReceived() e os eventos — a linha em
         * fusion_report_generations é atualizada de qualquer forma.
         *
         * Com o driver de cache 'null' não há deduplicação por esta via.
         */
        'deduplication_ttl' => env('FUSION_REPORT_WEBHOOK_DEDUP_TTL', 1440),
    ],

    'defaults' => [
        // Tema padrão aplicado quando a geração não define um via ->theme().
        // Ignorado nas gerações por ID de template.
        'theme' => env('FUSION_REPORT_THEME', 'default'),

        // Locale padrão aplicado quando a geração não define um via ->locale().
        'locale' => env('FUSION_REPORT_LOCALE', 'pt_BR'),

        /*
         * Formato(s) aplicados quando a geração não define nenhum via ->format().
         *
         * O servidor exige pelo menos um formato: sem este default, uma geração
         * sem ->format() explícito é recusada com 422.
         *
         * Aceita string ('pdf'), lista separada por vírgula ('pdf,xlsx') ou
         * array (['pdf', 'xlsx']). Válidos: pdf, xlsx, xls, docx, odt, csv,
         * html, frp.
         */
        'format' => env('FUSION_REPORT_FORMAT', 'pdf'),

        'params' => [
            // params => 123
        ],
    ],

    /*
     * Pre-registered report definitions.
     * Key: report name used in FusionReport::make()
     * Value: ReportDefinition class
     */
    'reports' => [
        // 'clientes_all' => \App\Reports\ClientesAllReport::class,
    ],
];
