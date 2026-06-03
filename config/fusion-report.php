<?php

return [
    'api_key' => env('FUSION_REPORT_API_KEY'),

    'log_generations' => env('FUSION_REPORT_LOG_GENERATIONS', true),

    'webhook' => [
        'url'    => env('FUSION_REPORT_WEBHOOK_URL'),
        'secret' => env('FUSION_REPORT_WEBHOOK_SECRET'),

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
    ],

    'defaults' => [
        // Tema padrão aplicado quando a geração não define um via ->theme().
        // Ignorado nas gerações por ID de template.
        'theme' => env('FUSION_REPORT_THEME', 'default'),

        // Locale padrão aplicado quando a geração não define um via ->locale().
        'locale' => env('FUSION_REPORT_LOCALE', 'pt_BR'),

        'params' => [
            // 'TENANT_ID' => '123',
        ],
    ],

    /*
     * Pre-registered report definitions.
     * Key: report name used in FusionReport::make()
     * Value: ReportDefinition class
     */
    'reports' => [
        'tenant_all' => \RiseTechApps\Tenancy\Reports\TenantAllReports::class
        // 'clientes_all' => \App\Reports\ClientesAllReport::class,
    ],
];
