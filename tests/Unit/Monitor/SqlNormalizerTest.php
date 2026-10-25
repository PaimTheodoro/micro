<?php

use Psf\Monitor\SqlNormalizer;

// Contrato da tarefa (tabela do brief): entrada => saída esperada
dataset('contrato', [
    'literais básicos' => ["SELECT * FROM users WHERE id = 5 AND name = 'bob'", 'SELECT * FROM users WHERE id = ? AND name = ?'],
    'IN de inteiros' => ['WHERE id IN (1, 2, 3)', 'WHERE id IN (?)'],
    'IN de strings' => ["IN ('a','b')", 'IN (?)'],
    'IN de placeholders' => ['IN (:a, :b)', 'IN (?)'],
    'VALUES repetidos' => ["INSERT INTO t (a, b) VALUES (1, 'x'), (2, 'y')", 'INSERT INTO t (a, b) VALUES (?)'],
    'escape com aspas dobradas' => ["x = 'it''s'", 'x = ?'],
    'escape com barra' => ["x = 'it\\'s'", 'x = ?'],
    'identificadores preservados' => [
        'SELECT "user name", `order`, [dbo].[t1] FROM t1 WHERE t1.col2 = 3.14',
        'SELECT "user name", `order`, [dbo].[t1] FROM t1 WHERE t1.col2 = ?',
    ],
    'número negativo' => ['WHERE x = -5', 'WHERE x = ?'],
    'hexadecimal' => ['WHERE h = 0xDEADBEEF', 'WHERE h = ?'],
    'comentário de linha' => ["SELECT 1 -- senha 'x'", 'SELECT ?'],
    'comentário de bloco' => ["SELECT /* 'abc' */ 1", 'SELECT ?'],
    'literal com marcador de comentário' => ["WHERE a = '-- nao e comentario' AND b = 2", 'WHERE a = ? AND b = ?'],
    'dollar quote' => ['WHERE a = $$segredo$$', 'WHERE a = ?'],
    'E-string' => ["WHERE a = E'a\\'b'", 'WHERE a = ?'],
    'aspas não terminada' => ["WHERE a = 'abc", 'WHERE a = ?'],
    'espaços colapsados' => ["  SELECT   1 \n FROM   t  ", 'SELECT ? FROM t'],
    'entrada vazia' => ['', ''],
]);

it('normaliza conforme o contrato', function (string $sql, string $expected) {
    expect(SqlNormalizer::normalize($sql))->toBe($expected);
})->with('contrato');

it('não lança com UTF-8 inválido e não deixa os bytes inválidos', function () {
    $out = SqlNormalizer::normalize("SELECT '\xff' FROM t");
    expect($out)->not->toContain("\xff")
        ->and($out)->toBe('SELECT ? FROM t');

    $out = SqlNormalizer::normalize("SELECT col\xff\xfe FROM t");
    expect(preg_match('//u', $out))->toBe(1)
        ->and($out)->not->toContain("\xff");
});

it('limita a 2000 caracteres e processa 1 MB rapidamente', function () {
    $sql = 'SELECT ' . str_repeat('a = 1 AND ', 100000);
    $start = microtime(true);
    $out = SqlNormalizer::normalize($sql);
    $elapsed = microtime(true) - $start;

    expect(mb_strlen($out))->toBeLessThanOrEqual(2000)
        ->and($elapsed)->toBeLessThan(1.0)
        ->and($out)->not->toContain('1');
});

it('gera hash sha1 estável', function () {
    $a = SqlNormalizer::hash('SELECT ?');
    expect($a)->toMatch('/^[0-9a-f]{40}$/')
        ->and(SqlNormalizer::hash('SELECT ?'))->toBe($a)
        ->and(SqlNormalizer::hash('SELECT ? FROM t'))->not->toBe($a);
});

// Casos extras (além do contrato)
dataset('extras', [
    'subtração binária' => ['SELECT a - 5 FROM t', 'SELECT a - ? FROM t'],
    'sinal após parêntese e vírgula' => ['SELECT f(-1, +2.5)', 'SELECT f(?, ?)'],
    'notação científica' => ['WHERE x = 1e5 OR y = 2.5E-3 OR z = .5', 'WHERE x = ? OR y = ? OR z = ?'],
    'placeholder posicional do PostgreSQL' => ['WHERE a = $1 AND b = $2', 'WHERE a = $1 AND b = $2'],
    'placeholders mantidos' => ['WHERE a = ? AND b = :nome', 'WHERE a = ? AND b = :nome'],
    'dollar quote com tag' => ['SELECT $fn$ corpo; \'x\' $fn$, 1', 'SELECT ?, ?'],
    'dollar quote não terminado' => ['WHERE a = $$segredo', 'WHERE a = ?'],
    'cast do PostgreSQL' => ["SELECT '5'::int", 'SELECT ?::int'],
    'comentário dentro de identificador' => ['SELECT "a -- b", `c /* d` FROM t', 'SELECT "a -- b", `c /* d` FROM t'],
    'comentário de bloco não terminado' => ["SELECT a /* 'segredo' ", 'SELECT a'],
    'identificador com dígito e cifrão' => ['SELECT t1.col2, a$b FROM t2', 'SELECT t1.col2, a$b FROM t2'],
    'IN misto não colapsa' => ['WHERE a IN (1, b)', 'WHERE a IN (?, b)'],
    'IN com subquery' => ['WHERE a IN (SELECT id FROM t WHERE x = 3)', 'WHERE a IN (SELECT id FROM t WHERE x = ?)'],
    'NOT IN sem espaço' => ['WHERE a NOT IN(1,2)', 'WHERE a NOT IN (?)'],
    'VALUES com placeholders e cauda' => [
        'INSERT INTO t (a) VALUES (?), (?), (?) ON CONFLICT DO NOTHING',
        'INSERT INTO t (a) VALUES (?) ON CONFLICT DO NOTHING',
    ],
    'VALUES não terminado' => ["INSERT INTO t VALUES (1, 'x", 'INSERT INTO t VALUES (?)'],
    'prefixos N e X' => ["WHERE a = N'josé' AND b = X'FF'", 'WHERE a = ? AND b = ?'],
    'número colado em letras' => ['WHERE a = 123abc', 'WHERE a = ?'],
    'comentário MySQL com #' => ["SELECT a # senha 'x'\nFROM t", 'SELECT a FROM t'],
    'tabela temporária SQL Server' => ['SELECT * FROM #tmp', 'SELECT * FROM #tmp'],
    'subscript de array não é identificador' => ['SELECT arr[1] FROM t', 'SELECT arr[?] FROM t'],
    'identificador entre aspas não terminado' => ['SELECT "abc FROM t', 'SELECT ?'],
    'colchete com espaço inicial' => ['SELECT arr[ 5] FROM t', 'SELECT arr[ ?] FROM t'],
    'colchete com expressão' => ['SELECT arr[i + 12345] FROM t', 'SELECT arr[i + ?] FROM t'],
    'ARRAY com negativo' => ['SELECT ARRAY[ -7 ]', 'SELECT ARRAY[ ? ]'],
    'colchete com comparação' => ['SELECT x[a=99]', 'SELECT x[a=?]'],
    'colchete com espaço interno' => ['SELECT [col 1] FROM [dbo].[t1]', 'SELECT [col 1] FROM [dbo].[t1]'],
    'aspas duplas como valor (MySQL)' => ['WHERE n = "segredo"', 'WHERE n = ?'],
    'aspas duplas após LIKE' => ['WHERE n LIKE "ab%"', 'WHERE n LIKE ?'],
    'aspas duplas em IN' => ['WHERE x IN ("a","b")', 'WHERE x IN (?)'],
    'aspas duplas após <> e THEN' => ['SELECT CASE WHEN a <> "x" THEN "y" ELSE "z" END', 'SELECT CASE WHEN a <> ? THEN ? ELSE ? END'],
    'identificadores qualificados do PostgreSQL' => [
        'SELECT "t"."id" FROM "t" JOIN "u" ON "t"."id" = "u"."tid"',
        'SELECT "t"."id" FROM "t" JOIN "u" ON "t"."id" = "u"."tid"',
    ],
    'identificador com espaço' => ['SELECT "user name" FROM t', 'SELECT "user name" FROM t'],
    'comparação entre colunas sem qualificação' => ['WHERE "a" = "b"', 'WHERE "a" = ?'],
    'só comentário' => ['-- nada', ''],
    'só espaços' => ["  \n\t ", ''],
]);

it('normaliza casos extras', function (string $sql, string $expected) {
    expect(SqlNormalizer::normalize($sql))->toBe($expected);
})->with('extras');

it('não deixa literais sobreviverem em entradas patológicas e roda em tempo linear', function (string $sql) {
    $start = microtime(true);
    $out = SqlNormalizer::normalize($sql);
    expect(microtime(true) - $start)->toBeLessThan(1.0)
        ->and(strlen($out))->toBeLessThanOrEqual(8000)
        ->and($out)->not->toContain('segredo')
        ->and($out)->not->toContain('7');
})->with([
    'muitas aspas' => [str_repeat("x = 'segredo' AND ", 30000) . "'segredo"],
    'aspas escapadas' => ["'" . str_repeat("''segredo", 100000)],
    'muitos colchetes' => ['SELECT ' . str_repeat('[7', 100000) . " 'segredo'"],
    'muitos dollar quotes' => [str_repeat('x = $a$segredo$a$ AND ', 50000) . '$a$segredo'],
    'IN gigante' => ['WHERE a IN (' . str_repeat("'segredo', ", 30000) . "'segredo')"],
    'VALUES gigante' => ['INSERT INTO t VALUES ' . str_repeat("('segredo', 1), ", 20000) . "('segredo', 1)"],
    'parênteses abertos' => ['SELECT ' . str_repeat('(', 500000) . "'segredo'"],
    'comentários' => [str_repeat('/* segredo */', 80000)],
    'barra invertida' => ["SELECT '" . str_repeat('\\', 500001) . "segredo"],
]);

it('corta em 2000 caracteres sem quebrar caractere multibyte', function () {
    $out = SqlNormalizer::normalize('SELECT ' . str_repeat('ção_', 2000) . ' FROM t');
    expect(mb_strlen($out))->toBe(2000)
        ->and(preg_match('//u', $out))->toBe(1);
});
