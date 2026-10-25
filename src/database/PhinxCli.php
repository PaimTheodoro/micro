<?php

namespace Psf\Database;

use Symfony\Component\Console\Application;

/**
 * Apoio ao wrapper `bin/phinx` (raiz do projeto e arquivo de config do Phinx).
 *
 * `ROOT` (settings.php) vem de `DOCUMENT_ROOT`, que no CLI é vazio: ali ele vira o diretório
 * do próprio framework (`<projeto>/vendor/psf/framework` quando instalado como dependência).
 * Por isso o `bin/phinx` define `PSF_PROJECT_ROOT` com a raiz do projeto consumidor, e os
 * caminhos dos comandos (migrations, seeds, models gerados) passam por aqui.
 */
final class PhinxCli
{
    /** Arquivos que o Phinx procura no diretório atual quando não recebe `--configuration`. */
    private const PHINX_CONFIG_FILES = ['phinx.php', 'phinx.json', 'phinx.yaml', 'phinx.yml'];

    /** Raiz do projeto: `PSF_PROJECT_ROOT` (bin/phinx), senão `ROOT`, senão `$fallback` (padrão: cwd). */
    public static function projectRoot(?string $fallback = null): string
    {
        return self::resolveProjectRoot(
            defined('PSF_PROJECT_ROOT') ? (string) constant('PSF_PROJECT_ROOT') : null,
            defined('ROOT') ? (string) constant('ROOT') : null,
            $fallback ?? (string) getcwd()
        );
    }

    /** Resolução pura (testável): o primeiro valor não vazio, sem separador no fim. */
    public static function resolveProjectRoot(?string $projectRoot, ?string $root, string $fallback): string
    {
        foreach ([$projectRoot, $root] as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return rtrim($candidate, '/\\');
            }
        }

        return $fallback;
    }

    /**
     * Config padrão para os comandos do Phinx: o `phinx.php` do framework, a menos que o
     * diretório atual tenha a sua própria config (que o Phinx continua usando, como antes).
     * O Phinx 0.16 só procura a config no cwd (a constante PHINX_CONFIG_FILE não é lida).
     */
    public static function defaultConfigFile(string $cwd, string $frameworkConfig): ?string
    {
        foreach (self::PHINX_CONFIG_FILES as $file) {
            if (is_file(rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . $file)) {
                return null;
            }
        }

        return $frameworkConfig;
    }

    /**
     * Define o padrão da opção global `--configuration` da aplicação do Phinx (mesclada em
     * todos os comandos ao rodar); `-c` explícito continua valendo. No-op sem a opção.
     */
    public static function applyDefaultConfig(Application $app, string $configFile): void
    {
        $definition = $app->getDefinition();
        if ($definition->hasOption('configuration')) {
            $definition->getOption('configuration')->setDefault($configFile);
        }
    }
}
