# Changelog

Todas as mudanças relevantes deste package são documentadas neste arquivo.

O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/)
e o versionamento segue [SemVer](https://semver.org/lang/pt-BR/).

---

## [Não lançado]

### Corrigido

- `TemplateManager::list()` lançava `TypeError: Argument #1 ($item) must be of
  type array, int given` ao rodar `php artisan fusion-report:sync`. O servidor
  responde `data.reports` com o paginator serializado
  (`{current_page, data: [...], last_page, ...}`), então o método mapeava a
  metadata da paginação em vez dos templates — o primeiro valor iterado era o
  int de `current_page`. Os itens agora são lidos de `reports.data`.

- `TemplateManager::list()` retornava no máximo uma página de templates
  (`per_page` padrão 50 no servidor). Com mais de 50 templates registrados, o
  `fusion-report:sync` tratava os das páginas seguintes como não registrados e
  os reenviava a cada execução. O método agora percorre todas as páginas
  (`per_page=100`).

### Alterado

- `TemplateManager::generations()` e `generationsByName()` passam a usar o mesmo
  desempacotamento tolerante a payload paginado. Funcionavam por acaso: o
  servidor devolve `$generations->toArray()` direto em `data`, então o antigo
  `$response['data'] ?? $response` caía nos itens certos. Agora a forma é
  explícita e um payload inesperado retorna coleção vazia em vez de `TypeError`.

### Documentação

- README: seção "Listar" documenta que `list()` pagina internamente e devolve
  todos os templates.
- README: tabela "Endpoints cobertos" corrigida de `/api/...` para `/api/v1/...`,
  que é o prefixo realmente usado pelo client.

---

## [1.0.0]

### Adicionado

- Client Laravel para o **fusion-report-server**, com API fluente e tipada.
- `FusionReportClient` + facade `FusionReport` como ponto de entrada.
- `ReportDefinition` — declaração de relatórios (nome, datasource, temas,
  parâmetros padrão, proteção, hooks de ciclo de vida).
- `ThemeFusion` — cada tema é um template distinto no servidor (identidade
  `name + theme`), com `.jrxml` e resources próprios.
- `ReportRequestBuilder` — geração síncrona (`generate()`) e assíncrona
  (`generateAsync()`), por nome ou por ID de template.
- Datasources: `NoDatasource`, `InlineJsonDatasource`, `InlineCsvDatasource`,
  `JsonUrlDatasource`, `ProfileDatasource`.
- `TemplateManager` — CRUD de templates JRXML (por ID e por nome) e histórico
  de gerações.
- `JasperManager` — formatos, status, jobs e sincronização de fontes.
- Recursos tipados: `GenerationResource`, `FileResource`, `TemplateResource`.
- Webhook: rota configurável, verificação de assinatura HMAC-SHA256,
  `WebhookPayload` / `WebhookDownload` e handler substituível via contrato
  `WebhookHandler`.
- `webhookParams()` — parâmetros anexados à URL do webhook para restabelecer
  contexto no callback (ex.: `tenant_id` em apps multi-tenant).
- Log de gerações em `fusion_report_generations`, com relação polimórfica
  (`loggable`), trait `HasFusionReportGenerations` e poda automática após 90 dias.
- Comando `fusion-report:sync` para registrar/atualizar templates no servidor
  (`--force` interativo, `--all` sem perguntas).
- `ReportProtection` — senha de usuário e de owner no PDF.
- Exceções dedicadas: `FusionReportException`, `ReportNotFoundException`,
  `WebhookSignatureException`.
