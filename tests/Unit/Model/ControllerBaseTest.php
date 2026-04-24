<?php

use Psf\Model\ControllerBase;

// Helper: instancia um ControllerBase anônimo sem chamar o __construct original
// (que depende de filter_input/apache_request_headers do contexto HTTP).
// Os métodos isGet/isPost/isPut/isDelete só leem $this->method, que setamos diretamente.
function makeController(string $method): ControllerBase
{
    $ctrl = new class extends ControllerBase {
        public function __construct() {}
    };
    $ctrl->method = $method;
    return $ctrl;
}

// --- isGet ---

it('isGet returns true when method is GET', function () {
    expect(makeController('GET')->isGet())->toBeTrue();
});

it('isGet returns false for non-GET methods', function () {
    expect(makeController('POST')->isGet())->toBeFalse();
    expect(makeController('PUT')->isGet())->toBeFalse();
    expect(makeController('DELETE')->isGet())->toBeFalse();
});

// --- isPost ---

it('isPost returns true when method is POST', function () {
    expect(makeController('POST')->isPost())->toBeTrue();
});

it('isPost returns false for non-POST methods', function () {
    expect(makeController('GET')->isPost())->toBeFalse();
    expect(makeController('PUT')->isPost())->toBeFalse();
});

// --- isPut ---

it('isPut returns true when method is PUT', function () {
    expect(makeController('PUT')->isPut())->toBeTrue();
});

it('isPut returns false for non-PUT methods', function () {
    expect(makeController('GET')->isPut())->toBeFalse();
    expect(makeController('POST')->isPut())->toBeFalse();
});

// --- isDelete ---

it('isDelete returns true when method is DELETE', function () {
    expect(makeController('DELETE')->isDelete())->toBeTrue();
});

it('isDelete returns false for non-DELETE methods', function () {
    expect(makeController('GET')->isDelete())->toBeFalse();
    expect(makeController('POST')->isDelete())->toBeFalse();
});

// --- Case insensitivity ---

it('method comparison is case-insensitive', function () {
    expect(makeController('get')->isGet())->toBeTrue();
    expect(makeController('Get')->isGet())->toBeTrue();
    expect(makeController('post')->isPost())->toBeTrue();
    expect(makeController('delete')->isDelete())->toBeTrue();
});

// --- Exclusividade ---

it('only one isX method returns true at a time', function () {
    $ctrl = makeController('POST');

    expect($ctrl->isGet())->toBeFalse();
    expect($ctrl->isPost())->toBeTrue();
    expect($ctrl->isPut())->toBeFalse();
    expect($ctrl->isDelete())->toBeFalse();
});
