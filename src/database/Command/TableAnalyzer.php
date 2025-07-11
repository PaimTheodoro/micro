<?php

namespace Psf\Database\Command;

use Phinx\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class TableAnalyzer extends AbstractCommand
{
    protected static $defaultName = 'analyze:table-vs-model';

    protected function configure()
    {
        $this
            ->setDescription('Analisa uma tabela do banco de dados e compara com um modelo existente')
            ->addArgument('table', InputArgument::REQUIRED, 'Nome da tabela no banco de dados')
            ->addArgument('model', InputArgument::REQUIRED, 'Nome completo da classe do modelo (ex: App\\Models\\User\\User)')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Arquivo de saída para salvar o relatório', null)
            ->setHelp('Este comando analisa uma tabela do banco de dados e compara com um modelo PHP existente, identificando diferenças e sugerindo correções.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
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

    private function getModelColumns($modelClass)
    {
        // Verifica se a classe existe
        if (!class_exists($modelClass)) {
            throw new \Exception("Classe '{$modelClass}' não encontrada.");
        }

        // Cria uma instância do modelo para análise
        $model = new $modelClass();
        
        // Obtém as propriedades públicas da classe
        $reflection = new \ReflectionClass($model);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        
        $columns = [];
        foreach ($properties as $property) {
            $name = $property->getName();
            $type = $this->getPropertyType($property);
            
            $columns[$name] = [
                'name' => $name,
                'type' => $type,
                'null' => true, // Assume nullable por padrão
            ];
        }
        
        return $columns;
    }

    private function getPropertyType($property)
    {
        // Tenta obter o tipo do docblock
        $docComment = $property->getDocComment();
        if ($docComment) {
            if (preg_match('/@var\s+(\w+)/', $docComment, $matches)) {
                return $matches[1];
            }
        }
        
        // Tipo padrão
        return 'string';
    }

    private function analyzeDifferences($tableColumns, $modelColumns, $tableName, $modelClass)
    {
        $analysis = [
            'table_name' => $tableName,
            'model_class' => $modelClass,
            'missing_in_model' => [],
            'missing_in_table' => [],
            'type_mismatches' => [],
            'suggestions' => []
        ];

        // Colunas que existem na tabela mas não no modelo
        foreach ($tableColumns as $column) {
            $columnName = $column['name'];
            if (!isset($modelColumns[$columnName])) {
                $analysis['missing_in_model'][] = [
                    'name' => $columnName,
                    'type' => $column['type'],
                    'null' => $column['null']
                ];
            }
        }

        // Colunas que existem no modelo mas não na tabela
        foreach ($modelColumns as $column) {
            $columnName = $column['name'];
            $found = false;
            foreach ($tableColumns as $tableColumn) {
                if ($tableColumn['name'] === $columnName) {
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $analysis['missing_in_table'][] = [
                    'name' => $columnName,
                    'type' => $column['type']
                ];
            }
        }

        // Verifica incompatibilidades de tipo
        foreach ($tableColumns as $tableColumn) {
            $columnName = $tableColumn['name'];
            if (isset($modelColumns[$columnName])) {
                $modelColumn = $modelColumns[$columnName];
                $tableType = $this->mapDatabaseTypeToPhpType($tableColumn['type']);
                $modelType = $modelColumn['type'];
                
                if ($tableType !== $modelType) {
                    $analysis['type_mismatches'][] = [
                        'name' => $columnName,
                        'table_type' => $tableColumn['type'],
                        'model_type' => $modelType,
                        'suggested_type' => $tableType
                    ];
                }
            }
        }

        // Gera sugestões
        $analysis['suggestions'] = $this->generateSuggestions($analysis);

        return $analysis;
    }

    private function mapDatabaseTypeToPhpType($databaseType)
    {
        $type = strtolower($databaseType);
        
        if (strpos($type, 'int') !== false) {
            return 'int';
        } elseif (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
            return 'float';
        } elseif (strpos($type, 'bool') !== false) {
            return 'bool';
        } elseif (strpos($type, 'date') !== false || strpos($type, 'time') !== false) {
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