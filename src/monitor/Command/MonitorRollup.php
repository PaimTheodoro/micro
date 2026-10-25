<?php

namespace Psf\Monitor\Command;

use Psf\Database\Dialect\DialectFactory;
use Psf\Monitor\Config;
use Psf\Monitor\Connection;
use Psf\Monitor\Rollup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `monitor:rollup [-e banco] [--max-hours N]`: agrega por hora os registros brutos
 * em `psf_monitor_rollups` (`Rollup::run`). Valida `--max-hours` antes de escrever.
 * Saída: `Horas agregadas`, `Registros gravados`, `Rollup até` e, se houver, `Horas puladas`.
 */
class MonitorRollup extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('monitor:rollup')
            ->setDescription('Agrega por hora os registros brutos do monitor')
            ->addOption(
                'environment',
                'e',
                InputOption::VALUE_REQUIRED,
                "Chave do banco em config['db'] (padrão: banco configurado para o monitor)"
            )
            ->addOption(
                'max-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Máximo de horas agregadas por execução',
                '48'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $maxHours = CommandOptions::maxHours($input->getOption('max-hours'));
            $opt = $input->getOption('environment');
            $db = is_string($opt) && $opt !== '' ? $opt : Config::database();
            $dbConfig = \PSF::config("db")[$db] ?? null;
            if (!is_array($dbConfig)) {
                throw new \RuntimeException("Banco '{$db}' não encontrado em config['db'].");
            }
            $rollup = new Rollup(Connection::open($db), DialectFactory::fromConfig($dbConfig));
            $now = new \DateTimeImmutable("now", new \DateTimeZone("UTC"));
            $result = $rollup->run($now, $maxHours);
            $output->writeln("Horas agregadas: " . $result['hours']);
            $output->writeln("Registros gravados: " . $result['rows']);
            $output->writeln("Rollup até: " . ($result['watermark'] ?? 'nunca'));
            RollupReport::skipped($output, $result['skipped']);
            return Command::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Falha ao agregar o monitor: ' . $e->getMessage() . \Psf\Monitor\MissingTable::suffix($e) . '</error>');
            return Command::FAILURE;
        }
    }
}
