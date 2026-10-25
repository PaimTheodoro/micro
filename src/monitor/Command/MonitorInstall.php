<?php

namespace Psf\Monitor\Command;

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\Config;
use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Psf\Monitor\Pruner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `monitor:install [-e banco]`: instala as tabelas do monitor (migrations de
 * `src/monitor/migrations`, idempotente) via `Installer`.
 * Saída: `Tabelas do monitor instaladas.`; falha: linha `<error>` e exit code 1.
 */
class MonitorInstall extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('monitor:install')
            ->setDescription('Instala as tabelas do monitor de observabilidade')
            ->addOption(
                'environment',
                'e',
                InputOption::VALUE_REQUIRED,
                "Chave do banco em config['db'] (padrão: banco configurado para o monitor)"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $opt = $input->getOption('environment');
            $db = is_string($opt) && $opt !== '' ? $opt : Config::database();
            (new Installer($db))->install();
            $output->writeln("Tabelas do monitor instaladas.");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Falha ao instalar o monitor: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
