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

        // Só relatórios registrados em `fusion-report.reports`, e só com o aval da
        // própria definition: nome e parâmetros vêm do navegador. Ver
        // ReportDefinition::authorize().
        $definition = FusionReport::definition($validated['name']);

        if ($definition === null) {
            return response()->json(['success' => false, 'message' => 'Report not found.'], 404);
        }

        $user   = $request->user();
        $params = array_merge($definition->defaultParams(), $validated['params'] ?? []);

        if (! $definition->authorize($user, $params)) {
            return response()->json(['success' => false, 'message' => 'This action is unauthorized.'], 403);
        }

        if (! empty($validated['email']) && ! $definition->canSendTo($user, $validated['email'])) {
            return response()->json(['success' => false, 'message' => 'The report cannot be sent to this e-mail.'], 403);
        }

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
