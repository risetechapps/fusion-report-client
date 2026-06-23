<?php

namespace RiseTechApps\FusionReport\Definitions;

use RiseTechApps\FusionReport\Exceptions\FusionReportException;

/**
 * Descreve um tema de um relatório.
 *
 * No servidor a identidade de um template é o par (name + theme), portanto cada
 * ThemeFusion corresponde a um template distinto, com seu próprio arquivo .jrxml,
 * resources e descrição.
 *
 * Construção fluente:
 *
 *   ThemeFusion::make('default')
 *       ->from(base_path('reports/tenant_all/default.jrxml'))
 *       ->withResources(base_path('reports/tenant_all/default.zip'))
 *       ->describedAs('Tenant Report');
 */
final class ThemeFusion
{
    private ?string $path = null;

    private ?string $resources = null;

    private ?string $description = null;

    private function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    /**
     * Caminho absoluto do arquivo .jrxml deste tema.
     */
    public function from(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Caminho absoluto do .zip de recursos (imagens, sub-reports, fontes).
     */
    public function withResources(?string $resources): self
    {
        $this->resources = $resources;

        return $this;
    }

    /**
     * Descrição exibida no servidor.
     */
    public function describedAs(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function resources(): ?string
    {
        return $this->resources;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * Valida que o tema está pronto para ser enviado ao servidor.
     *
     * @throws FusionReportException
     */
    public function validate(): void
    {
        if (empty($this->path)) {
            throw new FusionReportException("Tema '{$this->name}': arquivo .jrxml não definido (use ->from()).");
        }

        if (! is_file($this->path)) {
            throw new FusionReportException("Tema '{$this->name}': arquivo não encontrado em {$this->path}.");
        }

        if ($this->resources !== null && ! is_file($this->resources)) {
            throw new FusionReportException("Tema '{$this->name}': resources não encontrado em {$this->resources}.");
        }
    }
}
