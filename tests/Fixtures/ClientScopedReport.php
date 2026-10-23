<?php

namespace RiseTechApps\FusionReport\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

/** Libera a rota HTTP só para o `cliente_id` do próprio usuário. */
class ClientScopedReport extends TestReport
{
    public function name(): string
    {
        return 'por_cliente';
    }

    public function authorize(?Authenticatable $user, array $params): bool
    {
        return $user !== null && (int) ($params['cliente_id'] ?? 0) === (int) data_get($user, 'cliente_id');
    }
}
