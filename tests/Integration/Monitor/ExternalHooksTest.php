<?php

use Psf\Enumerators\Protocol;
use Psf\Http\{Http, Request};
use Psf\Monitor\{Config, Monitor};
use Psf\Utils\{CloudStorage, Email};

/*
| Ganchos de chamadas externas em Request, Http (deprecated), Email e CloudStorage.
| HTTP real: falha determinística em 127.0.0.1:1 (conexão recusada, offline) e
| sucesso contra o servidor embutido do PHP (`php -S`) subido no beforeAll.
|
| PHPMailer e AWS SDK são dependências opcionais. Quando não estão instaladas,
| este arquivo define STUBS mínimos (só para os testes) controlados por
| "botões" estáticos, para que os ganchos de Email e CloudStorage rodem de
| verdade. Quando as classes reais existem, os stubs não são definidos, os
| testes de botão são pulados e rodam os testes contra as classes reais.
*/

/** Estado compartilhado pelos testes deste arquivo. */
final class ExternalHooksState
{
    /** @var resource|null */
    public static $proc = null;
    public static ?int $port = null;
    public static ?string $dir = null;

    /** true quando os stubs de PHPMailer/AWS foram definidos por este arquivo. */
    public static bool $mailerStub = false;
    public static bool $awsStub = false;

    /** Config do PSF antes de um teste alterá-la (restaurada no afterEach). */
    public static ?array $savedConfig = null;
}

if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    ExternalHooksState::$mailerStub = true;
    eval(<<<'PHP'
namespace PHPMailer\PHPMailer;

class Exception extends \Exception {}

class SMTP { public const DEBUG_SERVER = 2; }

class PHPMailer
{
    /** Botão de teste: 'true' | 'false' | 'throw'. */
    public static string $sendMode = 'true';

    public $Host; public $SMTPAuth; public $Username; public $Password; public $SMTPSecure;
    public $Port; public $SMTPOptions; public $CharSet; public $Subject; public $Body;
    public $AltBody; public $ErrorInfo = ''; public $SMTPDebug = 0;

    public function __construct(public $exceptions = null) {}
    public function isSMTP() {}
    public function setFrom($address, $name = '') { return true; }
    public function addAddress($address, $name = '') { return true; }
    public function isHTML($isHtml = true) {}
    public function addAttachment($path, $name = '') { return true; }
    public function addStringAttachment($string, $filename) { return true; }

    public function send()
    {
        if (self::$sendMode === 'throw') {
            $this->ErrorInfo = 'SMTP connect() failed (stub)';
            throw new Exception($this->ErrorInfo);
        }
        return self::$sendMode === 'true';
    }
}
PHP);
}

if (!class_exists(\Aws\S3\S3Client::class)) {
    ExternalHooksState::$awsStub = true;
    eval(<<<'PHP'
namespace Aws\S3\Exception;

class S3Exception extends \RuntimeException {}
PHP);
    eval(<<<'PHP'
namespace Aws\S3;

class S3Client
{
    /** Botão de teste: 'ok' | 'fail'. */
    public static string $mode = 'ok';
    /** Argumentos recebidos por operação (para conferir que nada vaza para o monitor). */
    public static array $calls = [];

    private function answer(string $op, array $args, array $result)
    {
        self::$calls[] = [$op, $args];
        if (self::$mode === 'fail') {
            throw new Exception\S3Exception('falhou (stub)');
        }
        return $result;
    }

    public function putObject(array $args = [])
    {
        return $this->answer('putObject', $args, ['ObjectURL' => 'x', '@metadata' => ['statusCode' => 201]]);
    }

    public function getObject(array $args = [])
    {
        return $this->answer('getObject', $args, ['@metadata' => ['statusCode' => 200]]);
    }

    public function deleteObject(array $args = [])
    {
        return $this->answer('deleteObject', $args, []);
    }

    public function listObjectsV2(array $args = [])
    {
        return $this->answer('listObjectsV2', $args, [
            'Contents' => [['Key' => 'a.txt', 'Size' => '3', 'LastModified' => '2026-01-01']],
            'IsTruncated' => true,
            'NextContinuationToken' => 'proximo',
            '@metadata' => ['statusCode' => 200],
        ]);
    }
}
PHP);
}

/** Porta TCP livre em 127.0.0.1 (o SO escolhe). */
function externalHooksFreePort(): ?int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        return null;
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    $port = $name === false ? 0 : (int) substr($name, strrpos($name, ':') + 1);
    return $port > 0 ? $port : null;
}

/** URL base do servidor embutido, ou pula o teste quando ele não subiu. */
function externalHooksBase(): string
{
    if (ExternalHooksState::$port === null) {
        test()->markTestSkipped('servidor embutido do PHP (php -S) não subiu neste ambiente');
    }
    return 'http://127.0.0.1:' . ExternalHooksState::$port;
}

/** Remove o diretório temporário do router. */
function externalHooksRemoveDir(?string $dir): void
{
    if ($dir !== null) {
        @unlink($dir . '/router.php');
        @rmdir($dir);
    }
}

/** Altera uma chave da config do PSF, guardando a original para o afterEach. */
function externalHooksSetConfig(string $key, array $value): void
{
    $ref = new ReflectionProperty(PSF::class, 'config');
    $config = $ref->getValue();
    if (ExternalHooksState::$savedConfig === null) {
        ExternalHooksState::$savedConfig = $config;
    }
    $config[$key] = $value;
    $ref->setValue(null, $config);
}

function externalHooksAwsConfig(): void
{
    externalHooksSetConfig('aws', [
        'provider' => 'R2', 'bucket' => 'bucket-secreto', 'access_key_id' => 'chave-secreta',
        'access_key_secret' => 'segredo', 'account_id' => 'conta',
    ]);
}

function externalHooksSmtpConfig(): void
{
    externalHooksSetConfig('smtp', ['hostname' => 'smtp.exemplo.com.br']);
}

beforeAll(function () {
    $port = externalHooksFreePort();
    if ($port === null) {
        return;
    }

    $dir = sys_get_temp_dir() . '/psf-external-hooks-' . getmypid();
    @mkdir($dir);
    file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/fail') {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['erro' => 'indisponivel']);
    return true;
}
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'path' => $path]);
return true;
PHP);

    $cmd  = [PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/router.php'];
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $proc = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
    if (!is_resource($proc)) {
        externalHooksRemoveDir($dir);
        return;
    }

    // Espera o servidor aceitar conexões (até ~3 s)
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($c !== false) {
            fclose($c);
            ExternalHooksState::$proc = $proc;
            ExternalHooksState::$port = $port;
            ExternalHooksState::$dir  = $dir;
            return;
        }
        usleep(50_000);
    }

    proc_terminate($proc);
    proc_close($proc);
    externalHooksRemoveDir($dir);
});

afterAll(function () {
    if (is_resource(ExternalHooksState::$proc)) {
        proc_terminate(ExternalHooksState::$proc);
        proc_close(ExternalHooksState::$proc);
    }
    externalHooksRemoveDir(ExternalHooksState::$dir);
    ExternalHooksState::$proc = null;
    ExternalHooksState::$port = null;
    ExternalHooksState::$dir  = null;
});

beforeEach(function () {
    Monitor::reset();
    Config::fake(['enabled' => true]);
    if (ExternalHooksState::$mailerStub) {
        \PHPMailer\PHPMailer\PHPMailer::$sendMode = 'true';
    }
    if (ExternalHooksState::$awsStub) {
        \Aws\S3\S3Client::$mode  = 'ok';
        \Aws\S3\S3Client::$calls = [];
    }
});

afterEach(function () {
    Monitor::reset();
    Config::reset();
    if (ExternalHooksState::$savedConfig !== null) {
        (new ReflectionProperty(PSF::class, 'config'))->setValue(null, ExternalHooksState::$savedConfig);
        ExternalHooksState::$savedConfig = null;
    }
});

/* ------------------------------------------------------------------ HTTP */

it('records a failed http call without changing the result', function () {
    $result = Request::get()->url('http://127.0.0.1:1/x?token=segredo')->send();

    expect($result)->toBeFalse();

    $external = Monitor::events()['external'];
    expect($external)->toHaveCount(1);
    expect($external[0]['kind'])->toBe('http');
    expect($external[0]['target'])->toBe('127.0.0.1');
    expect($external[0]['status'])->toBeNull();
    expect($external[0]['duration_ms'])->toBeGreaterThanOrEqual(0);
    expect(json_encode(Monitor::events()))->not->toContain('segredo');
});

it('records http code 0 from a successful curl_exec as an error', function () {
    // file:// não é HTTP: o cURL devolve o conteúdo com código 0
    $file = tempnam(sys_get_temp_dir(), 'psf-hook');
    file_put_contents($file, 'conteudo');

    try {
        $result = Request::get()->url('file://' . $file)->send();
    } finally {
        @unlink($file);
    }

    expect($result)->toBeInstanceOf(stdClass::class);
    expect($result->code)->toBe(0);
    expect($result->body)->toBe('conteudo');

    $external = Monitor::events()['external'];
    expect($external)->toHaveCount(1);
    expect($external[0]['kind'])->toBe('http');
    expect($external[0]['status'])->toBeNull();
});

it('records a successful http call', function () {
    $base = externalHooksBase();

    $ok   = Request::get()->url($base . '/ok?token=segredo')->send();
    $fail = Request::get()->url($base . '/fail')->send();

    // Retorno igual ao de sempre: objeto com code, contentType, timing e body
    expect($ok)->toBeInstanceOf(stdClass::class);
    expect($ok->code)->toBe(200);
    expect($ok->body)->toBe(['ok' => true, 'path' => '/ok']);
    expect($ok->contentType)->toStartWith('application/json');
    expect($fail->code)->toBe(503);
    expect($fail->body)->toBe(['erro' => 'indisponivel']);

    $external = Monitor::events()['external'];
    expect($external)->toHaveCount(2);
    expect(array_column($external, 'kind'))->toBe(['http', 'http']);
    expect(array_column($external, 'target'))->toBe(['127.0.0.1', '127.0.0.1']);
    expect(array_column($external, 'status'))->toBe([200, 503]);
    expect($external[0]['duration_ms'])->toBeGreaterThanOrEqual(0);
    expect($external[1]['duration_ms'])->toBeGreaterThanOrEqual(0);
    expect(json_encode(Monitor::events()))->not->toContain('segredo');
});

it('does not instrument failed http calls when the monitor is disabled', function () {
    Config::fake(['enabled' => false]);

    expect(Request::get()->url('http://127.0.0.1:1/x?token=segredo')->send())->toBeFalse();
    expect(Http::request('GET', 'http://127.0.0.1:1/x', ['token' => 'segredo']))->toBe(['code' => 0, 'response' => null]);

    // Religa sem limpar o buffer: nada pode ter sido registrado enquanto desligado
    Config::fake(['enabled' => true]);
    expect(Monitor::events()['external'])->toBe([]);
});

it('does not instrument successful http calls when the monitor is disabled', function () {
    $base = externalHooksBase();
    Config::fake(['enabled' => false]);

    $ok     = Request::get()->url($base . '/ok')->send();
    $legacy = Http::request('GET', $base . '/fail', []);

    expect($ok->code)->toBe(200);
    expect($ok->body)->toBe(['ok' => true, 'path' => '/ok']);
    expect($legacy)->toBe(['code' => 503, 'response' => ['erro' => 'indisponivel']]);

    Config::fake(['enabled' => true]);
    expect(Monitor::events()['external'])->toBe([]);
});

it('Http::request records a refused call with the host only', function () {
    $failed = Http::request('GET', 'http://127.0.0.1:1/x', ['token' => 'segredo']);

    expect($failed)->toBe(['code' => 0, 'response' => null]);

    $external = Monitor::events()['external'];
    expect($external)->toHaveCount(1);
    expect($external[0]['kind'])->toBe('http');
    expect($external[0]['target'])->toBe('127.0.0.1');
    expect($external[0]['status'])->toBeNull();
    expect(json_encode(Monitor::events()))->not->toContain('segredo');
});

it('Http::request records successful calls with the host only', function () {
    $base = externalHooksBase();

    $ok   = Http::request('GET', $base . '/ok', ['token' => 'segredo']);
    $fail = Http::request('POST', $base . '/fail', ['senha' => 'segredo']);

    // Retorno igual ao de sempre
    expect($ok)->toBe(['code' => 200, 'response' => ['ok' => true, 'path' => '/ok']]);
    expect($fail)->toBe(['code' => 503, 'response' => ['erro' => 'indisponivel']]);

    $external = Monitor::events()['external'];
    expect(array_column($external, 'kind'))->toBe(['http', 'http']);
    expect(array_column($external, 'target'))->toBe(['127.0.0.1', '127.0.0.1']);
    expect(array_column($external, 'status'))->toBe([200, 503]);
    expect(json_encode(Monitor::events()))->not->toContain('segredo');
});

/* ----------------------------------------------------------------- Email */

/** Email com servidor e dados "secretos" que nunca podem ir para o monitor. */
function externalHooksSendEmail(string $host = 'smtp.exemplo.com.br', int $port = 587): array
{
    externalHooksSmtpConfig();
    $email = Email::define(Protocol::Smtp, [
        'host' => $host, 'user' => 'usuario-secreto', 'password' => 'senha-secreta',
        'port' => $port, 'name' => 'Teste',
    ]);
    $result = $email->send(
        ['Remetente', 'remetente-secreto@x.com'],
        ['Destino', 'destino-secreto@x.com'],
        'assunto-secreto',
        ['html' => '<p>corpo-secreto</p>']
    );
    return [$email, $result];
}

function externalHooksAssertNoMailSecrets(): void
{
    $json = json_encode(Monitor::events());
    foreach (['usuario-secreto', 'senha-secreta', 'remetente-secreto', 'destino-secreto', 'assunto-secreto', 'corpo-secreto'] as $secret) {
        expect($json)->not->toContain($secret);
    }
}

$mailerStubOnly = fn () => !ExternalHooksState::$mailerStub;
$mailerStubReason = 'PHPMailer real instalado: os testes com o stub controlável não se aplicam';

it('Email::send records a successful send with the smtp host only', function () {
    \PHPMailer\PHPMailer\PHPMailer::$sendMode = 'true';
    [$email, $result] = externalHooksSendEmail();

    expect($result)->toBeTrue();
    expect($email->getError())->toBeNull();

    $external = Monitor::events()['external'];
    expect($external)->toHaveCount(1);
    expect($external[0]['kind'])->toBe('mail');
    expect($external[0]['target'])->toBe('smtp.exemplo.com.br');
    expect($external[0]['status'])->toBe(200);
    externalHooksAssertNoMailSecrets();
})->skip($mailerStubOnly, $mailerStubReason);

it('Email::send keeps the old error string when send() returns false', function () {
    \PHPMailer\PHPMailer\PHPMailer::$sendMode = 'false';
    [$email, $result] = externalHooksSendEmail();

    expect($result)->toBe('Error to processs. No more data.');
    expect($email->getError())->toBe($result);

    $external = Monitor::events()['external'];
    expect($external)->toHaveCount(1);
    expect($external[0]['target'])->toBe('smtp.exemplo.com.br');
    expect($external[0]['status'])->toBeNull();
    externalHooksAssertNoMailSecrets();
})->skip($mailerStubOnly, $mailerStubReason);

it('Email::send keeps returning ErrorInfo when PHPMailer throws', function () {
    \PHPMailer\PHPMailer\PHPMailer::$sendMode = 'throw';
    [$email, $result] = externalHooksSendEmail();

    expect($result)->toBe('SMTP connect() failed (stub)');
    expect($email->getError())->toBe($result);

    $external = Monitor::events()['external'];
    expect($external)->toHaveCount(1);
    expect($external[0]['kind'])->toBe('mail');
    expect($external[0]['status'])->toBeNull();
    externalHooksAssertNoMailSecrets();
})->skip($mailerStubOnly, $mailerStubReason);

it('Email::send behaves the same with the monitor disabled', function () {
    Config::fake(['enabled' => false]);

    \PHPMailer\PHPMailer\PHPMailer::$sendMode = 'true';
    expect(externalHooksSendEmail()[1])->toBeTrue();
    \PHPMailer\PHPMailer\PHPMailer::$sendMode = 'false';
    expect(externalHooksSendEmail()[1])->toBe('Error to processs. No more data.');
    \PHPMailer\PHPMailer\PHPMailer::$sendMode = 'throw';
    expect(externalHooksSendEmail()[1])->toBe('SMTP connect() failed (stub)');

    Config::fake(['enabled' => true]);
    expect(Monitor::events()['external'])->toBe([]);
})->skip($mailerStubOnly, $mailerStubReason);

it('Email::send with the real PHPMailer records a refused smtp host', function () {
    // Porta 1 recusa: PHPMailer(true) lança e o catch devolve o ErrorInfo
    [$email, $result] = externalHooksSendEmail('127.0.0.1', 1);

    expect($result)->toBeString();
    expect($email->getError())->toBe($result);

    $external = Monitor::events()['external'];
    expect($external)->toHaveCount(1);
    expect($external[0]['kind'])->toBe('mail');
    expect($external[0]['target'])->toBe('127.0.0.1');
    expect($external[0]['status'])->toBeNull();
    externalHooksAssertNoMailSecrets();
})->skip(
    fn () => ExternalHooksState::$mailerStub,
    'PHPMailer (dependência opcional) não está instalado; o gancho de Email roda contra o stub nos testes acima'
);

/* ---------------------------------------------------------- CloudStorage */

$awsStubOnly = fn () => !ExternalHooksState::$awsStub;
$awsStubReason = 'AWS SDK real instalado: os testes com o stub controlável não se aplicam';

const EXTERNAL_HOOKS_EMPTY_LIST = ['keys' => [], 'nextContinuationToken' => null, 'isTruncated' => false];

it('CloudStorage records each successful operation with the provider label only', function () {
    externalHooksAwsConfig();
    $s3 = new \Aws\S3\S3Client();

    expect(CloudStorage::putObject($s3, 'chave-secreta.txt', 'conteudo'))
        ->toBe(['ObjectURL' => 'x', '@metadata' => ['statusCode' => 201]]);
    expect(CloudStorage::getObject($s3, 'chave-secreta.txt', '/tmp/destino'))->toBe('/tmp/destino');
    expect(CloudStorage::deleteObject($s3, 'chave-secreta.txt'))->toBeTrue();
    expect(CloudStorage::listObjects($s3))->toBe([
        'keys' => [['key' => 'a.txt', 'size' => 3, 'lastModified' => '2026-01-01']],
        'nextContinuationToken' => 'proximo',
        'isTruncated' => true,
    ]);

    // O SDK recebeu Bucket/Key; o monitor não
    expect(\Aws\S3\S3Client::$calls[0][1]['Bucket'])->toBe('bucket-secreto');

    $external = Monitor::events()['external'];
    expect(array_column($external, 'kind'))->toBe(['storage', 'storage', 'storage', 'storage']);
    expect(array_column($external, 'target'))->toBe(['r2/putobject', 'r2/getobject', 'r2/deleteobject', 'r2/listobjects']);
    expect(array_column($external, 'status'))->toBe([201, 200, 200, 200]);
    $json = json_encode(Monitor::events());
    expect($json)->not->toContain('bucket-secreto')->not->toContain('chave-secreta')->not->toContain('segredo');
})->skip($awsStubOnly, $awsStubReason);

it('CloudStorage failures keep the old return and are recorded as errors', function () {
    externalHooksAwsConfig();
    \Aws\S3\S3Client::$mode = 'fail';
    $s3 = new \Aws\S3\S3Client();

    expect(CloudStorage::putObject($s3, 'chave-secreta.txt', 'conteudo'))->toBeFalse();
    expect(CloudStorage::getObject($s3, 'chave-secreta.txt', '/tmp/destino'))->toBeFalse();
    expect(CloudStorage::deleteObject($s3, 'chave-secreta.txt'))->toBeFalse();
    expect(CloudStorage::listObjects($s3))->toBe(EXTERNAL_HOOKS_EMPTY_LIST);

    $external = Monitor::events()['external'];
    expect(array_column($external, 'kind'))->toBe(['storage', 'storage', 'storage', 'storage']);
    expect(array_column($external, 'target'))->toBe(['r2/putobject', 'r2/getobject', 'r2/deleteobject', 'r2/listobjects']);
    expect(array_column($external, 'status'))->toBe([null, null, null, null]);
    $json = json_encode(Monitor::events());
    expect($json)->not->toContain('bucket-secreto')->not->toContain('chave-secreta');
})->skip($awsStubOnly, $awsStubReason);

it('CloudStorage behaves the same with the monitor disabled', function () {
    externalHooksAwsConfig();
    Config::fake(['enabled' => false]);
    $s3 = new \Aws\S3\S3Client();

    expect(CloudStorage::putObject($s3, 'a.txt', 'conteudo'))->toBe(['ObjectURL' => 'x', '@metadata' => ['statusCode' => 201]]);
    expect(CloudStorage::getObject($s3, 'a.txt', '/tmp/destino'))->toBe('/tmp/destino');
    expect(CloudStorage::deleteObject($s3, 'a.txt'))->toBeTrue();
    expect(CloudStorage::listObjects($s3)['isTruncated'])->toBeTrue();

    \Aws\S3\S3Client::$mode = 'fail';
    expect(CloudStorage::putObject($s3, 'a.txt', 'conteudo'))->toBeFalse();
    expect(CloudStorage::getObject($s3, 'a.txt', '/tmp/destino'))->toBeFalse();
    expect(CloudStorage::deleteObject($s3, 'a.txt'))->toBeFalse();
    expect(CloudStorage::listObjects($s3))->toBe(EXTERNAL_HOOKS_EMPTY_LIST);

    Config::fake(['enabled' => true]);
    expect(Monitor::events()['external'])->toBe([]);
})->skip($awsStubOnly, $awsStubReason);

/** Dublê do S3Client REAL (só quando o AWS SDK está instalado). */
function externalHooksRealSdkDouble(bool $fail)
{
    return new class ($fail) extends \Aws\S3\S3Client {
        public function __construct(private bool $fail)
        {
        }

        private function answer(string $op, array $result)
        {
            if ($this->fail) {
                throw new \Aws\S3\Exception\S3Exception('falhou', new \Aws\Command($op));
            }
            return new \Aws\Result($result);
        }

        public function putObject(array $args = [])
        {
            return $this->answer('PutObject', ['@metadata' => ['statusCode' => 201]]);
        }

        public function getObject(array $args = [])
        {
            return $this->answer('GetObject', ['@metadata' => ['statusCode' => 200]]);
        }

        public function deleteObject(array $args = [])
        {
            return $this->answer('DeleteObject', []);
        }

        public function listObjectsV2(array $args = [])
        {
            return $this->answer('ListObjectsV2', ['Contents' => [], 'IsTruncated' => false, '@metadata' => ['statusCode' => 200]]);
        }
    };
}

it('CloudStorage with the real AWS SDK records success and failure', function () {
    externalHooksAwsConfig();

    $ok = externalHooksRealSdkDouble(false);
    expect(CloudStorage::putObject($ok, 'chave-secreta.txt', 'conteudo'))->toBeInstanceOf(\Aws\Result::class);
    expect(CloudStorage::getObject($ok, 'chave-secreta.txt', '/tmp/destino'))->toBe('/tmp/destino');
    expect(CloudStorage::deleteObject($ok, 'chave-secreta.txt'))->toBeTrue();
    expect(CloudStorage::listObjects($ok))->toBe(EXTERNAL_HOOKS_EMPTY_LIST);

    $fail = externalHooksRealSdkDouble(true);
    expect(CloudStorage::putObject($fail, 'chave-secreta.txt', 'conteudo'))->toBeFalse();
    expect(CloudStorage::listObjects($fail))->toBe(EXTERNAL_HOOKS_EMPTY_LIST);

    $external = Monitor::events()['external'];
    expect(array_column($external, 'target'))->toBe(['r2/putobject', 'r2/getobject', 'r2/deleteobject', 'r2/listobjects', 'r2/putobject', 'r2/listobjects']);
    expect(array_column($external, 'status'))->toBe([201, 200, 200, 200, null, null]);
    expect(json_encode(Monitor::events()))->not->toContain('bucket-secreto')->not->toContain('chave-secreta');
})->skip(
    fn () => ExternalHooksState::$awsStub,
    'AWS SDK (dependência opcional) não está instalado; os ganchos de CloudStorage rodam contra o stub nos testes acima'
);

/* ------------------------------------------------------------ estrutural */

it('every external call site goes through Monitor::trackExternal', function (string $file, int $expected) {
    $source = file_get_contents(dirname(__DIR__, 3) . '/' . $file);

    expect(substr_count($source, 'Monitor::trackExternal('))->toBe($expected);
    expect($source)->toContain('ExternalTarget::');
})->with([
    'Email'        => ['src/utils/Email.php', 1],
    'CloudStorage' => ['src/utils/CloudStorage.php', 4],
    'Request'      => ['src/http/Request.php', 1],
    'Http'         => ['src/http/Http.php', 1],
]);
