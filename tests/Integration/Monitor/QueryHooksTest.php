<?php

use Psf\Database\{Connect, Create, Delete, Read, Update};
use Psf\Http\HttpResponseException;
use Psf\Monitor\{Config, Monitor};

/*
| Ganchos de query nos shims de banco (Read/Create/Update/Delete).
| Usa o SQLite :memory: do harness de Integration (tests/Pest.php).
*/

beforeEach(function () {
    Monitor::reset();
    Config::fake(['enabled' => true]);
});

afterEach(function () {
    Monitor::reset();
    Config::reset();
});

it('records a read query with its connection', function () {
    Connect::getConnection('default')->exec("INSERT INTO fake_users (name, email) VALUES ('Ana', 'ana@x.com')");

    $read = (new Read)->exe('fake_users', 'WHERE id = :id', 'id=1');
    expect($read->getResult())->toHaveCount(1);

    $queries = Monitor::events()['queries'];
    expect($queries)->toHaveCount(1);
    expect($queries[0]['sql'])->toContain('fake_users');
    expect($queries[0]['connection'])->toBe('default');
    expect($queries[0]['duration_ms'])->toBeGreaterThanOrEqual(0);
});

it('records create, create batch, update and delete statements', function () {
    $create = Create::exe('fake_users', ['name' => 'Ana', 'email' => 'ana@x.com']);
    expect($create->getResult())->toBe('1');

    $batch = Create::batch('fake_users', [
        ['name' => 'Bia', 'email' => 'bia@x.com'],
        ['name' => 'Caio', 'email' => 'caio@x.com'],
    ]);
    expect($batch->getResult())->toBe(2);

    $update = Update::exe('fake_users', ['name' => 'Ana Maria'], 'WHERE id = :id', 'id=1');
    expect($update->getResult())->toBeTrue();
    expect($update->getRowCount())->toBe(1);

    $delete = Delete::exe('fake_users', 'WHERE id = :id', 'id=2');
    expect($delete->getResult())->toBeTrue();
    expect($delete->getRowCount())->toBe(1);

    $queries = Monitor::events()['queries'];
    expect($queries)->toHaveCount(4);

    $prefixes = ['INSERT', 'INSERT', 'UPDATE', 'DELETE'];
    foreach ($prefixes as $i => $prefix) {
        expect(str_starts_with(strtoupper(ltrim($queries[$i]['sql'])), $prefix))->toBeTrue();
        expect($queries[$i]['sql'])->toContain('fake_users');
        expect($queries[$i]['connection'])->toBe('default');
    }
});

it('records each chunk of a batch insert as one statement', function () {
    $rows = [];
    for ($i = 0; $i < 501; $i++) {
        $rows[] = ['name' => "u{$i}", 'email' => "u{$i}@x.com"];
    }

    expect(Create::batch('fake_users', $rows)->getResult())->toBe(501);

    $queries = Monitor::events()['queries'];
    expect($queries)->toHaveCount(2);
    expect(str_starts_with($queries[0]['sql'], 'INSERT'))->toBeTrue();
    expect(str_starts_with($queries[1]['sql'], 'INSERT'))->toBeTrue();
});

/*
| SQLite valida colunas já no prepare(); para falhar no execute() usa-se um erro
| de tempo de execução (overflow inteiro em abs()).
*/
const QUERY_HOOKS_RUNTIME_FAILURE = 'WHERE abs(-9223372036854775807 - 1) > 0';

it('records a failing read once, with its exception, and keeps the original behaviour', function () {
    expect(fn () => (new Read)->exe('fake_users', QUERY_HOOKS_RUNTIME_FAILURE))
        ->toThrow(HttpResponseException::class);

    $events = Monitor::events();
    expect($events['queries'])->toHaveCount(1);
    expect($events['queries'][0]['sql'])->toContain('abs(');
    expect($events['exceptions'])->toHaveCount(1);
    expect($events['exceptions'][0]['throwable'])->toBeInstanceOf(PDOException::class);
});

it('records a failing create once without recording the rethrown exception', function () {
    expect(fn () => Create::exe('fake_users', ['name' => 'Sem email']))
        ->toThrow(PDOException::class);

    $events = Monitor::events();
    expect($events['queries'])->toHaveCount(1);
    expect(str_starts_with($events['queries'][0]['sql'], 'INSERT'))->toBeTrue();
    expect($events['exceptions'])->toBe([]);
});

it('records a failing batch chunk once without recording the rethrown exception', function () {
    expect(fn () => Create::batch('fake_users', [['name' => 'Sem email', 'email' => null]]))
        ->toThrow(PDOException::class);

    $events = Monitor::events();
    expect($events['queries'])->toHaveCount(1);
    expect($events['exceptions'])->toBe([]);
});

it('records a failing update once, with its exception, and keeps the original behaviour', function () {
    Connect::getConnection('default')->exec("INSERT INTO fake_users (name, email) VALUES ('Ana', 'ana@x.com')");

    expect(fn () => Update::exe('fake_users', ['name' => null], 'WHERE id = 1'))
        ->toThrow(HttpResponseException::class);

    $events = Monitor::events();
    expect($events['queries'])->toHaveCount(1);
    expect(str_starts_with($events['queries'][0]['sql'], 'UPDATE'))->toBeTrue();
    expect($events['exceptions'])->toHaveCount(1);
    expect($events['exceptions'][0]['throwable'])->toBeInstanceOf(PDOException::class);
});

it('records a failing delete once, with its exception, and keeps the original behaviour', function () {
    expect(fn () => Delete::exe('fake_users', QUERY_HOOKS_RUNTIME_FAILURE))
        ->toThrow(HttpResponseException::class);

    $events = Monitor::events();
    expect($events['queries'])->toHaveCount(1);
    expect(str_starts_with($events['queries'][0]['sql'], 'DELETE'))->toBeTrue();
    expect($events['exceptions'])->toHaveCount(1);
});

it('does not record a statement that fails in prepare()', function () {
    // Comportamento original: Read::exe() prepara fora do try, então o PDOException escapa.
    expect(fn () => (new Read)->exe('fake_users', 'WHERE coluna_inexistente = 1'))
        ->toThrow(PDOException::class);

    expect(Monitor::events()['queries'])->toBe([]);
});

it('is inert when the monitor is disabled', function () {
    Config::fake(['enabled' => false]);

    $create = Create::exe('fake_users', ['name' => 'Ana', 'email' => 'ana@x.com']);
    expect($create->getResult())->toBe('1');
    expect(Create::batch('fake_users', [['name' => 'Bia', 'email' => 'bia@x.com']])->getResult())->toBe(1);
    expect((new Read)->exe('fake_users', 'WHERE id = :id', 'id=1')->getResult()[0]['name'])->toBe('Ana');
    expect(Update::exe('fake_users', ['name' => 'Ana Maria'], 'WHERE id = 1')->getResult())->toBeTrue();
    expect(Delete::exe('fake_users', 'WHERE id = 2')->getResult())->toBeTrue();
    expect(fn () => (new Read)->exe('fake_users', QUERY_HOOKS_RUNTIME_FAILURE))
        ->toThrow(HttpResponseException::class);

    expect(Monitor::events())->toBe([]);

    Config::fake(['enabled' => true]);
    expect(Monitor::events()['queries'])->toBe([]);
});

it('does not instrument schema introspection', function () {
    Connect::clearColumnsCache();
    Connect::getColunsForTable('fake_users', 'default');
    Connect::listTables('default', true);

    expect(Monitor::events()['queries'])->toBe([]);
    expect(Monitor::events()['exceptions'])->toBe([]);
});
