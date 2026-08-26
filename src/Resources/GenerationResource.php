<?php

namespace RiseTechApps\FusionReport\Resources;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Http\FusionReportHttp;
use RiseTechApps\FusionReport\Models\FusionReportGeneration;

class GenerationResource implements \JsonSerializable
{
    public function __construct(
        private array $data,
        private readonly FusionReportHttp $http,
    ) {}

    public function id(): string
    {
        return $this->data['id'];
    }

    public function reportId(): ?string
    {
        return $this->data['report_id'] ?? null;
    }

    public function mode(): string
    {
        return $this->data['mode'] ?? 'sync';
    }

    public function currentStatus(): string
    {
        return $this->data['status'] ?? 'unknown';
    }

    /** Sempre 0: `progress_percent` está em `$hidden` no servidor. */
    public function progressPercent(): int
    {
        return $this->data['progress_percent'] ?? 0;
    }

    /** Sempre null: `error_message` está em `$hidden` no servidor. */
    public function errorMessage(): ?string
    {
        return $this->data['error_message'] ?? null;
    }

    /** @return array<string> */
    public function requestedFormats(): array
    {
        return $this->data['requested_formats'] ?? [];
    }

    public function templateName(): ?string
    {
        return $this->data['template_name'] ?? null;
    }

    public function templateTheme(): ?string
    {
        return $this->data['template_theme'] ?? null;
    }

    public function frpId(): ?string
    {
        return $this->data['frp_id'] ?? null;
    }

    public function expiresAt(): ?string
    {
        return $this->data['expires_at'] ?? null;
    }

    public function createdAt(): ?string
    {
        return $this->data['created_at'] ?? null;
    }

    public function updatedAt(): ?string
    {
        return $this->data['updated_at'] ?? null;
    }

    public function isPending(): bool
    {
        return $this->currentStatus() === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->currentStatus() === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->currentStatus() === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->currentStatus() === 'failed';
    }

    public function isCancelled(): bool
    {
        return $this->currentStatus() === 'cancelled';
    }

    /**
     * Reconsulta o servidor e devolve o status atualizado.
     *
     * ATENÇÃO: depende de `get()` — indisponível via `X-API-KEY`. Ver o
     * docblock de `get()`.
     */
    public function status(): string
    {
        return $this->get()->currentStatus();
    }

    /**
     * Reconsulta o servidor e atualiza esta instância (não cria uma nova).
     *
     * ATENÇÃO: `GET /api/v1/generations/{id}` é restrito ao dashboard
     * (Sanctum). Autenticado por `X-API-KEY`, o servidor responde 401 e o
     * package converte em `FusionReportException`. Não há polling na
     * integração por API key — o resultado da geração assíncrona chega pelo
     * webhook (`onWebhookReceived()`).
     */
    public function get(): static
    {
        $this->data = $this->http->get("/api/v1/generations/{$this->id()}");

        if (config('fusion-report.log_generations', true)) {
            FusionReportGeneration::updateFromGeneration($this);
        }

        return $this;
    }

    /**
     * ATENÇÃO: `DELETE /api/v1/generations/{id}` é restrito ao dashboard
     * (Sanctum). Autenticado por `X-API-KEY`, o servidor responde 401 e o
     * package converte em `FusionReportException`.
     */
    public function cancel(): void
    {
        $this->http->delete("/api/v1/generations/{$this->id()}");

        if (config('fusion-report.log_generations', true)) {
            FusionReportGeneration::where('generation_uuid', $this->id())
                ->update(['status' => 'cancelled']);
        }
    }

    /**
     * Re-exporta esta geração para outro formato, a partir do .frp intermediário.
     *
     * Diferente de `FileResource::export()`, que parte de um arquivo específico,
     * aqui o servidor localiza sozinho o .frp da geração. Retorna uma geração
     * nova — a original permanece intacta.
     *
     * Formatos aceitos: pdf, xlsx, xls, docx, odt, csv, html ('frp' não, já que
     * é o próprio intermediário).
     *
     * @throws \RiseTechApps\FusionReport\Exceptions\ReportNotFoundException
     *         quando a geração não tem .frp disponível ou ele já expirou
     */
    public function export(string $format): static
    {
        $response = $this->http->post("/api/v1/generations/{$this->id()}/export", [
            'format' => $format,
        ]);

        return new static($response, $this->http);
    }

    /** @return Collection<int, FileResource> */
    public function files(): Collection
    {
        return collect($this->data['files'] ?? [])
            ->map(fn(array $file) => new FileResource($file, $this->http));
    }

    public function download(string $format): Response
    {
        $file = $this->files()->first(fn(FileResource $f) => $f->format() === $format);

        if ($file === null) {
            throw new FusionReportException("No file with format '{$format}' found in this generation.");
        }

        return $file->download();
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
