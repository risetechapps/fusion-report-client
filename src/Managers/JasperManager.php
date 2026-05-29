<?php

namespace RiseTechApps\FusionReport\Managers;

use RiseTechApps\FusionReport\Http\FusionReportHttp;

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
