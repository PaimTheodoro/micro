<?php

namespace Psf\Monitor\Command;

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\Config;
use Psf\Monitor\Connection;
use Psf\Monitor\Installer;
use Psf\Monitor\Watermark;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `monitor:status [-e banco]`: diagnóstico do monitor.
 * Saída: `Banco`, `Instalado` (as 5 tabelas existem), `Monitor ligado` e
 * `Rollup até` (watermark; `nunca` se ausente ou ilegível).
 */
class MonitorStatus extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('monitor:status')
            ->setDescription('Mostra o estado do monitor de observabilidade')
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
            $installer = new Installer($db);
            $output->writeln("Banco: " . $installer->database());
            $output->writeln("Instalado: " . ($installer->isInstalled() ? "sim" : "não"));
            $output->writeln("Monitor ligado: " . (Config::enabled() ? "sim" : "não"));
            $output->writeln("Rollup até: " . ($this->watermark($db) ?? "nunca"));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Falha ao consultar o monitor: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    /** Watermark do rollup; qualquer falha de leitura vira null ("nunca") */
    private function watermark(string $db): ?string
    {
        try {
            $dbConfig = \PSF::config("db")[$db] ?? null;
            if (!is_array($dbConfig)) {
                return null;
            }
            return (new Watermark(Connection::open($db), DialectFactory::fromConfig($dbConfig)))->read();
        } catch (\Throwable) {
            return null;
        }
    }
}
