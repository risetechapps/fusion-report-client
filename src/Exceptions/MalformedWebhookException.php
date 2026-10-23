<?php

namespace RiseTechApps\FusionReport\Exceptions;

/**
 * O corpo do webhook passou pela verificação de assinatura mas não tem a forma
 * esperada — falta `id` ou `status`.
 *
 * Reprocessar não resolve, então o controller responde 422 para o servidor
 * parar de retentar.
 */
class MalformedWebhookException extends FusionReportException {}
