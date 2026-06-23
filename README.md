# fusion-report-client

Laravel client package para comunicação com o **fusion-report-server** — API fluente e tipada para geração de relatórios JasperSoft.

---

## Requisitos

- PHP ^8.4
- Laravel 12

---

## Instalação

```bash
composer require risetechapps/fusion-report-client
```

Publique o arquivo de configuração:

```bash
php artisan vendor:publish --tag=fusion-report
```

---

## Configuração

```php
// config/fusion-report.php
return [
    'api_key' => env('FUSION_REPORT_API_KEY'),

    // Persiste cada geração na tabela fusion_report_generations
    'log_generations' => env('FUSION_REPORT_LOG_GENERATIONS', true),

    'webhook' => [
        'url'    => env('FUSION_REPORT_WEBHOOK_URL'),
        'secret' => env('FUSION_REPORT_WEBHOOK_SECRET'),

        // Path e middleware da rota que recebe o callback do servidor.
        // O package é agnóstico de contexto: a app hospedeira injeta aqui
        // o middleware que estabelece o contexto necessário (tenancy, auth,
        // etc.) ANTES do controller rodar. Ver a seção "Webhook".
        'path'       => env('FUSION_REPORT_WEBHOOK_PATH', '/fusion/webhook'),
        'middleware' => ['api'],
    ],

    'defaults' => [
        // Tema padrão aplicado quando a geração não define um via ->theme().
        // Ignorado nas gerações por ID de template.
        'theme' => env('FUSION_REPORT_THEME', 'default'),

        // Locale padrão aplicado quando a geração não define um via ->locale().
        'locale' => env('FUSION_REPORT_LOCALE', 'pt_BR'),

        'params' => [
            // Parâmetros enviados em toda geração automaticamente
            // 'TENANT_ID' => '123',
        ],
    ],

    'reports' => [
        // Definições de relatórios pré-registradas
        // 'client_all' => \App\Reports\ClientesAllReport::class,
    ],
];
```

`.env`:

```env
FUSION_REPORT_API_KEY=sua_api_key
FUSION_REPORT_THEME=default
FUSION_REPORT_LOCALE=pt_BR
FUSION_REPORT_WEBHOOK_URL=https://seu-projeto.com/fusion/webhook
FUSION_REPORT_WEBHOOK_SECRET=seu_secret
```

---

## Templates JRXML

### Listar

```php
$templates = FusionReport::templates()->list();

foreach ($templates as $template) {
    $template->id();
    $template->name();
    $template->theme();
    $template->description();
    $template->hasResources();
    $template->originalFilename();
    $template->createdAt();
    $template->updatedAt();
}

return response()->json($templates);
```

### Buscar por ID

```php
$template = FusionReport::templates()->find('uuid-do-template');
```

### Cadastrar

```php
$template = FusionReport::templates()->upload(
    filePath:         resource_path('reports/client_all.jrxml'),
    name:             'client_all',
    theme:            'default',
    description:      'Relatório de clientes',
    resourcesZipPath: resource_path('reports/resources.zip'), // opcional
);

$template->id();
$template->hasResources();
```

### Atualizar por ID

```php
$template = FusionReport::templates()->update(
    id:               'uuid-do-template',
    filePath:         resource_path('reports/client_all_v2.jrxml'),
    name:             'client_all',     // opcional
    theme:            'blue',           // opcional
    description:      'Nova descrição', // opcional
    resourcesZipPath: resource_path('reports/resources.zip'), // opcional
);
```

### Atualizar por nome

```php
$template = FusionReport::templates()->updateByName(
    name:             'client_all',
    filePath:         resource_path('reports/client_all_v2.jrxml'),
    theme:            'blue',           // opcional
    description:      'Nova descrição', // opcional
    resourcesZipPath: resource_path('reports/resources.zip'), // opcional
);
```

### Excluir por ID

```php
FusionReport::templates()->delete('uuid-do-template');
```

### Excluir por nome

```php
FusionReport::templates()->deleteByName('client_all');
FusionReport::templates()->deleteByName('client_all', 'blue'); // tema específico
```

### Histórico de gerações

```php
// Por ID do template
$generations = FusionReport::templates()->generations('uuid-do-template');

// Por nome do template
$generations = FusionReport::templates()->generationsByName('client_all');
```

---

## Geração de Relatórios

### Report Definitions (recomendado)

Crie uma classe que estende `ReportDefinition`:

```php
namespace App\Reports;

use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;
use RiseTechApps\FusionReport\Datasources\InlineJsonDatasource;
use RiseTechApps\FusionReport\Definitions\ReportDefinition;
use RiseTechApps\FusionReport\Definitions\ReportProtection;
use RiseTechApps\FusionReport\Definitions\ThemeFusion;
use RiseTechApps\FusionReport\Resources\GenerationResource;
use RiseTechApps\FusionReport\Webhook\WebhookPayload;

class ClientesAllReport extends ReportDefinition
{
    public function name(): string
    {
        return 'client_all';
    }

    // ── Usado pelo comando fusion-report:sync (registro de templates) ──
    //
    // No servidor a identidade de um template é o par (name + theme), então
    // cada ThemeFusion é um template distinto, com seu próprio .jrxml e resources.

    public function themes(): array
    {
        return [
            ThemeFusion::make('default')
                ->from(resource_path('reports/client_all/default.jrxml'))
                ->withResources(resource_path('reports/client_all/default.zip'))
                ->describedAs('Relatório consolidado de clientes'),

            // Cada tema tem seu próprio arquivo; resources/description são opcionais.
            ThemeFusion::make('blue')
                ->from(resource_path('reports/client_all/blue.jrxml')),
        ];
    }

    public function datasource(array $params = []): Datasource
    {
        return InlineJsonDatasource::from(
            Cliente::query()
                ->when($params['ANO'] ?? null, fn($q, $v) => $q->where('ano', $v))
                ->get()
                ->toArray()
        );
    }

    public function defaultParams(): array
    {
        return ['ANO' => date('Y')];
    }

    public function protection(): ?ReportProtection
    {
        return ReportProtection::password('secreta123'); // opcional
    }

    public function webhookParams(array $params = []): array
    {
        // Parâmetros anexados à URL base do webhook (query string).
        // Útil para carregar contexto que o callback precisa restabelecer.
        // Ex.: em apps multi-tenant, identificar o tenant na volta.
        return ['tenant_id' => tenancy()->getKey()];
    }

    public function onGenerated(GenerationResource $generation, array $context = []): void
    {
        // Executado automaticamente após geração síncrona
        if ($context['send_email'] ?? false) {
            Mail::to($context['email'])->send(new ReportReadyMail($generation));
        }

        dispatch(new ProcessReportJob($generation->id()));
    }

    public function onWebhookReceived(WebhookPayload $payload, array $context = []): void
    {
        // Executado automaticamente quando o callback da geração assíncrona
        // chega — desde que o template_name do payload bata com name().
        // O $context é o mesmo informado no disparo (->context([...])).
        if (! $payload->isSuccess()) {
            return;
        }

        if ($context['send_email'] ?? false) {
            Mail::to($context['email'])->send(new ReportReadyMail($payload));
        }

        dispatch(new ProcessReportJob($payload->uuid()));
    }
}
```

> **Context no fluxo assíncrono.** Em `onGenerated` (síncrono) o `$context` chega
> direto por parâmetro. No `onWebhookReceived` (assíncrono) o callback é uma
> requisição nova, então o `context` informado no disparo é **persistido** com a
> geração e o package o **recarrega e injeta** automaticamente no segundo
> parâmetro — você não precisa consultar nada. Requer `log_generations`
> habilitado (default).
>
> O package ainda injeta no context o **`locale` efetivo** da geração (o de
> `->locale()` ou, na falta, o `defaults.locale` do config), disponível como
> `$context['locale']` nos dois hooks. Um `locale` que você já tenha colocado no
> context manualmente é preservado (não é sobrescrito).

> **`webhookParams()`** — os parâmetros retornados são anexados como query
> string à URL base (`webhook.url`) **apenas** no `generateAsync()`. A URL
> final fica, por exemplo, `https://seu-app.com/fusion/webhook?tenant_id=abc`.
> Recebe os params já mesclados da geração, então você pode derivar valores
> do próprio relatório. Retorne `[]` (default) para não anexar nada.

Registre no config:

```php
'reports' => [
    'client_all' => \App\Reports\ClientesAllReport::class,
],
```

### Sincronizar templates com o servidor

O comando `fusion-report:sync` registra no servidor os templates definidos em
`fusion-report.reports`, expandindo o `themes()` de cada definition: **cada
`ThemeFusion` vira um template `(name + theme)` independente**. Ele primeiro lista o
que já existe no servidor (`templates()->list()`, indexado por `name + theme`) e
decide a ação por **tema**:

```bash
# Cadastra apenas os temas ainda não registrados (ignora os existentes)
php artisan fusion-report:sync

# Também atualiza os que já estão registrados
php artisan fusion-report:sync --force
```

| Situação | Sem `--force` | Com `--force` |
|---|---|---|
| Tema novo `(name + theme)` | cadastra (`upload`) | cadastra (`upload`) |
| Tema já registrado | ignora | atualiza (`updateByName`) |
| `themes()` vazio | falha (pula a definition) | falha (pula a definition) |
| `ThemeFusion` sem `->from()` / arquivo inexistente | falha (pula o tema) | falha (pula o tema) |

- O nome do tema é o que você passa em `ThemeFusion::make('...')` — não há mais um
  `defaults.theme` global no registro.
- `->withResources()` é opcional: sem ele, o template é registrado sem recursos.
- Cada tema é processado isoladamente: uma falha não aborta os demais; o comando
  retorna código de saída diferente de zero se houver qualquer falha.

### Geração síncrona

```php
$generation = FusionReport::make('client_all')->format('pdf')->generate();

// Com params e context
$generation = FusionReport::make('client_all', ['ANO' => '2025'])
    ->format(['pdf', 'xlsx'])
    ->context(['send_email' => true, 'email' => 'user@exemplo.com'])
    ->generate();

// Acessar arquivos
$generation->id();
$generation->mode();            // 'sync'
$generation->currentStatus();  // 'completed'
$generation->frpId();          // ID do arquivo .frp para re-exportar depois
$generation->files();          // Collection<FileResource>
$generation->expiresAt();

// URLs dos arquivos
$urls = $generation->files()->mapWithKeys(
    fn($f) => [$f->format() => $f->url()]
);
// ['pdf' => 'https://...', 'xlsx' => 'https://...']

// Download direto
$response = $generation->download('pdf');

return response($response->body(), 200, [
    'Content-Type'        => 'application/pdf',
    'Content-Disposition' => 'attachment; filename="relatorio.pdf"',
]);
```

### Geração assíncrona

```php
// webhook_url vai automaticamente do config
$generation = FusionReport::make('client_all')
    ->format('pdf')
    ->generateAsync();

$id = $generation->id(); // salve para consultar depois

// Com webhook diferente do padrão
$generation = FusionReport::make('client_all')
    ->format('pdf')
    ->generateAsync(webhook: 'https://outro-endpoint.com/webhook');

// Acompanhar status
$generation = FusionReport::generation($id)->get();
$generation->currentStatus();   // pending → processing → completed
$generation->progressPercent();
$generation->isFailed();
$generation->errorMessage();
```

### Geração pelo builder (sem registry)

```php
use RiseTechApps\FusionReport\Datasources\InlineJsonDatasource;

FusionReport::report('client_all')
    ->theme('blue')
    ->format(['pdf', 'xlsx'])
    ->params(['ANO' => '2026', 'EMPRESA' => 'Acme'])
    ->datasource(InlineJsonDatasource::from($data))
    ->locale('pt_BR')
    ->protection(ReportProtection::password('senha123'))
    ->generate();
```

### Geração por ID do template

```php
FusionReport::reportById('uuid-do-template')
    ->format('pdf')
    ->generate();

FusionReport::reportById('uuid-do-template')
    ->format('pdf')
    ->generateAsync();
```

---

## Datasources

### Nenhum datasource

```php
FusionReport::report('template')->format('pdf')->generate();
```

### JSON inline

```php
use RiseTechApps\FusionReport\Datasources\InlineJsonDatasource;

->datasource(InlineJsonDatasource::from($array))
->datasource(InlineJsonDatasource::fromJson('{"key":"value"}'))
```

### CSV inline

```php
use RiseTechApps\FusionReport\Datasources\InlineCsvDatasource;

->datasource(InlineCsvDatasource::from($csvString))
->datasource(InlineCsvDatasource::from($csvString, firstRow: true, delimiter: ';'))
->datasource(InlineCsvDatasource::fromRows($arrayOfRows))
```

### URL JSON externa

```php
use RiseTechApps\FusionReport\Datasources\JsonUrlDatasource;

->datasource(JsonUrlDatasource::get('https://api.exemplo.com/dados'))
->datasource(JsonUrlDatasource::get('https://api.exemplo.com/dados', [
    'Authorization' => 'Bearer token123',
]))
->datasource(JsonUrlDatasource::post('https://api.exemplo.com/dados'))
```

### Perfil pré-configurado no servidor

```php
use RiseTechApps\FusionReport\Datasources\ProfileDatasource;

->datasource(ProfileDatasource::use('nome_do_perfil'))
```

---

## Proteção de documentos

```php
use RiseTechApps\FusionReport\Definitions\ReportProtection;

// Senha de abertura
->protection(ReportProtection::password('senha123'))

// Senha de abertura + senha do owner
->protection(ReportProtection::withOwner('senha_user', 'senha_owner'))
```

---

## Arquivos Gerados

```php
$file = $generation->files()->first(fn($f) => $f->format() === 'pdf');

$file->id();
$file->format();    // 'pdf', 'xlsx', 'frp', etc.
$file->filename();
$file->url();       // URL de download (requer autenticação)
$file->expiresAt();

// Download do arquivo
$response = $file->download();

// Re-exportar .frp para outro formato → retorna nova GenerationResource
$novaGeracao = $file->export('xlsx');
$novaGeracao = FusionReport::file($frpId)->export('pdf');
```

---

## Webhook

O package registra automaticamente a rota do callback (default `POST /fusion/webhook`). Quando a geração assíncrona termina, o servidor faz POST nessa rota e o package:

1. Verifica a assinatura HMAC (se `webhook.secret` estiver configurado).
2. Atualiza o registro em `fusion_report_generations` (status + URLs).
3. Chama `onWebhookReceived()` da `ReportDefinition` cujo `name()` bate com o `template_name` do payload.

### 1. Reagindo ao callback — `onWebhookReceived()` (recomendado)

Basta implementar `onWebhookReceived()` na sua `ReportDefinition` (ver exemplo na seção *Report Definitions*). O package faz o roteamento pelo `template_name` automaticamente — você não precisa de rota nem handler próprios.

### 2. Handler customizado (opcional)

Para lógica global (independente de template), rebinde o contrato `WebhookHandler` num service provider seu:

```php
use RiseTechApps\FusionReport\Contracts\WebhookHandler;

$this->app->bind(WebhookHandler::class, \App\Handlers\MeuWebhookHandler::class);
```

### Contexto da requisição (multi-tenant, auth, etc.)

O callback chega do servidor externo **sem** o contexto da sua aplicação. Como o package é agnóstico de contexto, a rota do webhook usa o middleware definido em `webhook.middleware` — é aí que a app hospedeira estabelece o que precisar **antes** do handler rodar.

Exemplo multi-tenant: a definition anexa o `tenant_id` na URL via `webhookParams()`, e um middleware seu lê esse valor e inicializa o tenant — assim a query da geração já cai no banco correto:

```php
// app/Http/Middleware/InitializeFusionTenant.php
public function handle(Request $request, Closure $next)
{
    if ($tenantId = $request->query('tenant_id')) {
        tenancy()->initialize($tenantId);
    }

    return $next($request);
}
```

```php
// config/fusion-report.php
'webhook' => [
    // ...
    'middleware' => ['api', \App\Http\Middleware\InitializeFusionTenant::class],
],
```

### API do payload

```php
$payload->uuid();          // ID da geração
$payload->status();        // 'completed' | 'failed' | ...
$payload->isSuccess();     // status === 'completed'
$payload->isFailed();      // status === 'failed'
$payload->templateName();  // nome do template
$payload->frpId();         // ID do .frp (re-exportar depois)
$payload->error();         // mensagem de erro (quando falhou)
$payload->downloads();     // Collection<WebhookDownload>

foreach ($payload->downloads() as $download) {
    $download->format();     // 'pdf'
    $download->filename();   // 'relatorio.pdf'
    $download->url();        // URL de download (chave download_url)
    $download->expiresAt();  // expiração da URL (ou null)
}
```

### Verificação de assinatura

A verificação HMAC é feita automaticamente pelo package quando `webhook.secret` está configurado. Para uso manual:

```php
use RiseTechApps\FusionReport\Webhook\WebhookPayload;
use RiseTechApps\FusionReport\Exceptions\WebhookSignatureException;

try {
    $webhook = WebhookPayload::fromRequest($request, secret: config('fusion-report.webhook.secret'));
} catch (WebhookSignatureException $e) {
    return response()->json(['error' => 'Unauthorized'], 401);
}
```

---

## Monitoramento Jasper

```php
FusionReport::jasper()->formats();      // Formatos suportados
FusionReport::jasper()->status();       // Status geral
FusionReport::jasper()->jobs();         // Jobs assíncronos em andamento
FusionReport::jasper()->fontsStatus();  // Status das fontes instaladas
FusionReport::jasper()->fontsSync();    // Sincronizar fontes no servidor Java
```

---

## Tratamento de Erros

```php
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Exceptions\ReportNotFoundException;

try {
    $generation = FusionReport::make('client_all')->format('pdf')->generate();
} catch (ReportNotFoundException $e) {
    // Template não encontrado (404 ou 410)
} catch (FusionReportException $e) {
    // Erro de validação (422) ou erro interno do servidor
    // $e->getMessage() contém os detalhes
}
```

| HTTP | Situação | Exception |
|---|---|---|
| 422 | Campo obrigatório ausente ou arquivo inválido | `FusionReportException` |
| 404 | ID não encontrado (route model binding) | `ReportNotFoundException` |
| 410 | Nome não encontrado, conflito ou erro interno | `ReportNotFoundException` / `FusionReportException` |

---

## Referência completa — Endpoints cobertos

| Endpoint | Método do package |
|---|---|
| `GET /api/reports` | `templates()->list()` |
| `POST /api/reports` | `templates()->upload(...)` |
| `GET /api/reports/{id}` | `templates()->find($id)` |
| `POST /api/reports/{id}` | `templates()->update($id, ...)` |
| `POST /api/reports/name/{name}` | `templates()->updateByName($name, ...)` |
| `DELETE /api/reports/{id}` | `templates()->delete($id)` |
| `DELETE /api/reports/name/{name}` | `templates()->deleteByName($name, $theme)` |
| `GET /api/reports/{id}/generations` | `templates()->generations($id)` |
| `GET /api/reports/name/{name}/generations` | `templates()->generationsByName($name)` |
| `POST /api/generate` | `report('name')->generate()` |
| `POST /api/async` | `report('name')->generateAsync()` |
| `POST /api/reports/{id}/generate` | `reportById($id)->generate()` |
| `POST /api/reports/{id}/async` | `reportById($id)->generateAsync()` |
| `GET /api/generations/{id}` | `generation($id)->get()` |
| `DELETE /api/generations/{id}` | `generation($id)->cancel()` |
| `GET /api/files/{id}/download` | `file($id)->download()` |
| `POST /api/files/{id}/export` | `file($id)->export($format)` |
| `GET /api/jasper/formats` | `jasper()->formats()` |
| `GET /api/jasper/status` | `jasper()->status()` |
| `GET /api/jasper/jobs` | `jasper()->jobs()` |
| `GET /api/jasper/fonts/status` | `jasper()->fontsStatus()` |
| `POST /api/jasper/fonts/sync` | `jasper()->fontsSync()` |
