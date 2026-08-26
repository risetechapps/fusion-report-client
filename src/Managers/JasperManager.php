<?php

namespace RiseTechApps\FusionReport\Managers;

use RiseTechApps\FusionReport\Http\FusionReportHttp;

/**
 * Monitoramento do servidor Jasper.
 *
 * ATENÇÃO: todas as rotas `/api/v1/jasper/*` são restritas ao dashboard
 * (Sanctum) — expõem estado global do servidor Java, que não é escopado por
 * cliente. Autenticado por `X-API-KEY`, o servidor responde 401 e o package
 * converte em `FusionReportException`.
 *
 * Ver "Métodos indisponíveis via X-API-KEY" no README.
 */
class JasperManager
{
    public function __construct(private readonly FusionReportHttp $http) {}

    public function formats(): array
    {
        return $this->http->get('/api/v1/jasper/formats');
    }

    public function status(): array
    {
        return $this->http->get('/api/v1/jasper/status');
    }

    public function jobs(): array
    {
        return $this->http->get('/api/v1/jasper/jobs');
    }

    public function fontsStatus(): array
    {
        return $this->http->get('/api/v1/jasper/fonts/status');
    }

    public function fontsSync(): array
    {
        return $this->http->post('/api/v1/jasper/fonts/sync', []);
    }
}
