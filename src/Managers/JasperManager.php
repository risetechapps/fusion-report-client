<?php

namespace RiseTechApps\FusionReport\Managers;

use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Http\FusionReportHttp;

/**
 * Monitoramento do servidor Jasper.
 *
 * ATENÇÃO: as rotas `/api/v1/fusion-report/*` são restritas ao dashboard
 * (Sanctum). Autenticado por `X-API-KEY`, o servidor responde 401 e o package
 * converte em `AuthenticationException`. `status`, `jobs`, `metrics` e
 * `fontsStatus` exigem ainda um usuário administrador — expõem estado global
 * do servidor Java, que não é escopado por cliente.
 *
 * Antes da 1.0.0 oficial os caminhos eram `/api/v1/jasper/*`, que o servidor
 * renomeou durante o desenvolvimento: toda chamada respondia 404.
 *
 * Ver "Métodos indisponíveis via X-API-KEY" no README.
 */
class JasperManager
{
    public function __construct(private readonly FusionReportHttp $http) {}

    public function formats(): array
    {
        return $this->http->get('/api/v1/fusion-report/formats');
    }

    public function health(): array
    {
        return $this->http->get('/api/v1/fusion-report/health');
    }

    public function status(): array
    {
        return $this->http->get('/api/v1/fusion-report/status');
    }

    public function metrics(): array
    {
        return $this->http->get('/api/v1/fusion-report/metrics');
    }

    public function jobs(): array
    {
        return $this->http->get('/api/v1/fusion-report/jobs');
    }

    public function fontsStatus(): array
    {
        return $this->http->get('/api/v1/fusion-report/fonts/status');
    }

    /**
     * @deprecated O servidor não expõe mais a sincronização de fontes pela API.
     *
     * @throws FusionReportException sempre
     */
    public function fontsSync(): array
    {
        throw new FusionReportException(
            'Font sync is no longer available through the API. Fonts are synced by the Fusion Report server itself.'
        );
    }
}
