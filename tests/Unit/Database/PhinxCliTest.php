<?php

use Phinx\Console\PhinxApplication;
use Psf\Database\PhinxCli;

// ---------------------------------------------------------------------------
// resolveProjectRoot: PSF_PROJECT_ROOT > ROOT > fallback
// (constantes não podem ser desfeitas no processo do Pest: os valores são injetados)
// ---------------------------------------------------------------------------

it('prefers the consumer project root defined by bin/phinx over ROOT', function () {
    expect(PhinxCli::resolveProjectRoot('/app', '/app/vendor/psf/framework', '/fallback'))->toBe('/app');
});

it('falls back to ROOT when PSF_PROJECT_ROOT is not defined', function () {
    expect(PhinxCli::resolveProjectRoot(null, '/var/www', '/fallback'))->toBe('/var/www');
    expect(PhinxCli::resolveProjectRoot('', '/var/www', '/fallback'))->toBe('/var/www');
});

it('uses the fallback when neither constant is defined', function () {
    expect(PhinxCli::resolveProjectRoot(null, null, '/fallback'))->toBe('/fallback');
    expect(PhinxCli::resolveProjectRoot(null, '', '/fallback'))->toBe('/fallback');
});

it('drops a trailing separator from the resolved root', function () {
    expect(PhinxCli::resolveProjectRoot('/app/', null, '/fallback'))->toBe('/app');
});

it('reads the constants of the current process', function () {
    // No processo do Pest só ROOT existe (settings.php), então ele vence o fallback
    $expected = defined('PSF_PROJECT_ROOT') ? constant('PSF_PROJECT_ROOT') : ROOT;

    expect(PhinxCli::projectRoot('/fallback'))->toBe(rtrim($expected, '/\\'));
});

// ---------------------------------------------------------------------------
// defaultConfigFile / applyDefaultConfig: o Phinx só procura phinx.* no cwd
// ---------------------------------------------------------------------------

function phinxCliTempDir(): string
{
    $dir = sys_get_temp_dir() . '/psf-phinxcli-' . uniqid('', true);
    mkdir($dir);

    return $dir;
}

it('points Phinx to the framework config when the cwd has no phinx config', function () {
    $cwd = phinxCliTempDir();

    expect(PhinxCli::defaultConfigFile($cwd, '/fw/phinx.php'))->toBe('/fw/phinx.php');

    rmdir($cwd);
});

it('keeps the project own phinx config when the cwd has one', function (string $file) {
    $cwd = phinxCliTempDir();
    touch($cwd . '/' . $file);

    expect(PhinxCli::defaultConfigFile($cwd, '/fw/phinx.php'))->toBeNull();

    unlink($cwd . '/' . $file);
    rmdir($cwd);
})->with(['phinx.php', 'phinx.json', 'phinx.yaml', 'phinx.yml']);

it('sets the default of the global --configuration option of the Phinx application', function () {
    $app = new PhinxApplication();

    PhinxCli::applyDefaultConfig($app, '/fw/phinx.php');

    expect($app->getDefinition()->getOption('configuration')->getDefault())->toBe('/fw/phinx.php');
});

it('loads the framework config in a Phinx command run from a cwd without phinx config', function () {
    $app = new PhinxApplication();
    $app->setAutoExit(false);
    PhinxCli::applyDefaultConfig($app, '/nao/existe/phinx.php');

    $output = new \Symfony\Component\Console\Output\BufferedOutput();
    $app->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'status']), $output);

    // O Phinx tentou abrir o arquivo padrão, e não procurar phinx.yml no cwd
    expect($output->fetch())->toContain('/nao/existe/phinx.php');
});

it('does nothing on an application without the --configuration option', function () {
    $app = new \Symfony\Component\Console\Application();

    PhinxCli::applyDefaultConfig($app, '/fw/phinx.php');

    expect($app->getDefinition()->hasOption('configuration'))->toBeFalse();
});
