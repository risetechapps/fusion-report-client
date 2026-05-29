<?php

return [
    'api_key' => env('FUSION_REPORT_API_KEY'),

    'log_generations' => env('FUSION_REPORT_LOG_GENERATIONS', true),

    'webhook' => [
        'url'    => env('FUSION_REPORT_WEBHOOK_URL'),
        'secret' => env('FUSION_REPORT_WEBHOOK_SECRET'),
    ],

    'defaults' => [
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
