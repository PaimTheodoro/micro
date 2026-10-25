# Observabilidade (Monitor)

O monitor coleta métricas de cada request da API e as grava no **banco do próprio projeto**, sem infraestrutura nova. O framework entrega coleta, armazenamento, agregação por hora, uma API de leitura (`Reader`) e um serviço JSON opcional (`MonitorApi`, desligado por padrão); **cada projeto decide como consumir** (endpoint próprio, SQL direto, Grafana, Metabase). Não há UI nem rotas do framework.

São gravados **requests, queries SQL, exceções** (inclusive erros fatais do PHP) e **chamadas externas** (HTTP, e-mail e storage), agregados por hora em `psf_monitor_rollups` e lidos pela classe [`Reader`](#reader). O monitor é **opt-in**: desligado, cada gancho é só uma checagem de booleano.

## Índice

1. [O que é e por que](#o-que-é-e-por-que)
2. [Início rápido](#início-rápido)
3. [Configuração](#configuração)
4. [Como funciona](#como-funciona)
   - [Ciclo de vida do request](#ciclo-de-vida-do-request)
   - [X-Request-Id](#x-request-id)
   - [Queries](#queries)
   - [Exceções](#exceções)
   - [Chamadas externas](#chamadas-externas)
   - [Buffer, tetos e custo](#buffer-tetos-e-custo)
   - [Amostragem por peso](#amostragem-por-peso)
   - [Flush depois da resposta](#flush-depois-da-resposta)
   - [Circuit breaker](#circuit-breaker)
   - [`auto_migrate` (opt-in)](#auto_migrate-opt-in)
5. [Esquema das tabelas (contrato)](#esquema-das-tabelas-contrato)
6. [Privacidade](#privacidade)
7. [Agregados, histograma e percentis](#agregados-histograma-e-percentis)
8. [Reader](#reader)
9. [`MonitorApi` (serviço JSON opcional)](#monitorapi-serviço-json-opcional)
10. [Comandos CLI](#comandos-cli)
11. [Retenção e manutenção](#retenção-e-manutenção)
12. [Atualizando o framework](#atualizando-o-framework)
13. [Validação em bancos reais](#validação-em-bancos-reais)
14. [Limites e cuidados conhecidos](#limites-e-cuidados-conhecidos)
15. [Solução de problemas](#solução-de-problemas)
16. [Evolução](#evolução)

---

## O que é e por que

O monitor responde perguntas do dia a dia de uma API em produção: quais rotas são mais lentas ou erram mais, quais queries pesam no banco, quais exceções estão acontecendo e com que frequência, quais serviços externos (APIs HTTP, SMTP, storage) estão lentos ou falhando, e o que aconteceu num request específico (pelo `X-Request-Id`).

Princípios de desenho:

- **Sem infraestrutura nova.** Os dados ficam em tabelas `psf_monitor_*` num banco já configurado em `config['db']` (o principal ou um dedicado), acessíveis por SQL.
- **Nunca derruba a aplicação.** Todo gancho e todo o flush rodam em `try/catch (\Throwable)`; um [circuit breaker](#circuit-breaker) suspende a gravação quando o banco do monitor falha.
- **Custo baixo no caminho da resposta.** Durante o request os ganchos só guardam dados crus em memória; normalização, descrição de exceções e gravação acontecem no shutdown, [depois de liberar a resposta](#flush-depois-da-resposta) (com PHP-FPM).
- **Privacidade por desenho.** Nada de body, headers, cookies, query string ou URL real; SQL sem literais; trace sem argumentos; mensagens de erro de banco com os valores redigidos (ver [Privacidade](#privacidade)).
- **Agregados fiéis com amostragem.** Requests normais são amostrados, mas cada linha leva um `sample_weight`, então as somas ponderadas estimam 100% do tráfego (ver [Amostragem por peso](#amostragem-por-peso)).

---

## Início rápido

1. Ligue o monitor no config do projeto (`config['settings']['monitor']`):

```php
'db' => [
    'default' => [/* ... */],
    'monitor' => [/* banco dedicado, opcional */],
],
'settings' => [
    'monitor' => [
        'enabled'  => true,
        'database' => 'monitor',          // omitido = 'default'
        'sample'   => ['normal' => 0.1],  // grava ~10% dos requests normais
        'release'  => '1.4.2',            // opcional
    ],
],
```

2. Instale as tabelas e confira:

```bash
vendor/bin/phinx monitor:install
vendor/bin/phinx monitor:status
```

3. Agende a manutenção (agregação + limpeza) de hora em hora:

```cron
17 * * * * cd /caminho/do/projeto && php vendor/bin/phinx monitor:maintain >> /var/log/psf-monitor.log 2>&1
```

4. Leia os dados com o [`Reader`](#reader) num endpoint do projeto, ou por SQL direto sobre as [tabelas](#esquema-das-tabelas-contrato).

> Nos exemplos, `vendor/bin/phinx` é o wrapper do framework exposto pelo Composer no projeto consumidor (ele inicia o PSF antes de rodar o comando; não use o Phinx puro). No repositório do próprio framework, o mesmo wrapper é chamado como `./bin/phinx`. Os comandos `monitor:*` exigem `robmorgan/phinx` instalado no projeto.

Se `settings.monitor` estiver ausente, ou `enabled` for falso, o monitor fica totalmente desligado.

---

## Configuração

Tudo em `config['settings']['monitor']`. Valores omitidos usam o default. Para chaves que são arrays (`sample`, `slow_ms`, `retention`, `breaker`, `api`) o merge é recursivo; passar um escalar no lugar de um desses arrays é ignorado (o default continua valendo).

| Chave | Default | Efeito |
|-------|---------|--------|
| `enabled` | `false` | Liga o monitor (lido por truthiness). |
| `database` | `'default'` | Chave de `config['db']` onde as tabelas vivem. Vazia ou não-string vira `'default'`. |
| `sample.normal` | `0.1` | Probabilidade de gravar um request "normal" (sem erro, exceção ou lentidão). Limitada a `[0, 1]`; valor não numérico ou negativo vira `0.1`; `0` grava só erros, exceções e lentos. |
| `slow_ms.request` | `1000` | Request com duração >= este valor é sempre gravado. |
| `slow_ms.query` | `200` | Request com ao menos uma query de duração >= este valor é sempre gravado, mesmo que essa query tenha ficado acima do teto de eventos. |
| `slow_ms.external` | `1000` | Request com ao menos uma chamada externa de duração >= este valor é sempre gravado (peso 1), mesmo que essa chamada tenha ficado acima do teto de eventos. Ver [Chamadas externas](#chamadas-externas). |
| `max_events_per_request` | `200` | Teto de **queries** e, separadamente, de **chamadas externas** guardadas por request (mínimo 1 em cada tipo); o excedente é contado em `dropped_events`. Exceções têm teto fixo de 20. |
| `retention.raw_days` | `7` | Dias de dados brutos (requests, queries, exceções e chamadas externas). Inteiro 1..36500. Ver [Retenção e manutenção](#retenção-e-manutenção). |
| `retention.aggregate_days` | `90` | Dias de agregados por hora (`psf_monitor_rollups`). Inteiro 1..36500. |
| `breaker.failures` | `3` | Falhas seguidas de gravação até abrir o breaker. |
| `breaker.cooldown_seconds` | `300` | Tempo com a gravação suspensa depois que o breaker abre. |
| `connect_timeout` | `1` | Timeout de conexão (s) da conexão dedicada: `PDO::ATTR_TIMEOUT` (no SQL Server, `LoginTimeout` no DSN, pois o pdo_sqlsrv não aceita esse atributo). É só um default: ver [Limites](#limites-e-cuidados-conhecidos). |
| `expose_request_id` | `true` | Devolve o `X-Request-Id` na resposta e o expõe no CORS. |
| `store_user` | `true` | Grava `user_id` (de `Router::$auth`). |
| `user_id_property` | `'id'` | Propriedade (objeto) ou chave (array) de `Router::$auth` usada como `user_id`. |
| `auto_migrate` | `false` | Opt-in: cria as tabelas sozinho se faltarem (fora de `prd`). Ver [`auto_migrate`](#auto_migrate-opt-in). |
| `store_exception_message` | `true` | Grava a mensagem das exceções (as de `PDOException` e de erros fatais passam antes pela [limpeza de valores](#limpeza-de-mensagens)). Com `false`, a coluna `message` fica vazia para **todas** as exceções e as linhas `Caused by:` do trace saem sem mensagem. |
| `api.enabled` | `false` | Liga o serviço JSON opcional [`MonitorApi`](#monitorapi-serviço-json-opcional). Só `true` (booleano) liga; desligado, ele responde 404 sem consultar o banco. Não afeta a coleta nem o `Reader`. |
| `release` | _(ausente)_ | Valor livre (string ou número) gravado em `app_release`, cortado em 64 caracteres. |

O ambiente gravado em `env` vem de `config['environment']` (ausente ou vazio = `dev`).

---

## Como funciona

### Ciclo de vida do request

1. **Boot.** O construtor do `Router` chama `Monitor::boot()`. É idempotente e nunca lança (em caso de erro o monitor fica "sem boot" e todos os ganchos viram no-op). O boot guarda o início do request (`$_SERVER['REQUEST_TIME_FLOAT']`, ou `microtime(true)`), resolve o [`X-Request-Id`](#x-request-id), marca preflights CORS (`OPTIONS` com `Access-Control-Request-Method`) como ignorados, registra `Monitor::flush()` como função de shutdown (uma vez por processo) e emite o header `X-Request-Id` se os headers ainda não foram enviados.
2. **CORS.** Com `settings.cors` configurado, o `Router` acrescenta `X-Request-Id` a `Access-Control-Expose-Headers` (ver [X-Request-Id](#x-request-id)).
3. **Rota.** Ao casar a rota, o `Router` registra o **padrão** dela (`Monitor::route()`), nunca a URL real, junto com o método e a versão. Sem rota casada, registra `(unmatched)` antes de lançar o 404.
4. **Usuário.** Depois de uma autenticação bem-sucedida (middleware `authentication`), o `Router` passa `Router::$auth` para `Monitor::user()`, que extrai o `user_id` (se `store_user`).
5. **Queries, exceções e chamadas externas.** Os [ganchos dos shims de banco](#queries), do `Router` e dos [clientes externos](#chamadas-externas) guardam eventos crus no [buffer](#buffer-tetos-e-custo).
6. **Shutdown.** `Monitor::flush()` lê o status (`http_response_code()`, padrão 200) e a duração, detecta erro fatal, aplica a [amostragem](#amostragem-por-peso) e consulta o [breaker](#circuit-breaker). Só se o request for gravado: [libera a resposta](#flush-depois-da-resposta), normaliza o SQL, descreve as exceções e grava tudo **num único lote transacional** (request + queries + exceções + chamadas externas) pela conexão dedicada.

`Monitor::requestId()` devolve o id do request atual (`null` com o monitor desligado ou sem boot), útil para colocá-lo nos logs da aplicação. A aplicação também pode registrar uma exceção que ela mesma tratou com `Monitor::exception($e)`.

### X-Request-Id

- O `request_id` é o `X-Request-Id` de entrada **se** casar com `/^[A-Za-z0-9-]{8,64}$/` (para correlacionar com proxy/gateway); caso contrário o monitor gera 16 bytes aleatórios em hexadecimal (32 caracteres). O formato restrito evita injeção em logs.
- Com `expose_request_id = true`, o header `X-Request-Id: <request_id>` é emitido já no construtor do `Router`, portanto sai em **qualquer** resposta, inclusive erros e as que terminam em `exit` via `Http::response()` (desde que os headers ainda não tenham sido enviados).
- Para o header ser legível por JavaScript no navegador, quando o monitor está ligado, `expose_request_id` é verdadeiro **e** o CORS está configurado (`settings.cors`), o `Router` acrescenta `X-Request-Id` a `Access-Control-Expose-Headers` (sem duplicar, sem diferenciar maiúsculas). A classe `Cors` aceita a chave `expose_headers` no config também sem o monitor (ver [rotas.md](rotas.md#cors)).

### Queries

- **Onde são coletadas.** Em ganchos nos shims de banco: `Read`, `Create::exe`, `Create::batch`, `Update` e `Delete`. É gravado **um evento por statement executado**: cada chunk de `Create::batch` é um statement (um lote grande vira vários eventos). A duração é medida em volta do `execute()` (em `Read`, junto com o `fetchAll()`). Uma query que falha também é registrada (com a duração até a falha).
- **Não são instrumentados:** introspecção de esquema, metadata de `Model` e qualquer acesso que não passe por esses shims (por exemplo PDO usado diretamente pela aplicação). A conexão do próprio monitor também não é instrumentada.
- **Durante o request só o SQL cru é guardado** (até 8000 caracteres), com a duração, a chave de `config['db']` e o instante. A [normalização](#sql-normalizado) e o hash acontecem no flush, depois de a resposta ser liberada.
- **Limite de eventos.** Acima de `max_events_per_request` as queries seguintes são descartadas e somadas em `dropped_events`; o **maior tempo** de query do request continua sendo considerado pela amostragem (uma query lenta além do teto ainda faz o request ser gravado).

### Exceções

**São registradas:**

- Falhas 5xx na execução do controller: o `Router::dispatchController` registra a exceção **original**, com a cadeia `getPrevious()`, antes de embrulhá-la em "Erro ao executar a rota.". Conta como 5xx toda `\Exception` cujo código não esteja entre 400 e 499.
- `\Error`/`TypeError` (qualquer `\Throwable` que não seja `\Exception`) lançados por um controller: o `Router::dispatchController` registra e **relança o mesmo objeto, sem embrulhar nem alterar**.
- `PDOException` nos shims `Read`, `Update` e `Delete` (as de `Create` sobem até o `Router`, que as registra como falha 5xx do controller).
- **Erros fatais** do PHP (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`, `E_RECOVERABLE_ERROR`) lidos de `error_get_last()` no shutdown, gravados como `FatalError`. Um fatal força o status do request para **500** (no mínimo) e, portanto, o request é sempre gravado, **exceto em preflights CORS**, para os quais o flush retorna antes de ler o fatal.
- Qualquer exceção passada pela aplicação a `Monitor::exception($e)`.

**Não são registradas:** exceções do controller com código 4xx (400 a 499), `HttpResponseException`, e exceções lançadas pelo `Router` fora do `dispatchController` (rota não encontrada, falha de autenticação ou de middleware). Nesses casos só a linha do request é gravada (se amostrada), com o status que a resposta tiver no shutdown.

**Conteúdo gravado** (descrição feita no flush por `ExceptionDescriber`):

- `exception_class`, `message` (ver [Privacidade](#privacidade)), `file`, `line_no`.
- `trace` montado de `getTrace()` **sem os argumentos dos frames** (nunca `getTraceAsString()`), seguido da cadeia `getPrevious()` (`Caused by: Classe: mensagem (arquivo:linha)`, no máximo 20 elos).
- Limites: `exception_class` 191, `message` 500, `file` 255 caracteres; `trace` 16 KB (cortado com `... [truncado]`).
- `trace_hash = sha1(classe | arquivo:linha do throw | os 3 primeiros frames como "Classe::função")`, **sem** arquivo/linha dos chamadores: o mesmo ponto de `throw` alcançado por chamadores diferentes (outra linha, outra mensagem, outros argumentos) gera o **mesmo** hash. Closures do PHP 8.4 (`{closure:arquivo:linha}`) entram como `{closure}`.
- **`FatalError`:** `exception_class = 'FatalError'`, mensagem só até `Stack trace:` (o resto é descartado) e limpa como as de PDO, `trace` vazio e `trace_hash = sha1('FatalError|arquivo:linha')`.
- Teto de **20 exceções por request** (o excedente conta em `dropped_events`); o erro fatal é sempre mantido, mesmo acima do teto. A mesma instância de exceção só é registrada uma vez.

### Chamadas externas

O monitor mede o tempo e o resultado das chamadas que a aplicação faz a serviços de fora, dentro de cada request gravado. É a mesma regra das queries: durante o request só se guarda um evento cru no buffer, e a gravação acontece no flush.

**O que é medido** (ganchos nas classes do framework; com o monitor desligado cada um é só uma checagem de booleano e a chamada original roda como sempre):

| `kind` | Onde | `target` | `status` |
|--------|------|----------|----------|
| `http` | `Psf\Http\Request::send()` e `Psf\Http\Http::request()` (este último, depreciado) | **somente o host** da URL | código HTTP recebido; `null` em falha de transporte (erro do cURL) ou código 0 |
| `mail` | `Psf\Utils\Email::send()` | host do servidor SMTP (`server['host']`) | `200` em sucesso; `null` em falha (exceção do PHPMailer ou `send()` falso) |
| `storage` | `CloudStorage`: `putObject`, `getObject`, `listObjects` e `deleteObject` | `provedor/operação` | `@metadata.statusCode` do SDK quando existir; senão `200`; `null` em falha (exceção do SDK) |

Os ganchos **nunca alteram** o retorno nem a exceção da chamada original: o valor devolvido e a exceção lançada chegam intactos ao chamador, e a chamada que falha é registrada **uma vez**, com `status` nulo.

**Regra de privacidade do `target`.** O `target` é um rótulo seguro, em minúsculas, com no máximo 191 caracteres, gerado por `Psf\Monitor\ExternalTarget`. **Nunca** são gravados URL, esquema, caminho, query string, fragmento, usuário:senha (userinfo), porta, destinatário, remetente, assunto, bucket, chave de objeto, conta, header, body ou token. Entrada vazia ou inválida vira `(invalido)` (em storage, só a parte inválida é substituída).

| Origem | `target` gravado |
|--------|------------------|
| `https://usuario:senha@API.Exemplo.com:8443/v1/pedidos?token=abc#x` | `api.exemplo.com` |
| `http://[2001:db8::1]:8080/saude` | `2001:db8::1` (IPv6 sem colchetes) |
| `//cdn.exemplo.com/arquivo.png` | `cdn.exemplo.com` |
| `ftp://exemplo.com/`, `exemplo.com/sem-esquema`, `""` | `(invalido)` |
| servidor SMTP `smtp.exemplo.com` | `smtp.exemplo.com` |
| storage R2, `putObject` | `r2/putobject` (a operação também vai em minúsculas) |

O `kind` do evento é um de `http`, `mail` ou `storage`; qualquer outro valor é ignorado.

**`status` e erro.** `status` é o código HTTP quando existe e `null` quando a chamada falhou. Uma chamada que retorna com sucesso **sem status explícito** conta como `200`. Para os agregados, **erro** é `status IS NULL OR status >= 500`; um `4xx` **não** é erro (a chamada chegou ao serviço, que a recusou).

**Teto e lentidão.**

- `max_events_per_request` vale **por tipo de evento**: queries e chamadas externas têm baterias separadas, cada uma com o seu teto (200 por padrão). O excedente de chamadas externas é descartado e somado em `dropped_events`.
- O **maior tempo** de chamada externa é acompanhado mesmo das descartadas: se ele for >= `slow_ms.external` (padrão `1000` ms), o request é sempre gravado, com peso 1 (ver [Amostragem](#amostragem-por-peso)).
- A duração gravada é limitada ao intervalo da coluna (`decimal(10,2)`, até 99999999.99 ms); duração negativa, `NaN` ou infinita é ignorada.

**Chamadas customizadas.** Para medir outro serviço (um gateway de pagamento, uma fila, um cliente HTTP de terceiros), use a API estática do `Monitor`. Use sempre um `target` seguro (por exemplo `ExternalTarget::fromUrl($url)` ou um nome fixo); o `Monitor` grava o `target` **como veio** (cortado em 191 caracteres), sem sanear; só um `target` vazio ou com UTF-8 inválido é trocado por `(invalido)`. O `kind` passa por `trim` e minúsculas antes de ser validado (`' HTTP '` vira `http`).

- `Monitor::trackExternal(string $kind, string $target, \Closure $call, ?\Closure $statusFrom = null): mixed` executa a chamada, mede, devolve **exatamente** o retorno de `$call` e relança **exatamente** a exceção que ela lançar (registrada uma vez, `status = null`). Sem `$statusFrom`, retorno normal conta como `200`; com `$statusFrom` (`fn (mixed $resultado): ?int`), vale o que ele devolver (inteiro, string só de dígitos ou float inteiro); `null`, exceção dentro dele ou outro valor viram `null` (erro).
- `Monitor::external(string $kind, string $target, float $durationMs, ?int $status = null)` registra uma chamada **já medida**. **Atenção: omitir o `$status` conta como erro** (`null`); passe `200` (ou o código recebido) em chamadas bem-sucedidas.
- `Monitor::externalStart(): ?int` e `Monitor::externalEnd(?int $start, string $kind, string $target, ?int $status = null)` medem entre dois pontos do código (`externalStart()` devolve `null` com o monitor desligado e `externalEnd(null, ...)` não faz nada). O mesmo aviso do `$status` vale aqui.

Todos nunca lançam.

```php
use Psf\Monitor\{ExternalTarget, Monitor};

// A forma mais simples: o Monitor mede, devolve o retorno e relança a exceção original.
$resposta = Monitor::trackExternal(
    'http',
    ExternalTarget::fromUrl($url),
    fn () => $cliente->get($url),
    fn ($r) => $r->getStatusCode()          // opcional; sem isto, retorno normal = 200
);

// Medindo à mão (o status NÃO pode ser omitido em chamadas bem-sucedidas):
$t = Monitor::externalStart();
$ok = enviarParaFila($mensagem);
Monitor::externalEnd($t, 'http', 'fila.exemplo.com', $ok ? 200 : null);
```

### Buffer, tetos e custo

- Durante o request, os ganchos só **guardam o SQL cru** (cortado em 8000 caracteres sem partir UTF-8), a duração, a conexão e o instante; exceções são guardadas como objeto; chamadas externas guardam só `kind`, `target` (já seguro), duração, `status` e instante. Não há normalização nem hash no caminho da resposta.
- Descrição de exceções, normalização de SQL e hash rodam no **flush**, depois de `fastcgi_finish_request()`, e só se o request for gravado.
- Memória por request limitada: `max_events_per_request` queries (200 por padrão) × até 8000 caracteres + até `max_events_per_request` chamadas externas (eventos pequenos) + até 21 exceções (20 + o fatal).
- `dropped_events` (na linha do request) soma as queries e as chamadas externas acima do teto e as exceções acima de 20.

### Amostragem por peso

A decisão é tomada no shutdown, quando já se sabe status e duração:

- **Sempre gravado** (`sample_weight = 1`): status >= 500 (inclui erro fatal), qualquer exceção registrada, duração >= `slow_ms.request`, alguma query com duração >= `slow_ms.query` ou alguma chamada externa com duração >= `slow_ms.external` (mesmo que essa query ou chamada tenha ficado além do teto `max_events_per_request`).
- **Demais requests**: gravados com probabilidade `sample.normal`; cada linha gravada leva `sample_weight = 1 / sample.normal`. Com `sample.normal = 0`, nenhum request normal é gravado.

As linhas filhas (`queries`, `exceptions` e `external`) usam o mesmo `sample_weight` do request: ou o request é gravado com todos os seus eventos, ou não é gravado. Como a probabilidade de inclusão depende só da categoria do request, `SUM(sample_weight)` é uma estimativa **não enviesada** do total real.

Preflights CORS nunca são registrados. Requests sem rota casada (404) são tratados como os demais (amostrados), com `route = '(unmatched)'`.

### Flush depois da resposta

O flush roda como função de shutdown. Na ordem: decide o status (e o fatal), calcula o peso, consulta o breaker e, **só se o request for de fato gravado**, chama `fastcgi_finish_request()` (quando a função existe, ou seja, com PHP-FPM) para liberar o cliente antes de normalizar, descrever e gravar. Requests não gravados (preflight, fora da amostra, breaker aberto) não têm a saída fechada. Sem FPM a latência do flush é visível ao cliente (ver [Limites](#limites-e-cuidados-conhecidos)).

A gravação usa uma **conexão PDO dedicada** (sem passar por `Connect`): ela não herda transações da aplicação e não é instrumentada pelo próprio monitor. O lote inteiro (request + queries + exceções) vai numa transação, com `INSERT` multi-linha em chunks (no máximo ~2000 parâmetros e 1000 linhas por statement, por causa do SQL Server); uma falha desfaz tudo.

### Circuit breaker

Protege a aplicação de um banco de monitoramento lento ou fora do ar:

- Após `breaker.failures` falhas seguidas de gravação, o monitor para de gravar por `breaker.cooldown_seconds`.
- Estado em **APCu** quando disponível e ativo no SAPI (`apc.enabled`, e `apc.enable_cli` na CLI); sem APCu, num arquivo-flag `psf-monitor-<md5>.json` em `sys_get_temp_dir()`. A chave é derivada do banco do monitor (`md5('db:<database>')`).
- Ao abrir, registra **uma** linha de `error_log` com a causa (ver [Solução de problemas](#solução-de-problemas)).
- Um flush bem-sucedido zera o contador de falhas.
- Falhas do monitor nunca chegam à aplicação: todo o flush roda em `try/catch (\Throwable)`.

### `auto_migrate` (opt-in)

Com `'auto_migrate' => true`, o monitor instala as tabelas sozinho quando elas não existem. Regras:

- **Recusado em `prd`**: só quando `config['environment']` for **exatamente** `'prd'`. Outras grafias (`'production'`, `'PRD'`) **não** são tratadas como produção, e a ausência da chave conta como `dev`. Em `prd` o monitor registra uma linha de `error_log` e abre o breaker; o caminho de produção é `monitor:install`.
- **Só no flush, depois da resposta**: roda no shutdown, então o usuário não espera a criação das tabelas (com FPM; sem ele, ver [Limites](#limites-e-cuidados-conhecidos)).
- **Gatilho**: a gravação falhou **e** a consulta de tabelas do dialeto confirma que elas faltam. Outras falhas (rede, credencial, timeout) não tentam migrar: seguem o fluxo normal e contam para `breaker.failures`. Só os resultados `REFUSED` (em `prd`) e `UNAVAILABLE` (sem Phinx, sem DDL, falha de lock) abrem o breaker imediatamente.
- **Mesmo instalador** do `monitor:install` (mesma tabela de controle).
- **Sem corrida**: um `flock` não bloqueante num arquivo `psf-monitor-<md5(database)>.lock` em `sys_get_temp_dir()`; com o lock em mãos, o instalador confere de novo se as tabelas ainda faltam. Quem não pega o lock por **contenção** (outro request migrando) descarta o lote daquele request, sem contar falha no breaker. Se o arquivo de lock não puder ser aberto ou o `flock` falhar por outro motivo (ex.: sistema de arquivos sem suporte), o resultado é tratado como indisponível: uma linha de `error_log` com a causa e o breaker abre. O lock é **por host**: em vários servidores, cada um tem o seu.
- Depois de migrar, o mesmo request tenta gravar o lote **uma** vez (uma nova falha conta para o breaker).
- Sem Phinx ou sem permissão de DDL: uma linha de `error_log` com a causa e o breaker abre. A aplicação não é afetada.
- A flag é lida por **truthiness**: use um booleano de verdade (`true`/`false`). A string `'false'` seria interpretada como ligada.

---

## Esquema das tabelas (contrato)

Este esquema é o **contrato** para quem consome os dados por SQL. Mudanças incompatíveis exigirão nova versão de esquema e migration. As migrations ficam em `src/monitor/migrations/` e usam só a abstração do Phinx.

Convenções: todos os timestamps em **UTC** (`DATETIME`, formato `Y-m-d H:i:s`); durações em milissegundos; tabelas com prefixo `psf_monitor_`. Os nomes de coluna evitam palavras reservadas (`exception_class`, `app_release`, `metric_key`...), mas **quote os identificadores** ao escrever SQL direto.

São seis tabelas (todas verificadas por `monitor:status`), mais a tabela de controle de migrations `psf_monitor_migrations` (usada só pelo instalador).

### `psf_monitor_requests`

Um registro por request gravado (ver [Amostragem](#amostragem-por-peso)). `psf_monitor_queries`, `psf_monitor_exceptions` e `psf_monitor_external` guardam as linhas filhas, ligadas por `request_id`.

| Coluna | Tipo | Observação |
|--------|------|------------|
| `id` | bigint, PK, auto (integer no SQLite) | |
| `request_id` | varchar(64) | Igual ao header `X-Request-Id`. |
| `ts` | datetime | UTC, início do request. |
| `method` | varchar(10) | Maiúsculo, só `[A-Z]` (vazio vira `GET`). |
| `route` | varchar(191) | **Padrão** da rota (`users/{id:int}`), nunca a URL real. Sem rota casada: `(unmatched)`. |
| `version` | int, default 0 | Versão da API. |
| `status` | int | Código HTTP da resposta (>= 500 após erro fatal). |
| `duration_ms` | decimal(10,2) | |
| `memory_kb` | int | Pico de memória (`memory_get_peak_usage(true)`). |
| `user_id` | varchar(64), null | |
| `env` | varchar(20) | `config['environment']` (ou `dev`). |
| `app_release` | varchar(64), null | `settings.monitor.release`. |
| `dropped_events` | int, default 0 | Eventos descartados: queries e chamadas externas acima de `max_events_per_request` (cada tipo com o seu teto) e exceções acima do teto de 20 por request. |
| `sample_weight` | decimal(12,4) | `1 / sample.normal` para amostrados; `1` para sempre-gravados. |

Índices: `idx_psf_monitor_requests_ts (ts)`, `idx_psf_monitor_requests_route_ts (route, ts)`, `idx_psf_monitor_requests_request_id (request_id)`.

### `psf_monitor_queries`

Uma linha por query SQL executada pelos shims do framework (ver [Queries](#queries)) nos requests gravados.

| Coluna | Tipo | Observação |
|--------|------|------------|
| `id` | bigint, PK, auto (integer no SQLite) | |
| `request_id` | varchar(64) | Liga ao request em `psf_monitor_requests`. |
| `ts` | datetime | UTC, momento do fim da execução (segundos). |
| `sql_hash` | varchar(40) | `sha1` do `sql_text` (40 hex); chave de agrupamento. |
| `sql_text` | text | SQL **normalizado** (sem literais), até 2000 caracteres. |
| `duration_ms` | decimal(10,2) | Duração em volta de `execute()` (em `Read`, inclui o `fetchAll()`). |
| `connection_name` | varchar(64) | Chave de `config['db']` usada pela query. |
| `sample_weight` | decimal(12,4) | O mesmo do request pai. |

Índices: `idx_psf_monitor_queries_request_id (request_id)`, `idx_psf_monitor_queries_hash_ts (sql_hash, ts)`, `idx_psf_monitor_queries_ts (ts)`.

### `psf_monitor_exceptions`

Uma linha por exceção registrada (ver [Exceções](#exceções)) nos requests gravados.

| Coluna | Tipo | Observação |
|--------|------|------------|
| `id` | bigint, PK, auto (integer no SQLite) | |
| `request_id` | varchar(64) | Liga ao request em `psf_monitor_requests`. |
| `ts` | datetime | UTC, **início do request** (não o instante da exceção). |
| `exception_class` | varchar(191) | Classe (`FatalError` para erros fatais do PHP). |
| `message` | varchar(500) | Cortada em 500 caracteres; vazia com `store_exception_message = false`. |
| `file` | varchar(255) | Cortado em 255 caracteres. |
| `line_no` | int | |
| `trace_hash` | varchar(40) | `sha1` de agrupamento (ver [Exceções](#exceções)). |
| `trace` | text | Stack trace sem argumentos, até 16 KB. Vazio em `FatalError`. |
| `sample_weight` | decimal(12,4) | O mesmo do request pai. |

Índices: `idx_psf_monitor_exceptions_ts (ts)`, `idx_psf_monitor_exceptions_hash_ts (trace_hash, ts)`, `idx_psf_monitor_exceptions_request_id (request_id)`.

### `psf_monitor_external`

Uma linha por chamada externa (ver [Chamadas externas](#chamadas-externas)) nos requests gravados. Migration `20261026000001_psf_monitor_create_external.php`.

| Coluna | Tipo | Observação |
|--------|------|------------|
| `id` | bigint, PK, auto (integer no SQLite) | |
| `request_id` | varchar(64) | Liga ao request em `psf_monitor_requests`. |
| `ts` | datetime | UTC, momento do **fim** da chamada (segundos). |
| `kind` | varchar(16) | `http`, `mail` ou `storage`. |
| `target` | varchar(191) | Rótulo **seguro**: só o host (`http`, `mail`) ou `provedor/operação` (`storage`); nunca URL, query, userinfo, destinatário, bucket nem chave de objeto. |
| `duration_ms` | decimal(10,2) | Duração da chamada (limitada ao intervalo da coluna). |
| `status` | int, **null** | Código HTTP; `null` = falha/erro de transporte (e-mail: `200` em sucesso, `null` em falha). Erro nos agregados: `status IS NULL OR status >= 500`. |
| `sample_weight` | decimal(12,4) | O mesmo do request pai. |

Índices: `idx_psf_monitor_external_request_id (request_id)`, `idx_psf_monitor_external_ts (ts)`, `idx_psf_monitor_external_kind_target_ts (kind, target, ts)`.

### `psf_monitor_rollups`

Agregados por hora, preenchidos pelo `Rollup` (ver [Agregados](#agregados-histograma-e-percentis)). Chave primária composta `(bucket_hour, metric, metric_key)`; sem outros índices.

| Coluna | Tipo | Observação |
|--------|------|------------|
| `bucket_hour` | datetime | PK (1/3), UTC, início da hora. |
| `metric` | varchar(16) | PK (2/3): `request`, `query`, `exception`, `external`. |
| `metric_key` | varchar(191) | PK (3/3): `METHOD route` (request), `sql_hash` (query), `exception_class` (exception), `kind:target` (external, ex.: `http:api.exemplo.com`). Truncada em 191 caracteres. |
| `hits` | decimal(14,4) | |
| `errors` | decimal(14,4) | |
| `sum_ms` | decimal(18,2) | |
| `max_ms` | decimal(10,2) | |
| `histogram` | text | JSON com `Σ sample_weight` por bucket (10 posições). |
| `sample_text` | text, null | Texto de exemplo (SQL normalizado ou mensagem). |
| `last_ts` | datetime, null | Última ocorrência na hora. |

### `psf_monitor_state`

Chave/valor interno. Guarda o watermark do rollup (`name = 'rollup_watermark'`, `value` = `Y-m-d H:i:s` em UTC: o fim da última hora agregada).

| Coluna | Tipo |
|--------|------|
| `name` | varchar(64), PK |
| `value` | varchar(255) |

### Consulta direta

Exemplo sobre os brutos (estimativa da contagem real por rota, últimas 24 h; sintaxe MySQL, ajuste o quoting e as funções de data para o seu banco):

```sql
SELECT `route`, SUM(`sample_weight`) AS hits,
       SUM(CASE WHEN `status` >= 500 THEN `sample_weight` ELSE 0 END) AS errors,
       SUM(`sample_weight` * `duration_ms`) / SUM(`sample_weight`) AS avg_ms
FROM `psf_monitor_requests`
WHERE `ts` >= UTC_TIMESTAMP() - INTERVAL 1 DAY
GROUP BY `route`
ORDER BY hits DESC;
```

---

## Privacidade

**Nunca** são gravados: body, headers, cookies, token, query string, URL real (só o padrão da rota; nas chamadas externas, só o host), argumentos de funções do stack trace e literais de SQL. Das chamadas externas só se grava o rótulo seguro (`kind` e `target`: host ou `provedor/operação`), a duração e o status ([regra do `target`](#chamadas-externas)). O que é gravado no request: método, padrão da rota, versão, status, duração, pico de memória, `env`, `release`, `request_id`, contagem de descartes, peso e, se `store_user` estiver ligado, o `user_id`.

- **`user_id`** vem de `Router::$auth` (objeto: propriedade `user_id_property`; array: chave de mesmo nome). Só valores escalares (int, string, float) são aceitos; qualquer outra forma resulta em `null`. Desligue com `'store_user' => false`.
- **Trace sem argumentos:** montado de `getTrace()` só com classe, função, arquivo e linha de cada frame.
- **Mensagens da aplicação** (exceções que não são `PDOException`) são gravadas como vieram (até 500 caracteres): evite colocar dados sensíveis nelas, ou desligue com `'store_exception_message' => false` (nenhuma mensagem é gravada, nem nas linhas `Caused by:`).

### SQL normalizado

O SQL gravado nunca contém literais, porque o framework pode interpolar valores no texto (p.ex. `Read::exe(free: true)` e o QueryBuilder). O `SqlNormalizer` é um tokenizador de passada única:

- strings, números, hexadecimais e dollar quotes viram `?`; comentários (`--`, `#`, `/* */`) são removidos; espaços são colapsados;
- `IN (1, 2, 3)` e `IN (:a, :b)` viram `IN (?)`; tuplas repetidas de `VALUES (...), (...)` viram `VALUES (?)`;
- placeholders nomeados (`:id`) e `?` são mantidos; identificadores entre aspas duplas, crases ou colchetes são mantidos, exceto `"..."` em posição de valor (depois de comparação, `LIKE`/`ILIKE`, `THEN`/`ELSE` ou dentro de lista `IN`), que vira `?`;
- o resultado é cortado em 2000 caracteres (sem partir UTF-8) e `sql_hash = sha1(sql_text)`.

| Original | Gravado |
|----------|---------|
| `SELECT * FROM users WHERE id = 42 AND name = 'Ana' /* c */ AND age > 3.5` | `SELECT * FROM users WHERE id = ? AND name = ? AND age > ?` |
| `SELECT a FROM t WHERE id IN (1,2,3) AND x IN (:a,:b)` | `SELECT a FROM t WHERE id IN (?) AND x IN (?)` |
| `INSERT INTO t (a,b) VALUES (:r0_c0,:r0_c1), (:r1_c0,:r1_c1)` | `INSERT INTO t (a,b) VALUES (?)` |
| `UPDATE t SET a = 'x', b = 2 WHERE id = :id` | `UPDATE t SET a = ?, b = ? WHERE id = :id` |

Na dúvida o tokenizador redige demais. Limitações conhecidas estão em [Limites](#limites-e-cuidados-conhecidos).

### Limpeza de mensagens

Os drivers embutem dados das linhas nas mensagens de erro (`Duplicate entry 'jane@x.com'`, `Key (email)=(...)`, `The duplicate key value is (...)`, `Failing row contains (...)`). Por isso, a mensagem de toda `PDOException` (a principal **e** as da cadeia `getPrevious()`, inclusive nas linhas `Caused by:`) e a de erros fatais passam por `ExceptionDescriber::scrubMessage()`, que troca os valores por `?` antes do corte em 500 caracteres (a entrada da limpeza é limitada aos primeiros ~4000 bytes):

- **Listas entre parênteses** após `Key (col)=(`, `duplicate key value is (` e `Failing row contains (`: tudo do primeiro desses marcadores até o **último `)` da mensagem**, inclusive quebras de linha; se não houver `)` depois dele (ex.: linha com mais de ~4 KB, cortada pelo teto da entrada), até o **fim da mensagem**.
- **Formatos do MySQL ancorados**: em `Duplicate entry '...' for key ...` e `Incorrect <tipo> value: '...' for column ...`, tudo da aspa após `entry `/`value: ` até a **última** ocorrência de `' for key `/`' for column ` (em `Truncated incorrect <tipo> value: '...'`, sem âncora, até a última aspa). Assim, apóstrofos no valor (`O'Brien`, `O\'Brien`) não deixam fragmentos.
- **Conversão do SQL Server** (`Conversion failed when converting the varchar value '...' to data type int`): da aspa após `value ` até a **última** ocorrência de `' to data type ` (sem essa âncora, vale a regra genérica abaixo).
- **`: "..."` do PostgreSQL** (`invalid input syntax for type integer: "..."`, que não escapa `"` internas): até a **última `"` da mesma linha**.
- **Demais `'...'` e `"..."`** (ex.: nomes de coluna/chave em `Data truncated for column '...'`, `Data too long for column '...' at row`): aspa dobrada (`''`) e escapada (`\'`) não fecham a string; aspa sem fechamento consome o resto.
- **Números** depois de `value`, `=` ou `:`.

Exemplo: `SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'O'Brien' for key 'users.name'` vira `SQLSTATE[23000]: Integrity constraint violation: ? Duplicate entry '?' for key '?'`. A limpeza erra para o lado de redigir demais (ex.: `doesn't` apaga o resto da mensagem; um `)` posterior estende a redação da lista). Ficam **por desenho**: o código de erro do driver após `:` também vira `?`, e valores **sem aspas nem parênteses** em `PDOException` montadas pela própria aplicação (ex.: `new PDOException("usuário jane@x.com")`) não são reconhecidos.

---

## Agregados, histograma e percentis

O `Psf\Monitor\Rollup` transforma as linhas brutas em registros de `psf_monitor_rollups`, um por `(bucket_hour, metric, metric_key)`. É executado pelos comandos [`monitor:rollup` e `monitor:maintain`](#comandos-cli). Esta semântica é parte do contrato.

### Semântica por métrica

Como requests normais são gravados por amostragem, os agregados são **ponderados** por `sample_weight` e por isso refletem 100% do tráfego (estimativa não enviesada).

| Metric | `metric_key` | `hits` | `errors` | `sum_ms` / `max_ms` / `histogram` | `sample_text` |
|--------|--------------|--------|----------|-----------------------------------|---------------|
| `request` | `METHOD route` (ex.: `GET users/{id:int}`) | `Σ sample_weight` | `Σ sample_weight` das linhas com `status >= 500` (4xx **não** é erro) | de `duration_ms` | `null` |
| `query` | `sql_hash` | `Σ sample_weight` | sempre `0` | de `duration_ms` | `sql_text` da linha de menor `id` do hash na hora |
| `exception` | `exception_class` | `Σ sample_weight` | igual a `hits` (toda exceção é erro) | `0`, `0` e histograma zerado | `message` da linha de menor `id` da classe na hora |
| `external` | `kind:target` (ex.: `http:api.exemplo.com`, `mail:smtp.exemplo.com`, `storage:r2/putobject`) | `Σ sample_weight` | `Σ sample_weight` das linhas com `status IS NULL OR status >= 500` (4xx **não** é erro) | de `duration_ms` | `null` |

- `sum_ms = Σ sample_weight × duration_ms` (média = `sum_ms / hits`); `max_ms` é o máximo de `duration_ms` entre as linhas **gravadas** (como lentos e erros são sempre gravados, ele é fiel na prática); `last_ts` é o maior `ts` do grupo na hora.
- `histogram`: JSON com 10 posições, `Σ sample_weight` por bucket (ver [Histograma e percentis](#histograma-e-percentis)).
- Linhas de `queries`, `exceptions` e `external` herdam o `sample_weight` do request (que é 1 para qualquer request com erro, exceção ou lentidão).
- Queries, chamadas externas e exceções acima do teto por request são descartadas e **não entram** nos agregados: `hits` de `query`, `external` e `exception` é um piso.
- `metric_key` é truncada em 191 caracteres. Se duas chaves passam a ser iguais depois do corte, **ignorando maiúsculas/minúsculas e espaços à direita** (como collations case-insensitive/PAD SPACE), os registros são **fundidos** (soma de `hits`, `errors`, `sum_ms` e dos histogramas; máximo de `max_ms` e `last_ts`; `sample_text` do primeiro não nulo), em vez de violar a chave primária. A chave gravada é a **menor grafia em ordem binária**, a mesma em qualquer driver (a ordem das linhas do `GROUP BY` varia entre bancos; ex.: `GET /Users` e `GET /users ` viram um registro `GET /Users`).

### Quando e como cada hora é agregada

- **Só horas completas, com folga de 300 s** (`Rollup::GRACE_SECONDS`). A hora `[H, H+1h)` só é agregada quando `agora >= H + 1h + 300 s`, para dar tempo de requests em andamento gravarem. Por isso o dado agregado **atrasa até ~1 h 5 min** em relação ao tempo real.
- **Início.** Sem watermark, a primeira execução começa na hora da linha bruta mais antiga que ainda exista (nas quatro tabelas brutas). Sem nenhuma linha bruta, nada é feito. Se os brutos já foram limpos no meio daquela hora (prune antes do primeiro rollup), **a primeira hora agregada do backlog pode sair parcial**.
- **Recálculo idempotente por hora.** Cada hora é recalculada inteira a partir das linhas brutas: `DELETE` dos registros daquela `bucket_hour`, `INSERT` dos novos e avanço do watermark (`psf_monitor_state`) acontecem **na mesma transação**. Uma falha desfaz só aquela hora; as anteriores ficam gravadas e a próxima execução recomeça da hora que falhou. Rodar o comando duas vezes não duplica nada.
- **No máximo 48 horas novas por execução** (`--max-hours`, inteiro 1..10000; horas puladas também contam); um backlog maior é consumido pelas execuções seguintes.
- **Reagregação das últimas 2 horas (linhas atrasadas).** Um request que começa às 10:58 e é gravado às 11:06 tem `ts` na hora 10, que já foi agregada às 11:05. Por isso, quando há watermark, cada execução **recalcula antes as 2 horas anteriores ao watermark** (`Rollup::REAGGREGATE_HOURS`), do mesmo jeito idempotente. Linhas atrasadas com `ts` até 2 h antes do watermark convergem na execução seguinte; **mais atrasadas que isso se perdem**. Essas horas não contam em `--max-hours` nem em `Horas agregadas`/`Registros gravados` (o resultado de `Rollup::run()` traz `reaggregated` com quantas foram recalculadas) e o watermark nunca volta. Uma hora da janela sem nenhuma linha bruta é pulada (não apaga o agregado de brutos já limpos), e horas com mais de 1 dia nunca são recalculadas (brutos com menos de 1 dia nunca são apagados, pois `raw_days >= 1`).
- **Violação de integridade pula a hora.** Se uma hora nova falha com SQLSTATE de classe `23` (`23000`, `23505`...: por exemplo, chave duplicada que a fusão não previu), ela é desfeita e **pulada** em vez de travar o rollup: o watermark avança numa transação própria, a hora conta em `skipped` (e em `--max-hours`) e as seguintes continuam. Trade-off: **os agregados dessa hora se perdem**; as linhas brutas dela ficam até o prune. Os comandos imprimem `Horas puladas: N` e um aviso (só quando N > 0; exit code 0). Na janela de reagregação, a mesma falha mantém o agregado anterior. Qualquer outra falha (conexão, deadlock, sintaxe, ou falha ao gravar o watermark) desfaz a hora e propaga.
- **Erro ao ler o watermark** (que não seja a tabela `psf_monitor_state` ausente) **propaga**: o rollup e o prune param em vez de adivinhar o ponto de partida (recomeçar da hora bruta mais antiga substituiria agregados completos por parciais).

`Rollup::run($now, $maxHours = 48)` devolve `['hours', 'rows', 'watermark', 'reaggregated', 'skipped']`.

### Histograma e percentis

Os histogramas (`Psf\Monitor\Histogram`) usam 10 buckets fixos, com as bordas (ms):

```
[10, 25, 50, 100, 250, 500, 1000, 2500, 5000]
```

O bucket `i` cobre `[borda[i-1], borda[i])`: o primeiro começa em 0 (`[0, 10)`) e o último é **aberto**, `[5000, ∞)`. Uma duração exatamente igual a uma borda cai no bucket de cima.

Os percentis (`Histogram::percentile`) são **estimativas**: o ponto do percentil é interpolado linearmente dentro do bucket em que ele cai, assumindo distribuição uniforme ali. Quanto mais largo o bucket (e mais amostrado o tráfego), maior o erro; não use p95/p99 como valor exato.

- No bucket aberto não há limite superior: usa-se o `max_ms` do período como teto da interpolação. Se o `max_ms` for menor que 5000 (dado inconsistente), ele é ignorado e o resultado é `5000.0`. Sem `max_ms`, também `5000.0`.
- Nos demais buckets o resultado é limitado por `max_ms` quando informado.
- Histograma vazio ou corrompido (JSON inválido, tamanho diferente de 10, valores negativos ou não numéricos) é tratado como vazio; nada lança. Num intervalo, uma hora corrompida não contribui, e o percentil só é `0.0` se todas as horas do intervalo estiverem vazias ou corrompidas.
- Os histogramas de várias horas são somados posição a posição antes de calcular o percentil (é o que o `Reader` faz para um intervalo).

---

## Reader

`Psf\Monitor\Reader` é a API de leitura (somente leitura) dos dados do monitor. Devolve arrays simples, com números convertidos para `float` (**sem arredondar**: arredonde na apresentação). Agregados vêm de `psf_monitor_rollups`; só `request()` lê as tabelas brutas.

```php
use Psf\Monitor\Reader;

$reader = new Reader();                    // banco = settings.monitor.database (ou 'default')
$reader = new Reader('monitor');           // chave de config['db'] explícita
$reader = new Reader(pdo: $pdo);           // PDO já aberto (testes)
```

Construtor: `__construct(?string $database = null, ?\PDO $pdo = null)`. Sem `$pdo`, usa a conexão dedicada do monitor (aberta só no primeiro uso). O dialeto vem do `config['db']` do banco; sem essa entrada (PDO avulso), é deduzido do driver do PDO. Falhas de banco propagam como `\PDOException`.

**Intervalo.** `$from` e `$to` são `DateTimeInterface`, convertidos para UTC. Um registro entra quando `bucket_hour >= floor_hora(from)` e `bucket_hour < to` (semiaberto: a hora de `from` é arredondada para baixo; `to` é exclusivo; frações de segundo de `to` arredondam para cima). `from >= to` devolve `[]`. Como as horas são inteiras, `from = 10:30` inclui a hora 10:00.

**`limit`** é limitado a `[1, 1000]` (default 20) em `routes`, `external`, `slowQueries` e `exceptions`. Empates são desempatados pela chave, em ordem crescente.

| Método | Retorno |
|--------|---------|
| `routes(DateTimeInterface $from, DateTimeInterface $to, int $limit = 20, string $orderBy = 'sum_ms'): array` | Lista de `['key', 'method', 'route', 'hits', 'errors', 'error_rate', 'avg_ms', 'max_ms', 'p50_ms', 'p95_ms', 'p99_ms']`, ordenada de forma decrescente por `$orderBy`. |
| `external(DateTimeInterface $from, DateTimeInterface $to, int $limit = 20, string $orderBy = 'sum_ms'): array` | Chamadas externas por `kind:target`, com as mesmas regras de `routes()`: lista de `['key', 'kind', 'target', 'hits', 'errors', 'error_rate', 'avg_ms', 'max_ms', 'p50_ms', 'p95_ms', 'p99_ms']`, ordenada de forma decrescente por `$orderBy`. |
| `slowQueries(DateTimeInterface $from, DateTimeInterface $to, int $limit = 20): array` | Lista de `['sql_hash', 'sql', 'hits', 'avg_ms', 'max_ms', 'p95_ms']`, por tempo total (`sum_ms`) decrescente. `sql` é o `sample_text` da hora mais recente do hash (`null` se ausente). |
| `exceptions(DateTimeInterface $from, DateTimeInterface $to, int $limit = 20): array` | Lista de `['exception_class', 'hits', 'last_ts', 'message']`, por `hits` decrescente. `last_ts` é a maior ocorrência (`Y-m-d H:i:s`, UTC; `null` se ausente); `message` é o `sample_text` da hora mais recente da classe. |
| `throughput(DateTimeInterface $from, DateTimeInterface $to): array` | Série por hora, em ordem crescente, da metric `request`: `['hour' (Y-m-d H:i:s, UTC), 'hits', 'errors', 'avg_ms']`. |
| `request(string $requestId): ?array` | `['request' => [...], 'queries' => [...], 'exceptions' => [...], 'external' => [...]]` ou `null`. |

Detalhes:

- `orderBy` de `routes()` e de `external()` aceita **somente** `sum_ms`, `hits`, `errors`, `max_ms`, `avg_ms`; qualquer outro valor (inclusive entrada do usuário) vira `sum_ms`. O valor nunca entra no SQL.
- `routes()`: `key` é o `metric_key` (`GET users/{id:int}`); `method` e `route` são a chave separada no primeiro espaço. `error_rate = errors / hits` e `avg_ms = sum_ms / hits` (0.0 se `hits = 0`); `max_ms` é o maior do período; p50/p95/p99 vêm do histograma somado do período (ver [Histograma e percentis](#histograma-e-percentis)).
- `external()`: `key` é o `metric_key` (`http:api.exemplo.com`); `kind` e `target` são a chave separada no **primeiro** `:` (o `target` pode conter `:`, como um host IPv6). `error_rate`, `avg_ms`, `max_ms` e os percentis seguem a regra de `routes()`; `errors` conta `status IS NULL OR status >= 500` (4xx não é erro).
- `throughput()` **omite as horas sem nenhum registro** de rollup (sem tráfego gravado ou ainda não agregadas): quem desenha um gráfico precisa preencher as lacunas.
- `request($id)` lê as tabelas **brutas**: só existe dentro da retenção bruta (`raw_days`; ver o [teto](#retenção-e-manutenção) do prune) e para requests que foram gravados (amostrados); devolve `null` fora disso e para id vazio. A chave `external` (chamadas externas do request, ordenadas por `id`) é **adicional**: as demais não mudaram. As linhas de `request`, `queries`, `exceptions` e `external` vêm **como o driver as devolve** (`SELECT *`, em ordem de `id`): os tipos (int/string/float) variam conforme o driver.
- Os agregados só existem para horas já agregadas (atraso de até ~1 h 5 min): a hora corrente nunca aparece.

Exemplo, num endpoint do próprio projeto (a autenticação e a autorização são do projeto: o framework não expõe estes dados por conta própria):

```php
use Psf\Http\{Http, Router};
use Psf\Monitor\Reader;

#[Router(method: 'GET', path: '/admin/monitor/rotas', middlewares: ['authentication'])]
public function rotas(): void
{
    // ...verifique aqui se o usuário pode ver métricas (auth do projeto)...
    $reader = new Reader();
    $to     = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $from   = $to->modify('-24 hours');

    $rotas = array_map(fn (array $r): array => [
        'rota'  => $r['key'],
        'hits'  => round($r['hits']),
        'erros' => round($r['error_rate'] * 100, 2),   // arredonde na apresentação
        'p95'   => round($r['p95_ms'], 1),
    ], $reader->routes($from, $to, 10, 'sum_ms'));

    Http::response('OK', [
        'rotas'      => $rotas,
        'throughput' => $reader->throughput($from, $to),
        'excecoes'   => $reader->exceptions($from, $to, 5),
    ], 200);
}
```

Para o detalhe de um request reclamado por um usuário, peça o `X-Request-Id` da resposta e use `$reader->request($id)`. Para não escrever esse endpoint à mão, veja o [`MonitorApi`](#monitorapi-serviço-json-opcional).

---

## `MonitorApi` (serviço JSON opcional)

`Psf\Monitor\Api\MonitorApi` é uma classe de serviço que valida parâmetros de uma query string e chama o [`Reader`](#reader), devolvendo o formato dos controllers do framework. É **opcional** e **não registra nenhuma rota**: o projeto escreve o próprio método de controller (com `middlewares: ['authentication']`, e a autorização que quiser) e delega a ela, então a autenticação e a autorização continuam sendo do projeto. É **desligada por padrão**: com `settings.monitor.api.enabled` diferente de `true` ela responde `404` **sem consultar o banco**.

```php
'settings' => ['monitor' => ['enabled' => true, 'api' => ['enabled' => true]]],
```

`new MonitorApi(?Reader $reader = null)` (sem `$reader`, usa `new Reader()`) e `handle(string $endpoint, array $params): array`, que **nunca lança** e devolve `[mensagem, dados, status HTTP]` (o mesmo formato `array{0: string, 1: array, 2: int}` dos controllers, pronto para `Http::response($mensagem, $dados, $status)`).

### Endpoints

| `endpoint` | Devolve (em `dados`) | Parâmetros |
|------------|----------------------|------------|
| `routes` | `Reader::routes()` | `from`, `to`, `limit`, `order_by` |
| `queries` | `Reader::slowQueries()` | `from`, `to`, `limit` |
| `exceptions` | `Reader::exceptions()` | `from`, `to`, `limit` |
| `external` | `Reader::external()` | `from`, `to`, `limit`, `order_by` |
| `throughput` | `Reader::throughput()` | `from`, `to` |
| `request` | `Reader::request()` (`request`, `queries`, `exceptions`, `external`) | `id` (obrigatório) |

Outro `endpoint` responde `404` (`Endpoint desconhecido`).

### Parâmetros

- **`from` / `to`**: ISO-8601 com `T` ou espaço (`2026-10-25T10:00:00Z`, `2026-10-25T07:00:00-03:00`, `2026-10-25 10:00:00`), com fração de segundo opcional e fuso opcional (`Z`, `±HH:MM` ou `±HHMM`); **sem fuso vale UTC**. A data é convertida para UTC e o intervalo segue as regras do `Reader` (semiaberto, por hora). O formato é estrito (nada de texto livre nem data sem hora; datas impossíveis como `2026-13-45` são recusadas). Padrão: `to` = agora e `from` = `to − 24 h`. Inválidos ou `from >= to` → `422`.
- **`limit`**: inteiro de 1 a 1000 (ou string só de dígitos); padrão 20. Inválido → `422`. Não vale para `throughput` e `request`.
- **`order_by`**: só em `routes` e `external`; um de `sum_ms` (padrão), `hits`, `errors`, `max_ms`, `avg_ms`. Outro valor → `422`. Nos demais endpoints é ignorado.
- **`id`**: só em `request`; obrigatório, string que casa com `^[A-Za-z0-9-]{8,64}$` (o `X-Request-Id`). Fora do formato → `422`; id válido mas sem registro (não amostrado ou fora da retenção bruta) → `404`.

### Respostas

| Status | Quando | Mensagem |
|--------|--------|----------|
| `200` | Sucesso | `''` (vazia) |
| `404` | API desligada (`api.enabled`), endpoint desconhecido ou `request` inexistente | `API do monitor desabilitada`, `Endpoint desconhecido` ou `Requisição não encontrada` |
| `422` | Parâmetro inválido (`from`, `to`, intervalo, `limit`, `order_by`, `id`) | `Parâmetro <nome> inválido` ou `Intervalo inválido: from deve ser anterior a to` |
| `500` | Falha ao consultar (por exemplo, banco do monitor fora do ar) | `Erro ao consultar o monitor` |

**Privacidade das respostas.** Nenhum texto de erro de banco ou de exceção vaza: qualquer falha vira `500` com a mensagem fixa acima (a mensagem original pode conter DSN, host ou usuário). Os dados devolvidos são os mesmos do `Reader` (rotas, SQL normalizado, mensagens de exceção já gravadas, rótulos seguros de chamadas externas), por isso a autenticação e a autorização do endpoint são essenciais: o framework não expõe estes dados por conta própria. Os parâmetros nunca entram no SQL (`order_by` só escolhe numa lista fixa).

### Exemplo de controller do projeto

```php
<?php

namespace App\Controllers;

use Psf\Http\{Http, Router};
use Psf\Model\ControllerBase;
use Psf\Monitor\Api\MonitorApi;

class MonitorController extends ControllerBase
{
    // GET /v1/admin/monitor/routes?limit=10&order_by=errors
    // GET /v1/admin/monitor/external?from=2026-10-25T00:00:00Z
    // GET /v1/admin/monitor/request?id=<X-Request-Id>
    #[Router(method: 'GET', path: '/admin/monitor/{endpoint:string}', version: 1, middlewares: ['authentication'])]
    public function consultar(string $endpoint): void
    {
        // A autenticação foi feita pelo middleware; a AUTORIZAÇÃO é do projeto.
        if (!$this->podeVerMetricas()) {
            Http::response('Sem permissão', [], 403);
        }

        // $this->data traz a query string (GET) já parseada pelo ControllerBase.
        [$mensagem, $dados, $status] = (new MonitorApi())->handle($endpoint, $this->data ?? []);

        Http::response($mensagem, $dados, $status);
    }

    private function podeVerMetricas(): bool
    {
        $usuario = Router::$auth;   // o que o seu verifyauth devolveu (objeto ou array)
        $admin   = is_object($usuario) ? ($usuario->admin ?? false) : ($usuario['admin'] ?? false);

        return $admin === true;
    }
}
```

`Http::response()` omite `message` e `data` quando vazios e **encerra o processo** (`exit`). O `Router` **não escreve** o valor de retorno do controller (o `handle()` dele só o devolve a quem chamou, o `public/index.php`): um `return $api->handle(...)` não gera resposta HTTP. Use `Http::response($mensagem, $dados, $status)`, como no exemplo.

---

## Comandos CLI

Os comandos `monitor:*` são registrados pelo wrapper `bin/phinx` do framework, ao lado dos comandos de migration (`make:migration-from-model`, `make:model-from-table`, `analyze:table-vs-model`; ver [database.md](database.md)). Em todos:

- `-e`/`--environment` é a **chave de `config['db']`** (padrão: `settings.monitor.database`, ou `'default'`), não um ambiente do Phinx. Chave inexistente termina com erro.
- Qualquer falha imprime uma linha `<error>...</error>` e termina com **exit code 1**.

| Comando | O que faz | Saída |
|---------|-----------|-------|
| `monitor:install [-e banco]` | Roda as migrations pendentes do monitor | `Tabelas do monitor instaladas.` |
| `monitor:status [-e banco]` | Diagnóstico | `Banco: <chave>`, `Instalado: sim\|não`, `Monitor ligado: sim\|não`, `Rollup até: <data UTC>\|nunca` |
| `monitor:rollup [-e banco] [--max-hours N]` | Só o rollup | `Horas agregadas: N`, `Registros gravados: N`, `Rollup até: <data UTC>\|nunca`, [`Horas puladas: N` + aviso] |
| `monitor:prune [-e banco]` | Só a limpeza (brutos e agregados) | `Linhas apagadas: N`, `Agregados apagados: N` |
| `monitor:maintain [-e banco] [--max-hours N]` | Rollup e depois a limpeza (brutos e agregados) | as linhas do `monitor:rollup` seguidas das do `monitor:prune` |

### `monitor:install`

```bash
vendor/bin/phinx monitor:install              # banco = settings.monitor.database (ou default)
vendor/bin/phinx monitor:install -e meu_banco # chave de config['db'] explícita
```

- Roda o Manager do Phinx **apenas** sobre `src/monitor/migrations/`, com a tabela de controle própria `psf_monitor_migrations` (a `phinxlog` do projeto não é tocada). Essas migrations **não** entram no `paths.migrations` do Phinx: o `vendor/bin/phinx migrate` normal do projeto nunca cria (nem tenta criar) as tabelas do monitor, inclusive em projetos que não usam o monitor.
- É idempotente (roda só as migrations pendentes): rode-o sempre que [atualizar o framework](#atualizando-o-framework).
- **Requer `robmorgan/phinx` instalado no projeto** (é dependência de desenvolvimento do framework) e uma entrada de `config['db']` com `driver` e `database`.

### `monitor:status`

Imprime o banco, se as seis tabelas existem (`Instalado`), se o monitor está ligado no config e até onde o rollup já chegou (`Rollup até`, o watermark). Uma falha ao ler o watermark aparece como `nunca`. Um `Rollup até` muito atrás de "agora" (mais que ~1 h 5 min) indica que o cron não está rodando ou está falhando.

### `monitor:rollup`, `monitor:prune` e `monitor:maintain`

- `--max-hours` (default 48) é um inteiro de 1 a 10000 (inteiro ou string só de dígitos).
- **Validação antes de qualquer escrita:** o `monitor:rollup` valida `--max-hours`; o `monitor:prune` valida `retention.raw_days` e `retention.aggregate_days` (inteiros 1..36500, ou string só de dígitos); o `monitor:maintain` valida os três. Valor inválido encerra com erro (exit code 1) e **nada é agregado nem apagado** (nem o rollup do `monitor:maintain`). Valores como `0`, negativos, `7.5`, `'7.5'`, `'abc'`, `null` e acima de 36500 são rejeitados.
- `Horas agregadas` e `Registros gravados` contam só horas novas (as 2 horas reagregadas ficam de fora). `Horas puladas: N` e o aviso aparecem só quando alguma hora foi pulada por violação de integridade (ver [Agregados](#quando-e-como-cada-hora-é-agregada)).
- **Falha do rollup no `monitor:maintain` não impede a limpeza:** a falha é impressa (`<error>`), a limpeza de brutos e de agregados roda mesmo assim (o teto de 4 × `raw_days` continua valendo com o rollup quebrado) e o comando termina com **exit code 1**. Se a limpeza também falhar, as duas falhas são impressas.
- Rodar `monitor:rollup` ou `monitor:prune` isoladamente é seguro; o `monitor:maintain` é só a composição dos dois, na ordem rollup, brutos, agregados.

---

## Retenção e manutenção

Não há agregação nem limpeza durante os requests: agende o `monitor:maintain` (sugestão: de hora em hora, alguns minutos depois da hora cheia):

```cron
17 * * * * cd /caminho/do/projeto && php vendor/bin/phinx monitor:maintain >> /var/log/psf-monitor.log 2>&1
```

Se preferir separar as etapas, agende `monitor:rollup` e `monitor:prune` isoladamente.

### Ordem e limites da limpeza

1. **Brutos** (`psf_monitor_requests`, `psf_monitor_queries`, `psf_monitor_exceptions`, `psf_monitor_external`): apaga as linhas com `ts < corte`, onde `corte = max(min(agora − raw_days, watermark), agora − 4 × raw_days)`. Ou seja, normalmente não se apaga o que ainda não foi agregado (nunca além do watermark), **mas há um teto** (`Pruner::HARD_CEILING_FACTOR = 4`). Enquanto nenhum rollup rodou (sem watermark), apaga só por `raw_days`. A remoção é em lotes de 5000 linhas, com pausa curta entre lotes, de forma portável entre os 4 drivers.
2. **Agregados** (`psf_monitor_rollups`): apaga `bucket_hour < agora − aggregate_days`.

> **O teto de 4 × `raw_days` e a perda de horas.** Se o rollup ficar parado por muito tempo (cron quebrado, banco fora do ar), as linhas brutas mais velhas que `4 × raw_days` são apagadas **mesmo sem terem sido agregadas**, e essas horas ficam perdidas: ao voltar, o rollup recomeça do watermark, mas já não há linhas brutas para elas (os agregados dessas horas saem zerados/ausentes). Sem o teto, um rollup parado faria as tabelas brutas crescerem sem limite. Com o default (`raw_days = 7`) o rollup pode ficar parado por até 28 dias sem perda; monitore o `Rollup até` do `monitor:status`.

---

## Atualizando o framework

Rode `monitor:install` **antes ou junto de cada atualização do framework** (é idempotente: só as migrations novas rodam):

```bash
vendor/bin/phinx monitor:install
```

- **Projeto que instalou o monitor na fase 1** (só `psf_monitor_requests`, `psf_monitor_state` e `psf_monitor_rollups`): o `monitor:install` cria `psf_monitor_queries` e `psf_monitor_exceptions`. Enquanto elas não existirem, o lote do request (request + queries + exceções) é gravado numa única transação que **falha e é desfeita por inteiro**: nada é gravado, nem a linha do request, e, após `breaker.failures` falhas seguidas, o breaker abre e registra `[PSF][monitor] gravação suspensa ...`, que normalmente (quando a mensagem do banco indica tabela ausente) termina com `Tabelas ausentes: rode monitor:install.`. A aplicação em si não é afetada. Com `auto_migrate` ligado fora de `prd`, as tabelas novas são criadas sozinhas no primeiro flush.
- **Projeto da fase 2**: a fase 3 não acrescenta tabelas nem migrations; basta agendar o `monitor:maintain` (ver [Retenção e manutenção](#retenção-e-manutenção)). Até lá, `psf_monitor_rollups` fica vazia e o `Reader` devolve listas vazias.
- **Colunas `NOT NULL` (correção desta versão).** O Phinx 0.16 cria colunas **anuláveis** por padrão; as migrations do monitor passaram a declarar `'null' => false` em tudo o que o [contrato](#esquema-das-tabelas-contrato) não marca como `null`. No **MySQL** a instalação antes **falhava** (chave primária com colunas anuláveis) e agora funciona. Instalações já feitas em SQLite ou PostgreSQL **mantêm** as colunas anuláveis (migrations já aplicadas não são reexecutadas); isso é inofensivo, pois o monitor sempre preenche essas colunas. Para alinhar ao contrato, recrie as tabelas do monitor (apague-as e a `psf_monitor_migrations`, e rode `monitor:install`; os dados brutos e agregados se perdem).
- **Projeto da fase 3 (instalação com cinco tabelas)**: a fase 4 acrescenta `psf_monitor_external` (migration `20261026000001_psf_monitor_create_external.php`). **Rode `vendor/bin/phinx monitor:install`** (idempotente: só a migration nova roda). Enquanto a tabela não existir: (1) as gravações **falham de forma segura**: o lote de todo request gravado **que teve chamadas externas** é desfeito por inteiro (nada é gravado, nem a linha do request; requests sem chamadas externas continuam sendo gravados normalmente), o breaker abre depois de `breaker.failures` falhas e registra **uma** linha de `error_log` (normalmente terminando com `Tabelas ausentes: rode monitor:install.`); a aplicação não é afetada; (2) `monitor:rollup`, `monitor:prune` e `monitor:maintain` falham com `no such table` (ou o equivalente do driver), porque leem/limpam as quatro tabelas brutas; (3) `monitor:status` mostra `Instalado: não`. Com `auto_migrate` ligado fora de `prd`, a tabela nova é criada sozinha no primeiro flush (só a migration pendente roda).
- **Chamadas externas e API não exigem configuração nova**: as chamadas passam a ser registradas assim que a tabela existe. As chaves `slow_ms.external` e `api.enabled` têm default; a `MonitorApi` continua desligada até você ligar `api.enabled`.

---

## Validação em bancos reais

Além da suíte padrão (SQLite), o monitor tem uma suíte que roda o mesmo roteiro contra **MySQL e PostgreSQL reais**, em containers descartáveis: [`tests/Drivers/`](../tests/Drivers) com o [`docker-compose.drivers.yml`](../docker-compose.drivers.yml) da raiz do repositório.

**Versões validadas** (informadas por `SELECT VERSION()`):

| Banco | Imagem | Versão | Resultado |
|-------|--------|--------|-----------|
| MySQL | `mysql:8.4` | 8.4.13 (`sql_mode` padrão: `ONLY_FULL_GROUP_BY`, `STRICT_TRANS_TABLES`...) | validado |
| PostgreSQL | `postgres:16` | 16.15 | validado |
| SQL Server | — | — | **pendente** |

**SQL Server continua PENDENTE**: o driver ODBC da Microsoft (`msodbcsql18`), exigido pelo `pdo_sqlsrv`, não está instalado na máquina onde a validação foi feita, então nada do monitor foi executado num SQL Server real. O `docker-compose.drivers.yml` traz um serviço `sqlserver` (azure-sql-edge, nativo em arm64) só como documentação, fora do padrão (perfil `sqlserver`, sem aceitar a EULA). Até essa validação, trate o SQL Server como não testado.

**Como rodar:**

```bash
docker compose -f docker-compose.drivers.yml up -d --wait   # sobe MySQL (33306) e PostgreSQL (35432)
composer test:drivers                                       # = PSF_DRIVERS=1 pest --testsuite=Drivers
docker compose -f docker-compose.drivers.yml down -v        # apaga os containers e os dados
```

Sem `PSF_DRIVERS=1` todos os testes de `tests/Drivers` são **pulados** com o motivo (a suíte padrão continua rápida e sem Docker). Cada teste roda uma vez por driver, num banco novo de nome único (`psf_t_<hex>`), criado e apagado pelo próprio teste (também quando ele falha). Para apontar para outros servidores, use as variáveis (os padrões batem com o compose; o usuário precisa poder criar e apagar bancos):

| Variável | Padrão |
|----------|--------|
| `PSF_MYSQL_HOST` / `PSF_MYSQL_PORT` | `127.0.0.1` / `33306` |
| `PSF_MYSQL_USER` / `PSF_MYSQL_PASSWORD` | `root` / `psf_test_root` |
| `PSF_PGSQL_HOST` / `PSF_PGSQL_PORT` | `127.0.0.1` / `35432` |
| `PSF_PGSQL_USER` / `PSF_PGSQL_PASSWORD` | `postgres` / `psf_test_root` |

**O que é validado em cada driver:** `Installer::install()` com as migrations reais (6 tabelas, tipos, `bigint` identity, decimais, `text`, colunas anuláveis do contrato, índices e chaves primárias), reexecução idempotente e atualização de uma instalação parcial; `Connection::open` com o timeout aceito pelo driver; `DatabaseSink` (pesos fracionários, `NULL`, rota/alvo de 191 caracteres multibyte, SQL de 2000 caracteres, trace de 16 KB e mensagem de 500 caracteres de 4 bytes, lote de 1200 linhas em chunks, rollback da transação inteira com linha inválida em vários tipos de erro); o fluxo completo do `Monitor` com os shims `Create::exe/batch`, `Read::exe`, `Update::exe` e `Delete::exe` numa tabela descartável; a limpeza de mensagens contra erros **reais** de chave duplicada, `NOT NULL` e inteiro inválido; `auto_migrate` (com o lock); o circuit breaker com porta recusada e host que não responde (timeout respeitado); `Rollup` (as quatro métricas, reagregação, idempotência, fusão de chaves, hora pulada por violação de integridade real, `maxHours`); `Watermark` (dentro e fora de transação, e a corrida de INSERT); `Pruner` (lotes com `LIMIT`, watermark, teto, agregados); `Reader` (todos os métodos e ordenações, faixas, fusos, limites, lotes de `IN`, percentis, `avg_ms` sem divisão inteira); `MonitorApi`; os comandos `monitor:*`; e prepares emulados e nativos.

**Defeitos encontrados e corrigidos** (cada um com teste):

1. **Colunas anuláveis nas migrations** (todos os drivers; no MySQL a instalação abortava com `All parts of a PRIMARY KEY must be NOT NULL`): o Phinx 0.16 cria colunas `NULL` por padrão. As migrations declaram `'null' => false` (ver [Atualizando o framework](#atualizando-o-framework)).
2. **`Read::exe()` (e o `QueryBuilder`) quebrados no PostgreSQL**: `PostgreSQLDialect::quoteTable()` prefixava o nome do banco (`"banco"."tabela"`), que o PostgreSQL lê como `schema.tabela` (`relation ... does not exist`). Agora o banco é ignorado e a tabela é resolvida pelo `search_path` da conexão.
3. **Grafia da chave fundida dependia do driver**: ao fundir `GET /Users` e `GET /users `, ficava a primeira linha devolvida pelo `GROUP BY`, cuja ordem varia (no PostgreSQL vencia `GET /users `). Agora fica a menor grafia em ordem binária.
4. **`Watermark::write()` dentro de transação no PostgreSQL**: ao perder a corrida do INSERT (outra conexão inseriu a chave), o erro abortava a transação de quem chamou (`25P02`) e a recuperação por UPDATE falhava. O INSERT agora roda num `SAVEPOINT` (fora do SQL Server, onde se assume que o erro não aborta a transação: não validado; vale para o comportamento padrão, não para sessões com `XACT_ABORT ON`).

**Limites que continuam (comportamento dos drivers):**

- **MySQL: colisão por acento.** O `GROUP BY` já agrupa pela collation da coluna (`utf8mb4_unicode_ci`, criada pelo Phinx), então `/café` e `/cafe` viram um só grupo. A colisão na chave primária de `psf_monitor_rollups` só acontece quando o corte em 191 caracteres produz chaves que diferem apenas por acento; nesse caso a hora é pulada (validado no MySQL real).
- **Precisão de `sum_ms`.** `sum_ms` é `decimal(18,2)`: `Σ peso × duração` é arredondado a 2 casas por hora (ex.: `2.5 × 40.25 = 100.625` vira `100.63`), então `avg_ms` pode diferir em milésimos de milissegundo do que o SQLite (que guarda o valor exato) devolve.
- **`connect_timeout` no PostgreSQL.** A libpq trata valores abaixo de 2 s como 2 s; no MySQL vale o valor configurado.
- **Tipos nas linhas brutas.** `Reader::request()` devolve as linhas como o driver devolve: nos dois bancos, inteiros vêm como `int` e decimais como `string` (`"12.50"`); o restante do `Reader` já devolve `float`.
- **Corrida do watermark no MySQL.** Em `REPEATABLE READ` o UPDATE sem linha já trava a lacuna da chave e o INSERT concorrente espera o commit; a corrida só acontece no PostgreSQL (coberta pelo item 4).

---

## Limites e cuidados conhecidos

- **Drivers.** O monitor é **validado em MySQL 8.4 e PostgreSQL 16 reais** (ver [Validação em bancos reais](#validação-em-bancos-reais)) além do SQLite. **SQL Server ainda não foi validado** (pendente: o driver ODBC da Microsoft não estava disponível): valide-o em homologação antes de produção.
- **Formato dos timestamps no SQL Server.** No SQL Server, `'Y-m-d H:i:s'` é lido conforme a linguagem da sessão (em ordem dia-mês-ano, como `Português`, dia e mês trocam). Por isso, só nesse driver, todo valor ligado a coluna datetime (`ts` do `DatabaseSink`, janelas, `bucket_hour` e `last_ts` do `Rollup`, cortes do `Pruner`, intervalos do `Reader`) vai na forma ISO 8601 `Y-m-d\TH:i:s` (`Psf\Monitor\SqlDate`), que independe da linguagem. Os demais drivers continuam com espaço (no SQLite a comparação é textual). Ainda **não validado num SQL Server real**.
- **Colisão de `metric_key` por collation (MySQL e outros).** O rollup funde chaves que diferem só por maiúsculas/minúsculas ou espaços à direita. Chaves que diferem por acentos (collations accent-insensitive, comuns no MySQL) ainda podem colidir na chave primária de `psf_monitor_rollups` (no MySQL, só quando o corte em 191 caracteres as iguala, pois o `GROUP BY` já agrupa pela collation); nesse caso a hora é **pulada** (ver [Agregados](#quando-e-como-cada-hora-é-agregada)) em vez de travar o rollup, e seus agregados se perdem.
- **Chamadas externas: o que é coberto.** Só `Psf\Http\Request`, `Http::request()` (depreciado), `Email::send()` e as quatro operações do `CloudStorage`. **Não são medidos**: outros clientes HTTP (Guzzle, `file_get_contents`), cURL chamado diretamente pela aplicação, nem as conexões PDO (o tempo de banco aparece em [queries](#queries)). Para outros clientes, use [`Monitor::trackExternal()`](#chamadas-externas).
- **Chamadas externas: ganchos de `Email` e `CloudStorage` verificados com classes de apoio.** O PHPMailer e o AWS SDK não estão instalados no ambiente de desenvolvimento do framework; os testes desses dois ganchos usam classes de apoio (stand-in) com a mesma interface, e o ramo com o PHPMailer real só roda quando ele está instalado. Valide-os no seu projeto, com as bibliotecas reais, antes de depender dos números.
- **Rótulo `target` sem normalização de IDN/IP.** O host é só minúsculo e sem ponto final: nomes internacionais (IDN) ficam como vieram (não são convertidos para punycode) e formas equivalentes de IP (`127.1`, `0x7f.1`, IPv6 abreviado ou não) **não** são normalizadas, então podem gerar rótulos diferentes para o mesmo destino.
- **Latência do flush (`fastcgi_finish_request`).** O flush roda no shutdown. Com PHP-FPM, `fastcgi_finish_request()` é chamado imediatamente antes de gravar (só quando o request vai de fato ser gravado), então o cliente já recebeu a resposta. Sem ele (mod_php, servidor embutido do PHP) a latência do flush **é visível ao cliente**; o timeout curto e o breaker limitam o pior caso, e o monitor não se recusa a ligar nesses ambientes. Em contrapartida, com FPM o `fastcgi_finish_request()` fecha a saída: nos requests **gravados**, shutdown handlers registrados depois não conseguem mais enviar output nem headers. Requests não gravados (preflight, fora da amostra, breaker aberto) não são afetados.
- **Servidores de longa duração (RoadRunner, Swoole).** A função de shutdown dispara uma vez por **processo**, não por request. O host deve chamar `Monitor::flush()` e `Monitor::reset()` a cada request, e `$_SERVER['REQUEST_TIME_FLOAT']` pode refletir o início do worker, não do request (a duração ficaria inflada).
- **Workers e scripts CLI.** Os ganchos guardam eventos mesmo em processos de longa duração/CLI que nunca chamam `Monitor::flush()`/`reset()`. A memória é limitada pelos tetos, mas chame `Monitor::reset()` por job para não misturar eventos.
- **`sample.normal` muito pequeno.** `sample_weight = 1 / sample.normal` é gravado em `decimal(12,4)`; abaixo de ~1e-8 o peso estoura a coluna e a gravação falha (contando para o breaker). Não use valores abaixo de `1e-6`.
- **Status após erro fatal.** Depois de um fatal, `http_response_code()` pode ainda retornar `200` no shutdown; o monitor detecta o fatal via `error_get_last()` e força o status gravado para 500 (no mínimo), então o request é sempre gravado e a exceção `FatalError` é registrada (exceto em preflight CORS, que nunca é registrado).
- **Exceção não capturada (linha dupla).** Uma exceção que chega ao topo **sem** um handler global (`set_exception_handler`) termina o PHP com "Uncaught ...", um fatal: se ela já tinha sido registrada (pelo `Router`, no caso de `\Error`/`TypeError` e das falhas 5xx, ou por `Monitor::exception($e)` da aplicação), são gravadas **duas** linhas, a da exceção e uma de `FatalError`. O `settings.php` do framework registra o Ignition como handler global; nesse caso não há fatal e a exceção aparece uma vez só.
- **`prepare()` que falha.** Em `Read`, `Update` e `Delete` a exceção é registrada, mas nenhuma query (ela não chegou a executar).
- **Normalizador de SQL.** Para SQL escrito à mão: (1) no MySQL sem `ANSI_QUOTES`, valores entre aspas duplas fora de posições reconhecidas (depois de operador de comparação/`LIKE`/`THEN`/`ELSE`/lista `IN`), por exemplo argumentos de função `f(x, "s")` ou row values, podem sobreviver ao normalizador; prefira aspas simples ou bindings (a limitação é só do normalizador de SQL; a limpeza de mensagens de exceção redige qualquer `"..."`); (2) `#` é tratado como comentário do MySQL (no PostgreSQL é o operador XOR); (3) `VALUES(col)` dentro de `ON DUPLICATE KEY UPDATE` é colapsado; (4) sem `ext-mbstring`, UTF-8 inválido cai no fallback de trocar bytes não ASCII por `?`. Na dúvida o tokenizador redige demais.
- **`connect_timeout` é só um default.** Se o `extras` do banco definir `PDO::ATTR_TIMEOUT`, ele prevalece. No SQL Server o timeout vai como `LoginTimeout=<connect_timeout>` no DSN (nunca como `PDO::ATTR_TIMEOUT`, que o pdo_sqlsrv rejeita); se o DSN já tiver um `LoginTimeout`, ele prevalece. O `ERRMODE` é sempre forçado para exceções.
- **Conexão dedicada.** O monitor abre a própria conexão PDO (sem passar por `Connect`): não herda transações da aplicação e não é instrumentado. Por isso, consome uma conexão extra por request gravado.
- **`auto_migrate`**: opt-in; recusado quando `environment` é exatamente `'prd'` (outras grafias não contam; ausente conta como `dev`); o lock é por host (diretório temporário); a flag é lida por truthiness, use booleano real.
- **Phinx.** É dependência de desenvolvimento do framework: projetos precisam de `robmorgan/phinx` instalado para os comandos `monitor:*` e o `auto_migrate`.
- **Limites de tamanho** (em caracteres): `method` maiúsculo `[A-Z]` com até 10; `env` até 20; `release` até 64; `route` até 191; `user_id` até 64. No SQL Server, `varchar` não-N conta **bytes**, então rotas e `user_id` multibyte podem estourar a coluna nesse driver (a gravação falha e conta para o breaker).
- **`env` e `release` vêm da configuração.** `env` de `config['environment']` (padrão `dev`), `release` de `settings.monitor.release`. O framework não os detecta sozinho.
- **Agregados são estimativas ponderadas.** `hits`, `errors`, `sum_ms` e o histograma somam `sample_weight` (estimativa não enviesada, com variância maior em rotas de pouco tráfego); p50/p95/p99 são interpolados dentro do bucket.
- **`max_ms` só considera linhas gravadas.** Requests normais não amostrados não entram; lentos e erros são sempre gravados, então na prática o máximo é fiel.
- **Teto por request.** Queries, chamadas externas e exceções acima do teto por request (`max_events_per_request` em cada tipo, 20 exceções) são descartadas e **não entram** nos agregados: `hits` de `query`, `external` e `exception` é um piso.
- **Dado agregado atrasa até ~1 h 5 min** (hora completa + 300 s de folga); linhas brutas atrasadas entram se o `ts` estiver nas 2 horas anteriores ao watermark (reagregação); mais atrasadas se perdem.
- **Teto de 4 × `raw_days` do prune.** Com o rollup parado por mais que isso, as horas sem agregar são perdidas (ver [Retenção e manutenção](#retenção-e-manutenção)).

---

## Solução de problemas

### Nada é gravado

1. `monitor:status` mostra `Monitor ligado: não`? Confira `settings.monitor.enabled` (e se o config lido pela CLI é o mesmo da aplicação).
2. `Instalado: não`? Rode `monitor:install` (ver abaixo).
3. Lembre da amostragem: com `sample.normal = 0.1`, só ~10% dos requests normais são gravados; com `0`, só erros, exceções e lentos. Preflights CORS nunca são gravados.
4. Procure no log do PHP (`error_log`) uma linha `[PSF][monitor]`: o breaker pode estar aberto.

### Breaker aberto (linhas `[PSF][monitor]` no log)

O breaker registra **uma** linha quando abre; durante `breaker.cooldown_seconds` nada é gravado. As mensagens possíveis:

| Mensagem | Causa e ação |
|----------|--------------|
| `[PSF][monitor] gravação suspensa por <N>s após falhas consecutivas: <Classe>: <mensagem>.` | `breaker.failures` gravações seguidas falharam (banco fora do ar, credencial, timeout, coluna estourada...). Corrija a causa indicada; a gravação volta sozinha depois do cooldown. |
| ... seguida de `Tabelas ausentes: rode monitor:install.` | A mensagem do banco indica tabela inexistente. Rode `monitor:install` no banco do monitor. |
| `[PSF][monitor] auto_migrate ignorado em prd; rode monitor:install` | `auto_migrate` ligado com `environment = 'prd'` e tabelas ausentes. Rode `monitor:install`. |
| `[PSF][monitor] auto_migrate indisponível: <causa>` | O `auto_migrate` não conseguiu instalar (sem Phinx, sem permissão de DDL, falha no arquivo de lock). Corrija a causa ou rode `monitor:install`. |

Para não esperar o cooldown, apague o estado do breaker: o arquivo `psf-monitor-<md5('db:<database>')>.json` em `sys_get_temp_dir()` ou, com APCu, a chave de mesmo nome (sem `.json`) no APCu do SAPI da aplicação (o APCu da CLI é outro).

### Tabelas ausentes

`monitor:status` mostra `Instalado: não` (falta alguma das seis tabelas; num projeto que veio da fase 3, falta `psf_monitor_external`). Rode `vendor/bin/phinx monitor:install [-e banco]`; se falhar com `Instalação do monitor requer robmorgan/phinx.`, instale o Phinx no projeto; com `Configuração de banco '<chave>' incompleta para o Phinx.`, confira `driver` e `database` da entrada em `config['db']`.

### Rollup parado ou atrasado

- `Rollup até` mais de ~1 h 5 min atrás de agora: o cron do `monitor:maintain` não está rodando ou está falhando (exit code 1 e linhas `<error>` no log do cron).
- Backlog grande: cada execução agrega no máximo `--max-hours` horas novas (48 por padrão). Para alcançar o presente mais rápido, rode `vendor/bin/phinx monitor:rollup --max-hours 1000`.
- Atenção ao teto: com o rollup parado por mais de `4 × raw_days` dias, o prune apaga brutos ainda não agregados e essas horas ficam sem agregados (ver [Retenção e manutenção](#ordem-e-limites-da-limpeza)).
- `Rollup até: nunca` com dados brutos presentes: o rollup nunca rodou (ou a leitura do watermark falhou, no caso do `monitor:status`).

### Hora pulada

A saída mostra `Horas puladas: N` e o aviso de violação de integridade: uma hora nova falhou com SQLSTATE `23xxx` ao gravar os agregados (tipicamente chaves que o banco considera iguais por collation, como diferenças só de acento). O rollup segue em frente, o watermark avança e **os agregados dessa hora se perdem**; as linhas brutas dela continuam disponíveis (SQL direto, `Reader::request()`) até o prune. Se acontecer com frequência, revise a collation de `psf_monitor_rollups.metric_key` (uma collation sensível a acentos evita a colisão).

### `Reader` vazio ou `request()` nulo

- A hora corrente nunca está agregada; a anterior só entra depois da virada + 300 s **e** da próxima execução do cron.
- `throughput()` omite horas sem registro.
- `request($id)` é `null` quando o request não foi amostrado ou já passou da retenção bruta.

---

## Evolução

- **Fase 1 (entregue):** requests, `X-Request-Id`, amostragem por peso, conexão dedicada, circuit breaker, `auto_migrate`, comandos `monitor:install`, `monitor:status` e `monitor:prune`.
- **Fase 2 (entregue):** queries SQL (normalizadas) e exceções, inclusive erros fatais, com limpeza de mensagens.
- **Fase 3 (entregue):** rollups por hora com histograma e percentis, `Reader`, comandos `monitor:rollup` e `monitor:maintain`, teto de 4 × `raw_days` no prune.
- **Fase 4, Parte A (entregue):** chamadas externas (`Request`, `Http::request`, `Email`, `CloudStorage` e `Monitor::trackExternal`/`external`/`externalStart`/`externalEnd`) gravadas em `psf_monitor_external`, métrica `external` nos rollups, `Reader::external()` e a chave `external` de `Reader::request()`, e o serviço JSON opcional `MonitorApi` (desligado por padrão).
- **Fase 4, Parte B (entregue para MySQL e PostgreSQL):** suíte `tests/Drivers` contra MySQL 8.4 e PostgreSQL 16 reais e as correções que ela revelou (ver [Validação em bancos reais](#validação-em-bancos-reais)). **SQL Server pendente** (driver ODBC da Microsoft não instalado no ambiente de validação).
