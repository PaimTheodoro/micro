<?php

namespace Psf\Database\Command;

use Phinx\Console\Command\AbstractCommand;
use Psf\Model\MetadataCache;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class TableAnalyzer extends AbstractCommand
{
    protected static $defaultName = 'analyze:table-vs-model';

    protected function configure(): void
    {
        $this
            ->setDescription('Analisa uma tabela do banco de dados e compara com um modelo existente')
            ->addArgument('table', InputArgument::REQUIRED, 'Nome da tabela no banco de dados')
            ->addArgument('model', InputArgument::REQUIRED, 'Nome completo da classe do modelo (ex: App\\Models\\User\\User)')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Arquivo de saída para salvar o relatório', null)
            ->setHelp('Este comando analisa uma tabela do banco de dados e compara com um modelo PHP existente, identificando diferenças e sugerindo correções.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tableName = $input->getArgument('table');
        $modelClass = $input->getArgument('model');
        $outputFile = $input->getOption('output');

        try {
            // Obtém a configuração do banco de dados
            $config = $this->getConfig();
            $environment = $config->getEnvironment($config->getDefaultEnvironment());
            
            // Conecta ao banco de dados
            $adapter = $environment->getAdapter();
            
            // Obtém a estrutura da tabela
            $tableColumns = $adapter->getColumns($tableName);
            
            if (empty($tableColumns)) {
                $io->error("Tabela '{$tableName}' não encontrada no banco de dados.");
                return 1;
            }

            // Obtém a estrutura do modelo
            $modelColumns = $this->getModelColumns($modelClass);
            
            if (empty($modelColumns)) {
                $io->error("Modelo '{$modelClass}' não encontrado ou não pôde ser analisado.");
                return 1;
            }

            // Analisa as diferenças
            $analysis = $this->analyzeDifferences($tableColumns, $modelColumns, $tableName, $modelClass);
            
            // Exibe o relatório
            $this->displayReport($io, $analysis);
            
            // Salva o relatório se solicitado
            if ($outputFile) {
                $this->saveReport($outputFile, $analysis);
                $io->success("Relatório salvo em: {$outputFile}");
            }

            return 0;

        } catch (\Exception $e) {
            $io->error("Erro: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Retorna as colunas do modelo usando o mapeamento ORM real (#[Column] attributes).
     * Indexado por nome de coluna do banco (não pelo nome da propriedade PHP).
     * Retorna ['column_name' => ['name' => ..., 'property' => ..., 'type' => ..., 'null' => bool]]
     */
    private function getModelColumns(string $modelClass): array
    {
        if (!class_exists($modelClass)) {
            throw new \Exception("Classe '{$modelClass}' não encontrada.");
        }

        $columnMap = MetadataCache::getColumnMap($modelClass);

        if (empty($columnMap)) {
            throw new \Exception("Modelo '{$modelClass}' não possui #[Column] attributes — verifique se é um Model PSF válido.");
        }

        $columns = [];
        foreach ($columnMap as $propName => $colName) {
            $columns[$colName] = [
                'name'     => $colName,
                'property' => $propName,
                'type'     => $this->getPropertyPhpType($modelClass, $propName),
                'null'     => MetadataCache::isNullable($modelClass, $propName),
            ];
        }

        return $columns;
    }

    private function getPropertyPhpType(string $modelClass, string $propName): string
    {
        try {
            $prop = new \ReflectionProperty($modelClass, $propName);
            $type = $prop->getType();
            if ($type instanceof \ReflectionNamedType) {
                return $type->getName();
            }
        } catch (\ReflectionException $e) {
            // ignora — retorna string como fallback
        }
        return 'string';
    }

    private function analyzeDifferences(array $tableColumns, array $modelColumns, string $tableName, string $modelClass): array
    {
        $analysis = [
            'table_name'       => $tableName,
            'model_class'      => $modelClass,
            'missing_in_model' => [],
            'missing_in_table' => [],
            'type_mismatches'  => [],
            'suggestions'      => [],
        ];

        // Indexa colunas do banco por nome para lookup O(1)
        $dbColumnsByName = [];
        foreach ($tableColumns as $col) {
            $dbColumnsByName[$col->getName()] = $col;
        }

        // Colunas que existem na tabela mas não no modelo (usa #[Column] mapping)
        foreach ($dbColumnsByName as $colName => $col) {
            if (!isset($modelColumns[$colName])) {
                $analysis['missing_in_model'][] = [
                    'name' => $colName,
                    'type' => $col->getType(),
                    'null' => $col->isNull(),
                ];
            }
        }

        // Colunas que existem no modelo mas não na tabela
        foreach ($modelColumns as $colName => $modelCol) {
            if (!isset($dbColumnsByName[$colName])) {
                $analysis['missing_in_table'][] = [
                    'name'     => $colName,
                    'property' => $modelCol['property'],
                    'type'     => $modelCol['type'],
                ];
            }
        }

        // Incompatibilidades de tipo (coluna existe em ambos)
        foreach ($modelColumns as $colName => $modelCol) {
            if (!isset($dbColumnsByName[$colName])) continue;

            $dbCol      = $dbColumnsByName[$colName];
            $tablePhpType = $this->mapDatabaseTypeToPhpType($dbCol->getType());
            $modelPhpType = $modelCol['type'];

            if ($tablePhpType !== $modelPhpType) {
                $analysis['type_mismatches'][] = [
                    'name'           => $colName,
                    'property'       => $modelCol['property'],
                    'table_type'     => $dbCol->getType(),
                    'model_type'     => $modelPhpType,
                    'suggested_type' => $tablePhpType,
                ];
            }
        }

        $analysis['suggestions'] = $this->generateSuggestions($analysis);

        return $analysis;
    }

    private function mapDatabaseTypeToPhpType($databaseType)
    {
        $type = strtolower($databaseType);
        
        if (str_contains($type, 'int')) {
            return 'int';
        } elseif (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
            return 'float';
        } elseif (str_contains($type, 'bool')) {
            return 'bool';
        } elseif (str_contains($type, 'date') || str_contains($type, 'time')) {
            return 'string';
        } else {
            return 'string';
        }
    }

    private function generateSuggestions($analysis)
    {
        $suggestions = [];

        // Sugestões para colunas faltantes no modelo
        if (!empty($analysis['missing_in_model'])) {
            $suggestions[] = "// Adicione as seguintes propriedades ao modelo:";
            foreach ($analysis['missing_in_model'] as $column) {
                $type = $this->mapDatabaseTypeToPhpType($column['type']);
                $nullable = $column['null'] ? '?' : '';
                $suggestions[] = "public {$nullable}{$type} \${$column['name']};";
            }
        }

        // Sugestões para incompatibilidades de tipo
        if (!empty($analysis['type_mismatches'])) {
            $suggestions[] = "// Corrija os tipos das seguintes propriedades:";
            foreach ($analysis['type_mismatches'] as $mismatch) {
                $suggestions[] = "// {$mismatch['name']}: altere de '{$mismatch['model_type']}' para '{$mismatch['suggested_type']}'";
            }
        }

        return $suggestions;
    }

    private function displayReport($io, $analysis)
    {
        $io->title("Análise: {$analysis['table_name']} vs {$analysis['model_class']}");

        // Colunas faltantes no modelo
        if (!empty($analysis['missing_in_model'])) {
            $io->section("Colunas faltantes no modelo:");
            foreach ($analysis['missing_in_model'] as $column) {
                $io->text("• {$column['name']} ({$column['type']})");
            }
        }

        // Colunas faltantes na tabela
        if (!empty($analysis['missing_in_table'])) {
            $io->section("Colunas faltantes na tabela:");
            foreach ($analysis['missing_in_table'] as $column) {
                $io->text("• {$column['name']} ({$column['type']})");
            }
        }

        // Incompatibilidades de tipo
        if (!empty($analysis['type_mismatches'])) {
            $io->section("Incompatibilidades de tipo:");
            foreach ($analysis['type_mismatches'] as $mismatch) {
                $io->text("• {$mismatch['name']}: tabela={$mismatch['table_type']}, modelo={$mismatch['model_type']}");
            }
        }

        // Sugestões
        if (!empty($analysis['suggestions'])) {
            $io->section("Sugestões:");
            foreach ($analysis['suggestions'] as $suggestion) {
                $io->text($suggestion);
            }
        }

        // Resumo
        $totalIssues = count($analysis['missing_in_model']) + 
                      count($analysis['missing_in_table']) + 
                      count($analysis['type_mismatches']);

        if ($totalIssues === 0) {
            $io->success("Nenhuma diferença encontrada! A tabela e o modelo estão sincronizados.");
        } else {
            $io->warning("Encontradas {$totalIssues} diferença(s) entre a tabela e o modelo.");
        }
    }

    private function saveReport($filename, $analysis)
    {
        $content = "# Relatório de Análise: {$analysis['table_name']} vs {$analysis['model_class']}\n\n";
        
        if (!empty($analysis['missing_in_model'])) {
            $content .= "## Colunas faltantes no modelo:\n";
            foreach ($analysis['missing_in_model'] as $column) {
                $content .= "- {$column['name']} ({$column['type']})\n";
            }
            $content .= "\n";
        }

        if (!empty($analysis['missing_in_table'])) {
            $content .= "## Colunas faltantes na tabela:\n";
            foreach ($analysis['missing_in_table'] as $column) {
                $content .= "- {$column['name']} ({$column['type']})\n";
            }
            $content .= "\n";
        }

        if (!empty($analysis['type_mismatches'])) {
            $content .= "## Incompatibilidades de tipo:\n";
            foreach ($analysis['type_mismatches'] as $mismatch) {
                $content .= "- {$mismatch['name']}: tabela={$mismatch['table_type']}, modelo={$mismatch['model_type']}\n";
            }
            $content .= "\n";
        }

        if (!empty($analysis['suggestions'])) {
            $content .= "## Sugestões:\n";
            foreach ($analysis['suggestions'] as $suggestion) {
                $content .= "{$suggestion}\n";
            }
        }

        file_put_contents($filename, $content);
    }
} 
