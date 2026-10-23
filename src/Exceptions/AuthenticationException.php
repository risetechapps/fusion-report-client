<?php

namespace RiseTechApps\FusionReport\Exceptions;

/**
 * HTTP 401 — o servidor não aceitou a credencial.
 *
 * Causas comuns: `api_key` ausente ou inválida no config, ou rota restrita ao
 * dashboard (Sanctum) sendo chamada com `X-API-KEY`. Ver "Métodos indisponíveis
 * via X-API-KEY" no README.
 */
class AuthenticationException extends FusionReportException {}
