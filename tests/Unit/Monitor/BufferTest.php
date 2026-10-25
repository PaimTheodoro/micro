<?php

use Psf\Monitor\Buffer;

it('keeps queries up to the cap and counts the rest as dropped', function () {
    $b = new Buffer(3);
    for ($i = 0; $i < 5; $i++) {
        $b->addQuery("SELECT $i", 1.0, 'default', 100 + $i);
    }
    expect(count($b->queries()))->toBe(3)
        ->and($b->droppedEvents())->toBe(2)
        ->and($b->queries()[0])->toBe(['sql' => 'SELECT 0', 'duration_ms' => 1.0, 'connection' => 'default', 'ts' => 100]);
});

it('tracks the slowest query even when it was dropped', function () {
    $b = new Buffer(1);
    $b->addQuery('SELECT 1', 10.0, 'default', 1);
    $b->addQuery('SELECT 2', 450.5, 'default', 2);
    expect($b->maxQueryMs())->toBe(450.5)
        ->and(count($b->queries()))->toBe(1)
        ->and($b->queries()[0]['sql'])->toBe('SELECT 1');
});

it('cuts a huge SQL safely', function () {
    $b = new Buffer(5);
    $b->addQuery(str_repeat('é', 20000), 1.0, 'default', 1);
    $sql = $b->queries()[0]['sql'];
    expect(mb_strlen($sql))->toBe(Buffer::RAW_SQL_MAX)
        ->and(mb_check_encoding($sql, 'UTF-8'))->toBeTrue();
});

it('does not throw on invalid UTF-8 SQL', function () {
    $b = new Buffer(5);
    $b->addQuery(str_repeat("\xff\xfe", 10000), 1.0, 'default', 1);
    expect(is_string($b->queries()[0]['sql']))->toBeTrue()
        ->and(mb_check_encoding($b->queries()[0]['sql'], 'UTF-8'))->toBeTrue();
});

it('caps exceptions and counts the overflow', function () {
    $b = new Buffer(5);
    expect($b->hasExceptions())->toBeFalse();
    $results = [];
    for ($i = 0; $i < 25; $i++) {
        $results[] = $b->addException(['n' => $i]);
    }
    expect(array_slice($results, 0, 20))->each->toBeTrue()
        ->and(array_slice($results, 20))->each->toBeFalse()
        ->and($b->droppedEvents())->toBe(5)
        ->and($b->hasExceptions())->toBeTrue()
        ->and(count($b->exceptions()))->toBe(20);
});

it('treats a cap below 1 as 1', function () {
    $b = new Buffer(0);
    $b->addQuery('a', 1.0, 'c', 1);
    $b->addQuery('b', 1.0, 'c', 2);
    expect(count($b->queries()))->toBe(1)->and($b->droppedEvents())->toBe(1);
});

it('is memory-bounded for a 10000 query loop', function () {
    $b = new Buffer(200);
    for ($i = 0; $i < 10000; $i++) {
        $b->addQuery('SELECT 1', 1.0, 'default', $i);
    }
    expect(count($b->queries()))->toBe(200)->and($b->droppedEvents())->toBe(9800);
});

it('always stores a fatal, even past the exception cap, without counting a drop', function () {
    $b = new Buffer(5);
    for ($i = 0; $i < 20; $i++) {
        $b->addException(['n' => $i]);
    }
    expect($b->addException(['n' => 20]))->toBeFalse();

    $b->addFatal(['fatal' => ['type' => E_ERROR]]);

    expect(count($b->exceptions()))->toBe(Buffer::EXCEPTION_CAP + 1)
        ->and($b->exceptions()[20])->toBe(['fatal' => ['type' => E_ERROR]])
        ->and($b->droppedEvents())->toBe(1)
        ->and($b->hasExceptions())->toBeTrue();
});

it('bounds the work on a 5 MB SQL to a prefix', function () {
    $sql = str_repeat("SELECT é \xFF ", 500_000); // ~6 MB, com UTF-8 inválido
    $buffer = new Buffer(100);

    $inicio = microtime(true);
    for ($i = 0; $i < 20; $i++) {
        $buffer->addQuery($sql, 1.0, 'default', 0);
    }
    $tempo = microtime(true) - $inicio;

    $guardado = $buffer->queries()[0]['sql'];
    expect($tempo)->toBeLessThan(0.05)
        ->and(mb_strlen($guardado, 'UTF-8'))->toBeLessThanOrEqual(Buffer::RAW_SQL_MAX)
        ->and(mb_check_encoding($guardado, 'UTF-8'))->toBeTrue();
});

it('keeps an SQL of exactly RAW_SQL_MAX characters intact', function () {
    $ascii = str_repeat('a', Buffer::RAW_SQL_MAX);
    $multi = str_repeat('€', Buffer::RAW_SQL_MAX); // 3 bytes por caractere
    $buffer = new Buffer(10);
    $buffer->addQuery($ascii, 1.0, 'default', 0);
    $buffer->addQuery($multi, 1.0, 'default', 0);
    $buffer->addQuery($multi . 'x', 1.0, 'default', 0);

    expect($buffer->queries()[0]['sql'])->toBe($ascii)
        ->and($buffer->queries()[1]['sql'])->toBe($multi)
        ->and($buffer->queries()[2]['sql'])->toBe($multi);
});
