<?php

namespace Psf\Database\Command;

use Phinx\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ModelGenerator extends AbstractCommand
{
    protected static $defaultName = 'make:model-from-table';

    protected function configure()
    {
        $this
            ->setDescription('Gera um modelo PHP a partir de uma tabela do banco de dados')
            ->addArgument('table', InputArgument::REQUIRED, 'Nome da tabela no banco de dados')
            ->addArgument('class', InputArgument::REQUIRED, 'Nome completo da classe do modelo (ex: App\\Models\\User\\User)')
            ->setHelp('Este comando gera um modelo PHP baseado na estrutura de uma tabela existente no banco de dados.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $tableName = $input->getArgument('table');
        $className = $input->getArgument('class');

        try {
            // Obtém a configuração do banco de dados
            $config = $this->getConfig();
            $environment = $config->getEnvironment($config->getDefaultEnvironment());
            
            // Conecta ao banco de dados
            $adapter = $environment->getAdapter();
            
            // Obtém a estrutura da tabela
            $columns = $adapter->getColumns($tableName);
            
            if (empty($columns)) {
                $io->error("Tabela '{$tableName}' não encontrada no banco de dados.");
                return 1;
            }

            // Gera o código do modelo
            $modelCode = $this->generateModelCode($tableName, $className, $columns);
            
            // Determina o caminho do arquivo
            $filePath = $this->getModelFilePath($className);
            
            // Cria o diretório se não existir
            $directory = dirname($filePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            
            // Escreve o arquivo
            if (file_put_contents($filePath, $modelCode) === false) {
                $io->error("Erro ao escrever o arquivo: {$filePath}");
                return 1;
            }

            $io->success("Modelo gerado com sucesso: {$filePath}");
            return 0;

        } catch (\Exception $e) {
            $io->error("Erro: " . $e->getMessage());
            return 1;
        }
    }

    private function generateModelCode($tableName, $className, $columns)
    {
        $namespace = $this->getNamespaceFromClassName($className);
        $shortClassName = $this->getShortClassName($className);
        
        $attributes = [];
        $types = [];
        
        foreach ($columns as $column) {
            $name = $column['name'];
            $type = $this->mapDatabaseTypeToPhpType($column['type']);
            $nullable = $column['null'] ? '?' : '';
            
            $attributes[] = "    public {$nullable}{$type} \${$name};";
            $types[] = "        '{$name}' => '{$type}',";
        }

        $attributesCode = implode("\n", $attributes);
        $typesCode = implode("\n", $types);

        return "<?php

namespace {$namespace};

use Psf\\Model\\Model;

/**
 * Modelo gerado automaticamente para a tabela '{$tableName}'
 * 
 * @property int \$id
" . $this->generatePropertyComments($columns) . "
 */
class {$shortClassName} extends Model
{
    protected \$table = '{$tableName}';
    
    protected \$fillable = [
" . $this->generateFillableArray($columns) . "
    ];
    
    protected \$casts = [
" . $typesCode . "
    ];
    
{$attributesCode}
}";
    }

    private function getNamespaceFromClassName($className)
    {
        $parts = explode('\\', $className);
        array_pop($parts); // Remove o nome da classe
        return implode('\\', $parts);
    }

    private function getShortClassName($className)
    {
        $parts = explode('\\', $className);
        return end($parts);
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

    private function generatePropertyComments($columns)
    {
        $comments = [];
        foreach ($columns as $column) {
            $name = $column['name'];
            $type = $this->mapDatabaseTypeToPhpType($column['type']);
            $nullable = $column['null'] ? '|null' : '';
            $comments[] = " * @property {$type}{$nullable} \${$name}";
        }
        return implode("\n", $comments);
    }

    private function generateFillableArray($columns)
    {
        $fillable = [];
        foreach ($columns as $column) {
            $name = $column['name'];
            if ($name !== 'id') { // Exclui o ID do fillable
                $fillable[] = "        '{$name}',";
            }
        }
        return implode("\n", $fillable);
    }

    private function getModelFilePath($className)
    {
        // Converte o namespace em caminho de arquivo
        $path = str_replace('\\', '/', $className);
        
        // Remove o prefixo 'App/' e adiciona o caminho correto
        if (strpos($path, 'App/') === 0) {
            $path = substr($path, 4); // Remove 'App/'
        }
        
        // Adiciona a extensão .php
        $path .= '.php';
        
        // Retorna o caminho completo a partir da raiz do projeto
        return ROOT . '/app/models/' . $path;
    }
} 