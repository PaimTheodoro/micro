<?php

// O Composer liga vendor/bin/<nome> ao primeiro pacote que declarar o nome; `phinx` conflita com o
// robmorgan/phinx, então o wrapper também é exposto como `psf`.

it('declares both bins in composer.json', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);

    expect($composer['bin'])->toContain('bin/phinx')->toContain('bin/psf');
});

it('ships bin/psf as an executable delegate of bin/phinx', function () {
    $file = dirname(__DIR__, 3) . '/bin/psf';

    expect(file_exists($file))->toBeTrue();
    expect(is_executable($file))->toBeTrue();

    $contents = (string) file_get_contents($file);
    expect($contents)->toStartWith("#!/usr/bin/env php\n")
        ->and($contents)->toContain("require __DIR__ . '/phinx';");
});

it('does not recommend vendor/bin/phinx for PSF commands in README and docs', function () {
    $root = dirname(__DIR__, 3);
    $files = array_merge([$root . '/README.md'], glob($root . '/docs/*.md') ?: []);
    $offenders = [];

    foreach ($files as $file) {
        if (basename($file) === 'melhorias.md') {
            continue; // histórico de versões
        }
        foreach (file($file) as $n => $line) {
            if (preg_match('#vendor/bin/phinx\s+(monitor:|make:|analyze:)#', $line)) {
                $offenders[] = basename($file) . ':' . ($n + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
