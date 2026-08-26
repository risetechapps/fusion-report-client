# Política de Segurança

## Reportando uma vulnerabilidade

Não abra issue pública para falhas de segurança. Envie um e-mail para
**apps@risetech.com.br** com a descrição, os passos de reprodução e a versão
afetada. Confirmamos o recebimento e retornamos com um plano de correção.

## Versões suportadas

| Versão | Suporte |
|---|---|
| 1.x | ✅ |

## Notas de segurança para quem integra

### Segredo do webhook

O package verifica a assinatura HMAC-SHA256 do callback usando
`fusion-report.webhook.secret`. **Configure sempre esse valor em produção.**

Quando o segredo está vazio, `WebhookPayload::fromRequest()` pula a verificação
e a rota do webhook aceita qualquer POST — o que permite a terceiros alterar
status e URLs de download em `fusion_report_generations`. Esse comportamento é
herdado e será endurecido; até lá, tratar o segredo como obrigatório é
responsabilidade da aplicação.

Gere o segredo no dashboard do Fusion Report Server e mantenha-o igual dos dois
lados:

```env
FUSION_REPORT_WEBHOOK_SECRET=...
```

### API Key

`FUSION_REPORT_API_KEY` dá acesso total aos templates e à cota de geração da
conta. Mantenha fora do controle de versão e rotacione pelo dashboard em caso de
exposição.

### Rota do webhook

A rota é pública por natureza — o servidor precisa alcançá-la. A proteção é a
assinatura HMAC, não middleware de autenticação. O middleware configurado em
`fusion-report.webhook.middleware` serve para estabelecer contexto (tenancy,
por exemplo), não para autenticar o chamador.
