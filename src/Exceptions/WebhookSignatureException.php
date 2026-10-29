<?php

namespace RiseTechApps\FusionReport\Exceptions;

class WebhookSignatureException extends FusionReportException
{
    /** Header `X-Webhook-Signature` ausente. */
    public const MISSING = 'missing_signature';

    /** Header presente, mas não confere com timestamp + corpo + segredo. */
    public const INVALID = 'invalid_signature';

    /** Header `X-Webhook-Timestamp` ausente ou não numérico. */
    public const MISSING_TIMESTAMP = 'missing_timestamp';

    /** Assinatura válida, mas o timestamp está fora da janela de tolerância. */
    public const EXPIRED = 'expired_timestamp';

    public function __construct(string $message, private readonly string $reason = self::INVALID)
    {
        parent::__construct($message);
    }

    public static function missing(): self
    {
        return new self('Missing X-Webhook-Signature header.', self::MISSING);
    }

    public static function invalid(): self
    {
        return new self('Invalid webhook signature.', self::INVALID);
    }

    public static function missingTimestamp(): self
    {
        return new self('Missing or invalid X-Webhook-Timestamp header.', self::MISSING_TIMESTAMP);
    }

    public static function expired(): self
    {
        return new self('Webhook timestamp is outside the tolerance window.', self::EXPIRED);
    }

    /**
     * Motivo devolvido no 401. Seguro de expor: quem chamou já sabe o que
     * mandou, e nenhum deles revela a assinatura esperada.
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
