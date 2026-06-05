<?php

namespace RiseTechApps\FusionReport\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RiseTechApps\FusionReport\Facades\FusionReport;

class GenerateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'   => ['required', 'string'],
            'params' => ['sometimes', 'array'],
            'format' => ['sometimes'],
            'mode'   => ['sometimes', 'in:sync,async'],
            'email'  => ['sometimes', 'email'],
        ]);

        $builder = FusionReport::make($validated['name'], $validated['params'] ?? [])
            ->locale(app()->getLocale());

        if (! empty($validated['format'])) {
            $builder->format($validated['format']);
        }

        if (! empty($validated['email'])) {
            $builder->context([
                'send_email' => true,
                'email'      => $validated['email'],
            ]);
        }

        $generation = ($validated['mode'] ?? 'async') === 'sync'
            ? $builder->generate()
            : $builder->generateAsync();

        return response()->json([
            'success' => true,
            'data'    => [
                'generation_id' => $generation->id(),
                'status'        => $generation->currentStatus(),
                'mode'          => $generation->mode(),
                'files'         => $generation->files()
                    ->map(fn($file) => [
                        'id'         => $file->id(),
                        'filename'   => $file->filename(),
                        'format'     => $file->format(),
                        'url'        => $file->url(),
                        'expires_at' => $file->expiresAt(),
                    ])
                    ->values(),
            ],
        ], 200);
    }
}
