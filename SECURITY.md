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

O package verifica a assinatura HMAC-SHA256 de `"{X-Webhook-Timestamp}.{corpo}"`
usando `fusion-report.webhook.secret`, recusa timestamp com mais de 5 minutos
de diferença e ignora `X-Webhook-Id` repetido. **Configure sempre o segredo em
produção.**

Quando o segredo está vazio, a rota do webhook responde 503 e não processa
nada. `FUSION_REPORT_WEBHOOK_REQUIRE_SIGNATURE=false` desliga essa trava e
aceita qualquer POST — o que permite a terceiros alterar status e URLs de
download em `fusion_report_generations`. Use só em desenvolvimento local.

A janela de 5 minutos depende do relógio do servidor da app: mantenha NTP
ligado.

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
