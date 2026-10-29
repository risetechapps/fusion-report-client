# Changelog

Todas as mudanças relevantes deste package são documentadas neste arquivo.

O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/)
e o versionamento segue [SemVer](https://semver.org/lang/pt-BR/).

---

## [1.0.0] - 2026-10-28

Primeira versão oficial, para o **fusion-report-server 1.0** (contrato OpenAPI
1.0.2). As tags anteriores foram de teste e não fazem parte do histórico.

### Adicionado

#### Client e geração

- `FusionReportClient` e a facade `FusionReport` como ponto de entrada; o
  `FusionReportHttp` é singleton no container e pode ser injetado.
- `ReportDefinition`: nome, datasource, temas, parâmetros padrão, proteção e
  hooks de ciclo de vida (`onGenerated()` no síncrono, `onWebhookReceived()` no
  callback). Definitions são resolvidas pelo container, então aceitam
  dependências no construtor.
- `ThemeFusion`: cada tema é um template distinto no servidor (identidade
  `name + theme`), com `.jrxml` e `resources.zip` próprios.
- `ReportRequestBuilder`: `generate()` (síncrono) e `generateAsync()`, por nome
  ou por id (`reportById()`), com `format()`, `params()`, `locale()`, `theme()`,
  `datasource()`, `protection()`, `context()`, `for()` e `webhookParams()`.
  - `format()` aceita `'pdf'`, `'pdf,xlsx'` ou `['pdf', 'xlsx']`, normaliza
    caixa e duplicatas e recusa item que não é string. Padrão em
    `defaults.format` (`FUSION_REPORT_FORMAT`).
  - Params serializados por tipo: `null`, `bool` (`true`/`false`), `array`
    (JSON), `BackedEnum`, `DateTimeInterface` (`Y-m-d`) e `Stringable`; tipo sem
    representação textual lança `FusionReportException` com o nome da chave.
    Chaves numéricas são preservadas.
  - `generate()` também manda `webhook_url`: o servidor o chama quando a geração
    síncrona cai na fila (202).
- `FusionReport::register()`, `make()`, `definition()` e `definitionFor()`.
- Datasources: `NoDatasource`, `InlineJsonDatasource`, `InlineCsvDatasource`
  (CSV RFC 4180 via `fputcsv`, delimitador de 1 caractere), `JsonUrlDatasource`
  (GET/POST/PUT/DELETE) e `ProfileDatasource`.
- Recursos tipados: `GenerationResource` (`wait()`, `get()`, `status()`,
  `cancel()`, `export()`), `FileResource` (`url()`, `download()`, `export()`,
  `size()`) e `TemplateResource`.
- `ReportProtection`: senha de usuário e de owner no PDF.

#### Templates

- `TemplateManager`: `list()` (percorre todas as páginas), `find()`, `upload()`,
  `update()`, `updateByName()` (arquivo opcional; tema na query string),
  `delete()`, `deleteByName()`, `downloadJrxml()`, `generations()`,
  `generationsByName()` e `quota()` (limite de templates do plano).
- Comando `fusion-report:sync`: registra e atualiza no servidor os templates de
  `fusion-report.reports`.
  - `--force` interativo e `--all` sem perguntas.
  - `--prune` remove do servidor os templates `(name + theme)` que saíram do
    config, com confirmação no terminal.
  - Valida o config antes de enviar: `name()` repetido, tema repetido e nome
    vazio viram **Failed** com o motivo, e o resto segue.
  - Avisa quando os templates novos passam do limite do plano.

#### Webhook

- Rota configurável (`webhook.path`, `webhook.middleware`) com verificação
  HMAC-SHA256 de `"{X-Webhook-Timestamp}.{corpo bruto}"` em
  `X-Webhook-Signature` (aceita com ou sem `sha256=`) e recusa de timestamp
  com mais de 5 minutos de diferença (`webhook.tolerance`).
- `WebhookPayload` / `WebhookDownload` e handler substituível pelo contrato
  `WebhookHandler`.
- Respostas: `401` com `reason` (`missing_signature`, `missing_timestamp`,
  `invalid_signature` ou `expired_timestamp`), `200 {"duplicate": true}` para
  `X-Webhook-Id` já processado,
  `422` para payload sem `id`/`status`, `503` para geração ainda não gravada
  (o servidor reentrega) e `200 {"ok": true, "test": true}` para o evento de
  teste do painel (`webhook.test`).
- Deduplicação por `X-Webhook-Id`, por status gravado e por geração + status
  no cache (`webhook.deduplication_ttl`, padrão 2 dias). Se o processamento
  falhar, as marcas são desfeitas para a nova tentativa reprocessar (pelo
  menos uma vez); na fila, o id é liberado quando o job esgota as tentativas.
- Processamento em fila opcional (`webhook.queue`): job
  `ProcessFusionReportWebhook` (`tries = 3`, backoff 10s/60s/180s), com
  `failed()` que registra o erro e dispara `ReportWebhookFailed`.

#### Log de gerações

- Tabela `fusion_report_generations` (`log_generations`), com relação
  polimórfica `loggable` (respeita o morph map), trait
  `HasFusionReportGenerations` e poda automática após 90 dias. Com o log
  ligado, o dono precisa ter chave UUID; a geração é recusada antes do POST.

#### Eventos e exceções

- Eventos `ReportGenerationStarted`, `ReportGenerationCompleted`,
  `ReportGenerationFailed` e `ReportWebhookFailed`.
- Exceções, todas herdando de `FusionReportException`:
  `AuthenticationException` (401 e `api_key` ausente), `AuthorizationException`
  (403), `ReportNotFoundException` (404), `RateLimitException` (429, com
  `retryAfter()`), `ServerException` (5xx, com `isRendererFailure()`),
  `WebhookSignatureException`, `MalformedWebhookException` e
  `UnknownGenerationException`. Resposta 2xx sem `id` lança
  `FusionReportException` com as chaves recebidas.

#### Rota de geração

- `FusionReport::routes()` registra `POST /reports/generate` (middleware padrão
  `auth:sanctum`, sobrescrevível).

### Segurança

- **`POST /reports/generate` nega por padrão.** Nome fora de
  `fusion-report.reports` responde 404; cada definition exposta precisa
  sobrescrever `ReportDefinition::authorize($user, $params)` (403 sem isso); o
  `email` só aceita o do próprio usuário, ajustável em `canSendTo()`.
- **Webhook falha fechado.** Sem `webhook.secret`, a rota responde 503 e não
  processa nada; `webhook.require_signature = false` existe só para
  desenvolvimento e gera `Log::warning` a cada callback.
- A `WebhookSignatureException` nunca expõe a assinatura esperada, e o log do
  webhook não grava o corpo (só IP, tamanho e, no payload malformado, as
  chaves).
- `api_key` é obrigatória e lida a cada requisição: sem ela, nenhuma requisição
  sai, e uma chave limpa em runtime não é substituída pela anterior.
- Ids e nomes são codificados com `rawurlencode` em todas as rotas.

### Limitações conhecidas

Ver a seção "Limitações conhecidas" do README: URL do servidor fixa em
`FusionReportHttp::BASE_URL`, rotas restritas ao dashboard (Sanctum), campos
mantidos em `$hidden` pelo servidor e `ConnectionException` fora da hierarquia
de exceções do package.
