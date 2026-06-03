<?php

namespace RiseTechApps\FusionReport\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RiseTechApps\FusionReport\Resources\GenerationResource;

class FusionReportGeneration extends Model
{
    use MassPrunable;
    protected $table = 'fusion_report_generations';

    protected $fillable = [
        'generation_uuid',
        'frp_uuid',
        'name',
        'formats',
        'download_urls',
        'status',
        'context',
        'loggable_type',
        'loggable_id',
    ];

    protected $casts = [
        'formats'       => 'array',
        'download_urls' => 'array',
        'context'       => 'array',
    ];

    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        return static::where('created_at', '<', now()->subDays(90));
    }

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function createFromGeneration(GenerationResource $generation, ?Model $owner = null, array $context = []): static
    {
        $downloadUrls = $generation->isCompleted()
            ? $generation->files()
                ->mapWithKeys(fn($file) => [$file->format() => $file->url()])
                ->toArray()
            : null;

        return static::create([
            'generation_uuid' => $generation->id(),
            'frp_uuid'        => $generation->frpId(),
            'name'            => $generation->templateName(),
            'formats'         => $generation->requestedFormats(),
            'download_urls'   => $downloadUrls,
            'status'          => $generation->currentStatus(),
            'context'         => $context ?: null,
            'loggable_type'   => $owner ? get_class($owner) : null,
            'loggable_id'     => $owner?->getKey(),
        ]);
    }

    public static function updateFromGeneration(GenerationResource $generation): void
    {
        $record = static::where('generation_uuid', $generation->id())->first();

        if ($record === null) {
            return;
        }

        $data = ['status' => $generation->currentStatus()];

        if ($generation->isCompleted()) {
            $data['frp_uuid']      = $generation->frpId();
            $data['download_urls'] = $generation->files()
                ->mapWithKeys(fn($file) => [$file->format() => $file->url()])
                ->toArray();
        }

        $record->update($data);
    }
}
