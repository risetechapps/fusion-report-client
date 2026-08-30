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

        // Exige assinatura HMAC valida. Com true (padrao) e 'secret' vazio,
        // a rota recusa a requisicao em vez de aceitar payload nao assinado.
        // So desligue em desenvolvimento local.
        'require_signature' => env('FUSION_REPORT_WEBHOOK_REQUIRE_SIGNATURE', true),

        // Processa o callback numa fila em vez de dentro da requisicao.
        // false = inline; true = fila padrao; string = fila nomeada.
        // Exige worker rodando. Ver "Processamento em fila".
        'queue' => env('FUSION_REPORT_WEBHOOK_QUEUE', false),

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

        // Formato(s) usados quando a geracao nao define nenhum via ->format().
        // O servidor exige 'format': sem este default, geracao sem ->format()
        // volta 422. Aceita 'pdf', 'pdf,xlsx' ou ['pdf', 'xlsx'].
        'format' => env('FUSION_REPORT_FORMAT', 'pdf'),

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
FUSION_REPORT_FORMAT=pdf
FUSION_REPORT_WEBHOOK_URL=https://seu-projeto.com/fusion/webhook
FUSION_REPORT_WEBHOOK_SECRET=seu_secret   # obrigatorio: sem ele o webhook responde 503
FUSION_REPORT_WEBHOOK_QUEUE=false
```

> **`api_key` é obrigatória.** É ela que vai no header `X-API-KEY`, a única
> credencial que o client usa. Sem ela, qualquer chamada — inclusive
> `file($id)->download()` — lança `AuthenticationException` antes de a
> requisição sair, em vez de partir anônima e voltar como 401 genérico.
>
> A chave é lida do config a cada requisição, não uma vez na inicialização.
> Aplicações que a definem em runtime (bootstrapper de tenant, worker de fila
> que troca de contexto) são atendidas com o valor do contexto corrente.

---

## Templates JRXML

### Listar

Retorna **todos** os templates registrados. O endpoint do servidor é paginado
(`per_page` padrão 50, máximo 100), mas `list()` percorre as páginas
internamente — não é preciso paginar na aplicação.

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

### Baixar o .jrxml registrado

Devolve o XML cru (`application/xml`), não o envelope JSON do resto da API.
Útil para conferir o que está publicado antes de reenviar.

```php
$response = FusionReport::templates()->downloadJrxml('uuid-do-template');

$xml = $response->body();

// Comparar com o arquivo local antes de sincronizar
$local = file_get_contents(resource_path('reports/client_all/default.jrxml'));

if (trim($xml) !== trim($local)) {
    $this->info('Template divergente do servidor.');
}
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

> **Indisponível via `X-API-KEY`** — ambas as rotas são restritas ao dashboard
> (Sanctum) e retornam 401. Para histórico na integração por API key, use a
> tabela local `fusion_report_generations`. Ver
> *[Métodos indisponíveis via X-API-KEY](#métodos-indisponíveis-via-x-api-key)*.

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
        // Só no modo SÍNCRONO (generate()). generateAsync() não chama este hook:
        // ali o servidor apenas enfileirou o job e files() viria vazio.
        // O equivalente assíncrono é onWebhookReceived(), logo abaixo.
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

# Seleção interativa: escolhe quais temas já registrados atualizar
php artisan fusion-report:sync --force

# Atualiza TODOS os temas já registrados, sem perguntar
php artisan fusion-report:sync --all
```

| Situação | `sync` | `sync --force` | `sync --all` |
|---|---|---|---|
| Tema novo `(name + theme)` | cadastra (`upload`) | não toca¹ | cadastra (`upload`) |
| Tema já registrado | ignora | atualiza só se selecionado | atualiza (`updateByName`) |
| `themes()` vazio | falha (pula a definition) | — | falha (pula a definition) |
| `ThemeFusion` sem `->from()` / arquivo inexistente | falha (pula o tema) | falha (pula o tema) | falha (pula o tema) |

¹ `--force` é **cirúrgico**: lista só os temas já registrados num seletor
(`multiselect`), processa **apenas os marcados** e ignora todo o resto — a barra de
progresso reflete só o trabalho real. Para cadastrar temas novos use o `sync`
normal ou `--all`.

- **`--force`** abre um seletor interativo dos temas já registrados; marque quais
  reenviar (espaço marca, Enter confirma). Sem terminal interativo (ex.: CI) ele
  cai para o comportamento de atualizar todos os registrados.
- **`--all`** atualiza todos os registrados e cadastra os novos, sem prompt — útil
  em pipelines / deploy.
- Se nada for selecionado no `--force`, o comando informa e sai sem alterações.

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
```

> **O resultado da geração assíncrona chega pelo webhook, não por polling.**
> `FusionReport::generation($id)->get()` bate em `GET /api/v1/generations/{id}`,
> que o servidor restringe ao dashboard (Sanctum). Autenticado por `X-API-KEY`,
> retorna 401 → `FusionReportException`. Ver
> *[Métodos indisponíveis via X-API-KEY](#métodos-indisponíveis-via-x-api-key)*.
>
> Configure `webhook.url` e implemente `onWebhookReceived()` na sua
> `ReportDefinition` — é o caminho suportado para saber que o relatório ficou pronto.

### Formatos

O servidor exige pelo menos um formato em toda geração. Quando você não chama
`->format()`, vale o `defaults.format` do config (`pdf` de fábrica):

```php
// Usa defaults.format
FusionReport::make('client_all')->generate();

// Sobrescreve o default — não acumula com ele
FusionReport::make('client_all')->format('xlsx')->generate();

// Múltiplos formatos, nas três formas aceitas
->format(['pdf', 'xlsx'])
->format('pdf,xlsx')
->format('pdf')->format('xlsx')
```

Entrada é normalizada: espaços são removidos, maiúsculas viram minúsculas
(`'PDF'` → `'pdf'`) e duplicatas caem fora.

Válidos: `pdf`, `xlsx`, `xls`, `docx`, `odt`, `csv`, `html`, `frp`.

> Definir `defaults.format` como `null` ou `''` volta ao comportamento anterior:
> o payload omite `format` e o servidor responde 422 se a geração não informar um.

### Parâmetros

O servidor recebe `params` como lista de strings `CHAVE=valor`. A conversão é
feita pelo package:

| Tipo PHP | Vira |
|---|---|
| `string`, `int`, `float` | o próprio valor |
| `bool` | `true` / `false` |
| `null` | string vazia |
| `array` | JSON (`["a","b"]`, `{"x":1}`) |
| `BackedEnum` | o `value` do case |
| `DateTimeInterface` | ISO-8601 (`DATE_ATOM`) |
| `Stringable` | `__toString()` |
| qualquer outro | `FusionReportException` |

```php
->params([
    'ANO'     => 2026,
    'ATIVO'   => true,               // ATIVO=true
    'PERIODO' => new DateTimeImmutable('2026-01-01'),
    'TAGS'    => ['a', 'b'],         // TAGS=["a","b"]
])
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

### Re-exportar uma geração inteira

Quando você tem o ID da geração e não o do arquivo, o servidor localiza o `.frp`
sozinho:

```php
$nova = FusionReport::generation($generationId)->export('xlsx');

$nova->id();       // geração nova; a original continua intacta
$nova->files();
```

Formatos aceitos: `pdf`, `xlsx`, `xls`, `docx`, `odt`, `csv`, `html`. O `frp` não
entra na lista — é o próprio intermediário.

Lança `ReportNotFoundException` quando a geração não tem `.frp` disponível ou ele
já expirou.

---

## Webhook

O package registra automaticamente a rota do callback (default `POST /fusion/webhook`). Quando a geração assíncrona termina, o servidor faz POST nessa rota e o package:

1. Verifica a assinatura HMAC (obrigatória — ver *Verificação de assinatura*).
2. Atualiza o registro em `fusion_report_generations` (status + URLs).
3. Descarta reentregas: se o status gravado já é o do payload, para aqui.
4. Dispara `ReportGenerationCompleted` / `ReportGenerationFailed`.
5. Chama `onWebhookReceived()` da `ReportDefinition` cujo `name()` bate com o `template_name` do payload.

> **Entrega duplicada.** O servidor reentrega o callback até 3 vezes (backoff
> 10s/60s/180s) sempre que não recebe resposta — e o timeout dele é de 15s,
> contados enquanto o seu handler ainda está rodando. Um `onWebhookReceived()`
> lento aumenta a própria chance de ser reexecutado.
>
> O passo 3 protege contra isso comparando o status já gravado com o do payload.
> Ainda assim, mantenha o hook leve: prefira `dispatch()` de um job a fazer o
> trabalho pesado inline.

### Processamento em fila (recomendado)

Por padrão o callback é processado **dentro da requisição**: o `200` só sai
depois do update, dos eventos e do `onWebhookReceived()`. Como o servidor
concede 15s e reentrega até 3 vezes, um hook que manda e-mail ou sobe arquivo
passa desse limite — o trabalho é feito, mas o servidor registra
`Client webhook gave up after all attempts` e reentrega. O log passa a mentir
sobre o que aconteceu.

Ligando a fila, a assinatura é verificada, o payload é enfileirado e o `200` sai
em milissegundos:

```env
FUSION_REPORT_WEBHOOK_QUEUE=true       # fila padrão
FUSION_REPORT_WEBHOOK_QUEUE=webhooks   # fila nomeada
```

O job é o `ProcessFusionReportWebhook` (`tries = 3`, backoff 10s/60s/180s).

> **Exige worker rodando.** Sem `queue:work` consumindo a fila, os callbacks
> ficam parados e o fluxo assíncrono não completa. Por isso o padrão é `false`.

**Contexto (tenancy, auth).** O job é despachado de dentro da requisição, onde o
middleware de `webhook.middleware` já estabeleceu o contexto. Pacotes de tenancy
com suporte a fila propagam esse contexto automaticamente. Se o seu não faz
isso, mantenha `queue => false` ou trate a propagação no seu próprio
`WebhookHandler`.

Validação de payload malformado continua acontecendo **antes** do
enfileiramento, então o `422` segue valendo — depois do `200` não haveria como
avisar o servidor.

### Deduplicação

A deduplicação usa dois guards, e basta um acusar entrega anterior:

| Guard | Força | Limite |
|---|---|---|
| Status já gravado na linha | Durável, sobrevive a flush de cache | Só existe com `log_generations` ligado |
| `Cache::add()` da chave `fusion-report:webhook:{uuid}:{status}` | Atômico — fecha a corrida de entregas simultâneas | Perde efeito se o cache for limpo, ou com driver `null` |

O TTL do cache sai de `webhook.deduplication_ttl` (minutos, padrão 1440). O
servidor reentrega no máximo por ~4 minutos, então 24h cobre com folga.

> **Com `log_generations = false`** não existe registro, e com ele some o
> `$context` — que chega sempre vazio em `onWebhookReceived()` e nos eventos —
> e o `$owner`, que vem `null`. A deduplicação passa a depender só do cache. Os
> hooks continuam disparando; antes desta versão, o callback virava no-op
> silencioso.
>
> Se você usa `->context([...])` ou `->for($model)`, mantenha o log ligado.

Registro ausente **com** o log ligado é tratado como anomalia, não como
duplicata: o package registra `Log::warning` e não dispara nada. Em apps
multi-tenant isso normalmente significa que o middleware de `webhook.middleware`
não inicializou o tenant antes do controller — a consulta caiu no banco errado.

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

A verificação HMAC é feita automaticamente pelo package.

> **`webhook.secret` é obrigatório.** Sem ele a rota responde **503** e não
> processa nada — a alternativa seria aceitar payload não assinado, permitindo
> que qualquer POST alterasse status e URLs de download das suas gerações e
> disparasse os eventos da aplicação.
>
> Para desenvolvimento local sem segredo, e só para isso:
>
> ```env
> FUSION_REPORT_WEBHOOK_REQUIRE_SIGNATURE=false
> ```
>
> Com essa flag o package registra um `Log::warning` a cada callback aceito sem
> verificação.

Respostas da rota de webhook:

| Situação | HTTP |
|---|---|
| Processado | 200 |
| Assinatura ausente ou inválida | 401 |
| Payload sem `id` ou `status` | 422 (o servidor para de retentar) |
| `webhook.secret` não configurado | 503 |

Para uso manual:

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

> **Indisponível via `X-API-KEY`.** As cinco rotas `/api/v1/jasper/*` são
> exclusivas do dashboard (Sanctum) — expõem estado global do servidor Java, que
> não é escopado por cliente. Chamadas autenticadas por API key retornam 401.
> Os métodos seguem no package para quem autentica de outra forma.

```php
FusionReport::jasper()->formats();      // Formatos suportados
FusionReport::jasper()->status();       // Status geral
FusionReport::jasper()->jobs();         // Jobs assíncronos em andamento
FusionReport::jasper()->fontsStatus();  // Status das fontes instaladas
FusionReport::jasper()->fontsSync();    // Sincronizar fontes no servidor Java
```

---

## Tratamento de Erros

Todas as exceptions do package herdam de `FusionReportException`, então um único
`catch` continua capturando tudo. As específicas existem para quem precisa
distinguir o caso.

```php
use RiseTechApps\FusionReport\Exceptions\AuthenticationException;
use RiseTechApps\FusionReport\Exceptions\AuthorizationException;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Exceptions\RateLimitException;
use RiseTechApps\FusionReport\Exceptions\ReportNotFoundException;

try {
    $generation = FusionReport::make('client_all')->format('pdf')->generate();
} catch (RateLimitException $e) {
    // Cota do plano esgotada — reenfileire para depois
    $wait = $e->retryAfter() ?? 60;   // segundos, quando o servidor informa
} catch (AuthenticationException $e) {
    // api_key não configurada (lançada antes da requisição sair),
    // api_key inválida, ou rota restrita ao dashboard
} catch (AuthorizationException $e) {
    // Recurso de outra conta
} catch (ReportNotFoundException $e) {
    // Template não encontrado (404 ou 410)
} catch (FusionReportException $e) {
    // Validação (422) ou erro interno do servidor
}
```

> **Falha de conexão fica de fora.** Timeout e rede indisponível lançam
> `Illuminate\Http\Client\ConnectionException`, que **não** herda de
> `FusionReportException` — não há resposta HTTP para o package traduzir.
> Capture separadamente quando isso importar:
>
> ```php
> use Illuminate\Http\Client\ConnectionException;
>
> } catch (ConnectionException $e) {
>     // O servidor pode ter concluído a geração mesmo assim — ver
>     // "Limitações conhecidas".
> }
> ```

| HTTP | Situação | Exception |
|---|---|---|
| — | `api_key` não configurada — nenhuma requisição chega a sair | `AuthenticationException` |
| 401 | Credencial ausente/inválida, ou rota só de dashboard | `AuthenticationException` |
| 403 | Recurso pertence a outra conta | `AuthorizationException` |
| 422 | Campo obrigatório ausente ou arquivo inválido | `FusionReportException` |
| 429 | Cota de requisições do plano esgotada | `RateLimitException` |
| 404 | ID não encontrado (route model binding) | `ReportNotFoundException` |
| 410 | Nome não encontrado, conflito ou erro interno | `ReportNotFoundException` / `FusionReportException` |

---

## Eventos

O package dispara eventos em todo ciclo de geração. Use quando quiser reagir sem
ser dono da `ReportDefinition` — o hook `onGenerated()`/`onWebhookReceived()`
continua funcionando e os dois convivem.

```php
use RiseTechApps\FusionReport\Events\ReportGenerationStarted;
use RiseTechApps\FusionReport\Events\ReportGenerationCompleted;
use RiseTechApps\FusionReport\Events\ReportGenerationFailed;
```

| Evento | Quando |
|---|---|
| `ReportGenerationStarted` | O servidor aceitou a requisição (síncrona **e** assíncrona) |
| `ReportGenerationCompleted` | Concluída — na síncrona, pela resposta; na assíncrona, pelo webhook |
| `ReportGenerationFailed` | Falhou, pelos mesmos dois caminhos |

Todos expõem as mesmas propriedades públicas:

```php
class MyListener
{
    public function handle(ReportGenerationCompleted $event): void
    {
        $event->generation;   // GenerationResource
        $event->context;      // array passado via ->context()
        $event->owner;        // ?Model — de ->for() / ReportDefinition::owner()

        foreach ($event->generation->files() as $file) {
            Storage::put("relatorios/{$file->filename()}", $file->download()->body());
        }
    }
}
```

`ReportGenerationFailed` tem ainda `$event->error`.

> Na geração **síncrona** o `Started` é seguido imediatamente por
> `Completed`/`Failed`, no mesmo ciclo — a resposta já vem finalizada. Na
> assíncrona só o `Started` sai na hora; o desfecho chega pelo webhook.
>
> `$event->error` costuma vir `null` no caminho assíncrono: o servidor mantém
> `error_message` em `$hidden`. Ver *[Campos sempre vazios](#campos-sempre-vazios)*.

---

## Métodos indisponíveis via X-API-KEY

O client autentica exclusivamente por `X-API-KEY`. Parte das rotas do servidor é
restrita ao dashboard e exige Bearer token do Sanctum — chamá-las com API key
retorna **401**, que o package converte em `FusionReportException`.

Os métodos abaixo continuam existindo (podem ser usados por quem autentica de
outra forma), mas **não funcionam na integração por API key**:

| Método | Rota | Alternativa |
|---|---|---|
| `generation($id)->get()` | `GET /api/v1/generations/{id}` | webhook (`onWebhookReceived()`) |
| `generation($id)->status()` | `GET /api/v1/generations/{id}` | webhook |
| `generation($id)->cancel()` | `DELETE /api/v1/generations/{id}` | — |
| `templates()->generations($id)` | `GET /api/v1/reports/{id}/generations` | tabela `fusion_report_generations` |
| `templates()->generationsByName($n)` | `GET /api/v1/reports/name/{n}/generations` | tabela `fusion_report_generations` |
| `jasper()->formats()` | `GET /api/v1/jasper/formats` | — |
| `jasper()->status()` | `GET /api/v1/jasper/status` | — |
| `jasper()->jobs()` | `GET /api/v1/jasper/jobs` | — |
| `jasper()->fontsStatus()` | `GET /api/v1/jasper/fonts/status` | — |
| `jasper()->fontsSync()` | `POST /api/v1/jasper/fonts/sync` | — |

Para histórico de gerações, use o log local do próprio package
(`fusion_report_generations`), alimentado na geração e atualizado pelo webhook —
ver *[Report Definitions](#report-definitions-recomendado)* e a trait
`HasFusionReportGenerations`.

### Campos sempre vazios

O servidor mantém alguns campos em `$hidden`, então nunca chegam no JSON. Estes
accessors retornam sempre o valor padrão, independentemente da autenticação:

| Accessor | Retorna sempre |
|---|---|
| `GenerationResource::errorMessage()` | `null` |
| `GenerationResource::progressPercent()` | `0` |
| `WebhookPayload::error()` | `null` |
| `TemplateResource::filePath()` | `''` |
| `TemplateResource::resourcesPath()` | `null` |

Na prática: quando uma geração assíncrona falha, o webhook chega com
`status: 'failed'` e sem motivo. O texto do erro só aparece na geração
**síncrona**, na mensagem da `FusionReportException`.

---

## Limitações conhecidas

Comportamentos que valem saber antes de integrar. Nenhum é acidental — todos
estão registrados aqui em vez de serem descobertos em produção.

### Timeout menor que o do servidor

O client espera **60s** por resposta. O servidor concede **90s** ao Jasper numa
geração síncrona, e ainda gasta tempo depois disso baixando e armazenando os
arquivos.

Numa geração síncrona pesada, o client desiste antes de o servidor terminar:

```
t=60s   client estoura → Illuminate\Http\Client\ConnectionException
t=75s   Jasper devolve o PDF
t=80s   servidor grava os arquivos e debita a cota
        → geração existe e foi cobrada, e o client não tem o ID nem os arquivos
```

Duas consequências práticas:

- **O timeout não é configurável** — está fixo em `FusionReportHttp`.
- **`ConnectionException` não herda de `FusionReportException`.** Quem faz
  `catch (FusionReportException $e)` não pega falha de conexão. Capture
  `Illuminate\Http\Client\ConnectionException` separadamente.

Para relatórios grandes, prefira `generateAsync()` com webhook.

### Sem retry automático

Um 5xx transitório ou soluço de rede vira exceção direto no chamador. Retry não
foi adicionado de propósito: seria seguro apenas em leituras (`get`, `download`)
e perigoso em `generate`, `async`, `upload` e `export`, que criam estado e
consomem cota — o servidor cobra por requisição recebida, então três tentativas
de uma geração seriam três débitos.

Se precisar, implemente o retry na sua aplicação, envolvendo só as leituras.

### URL do servidor não é configurável

`FusionReportHttp::BASE_URL` é uma constante. Não há `config` nem variável de
ambiente para apontar o client a outra instância — trocar exige editar o
package.

### Replay de webhook

O servidor assina apenas o corpo, sem timestamp, então o par corpo+assinatura é
válido indefinidamente. A deduplicação limita muito o estrago: um replay do
mesmo status não reexecuta os efeitos. Proteção completa exigiria timestamp
assinado, o que é mudança no servidor.

Com `log_generations = false` a deduplicação depende só do cache — expirado o
TTL, um replay volta a disparar os hooks.

### Outros

- A poda de `fusion_report_generations` é fixa em **90 dias**
  (`FusionReportGeneration::prunable()`), sem config.
- Os dois `export()` **não** registram a geração nova em
  `fusion_report_generations`, ao contrário de `generate()` e `generateAsync()`.
- `generationsByName()` não aceita `theme`. Com o mesmo nome em temas
  diferentes, não dá para escolher qual histórico buscar.
- O roteamento do webhook para a `ReportDefinition` casa apenas por `name()`,
  ignorando o tema.

---

## Referência completa — Endpoints cobertos

Rotas marcadas com ⚠️ exigem Sanctum e não funcionam via `X-API-KEY`
(ver seção acima).

| Endpoint | Método do package |
|---|---|
| `GET /api/v1/reports` | `templates()->list()` |
| `POST /api/v1/reports` | `templates()->upload(...)` |
| `GET /api/v1/reports/{id}` | `templates()->find($id)` |
| `POST /api/v1/reports/{id}` | `templates()->update($id, ...)` |
| `POST /api/v1/reports/name/{name}` | `templates()->updateByName($name, ...)` |
| `GET /api/v1/reports/{id}/jrxml` | `templates()->downloadJrxml($id)` |
| `DELETE /api/v1/reports/{id}` | `templates()->delete($id)` |
| `DELETE /api/v1/reports/name/{name}` | `templates()->deleteByName($name, $theme)` |
| `GET /api/v1/reports/{id}/generations` | `templates()->generations($id)` ⚠️ |
| `GET /api/v1/reports/name/{name}/generations` | `templates()->generationsByName($name)` ⚠️ |
| `POST /api/v1/generate` | `report('name')->generate()` |
| `POST /api/v1/async` | `report('name')->generateAsync()` |
| `POST /api/v1/reports/{id}/generate` | `reportById($id)->generate()` |
| `POST /api/v1/reports/{id}/async` | `reportById($id)->generateAsync()` |
| `GET /api/v1/generations/{id}` | `generation($id)->get()` ⚠️ |
| `DELETE /api/v1/generations/{id}` | `generation($id)->cancel()` ⚠️ |
| `GET /api/v1/files/{id}/download` | `file($id)->download()` |
| `POST /api/v1/files/{id}/export` | `file($id)->export($format)` |
| `POST /api/v1/generations/{id}/export` | `generation($id)->export($format)` |
| `GET /api/v1/jasper/formats` | `jasper()->formats()` ⚠️ |
| `GET /api/v1/jasper/status` | `jasper()->status()` ⚠️ |
| `GET /api/v1/jasper/jobs` | `jasper()->jobs()` ⚠️ |
| `GET /api/v1/jasper/fonts/status` | `jasper()->fontsStatus()` ⚠️ |
| `POST /api/v1/jasper/fonts/sync` | `jasper()->fontsSync()` ⚠️ |
