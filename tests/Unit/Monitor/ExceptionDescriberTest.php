<?php

use Psf\Monitor\ExceptionDescriber;

function falha(string $senha)
{
    throw new RuntimeException('x');
}

function lancaNoMesmoPonto(string $msg, int $linha = 1)
{
    if ($linha === 1) {
        throw new RuntimeException($msg);
    }
    throw new RuntimeException($msg);
}

function intermediarioDoHash(string $msg, int $linha = 1)
{
    lancaNoMesmoPonto($msg, $linha);
}

function capturaDescricao(callable $fn): array
{
    try {
        $fn();
    } catch (Throwable $e) {
        return ExceptionDescriber::describe($e);
    }
    throw new LogicException('não lançou');
}

function recursaoProfunda(int $n)
{
    if ($n === 0) {
        throw new LogicException('fundo');
    }
    recursaoProfunda($n - 1);
}

it('never includes frame arguments', function () {
    try {
        falha('hunter2');
    } catch (Throwable $e) {
        $d = ExceptionDescriber::describe($e);
    }
    expect($d['trace'])->not->toContain('hunter2')
        ->and($d['trace'])->toContain('falha(');
});

it('includes the previous chain', function () {
    $e = new Exception('externa', 0, new RuntimeException('interna'));
    $d = ExceptionDescriber::describe($e);
    expect($d['trace'])->toContain('Caused by: RuntimeException: interna');
});

it('caps message, class, file and trace', function () {
    $e = new Exception(str_repeat('é', 5000));
    $d = ExceptionDescriber::describe($e);
    expect(mb_strlen($d['message']))->toBe(500)
        ->and(mb_check_encoding($d['message'], 'UTF-8'))->toBeTrue()
        ->and(strlen($d['exception_class']))->toBeLessThanOrEqual(191)
        ->and(strlen($d['file']))->toBeLessThanOrEqual(255);

    $prev = null;
    for ($i = 0; $i < 2000; $i++) {
        $prev = new Exception('x' . str_repeat('é', 50), 0, $prev);
    }
    $d = ExceptionDescriber::describe($prev);
    expect(strlen($d['trace']))->toBeLessThanOrEqual(16384)
        ->and($d['trace'])->toEndWith('... [truncado]')
        ->and(mb_check_encoding($d['trace'], 'UTF-8'))->toBeTrue();

    try {
        recursaoProfunda(2000);
    } catch (Throwable $e) {
        $d = ExceptionDescriber::describe($e);
    }
    expect(strlen($d['trace']))->toBeLessThanOrEqual(16384)
        ->and($d['trace'])->toEndWith('... [truncado]');
});

it('survives invalid utf-8 and cyclic-looking input', function () {
    $e = new Exception("ruim \xC3\x28 " . str_repeat("\xFF", 3000));
    $d = ExceptionDescriber::describe($e);
    expect(mb_check_encoding($d['message'], 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($d['message']))->toBeLessThanOrEqual(500);
});

it('hashes by location, not by message or arguments', function () {
    $mk = fn (string $m) => new Exception($m);
    $h = [];
    foreach (['um', 'dois'] as $m) {
        $h[] = ExceptionDescriber::describe($mk($m));
    }
    [$a, $b] = $h;
    $c = ExceptionDescriber::describe(new Exception('um'));
    expect($a['trace_hash'])->toBe($b['trace_hash'])
        ->and($a['trace_hash'])->not->toBe($c['trace_hash'])
        ->and($a['trace_hash'])->toMatch('/^[0-9a-f]{40}$/');
});

it('describes a fatal error', function () {
    $d = ExceptionDescriber::fatal([
        'type' => E_ERROR,
        'message' => "Uncaught Exception: boom in /a.php:12\nStack trace:\n#0 x('segredo')",
        'file' => '/a.php',
        'line' => 12,
    ]);
    expect($d['exception_class'])->toBe('FatalError')
        ->and($d['message'])->not->toContain('Stack trace')
        ->and($d['message'])->not->toContain('segredo')
        ->and($d['trace'])->toBe('')
        ->and($d['line_no'])->toBe(12)
        ->and($d['trace_hash'])->toMatch('/^[0-9a-f]{40}$/');
});

it('hashes the same bug reached through different call paths equally', function () {
    // Mesmo ponto de lançamento, chamado de linhas diferentes, com mensagem e argumentos diferentes
    $a = capturaDescricao(function () {
        intermediarioDoHash('primeira mensagem');
    });
    $b = capturaDescricao(function () {
        $x = 1;
        intermediarioDoHash('outra mensagem ' . $x);
    });
    $c = capturaDescricao(fn () => intermediarioDoHash(str_repeat('z', 30)));

    expect($a['trace_hash'])->toBe($b['trace_hash'])
        ->and($a['trace_hash'])->toBe($c['trace_hash'])
        ->and($a['trace_hash'])->toMatch('/^[0-9a-f]{40}$/');
});

it('hashes a different throw line or class differently', function () {
    $a = capturaDescricao(fn () => intermediarioDoHash('m', 1));
    $b = capturaDescricao(fn () => intermediarioDoHash('m', 2));
    expect($a['trace_hash'])->not->toBe($b['trace_hash']);

    $mk = fn (string $cls) => new $cls('m');
    $r = ExceptionDescriber::describe($mk(RuntimeException::class));
    $l = ExceptionDescriber::describe($mk(LogicException::class));
    expect($r['trace_hash'])->not->toBe($l['trace_hash'])
        ->and($l['trace_hash'])->toMatch('/^[0-9a-f]{40}$/');
});

it('hashes frames that miss keys without failing', function () {
    $e = new RuntimeException('m');
    $ref = new ReflectionProperty(Exception::class, 'trace');
    $ref->setAccessible(true);
    $ref->setValue($e, [[], ['function' => 'f'], ['class' => 'K'], ['class' => 'K', 'function' => 'g', 'file' => '/x', 'line' => 3]]);
    $d = ExceptionDescriber::describe($e);
    expect($d['trace_hash'])->toMatch('/^[0-9a-f]{40}$/')
        ->and($d['exception_class'])->toBe('RuntimeException');
});

// --- limpeza de mensagens (PDO e fatal) ---

function pdoErro(string $mensagem, ?Throwable $anterior = null): PDOException
{
    return new PDOException($mensagem, 0, $anterior);
}

it('scrubs values from a MySQL duplicate entry message', function () {
    $d = ExceptionDescriber::describe(pdoErro(
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'jane@x.com' for key 'users.email'"
    ));
    expect($d['message'])->not->toContain('jane@x.com')
        ->and($d['message'])->toContain('Duplicate entry')
        ->and($d['message'])->toContain('SQLSTATE[23000]');
});

it('scrubs values from a MySQL incorrect value message', function () {
    $d = ExceptionDescriber::describe(pdoErro(
        "SQLSTATE[HY000]: General error: 1366 Incorrect integer value: 'abc' for column 'n' at row 1"
    ));
    expect($d['message'])->not->toContain('abc')
        ->and($d['message'])->toContain('Incorrect integer value');
});

it('scrubs values from PostgreSQL messages', function () {
    $d = ExceptionDescriber::describe(pdoErro(
        "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_key\"\nDETAIL:  Key (email)=(jane@x.com) already exists."
    ));
    expect($d['message'])->not->toContain('jane@x.com')
        ->and($d['message'])->toContain('already exists');

    $d = ExceptionDescriber::describe(pdoErro(
        'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type integer: "abc"'
    ));
    expect($d['message'])->not->toContain('abc')
        ->and($d['message'])->toContain('invalid input syntax');

    $d = ExceptionDescriber::describe(pdoErro(
        "DETAIL:  Failing row contains (1, jane@x.com, null)."
    ));
    expect($d['message'])->not->toContain('jane@x.com');
});

it('scrubs values from a SQL Server unique key message', function () {
    $d = ExceptionDescriber::describe(pdoErro(
        "SQLSTATE[23000]: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Violation of UNIQUE KEY constraint 'UQ_users_email'. Cannot insert duplicate key in object 'dbo.users'. The duplicate key value is (jane@x.com)."
    ));
    expect($d['message'])->not->toContain('jane@x.com')
        ->and($d['message'])->toContain('Violation of UNIQUE KEY constraint');
});

it('scrubs numeric literals after value, = and :', function () {
    $d = ExceptionDescriber::describe(pdoErro('Out of range value 98765 for column; id=4242; total: 31337'));
    expect($d['message'])->not->toContain('98765')
        ->and($d['message'])->not->toContain('4242')
        ->and($d['message'])->not->toContain('31337');
});

it('scrubs a PDOException in the previous chain, including the trace', function () {
    $pdo = pdoErro("SQLSTATE[23000]: Duplicate entry 'jane@x.com' for key 'users.email'");
    $e = new RuntimeException('falha ao salvar', 0, $pdo);
    $d = ExceptionDescriber::describe($e);
    expect($d['message'])->toBe('falha ao salvar')
        ->and($d['trace'])->toContain('Caused by: PDOException')
        ->and($d['trace'])->not->toContain('jane@x.com');
});

it('keeps application (non-PDO) messages unchanged', function () {
    $msg = "Usuário 'jane@x.com' não encontrado: id=42";
    $d = ExceptionDescriber::describe(new RuntimeException($msg));
    expect($d['message'])->toBe($msg);

    $d = ExceptionDescriber::describe(new RuntimeException('externa', 0, new LogicException($msg)));
    expect($d['trace'])->toContain($msg);
});

it('scrubs quoted values from fatal messages', function () {
    $d = ExceptionDescriber::fatal([
        'type' => E_ERROR,
        'message' => "Uncaught PDOException: SQLSTATE[23000]: Duplicate entry 'jane@x.com' for key 'users.email' in /a.php:3\nStack trace:\n#0 {main}",
        'file' => '/a.php',
        'line' => 3,
    ]);
    expect($d['message'])->not->toContain('jane@x.com')
        ->and($d['message'])->toContain('Uncaught PDOException');
});

it('stores no message at all when the flag is off', function () {
    $e = new RuntimeException('segredo-app', 0, pdoErro("Duplicate entry 'jane@x.com'", new LogicException('segredo-interno')));
    $d = ExceptionDescriber::describe($e, false);
    expect($d['message'])->toBe('')
        ->and($d['trace'])->toContain('Caused by: PDOException')
        ->and($d['trace'])->toContain('Caused by: LogicException')
        ->and($d['trace'])->not->toContain('segredo')
        ->and($d['trace'])->not->toContain('jane@x.com');

    $f = ExceptionDescriber::fatal(['type' => E_ERROR, 'message' => 'boom secreto', 'file' => '/a.php', 'line' => 1], false);
    expect($f['message'])->toBe('');
});

it('scrubs pathological input in linear time without throwing', function () {
    $casos = [
        str_repeat("'", 1_000_000),
        str_repeat("'a", 500_000),
        "Duplicate entry 'jane@x.com sem fim " . str_repeat('x', 1_000_000),
        "ruim \xC3\x28 'segredo \xFF\xFE" . str_repeat("\xFF", 100_000),
        str_repeat(')=(', 300_000),
        str_repeat('=1', 500_000),
    ];
    $inicio = microtime(true);
    foreach ($casos as $msg) {
        $limpa = ExceptionDescriber::scrubMessage($msg);
        expect(mb_check_encoding($limpa, 'UTF-8'))->toBeTrue();
        $d = ExceptionDescriber::describe(pdoErro($msg));
        expect(mb_strlen($d['message']))->toBeLessThanOrEqual(500)
            ->and(mb_check_encoding($d['message'], 'UTF-8'))->toBeTrue();
    }
    expect(microtime(true) - $inicio)->toBeLessThan(1.0)
        ->and(ExceptionDescriber::scrubMessage("Duplicate entry 'jane@x.com sem fim"))->not->toContain('jane')
        ->and(ExceptionDescriber::scrubMessage("x 'segredo \xFF"))->not->toContain('segredo');
});

// --- listas do PostgreSQL/SQL Server: até o último ")" ou até o fim ---

it('scrubs a PostgreSQL failing row larger than the scrub input cap', function () {
    $msg = "SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"x\" violates not-null constraint\n"
        . 'DETAIL:  Failing row contains (1, jane@x.com, ' . str_repeat('texto-longo ', 400) . 'segredo-final, null).';
    expect(strlen($msg))->toBeGreaterThan(4800);
    $d = ExceptionDescriber::describe(pdoErro($msg));
    expect($d['message'])->not->toContain('jane@x.com')
        ->and($d['message'])->not->toContain('texto-longo')
        ->and($d['message'])->toContain('Failing row contains (?');
});

it('scrubs list values that contain a newline', function () {
    $casos = [
        "DETAIL:  Failing row contains (1, jane@x.com, Rua A\nApto 3, null).",
        "DETAIL:  Key (email)=(jane@x.com\nx) already exists.",
        "DETAIL:  Key (a, b)=(1, x\ny) already exists.",
        "The duplicate key value is (jane@x.com\nApto 3).",
    ];
    foreach ($casos as $msg) {
        $limpa = ExceptionDescriber::scrubMessage($msg);
        expect($limpa)->not->toContain('jane@x.com')
            ->and($limpa)->not->toContain('Apto 3')
            ->and($limpa)->not->toContain('x\ny')
            ->and($limpa)->not->toMatch('/\by\)/');
    }
    expect(ExceptionDescriber::scrubMessage("DETAIL:  Key (a, b)=(1, x\ny) already exists."))
        ->toBe('DETAIL:  Key (a, b)=(?) already exists.');
});

it('scrubs an unclosed list opener up to the end of the message', function () {
    expect(ExceptionDescriber::scrubMessage('DETAIL:  Failing row contains (1, jane@x.com, Rua A'))
        ->toBe('DETAIL:  Failing row contains (?')
        ->and(ExceptionDescriber::scrubMessage("Key (email)=(jane@x.com\nApto 3"))
        ->toBe('Key (email)=(?');
});

it('scrubs multiline list values in the previous chain and in fatal messages', function () {
    $pdo = pdoErro("SQLSTATE[23505]: ERROR:  duplicate key\nDETAIL:  Key (email)=(jane@x.com\nApto 3) already exists.");
    $d = ExceptionDescriber::describe(new RuntimeException('falha ao salvar', 0, $pdo));
    expect($d['trace'])->toContain('Caused by: PDOException')
        ->and($d['trace'])->not->toContain('jane@x.com')
        ->and($d['trace'])->not->toContain('Apto 3');

    $f = ExceptionDescriber::fatal([
        'type' => E_ERROR,
        'message' => "Uncaught PDOException: DETAIL:  Failing row contains (1, jane@x.com, Rua A\nApto 3, null). in /a.php:3\nStack trace:\n#0 {main}",
        'file' => '/a.php',
        'line' => 3,
    ]);
    expect($f['message'])->not->toContain('jane@x.com')
        ->and($f['message'])->not->toContain('Apto 3')
        ->and($f['message'])->toContain('Uncaught PDOException');
});

// --- MySQL: valores com apóstrofo ---

it('scrubs MySQL values that contain an apostrophe', function () {
    $casos = [
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'O'Brien' for key 'users.name'",
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'O\\'Brien' for key 'users.name'",
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'O'Brien' for key 'x' for key 'users.name'",
        "SQLSTATE[HY000]: General error: 1366 Incorrect string value: 'O'Brien' for column 'nome' at row 1",
        "SQLSTATE[HY000]: General error: 1292 Incorrect datetime value: 'O\\'Brien' for column 'dt' at row 1",
        "SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect DOUBLE value: 'O'Brien'",
    ];
    foreach ($casos as $msg) {
        $limpa = ExceptionDescriber::scrubMessage($msg);
        expect($limpa)->not->toContain('Brien');
        $d = ExceptionDescriber::describe(new RuntimeException('externa', 0, pdoErro($msg)));
        expect($d['trace'])->not->toContain('Brien');
        $f = ExceptionDescriber::fatal(['type' => E_ERROR, 'message' => "Uncaught PDOException: $msg in /a.php:3", 'file' => '/a.php', 'line' => 3]);
        expect($f['message'])->not->toContain('Brien');
    }
    expect(ExceptionDescriber::scrubMessage("Duplicate entry 'O'Brien' for key 'users.name'"))
        ->toBe("Duplicate entry '?' for key '?'")
        ->and(ExceptionDescriber::scrubMessage("Incorrect integer value: 'a'b' for column 'n' at row 1"))
        ->toBe("Incorrect integer value: '?' for column '?' at row 1");
});

it('keeps the generic quoted rule for doubled and escaped quotes', function () {
    expect(ExceptionDescriber::scrubMessage("erro em 'O''Brien' aqui"))->toBe("erro em '?' aqui")
        ->and(ExceptionDescriber::scrubMessage("erro em 'O\\'Brien' aqui"))->toBe("erro em '?' aqui")
        ->and(ExceptionDescriber::scrubMessage('erro em "a""b" e "c\\"d" aqui'))->toBe('erro em "?" e "?" aqui')
        ->and(ExceptionDescriber::scrubMessage("Data truncated for column 'nome' at row 1"))->toBe("Data truncated for column '?' at row 1")
        ->and(ExceptionDescriber::scrubMessage("Data too long for column 'nome' at row 1"))->toBe("Data too long for column '?' at row 1");
});

it('scrubs list and anchored pathological input in linear time', function () {
    $casos = [
        str_repeat('(', 1_000_000),
        str_repeat('value is (', 100_000),
        str_repeat(')=(a', 250_000),
        str_repeat('row contains (', 70_000) . str_repeat(')', 100_000),
        str_repeat("Duplicate entry '", 60_000),
        str_repeat("value: 'x' for column ", 45_000),
        str_repeat("\\'", 500_000),
        str_repeat("''", 500_000),
    ];
    $inicio = microtime(true);
    foreach ($casos as $msg) {
        $limpa = ExceptionDescriber::scrubMessage($msg);
        expect(mb_check_encoding($limpa, 'UTF-8'))->toBeTrue();
        $d = ExceptionDescriber::describe(pdoErro($msg));
        expect(mb_strlen($d['message']))->toBeLessThanOrEqual(500);
    }
    expect(microtime(true) - $inicio)->toBeLessThan(1.0);
});

// --- SQL Server: conversão; PostgreSQL: aspas duplas internas ---

it('scrubs SQL Server conversion values that contain an apostrophe', function () {
    foreach (['varchar', 'nvarchar'] as $tipo) {
        $msg = "SQLSTATE[22018]: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Conversion failed when converting the $tipo value 'O'Brien-secret' to data type int.";
        expect(ExceptionDescriber::scrubMessage($msg))
            ->toEndWith("Conversion failed when converting the $tipo value '?' to data type int.");
        $d = ExceptionDescriber::describe(new RuntimeException('externa', 0, pdoErro($msg)));
        expect($d['trace'])->not->toContain('Brien');
        $f = ExceptionDescriber::fatal(['type' => E_ERROR, 'message' => "Uncaught PDOException: $msg in /a.php:3", 'file' => '/a.php', 'line' => 3]);
        expect($f['message'])->not->toContain('Brien');
    }
    // valor contendo a própria âncora: redigir demais é aceitável, vazar não
    $limpa = ExceptionDescriber::scrubMessage("Conversion failed when converting the varchar value 'a' to data type b-secret' to data type int.");
    expect($limpa)->not->toContain('secret');
});

it('scrubs PostgreSQL double-quoted values with inner double quotes up to the last quote of the line', function () {
    $limpa = ExceptionDescriber::scrubMessage("ERROR:  invalid input syntax for type integer: \"a\"bsecret\"\nLINE 1: SELECT 'x'");
    expect($limpa)->toBe("ERROR:  invalid input syntax for type integer: \"?\"\nLINE 1: SELECT '?'")
        ->and(ExceptionDescriber::scrubMessage('ERROR:  invalid input syntax for type integer: "jane@x.com"'))
        ->toBe('ERROR:  invalid input syntax for type integer: "?"');
    $d = ExceptionDescriber::describe(new RuntimeException('externa', 0, pdoErro("ERROR:  invalid input syntax for type integer: \"a\"bsecret\"")));
    expect($d['trace'])->not->toContain('bsecret');
});

it('scrubs conversion and colon-quote pathological input in linear time', function () {
    $casos = [
        str_repeat("value 'x' to data type ", 45_000),
        str_repeat("value '", 150_000),
        str_repeat(': "', 350_000),
        str_repeat(": \"a\"b\n", 150_000),
    ];
    $inicio = microtime(true);
    foreach ($casos as $msg) {
        $limpa = ExceptionDescriber::scrubMessage($msg);
        expect(mb_check_encoding($limpa, 'UTF-8'))->toBeTrue();
        $d = ExceptionDescriber::describe(pdoErro($msg));
        expect(mb_strlen($d['message']))->toBeLessThanOrEqual(500);
    }
    expect(microtime(true) - $inicio)->toBeLessThan(1.0);
});
