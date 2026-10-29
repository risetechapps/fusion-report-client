# Changelog

Todas as mudanças relevantes deste package são documentadas neste arquivo.

O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/)
e o versionamento segue [SemVer](https://semver.org/lang/pt-BR/).

---

## [1.1.0] - 2026-10-29

Acompanha o **fusion-report-server 1.1.0**. Cota nos headers, `usage()` e o
histórico de gerações pela API key exigem o servidor 1.1; o resto continua
funcionando com o 1.0.

### Atualização

- **Removido `fusion-report.url` (`FUSION_REPORT_URL`).** A URL do servidor é
  fixa em `FusionReportHttp::BASE_URL` (`https://fusionreport.app.br`); a
  variável passa a ser ignorada.
- **Removido o `JasperManager` (`FusionReport::jasper()`).** Status, métricas,
  jobs e fontes do renderizador são rotas de administrador no servidor e não
  respondem à API key. Para saber se o serviço está no ar, trate o
  `ServerException` da geração.
- **Dono da geração com chave UUID.** Com `log_generations` ligado,
  `->for($model)` com id inteiro lança `FusionReportException` antes do POST.
  Antes, o relatório era gerado (e cobrado) e o insert local falhava.
- **Webhook com janela de 5 minutos.** `X-Webhook-Timestamp` fora de
  `webhook.tolerance` (padrão 300 s) responde 401 `expired_timestamp`. Confira o
  relógio do servidor da aplicação (NTP); `FUSION_REPORT_WEBHOOK_TOLERANCE=0`
  desliga a checagem.
- `composer.json` exige `laravel/framework ^12` no lugar dos `illuminate/*`
  avulsos.

### Adicionado

- **Cota da geração.** `GenerationResource::quota()` traz limite, restante,
  usado e fim do período, lidos dos headers `X-Quota-*` da resposta de
  `generate()` e `generateAsync()`. Plano ilimitado volta `limit()` e
  `remaining()` null; servidor sem os headers volta `quota()` null.
- **Consumo da conta.** `FusionReport::usage()` (`UsageResource`: plano,
  período, requisições, excedente, limite por minuto e a chave usada) e
  `FusionReport::dailyUsage($days)` (requisições por dia, de 1 a 90 dias). Não
  descontam da cota.
- **`RateLimitException` com a cota.** `quota()` e `isQuotaExhausted()` separam
  a cota esgotada do limite de taxa, e `retryAfter()` passa a dar o fim do
  período quando a cota esgotou (o servidor não manda `Retry-After` nesse caso).
- **Histórico de gerações pela API key.** `templates()->generations($id)` e
  `generationsByName($name)` funcionam com o servidor 1.1 (antes, 401). Ganham
  `perPage` (1 a 100), e `generationsByName()` ganha `theme`.
- `TemplateManager::quota()`: limite de templates do plano e quantos a conta já
  tem.
- `fusion-report:sync`: `--prune` remove do servidor os templates que saíram
  do config, com confirmação; o config é validado antes do envio (`name()`
  repetido, tema repetido e nome vazio viram **Failed** com o motivo), e o
  comando avisa quando os templates novos passam do limite do plano.
- Evento `ReportWebhookFailed` e `ProcessFusionReportWebhook::failed()`: o job
  da fila que esgota as tentativas registra o erro e dispara o evento.
- Webhook: `200 {"duplicate": true}` para `X-Webhook-Id` já processado e
  `200 {"ok": true, "test": true}` para o evento de teste do painel
  (`webhook.test`).
- `UnknownGenerationException`: callback que chega antes de `generateAsync()`
  gravar a linha local (relatório rápido). A rota responde 503 para o servidor
  reentregar, e o job da fila tenta de novo pelo backoff.

### Corrigido

- **`wait()` não esperava a geração criada só com o id.**
  `FusionReport::generation($id)->wait()` voltava na hora com status `unknown`:
  sem status carregado, o loop nem começava. Agora consulta o servidor antes.
- `InlineCsvDatasource` gera CSV RFC 4180 com `fputcsv` (aspas e quebras de
  linha dentro do campo), e o JSON inline usa `JSON_THROW_ON_ERROR`.
- O log de gerações respeita o morph map do app na relação `loggable`.
- `webhook.deduplication_ttl` passa de 1 para 2 dias, para cobrir as novas
  tentativas do servidor (1 min, 5 min, 15 min, 1 h e 6 h).

## [1.0.0] - 2026-10-23

Primeira versão oficial. Consolida o que foi desenvolvido até aqui — ver
*Histórico de desenvolvimento* no fim do arquivo — e as mudanças abaixo, que
acompanham o **fusion-report-server 1.0.0**.

### Segurança

- **A rota `POST /reports/generate` (`FusionReport::routes()`) aceitava qualquer
  relatório e quaisquer parâmetros de qualquer usuário autenticado**, e mandava o
  resultado para qualquer e-mail. Um relatório filtrado por `cliente_id` entregava
  os dados de outro cliente a quem trocasse o número. Agora a rota:
  - responde **404** para nome fora de `fusion-report.reports` (antes, 500);
  - pergunta à definition — `ReportDefinition::authorize($user, $params)`, **negado
    por padrão** (403);
  - só aceita `email` do próprio usuário, ajustável por
    `ReportDefinition::canSendTo($user, $email)`.

  **Breaking** para quem já usa a rota: cada definition exposta por ela precisa
  sobrescrever `authorize()`.
- Novo `FusionReport::definition($name)`: a definition registrada, ou `null`.
- **A rota de webhook passa a falhar fechada.** Antes, `webhook.secret` vazio
  fazia `WebhookPayload::fromRequest()` pular a verificação HMAC por completo, e
  a rota virava um endpoint sem autenticação capaz de alterar `status` e
  `download_urls` em `fusion_report_generations` e de disparar
  `onWebhookReceived()` e os eventos da aplicação. Sem segredo, a rota agora
  responde **503** e não processa nada. Nova flag
  `webhook.require_signature` (padrão `true`) permite desligar a exigência em
  desenvolvimento — cada callback aceito sem verificação gera `Log::warning`.
- **`WebhookSignatureException` não expõe mais a assinatura esperada.** A
  mensagem incluía `Expected: [sha256=...]`, que é o HMAC válido para o corpo
  recebido — quem conseguisse observar a exceção (log, debug, resposta) teria um
  oráculo de assinatura. A mensagem agora é apenas
  `Invalid webhook signature.`

### Adicionado

- `GenerationResource::wait($timeoutSeconds = 60, $intervalSeconds = 2)`: reconsulta
  até a geração sair de `pending`/`processing`. Para a geração síncrona que o servidor
  Java enfileira — o servidor 1.0.0 responde 202, e antes o resultado se perdia: sem
  webhook no modo síncrono e sem como consultar.
- `generation($id)->get()`, `->status()` e `->cancel()` passam a funcionar com
  `X-API-KEY` (servidor 1.0.0). Saíram da lista de métodos indisponíveis.
- `ServerException` (subclasse de `FusionReportException`) para respostas 5xx,
  com `status()` e `isRendererFailure()` (502). O servidor 1.0.0 passou a
  responder 500/502 onde antes respondia 410, que o package traduzia como
  `ReportNotFoundException`.
- `fusion-report.url` (`FUSION_REPORT_URL`) e `fusion-report.timeout`
  (`FUSION_REPORT_TIMEOUT`): a URL do servidor deixou de ser fixa, e o timeout
  de 60 s passou a ser configurável.
- Primeira suíte de testes do package (`tests/`, Pest + Testbench).
- Processamento do webhook em fila, via `webhook.queue`
  (`FUSION_REPORT_WEBHOOK_QUEUE`). O callback era sempre processado dentro da
  requisição: o `200` só saía depois do update, dos eventos e do
  `onWebhookReceived()`. Como o servidor concede 15s e reentrega até 3 vezes, um
  hook que manda e-mail ou sobe arquivo estourava o limite — o trabalho era
  feito, mas o servidor registrava `Client webhook gave up after all attempts` e
  reentregava. Com a fila ligada, a assinatura é verificada, o payload vai para
  o job `ProcessFusionReportWebhook` (`tries = 3`, backoff 10s/60s/180s) e o
  `200` sai em milissegundos. Padrão `false` — ligar exige worker rodando.
  A validação de payload malformado continua antes do enfileiramento, para o
  `422` seguir valendo.
- `defaults.format` no config (`FUSION_REPORT_FORMAT`, padrão `pdf`). O servidor
  valida `format` como `required|array|min:1`, então toda geração sem
  `->format()` explícito era recusada com 422 — não havia default no client.
  Aceita `'pdf'`, `'pdf,xlsx'` ou `['pdf', 'xlsx']`.
- `TemplateManager::downloadJrxml($id)` — cobre `GET /api/v1/reports/{id}/jrxml`,
  que não tinha método no client. Devolve a `Response` crua: o endpoint responde
  XML (`application/xml`), não o envelope JSON do resto da API, então passa por
  `download()` e não por `get()`.
- `GenerationResource::export($format)` — cobre
  `POST /api/v1/generations/{id}/export`. Antes só existia o export por arquivo
  (`FileResource::export()`); agora dá para re-exportar a partir do ID da
  geração, com o servidor localizando o `.frp` sozinho. Ambos eram alcançáveis
  por API key e estavam sem cobertura.
- Exceptions específicas por status HTTP, todas herdando de
  `FusionReportException` (nenhuma quebra de `catch` existente):
  `AuthenticationException` (401), `AuthorizationException` (403) e
  `RateLimitException` (429). Esta última expõe `retryAfter()` em segundos
  quando o servidor manda o cabeçalho `Retry-After`.
- Eventos de ciclo de vida: `ReportGenerationStarted`,
  `ReportGenerationCompleted` e `ReportGenerationFailed`, disparados na geração
  síncrona, na assíncrona e no callback de webhook. Permitem reagir a gerações
  sem ser dono da `ReportDefinition`. Carregam `generation`, `context` e `owner`
  como propriedades públicas readonly.
- `FusionReportHttp` registrado como singleton no container, para poder ser
  injetado (o handler de webhook passou a depender dele).
- `LICENSE` (MIT) — declarado no `composer.json` desde sempre, mas o arquivo não
  existia.
- `SECURITY.md`, com canal de report e as notas de integração sobre segredo de
  webhook e API key.
- `.gitignore` e `.editorconfig`.

### Corrigido

- **`jasper()` apontava para rotas que não existem mais.** O servidor renomeou
  `/api/v1/jasper/*` para `/api/v1/fusion-report/*` durante o desenvolvimento, e toda chamada
  respondia 404. Os caminhos foram atualizados e ganharam `health()` e
  `metrics()`. `fontsSync()` foi descontinuado: a rota saiu do servidor, e o
  método agora lança `FusionReportException` com o motivo.
- **Nome de template com espaço, `/` ou `?` quebrava a URL.** `updateByName()`,
  `deleteByName()` e `generationsByName()` interpolavam o nome cru no caminho; o
  servidor aceita qualquer nome de até 255 caracteres. Agora vai com
  `rawurlencode()`.
- **`generate()` disparava `ReportGenerationCompleted` para geração pendente.**
  Quando o servidor Java enfileira o job, a resposta síncrona volta `pending`, e
  o evento saía sem arquivo nenhum. Agora só `completed` dispara `Completed`, só
  `failed` dispara `Failed`; pendente não dispara nada.
- **`X-API-KEY` deixava de ser enviado quando a chave não estava resolvida na
  primeira vez que o container montou o serviço.** `FusionReportHttp` é
  singleton e capturava `api_key` no construtor, então o valor do primeiro
  contexto valia para o processo inteiro: geração saía sem o header e o servidor
  respondia 401. Mesmo efeito para quem troca a chave em runtime — bootstrapper
  de tenant, worker de fila que muda de contexto, teste que sobrescreve o
  config. A chave passa a ser lida a cada requisição, com o array recebido no
  construtor como fallback.
- Com `log_generations = false`, todo callback de webhook virava no-op. O
  handler buscava o registro em `fusion_report_generations` e retornava cedo
  quando não achava — mas com o log desligado o registro nunca é criado, então
  `onWebhookReceived()` e os eventos jamais disparavam e o fluxo assíncrono
  sumia em silêncio. Agora os efeitos disparam também nesse modo, sem
  deduplicação e com `$context` vazio e `$owner` null, por não haver tabela onde
  guardá-los.
- Registro ausente **com** o log ligado passa a gerar `Log::warning` em vez de
  retorno silencioso. Em apps multi-tenant isso costuma indicar que o middleware
  de `webhook.middleware` não inicializou o tenant antes do controller.
- Deduplicação de webhook reforçada com um segundo guard, `Cache::add()` na
  chave `fusion-report:webhook:{uuid}:{status}`. Fecha dois furos que o guard de
  status sozinho deixava: no modo `log_generations = false` não havia linha e
  portanto nenhuma deduplicação — reentrega normal do servidor, sem atacante
  algum, repetia e-mail e job até 3 vezes; e mesmo com o log ligado havia uma
  corrida, já que o status é lido antes de ser gravado, então duas entregas
  simultâneas passavam as duas. `Cache::add()` é atômico e resolve os dois. TTL
  em `webhook.deduplication_ttl` (minutos, padrão 1440).
- Callback duplicado reexecutava os efeitos colaterais. O servidor reentrega o
  webhook até 3 vezes (backoff 10s/60s/180s) sempre que a resposta não chega —
  inclusive quando o handler já rodou inteiro mas estourou o timeout de 15s da
  requisição, o que é mais provável justamente quando o hook faz trabalho
  pesado. `onWebhookReceived()` e os eventos rodavam de novo a cada entrega,
  gerando e-mail e job duplicados. O handler agora compara o status já gravado
  com o do payload e, sendo o mesmo, atualiza a linha mas não repete os efeitos.
  A escrita continua sempre acontecendo, para o caso de a primeira entrega ter
  morrido no meio.
- `Facade::routes()` ignorava o middleware informado pelo caller. Eram dois
  defeitos na mesma closure: `$options` não estava no escopo (faltava `use`),
  então `$options['middleware'] ?? []` sempre resultava em `[]`, e a linha
  seguinte sobrescrevia tudo com `['auth:sanctum']` fixo. O docblock prometia
  `routes(['middleware' => 'auth:api'])`. Agora o middleware do caller vence e
  `auth:sanctum` é só o padrão de quem não informa nada.
- `serializeParams()` corrompia parâmetros não-string. Um `array` virava a
  string literal `CHAVE=Array` (Warning "Array to string conversion", sem
  crash), e `false` virava `CHAVE=`, indistinguível de string vazia. A conversão
  passa a tratar `null`, `bool`, `array` (JSON), `BackedEnum`,
  `DateTimeInterface` (ISO-8601) e `Stringable`, lançando
  `FusionReportException` com o nome da chave para tipos sem representação
  textual.
- `WebhookPayload::uuid()` e `status()` acessavam o array direto. Payload sem
  `id` ou `status` — já validado pela assinatura — virava
  "Undefined array key" e um 500 opaco. Agora lançam
  `MalformedWebhookException`, que o controller converte em **422** para o
  servidor parar de retentar um payload que nunca vai funcionar.
- `FusionReportGeneration::createFromGeneration()` usava `create()` contra a
  constraint `unique` de `generation_uuid`, então retry da requisição ou webhook
  reentregue estourava. Passa a usar `updateOrCreate()`.
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

- Upload de template e de resources vai em stream em vez de `file_get_contents`.
- **`api_key` passa a ser obrigatória.** O header só era anexado quando a chave
  existia (`if (! empty(...))`), então uma configuração incompleta virava
  requisição anônima e o erro chegava como 401 genérico do servidor, longe da
  causa. Sem chave, o package agora lança `AuthenticationException` antes de
  qualquer requisição sair, apontando `FUSION_REPORT_API_KEY`. Afeta também
  `file($id)->download()`, cuja rota é pública no servidor e antes funcionava
  sem chave nenhuma.
- `FusionReportHttp` passa a ler a mensagem de erro de `message` **ou** `error`.
  O middleware de cota do servidor responde `{"error": "..."}`, então o motivo
  do 429 se perdia e virava `"Fusion Report Server error [429]."`.
- `composer.json`: declaradas as dependências que o package já usava de fato —
  `illuminate/bus`, `illuminate/contracts` e `illuminate/queue`. Vinham
  transitivamente por `illuminate/support`, e o job de webhook as usa
  diretamente. O conjunto resolvido não muda.
- `composer.json`: removido o campo `version` fixo — a versão passa a vir das
  tags git, como o Composer espera de uma biblioteca.
- `format()` normaliza a entrada: aceita lista separada por vírgula
  (`'pdf,xlsx'`), remove espaços, converte para minúsculas (`'PDF'` → `'pdf'`) e
  descarta duplicatas. Antes, `->format('pdf,xlsx')` virava um único formato
  inválido `'pdf,xlsx'` e `'PDF'` era recusado pelo enum do servidor.
- **Mudança de comportamento:** parâmetro booleano agora serializa como
  `CHAVE=true` / `CHAVE=false`, não mais `CHAVE=1` / `CHAVE=`. Relatórios que
  comparem o parâmetro com `"1"` precisam ser ajustados.
- `ReportDefinition::onGenerated()` documentado como exclusivo do modo síncrono.
  `generateAsync()` não o executa — naquele ponto o servidor apenas enfileirou
  o job e `files()` viria vazio. O equivalente assíncrono é
  `onWebhookReceived()`; para reagir ao enfileiramento, use o evento
  `ReportGenerationStarted`. Mesma nota em `setAfterGenerate()` e no README.
- `composer.lock` deixa de ser versionado. Não é usado por quem consome o
  package, e o arquivo estava defasado, com advisories de severidade alta em
  `guzzlehttp/guzzle` e `league/commonmark` que um CI instalaria.
- `TemplateManager::generations()` e `generationsByName()` passam a usar o mesmo
  desempacotamento tolerante a payload paginado. Funcionavam por acaso: o
  servidor devolve `$generations->toArray()` direto em `data`, então o antigo
  `$response['data'] ?? $response` caía nos itens certos. Agora a forma é
  explícita e um payload inesperado retorna coleção vazia em vez de `TypeError`.

### Documentação

- README: nova seção "Limitações conhecidas", registrando o que ficou
  deliberadamente de fora desta rodada — timeout de 60s menor que os 90s que o
  servidor concede ao Jasper (com `ConnectionException` fora da hierarquia de
  exceptions do package), ausência de retry automático e o porquê, `BASE_URL`
  não configurável, replay de webhook, poda fixa em 90 dias, `export()` sem
  registro no log local, `generationsByName()` sem `theme` e roteamento da
  definition ignorando o tema.
- README: aviso na seção de tratamento de erros de que
  `Illuminate\Http\Client\ConnectionException` não é capturado por
  `catch (FusionReportException $e)`.
- README: seção "Listar" documenta que `list()` pagina internamente e devolve
  todos os templates.
- README: tabela "Endpoints cobertos" corrigida de `/api/...` para `/api/v1/...`,
  que é o prefixo realmente usado pelo client.
- README: nova seção "Métodos indisponíveis via X-API-KEY". O client autentica
  só por `X-API-KEY`, mas 9 métodos públicos batem em rotas que o servidor
  restringe ao dashboard (Sanctum) e respondem 401 — `generation($id)->get()`,
  `->status()`, `->cancel()`, `templates()->generations()`,
  `generationsByName()` e os 5 de `jasper()`. Estavam documentados como se
  funcionassem. Os métodos seguem no package (sem quebra de compatibilidade),
  agora com o aviso no README e nos docblocks.
- README: seção "Geração assíncrona" deixa explícito que não há polling na
  integração por API key — o resultado chega pelo webhook. O exemplo anterior
  mostrava `generation($id)->get()` seguido de `progressPercent()` e
  `errorMessage()`, sendo que a chamada dá 401 e os dois campos vêm sempre
  vazios.
- README: nova subseção "Campos sempre vazios", listando os accessors que leem
  campos mantidos em `$hidden` pelo servidor — `errorMessage()`,
  `progressPercent()`, `WebhookPayload::error()`, `TemplateResource::filePath()`
  e `resourcesPath()`. Consequência prática: geração assíncrona que falha chega
  no webhook com `status: 'failed'` e sem motivo.
- Docblocks de aviso nos métodos afetados (`JasperManager` em nível de classe,
  `GenerationResource::get()/status()/cancel()`, `TemplateManager::generations()`
  e `generationsByName()`) e nos accessors sempre vazios, para o aviso aparecer
  no autocomplete da IDE.
- `GenerationResource::get()`: docblock corrigido — atualiza a própria instância
  e devolve `$this`, não uma nova.

---

## Histórico de desenvolvimento

Versão publicada durante o desenvolvimento como `1.0.0`. Tudo o que está aqui faz
parte da 1.0.0 oficial.

### dev-1.0.0

#### Adicionado

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
