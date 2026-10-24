<?php

use Psf\Database\{Connect, Create};
use Psf\Model\ControllerBase;

function noteCount(): int
{
    return (int) Connect::getConnection('default')->query('SELECT COUNT(*) FROM fake_notes')->fetchColumn();
}

it('rolls back and keeps the cached connection', function () {
    $pdo = Connect::initTransaction('default');
    Create::exe('fake_notes', ['title' => 'a'], 'default');
    expect(Connect::inTransactionQuery('default'))->toBeTrue();

    Connect::rollBackTransaction('default');

    expect(noteCount())->toBe(0);
    expect(Connect::getConnect('default'))->toBe($pdo);
});

it('commits and keeps the cached connection (and the :memory: database)', function () {
    $pdo = Connect::initTransaction('default');
    Create::exe('fake_notes', ['title' => 'a'], 'default');

    Connect::commitTransaction('default');

    expect(noteCount())->toBe(1);
    expect(Connect::getConnect('default'))->toBe($pdo);
});

it('tolerates commit/rollback without an active transaction or connection', function () {
    Connect::commitTransaction('default');
    Connect::rollBackTransaction('default');

    Connect::$connect = null;
    Connect::commitTransaction('default');
    Connect::rollBackTransaction('default');

    expect(Connect::inTransactionQuery('default'))->toBeFalse();
});

it('runServiceTransaction commits on success and rolls back on an error status', function () {
    $controller = new class extends ControllerBase {
        public function __construct() {}
    };

    $controller->runServiceTransaction(function () {
        Create::exe('fake_notes', ['title' => 'ok'], 'default');
        return ['ok', null, 200];
    });
    expect(noteCount())->toBe(1);

    $controller->runServiceTransaction(function () {
        Create::exe('fake_notes', ['title' => 'falha'], 'default');
        return ['erro', null, 422];
    });
    expect(noteCount())->toBe(1);
});

it('runServiceTransaction rolls back and rethrows when the action throws', function () {
    $controller = new class extends ControllerBase {
        public function __construct() {}
    };

    expect(fn() => $controller->runServiceTransaction(function () {
        Create::exe('fake_notes', ['title' => 'a'], 'default');
        throw new RuntimeException('falhou');
    }))->toThrow(RuntimeException::class, 'falhou');

    expect(Connect::inTransactionQuery('default'))->toBeFalse();
    expect(noteCount())->toBe(0);
});
