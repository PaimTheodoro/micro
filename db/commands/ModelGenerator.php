<?php

namespace Psf\Database\Command;

use Phinx\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use PDO;

class ModelGenerator extends AbstractCommand{
    protected function configure(){
        $this->setName('make:model-from-table')
             ->setDescription('Generates a Model class from an existing database table')
             ->addArgument('table', InputArgument::REQUIRED, 'The table name to generate the model from')
             ->addArgument('namespace', InputArgument::REQUIRED, 'The namespace for the generated model (e.g., App\\Models\\User)')
             ->addOption('output-dir', 'o', InputOption::VALUE_OPTIONAL, 'Output directory for the generated model', 'app/models')
             ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing model file');
    }

    protected function execute(InputInterface $input, OutputInterface $output){
        $tableName = $input->getArgument('table');
        $namespace = $input->getArgument('namespace');
        $outputDir = $input->getOption('output-dir');
        $force = $input->getOption('force');

        $output->writeln("<info>Generating model for table: {$tableName}</info>");

        // Obter o adaptador Phinx para o ambiente atual
        $environment = $this->getManager()->getEnvironment($this->getManager()->getDefaultEnvironment());
        /** @var \Phinx\Db\Adapter\AdapterInterface $adapter */
        $adapter = $environment->getAdapter();

        if (!$adapter->hasTable($tableName)) {
            $output->writeln("<error>Table '{$tableName}' does not exist in the database.</error>");
            return self::CODE_ERROR;
        }

        // Obter informações da tabela
        $columns = $adapter->getColumns($tableName);
        $indexes = $adapter->getIndexes($tableName);
        $foreignKeys = $adapter->getForeignKeys($tableName);

        // Gerar o código do modelo
        $modelCode = $this->generateModelCode($tableName, $namespace, $columns, $indexes, $foreignKeys);

        // Determinar o nome da classe e caminho do arquivo
        $className = $this->getClassNameFromNamespace($namespace);
        $filePath = $this->getFilePath($outputDir, $namespace, $className);

        // Verificar se o arquivo já existe
        if (file_exists($filePath) && !$force) {
            $output->writeln("<error>Model file already exists: {$filePath}</error>");
            $output->writeln("Use --force option to overwrite.");
            return self::CODE_ERROR;
        }

        // Criar diretório se não existir
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Escrever o arquivo
        if (file_put_contents($filePath, $modelCode) === false) {
            $output->writeln("<error>Could not write model file to: {$filePath}</error>");
            return self::CODE_ERROR;
        }

        $output->writeln("<info>Model generated successfully: {$filePath}</info>");
        $output->writeln("<comment>Don't forget to add the model to your autoloader if needed.</comment>");

        return self::CODE_SUCCESS;
    }

    private function generateModelCode(string $tableName, string $namespace, array $columns, array $indexes, array $foreignKeys): string{
        $className = $this->getClassNameFromNamespace($namespace);
        $properties = [];
        $imports = [
            'use \\Psf\\Model\\{Model, ModelTrait};',
            'use \\Psf\\Model\\Attributes\\{Column, Table, PrimaryKey, Type, Standard, Enum, Nullable, ColumnCreatedDate, ColumnUpdatedDate, ColumnDeletedDate, Database};'
        ];

        // Identificar chaves primárias
        $primaryKeys = [];
        foreach ($indexes as $index) {
            if ($index['type'] === 'PRIMARY') {
                $primaryKeys = $index['columns'];
                break;
            }
        }

        // Identificar colunas de timestamp padrão
        $timestampColumns = ['created', 'updated', 'deleted', 'created_at', 'updated_at', 'deleted_at'];

        foreach ($columns as $column) {
            $propertyName = $this->camelCase($column->getName());
            $columnName = $column->getName();
            $columnType = $this->getPhpTypeFromDbType($column);
            $attributes = [];

            // Atributo Column
            $attributes[] = "Column('{$columnName}')";

            // Primary Key
            if (in_array($columnName, $primaryKeys)) {
                $attributes[] = 'PrimaryKey';
            }

            // Type
            $sqlType = $this->getSqlTypeDefinition($column);
            if ($sqlType) {
                $attributes[] = "Type('{$sqlType}')";
            }

            // Nullable
            if ($column->isNull()) {
                $attributes[] = 'Nullable';
            }

            // Timestamp columns
            if (in_array($columnName, $timestampColumns)) {
                if (str_contains($columnName, 'created')) {
                    $attributes[] = 'ColumnCreatedDate';
                } elseif (str_contains($columnName, 'updated')) {
                    $attributes[] = 'ColumnUpdatedDate';
                } elseif (str_contains($columnName, 'deleted')) {
                    $attributes[] = 'ColumnDeletedDate';
                }
            }

            // Foreign Keys
            foreach ($foreignKeys as $fk) {
                if (in_array($columnName, $fk['columns'])) {
                    $referencedTable = $fk['referenced_table'];
                    $referencedColumn = $fk['referenced_columns'][0];
                    
                    // Tentar inferir o tipo baseado na tabela referenciada
                    if ($referencedColumn === 'id') {
                        $attributes[] = "Type('int')";
                    }
                    break;
                }
            }

            $attributesStr = implode(', ', $attributes);
            $properties[] = "    #[{$attributesStr}]";
            $properties[] = "    public \${$propertyName};";
            $properties[] = "";
        }

        $propertiesStr = implode("\n", $properties);

        return <<<PHP
<?php

namespace {$namespace};

{$imports[0]}
{$imports[1]}

#[Table('{$tableName}')]
class {$className} extends Model{
    use ModelTrait;

{$propertiesStr}
    // TODO: Add your business logic methods here
    
    // Example methods:
    // public static function findByEmail(string \$email): ?self{
    //     return self::find()
    //         ->andWhere([self::class . '.email' => \$email])
    //         ->one();
    // }
}
PHP;
    }

    private function getClassNameFromNamespace(string $namespace): string{
        $parts = explode('\\', $namespace);
        return end($parts);
    }

    private function getFilePath(string $outputDir, string $namespace, string $className): string{
        $namespaceParts = explode('\\', $namespace);
        array_shift($namespaceParts); // Remove 'App'
        
        $relativePath = implode('/', $namespaceParts);
        return rtrim($outputDir, '/') . '/' . $relativePath . '/' . $className . '.php';
    }

    private function camelCase(string $string): string{
        // Converter snake_case para camelCase
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
    }

    private function getPhpTypeFromDbType($column): string{
        $type = strtolower($column->getType());
        
        $typeMap = [
            'integer' => 'int',
            'biginteger' => 'int',
            'string' => 'string',
            'text' => 'string',
            'datetime' => 'string',
            'timestamp' => 'string',
            'date' => 'string',
            'time' => 'string',
            'boolean' => 'bool',
            'decimal' => 'float',
            'float' => 'float',
            'blob' => 'string'
        ];

        return $typeMap[$type] ?? 'string';
    }

    private function getSqlTypeDefinition($column): string{
        $type = strtolower($column->getType());
        $limit = $column->getLimit();
        
        $typeMap = [
            'integer' => 'int',
            'biginteger' => 'bigint',
            'string' => 'varchar',
            'text' => 'text',
            'datetime' => 'datetime',
            'timestamp' => 'timestamp',
            'date' => 'date',
            'time' => 'time',
            'boolean' => 'tinyint(1)',
            'decimal' => 'decimal',
            'float' => 'float',
            'blob' => 'blob'
        ];

        $sqlType = $typeMap[$type] ?? $type;

        if ($limit && $type === 'string') {
            $sqlType .= "({$limit})";
        }

        if ($limit && $type === 'decimal') {
            $precision = $column->getPrecision() ?? 10;
            $scale = $column->getScale() ?? 2;
            $sqlType .= "({$precision},{$scale})";
        }

        return $sqlType;
    }
} 