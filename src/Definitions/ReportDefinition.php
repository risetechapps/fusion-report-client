<?php

namespace RiseTechApps\FusionReport\Definitions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;
use RiseTechApps\FusionReport\Resources\GenerationResource;
use RiseTechApps\FusionReport\Webhook\WebhookPayload;

abstract class ReportDefinition
{
    /**
     * Nome do relatório no servidor (parte da identidade name + theme).
     */
    abstract public function name(): string;

    abstract public function datasource(array $params = []): Datasource;

    /**
     * Topics available for this report.
     *
     * On the server the identity of a template is (name + theme), so each
     * Theme is a distinct template, with its own .jrxml file and resources.
     *
     * @return array<int, ThemeFusion>
     */
    abstract public function themes(): array;

    public function defaultParams(): array
    {
        return [];
    }

    public function protection(): ?ReportProtection
    {
        return null;
    }

    /**
     * Parameters to be appended to the webhook base URL (query string).
     * Useful for loading context that the callback needs — e.g., tenant_id.
     *
     * @param  array<string, mixed>  $params  Parameters already merged from generation
     * @return array<string, scalar>
     */
    public function webhookParams(array $params = []): array
    {
        return [];
    }

    /**
     * Pode `$user` gerar este relatório, com estes parâmetros, pela rota HTTP do
     * package (`FusionReport::routes()`)?
     *
     * **Negado por padrão.** A rota recebe nome e parâmetros do navegador: sem
     * esta checagem qualquer usuário autenticado geraria qualquer relatório
     * registrado — e um relatório que filtra por `cliente_id` entregaria os dados
     * de outro cliente a quem trocasse o número. Sobrescreva para liberar, e
     * valide aqui os parâmetros que escolhem dados:
     *
     *     public function authorize(?Authenticatable $user, array $params): bool
     *     {
     *         return $user !== null
     *             && Cliente::whereKey($params['cliente_id'] ?? null)
     *                 ->where('user_id', $user->getAuthIdentifier())
     *                 ->exists();
     *     }
     *
     * `$params` já vem mesclado com `defaultParams()` — são os valores que o
     * relatório vai receber. Não vale para `FusionReport::make()` chamado no
     * código da aplicação, que já é confiável.
     *
     * @param  array<string, mixed>  $params
     */
    public function authorize(?Authenticatable $user, array $params): bool
    {
        return false;
    }

    /**
     * Pode o resultado ser enviado para `$email` (campo `email` da rota HTTP)?
     *
     * Por padrão, só para o e-mail do próprio usuário: aceitar qualquer endereço
     * fazia da rota um jeito de mandar o relatório para fora.
     */
    public function canSendTo(?Authenticatable $user, string $email): bool
    {
        $own = $user === null ? null : data_get($user, 'email');

        return is_string($own) && strcasecmp(trim($own), trim($email)) === 0;
    }

    /**
     * Dono gravado em `fusion_report_generations.loggable`.
     *
     * Por padrão, o usuário autenticado — desde que seja um Model. Um guard que
     * devolve `GenericUser` ou outro Authenticatable sem tabela estourava
     * TypeError no retorno; agora a geração só fica sem dono.
     */
    public function owner(): ?Model
    {
        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }

    /**
     * Executado após geração SÍNCRONA (`generate()`), com o relatório pronto.
     *
     * NÃO é chamado por `generateAsync()`: ali o servidor apenas enfileirou o
     * job, `$generation->files()` viria vazio e não há o que entregar. O
     * equivalente assíncrono é `onWebhookReceived()`.
     *
     * Para reagir ao enfileiramento em si, escute o evento
     * `ReportGenerationStarted`, disparado nos dois modos.
     */
    public function onGenerated(GenerationResource $generation, array $context = []): void {}

    /**
     * Executado quando o callback da geração assíncrona chega — é aqui que o
     * resultado de `generateAsync()` aparece.
     */
    public function onWebhookReceived(WebhookPayload $payload, array $context = []): void {}
}
