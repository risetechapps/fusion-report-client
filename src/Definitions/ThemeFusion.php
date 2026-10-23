<?php

namespace RiseTechApps\FusionReport\Definitions;

use RiseTechApps\FusionReport\Exceptions\FusionReportException;

/**
* Describes a theme of a report.
 *
 * On the server, the identity of a template is the pair (name + theme), so each
 * ThemeFusion corresponds to a distinct template, with its own .jrxml file,
 * Resources and description.
 *
 * Fluent Construction:
 *
 * ThemeFusion::make('default')
 * ->from(base_path('reports/tenant_all/default.jrxml'))
 * ->withResources(base_path('reports/tenant_all/default.zip'))
 * ->describedAs('Tenant Report');
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
     * Absolute path of the .jrxml file of this theme.
     */
    public function from(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Absolute path of the .zip of resources (images, sub-reports, sources).
     */
    public function withResources(?string $resources): self
    {
        $this->resources = $resources;

        return $this;
    }

    /**
     * Description displayed on the server.
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
     * Validates that the theme is ready to be sent to the server.
     *
     * @throws FusionReportException
     */
    public function validate(): void
    {
        if (empty($this->path)) {
            throw new FusionReportException("Theme '{$this->name}': .jrxml file not defined (use ->from()).");
        }

        if (! is_file($this->path)) {
            throw new FusionReportException("Theme '{$this->name}': File not found in {$this->path}.");
        }

        if ($this->resources !== null && ! is_file($this->resources)) {
            throw new FusionReportException("Theme '{$this->name}': Resources not found in {$this->resources}.");
        }
    }
}
