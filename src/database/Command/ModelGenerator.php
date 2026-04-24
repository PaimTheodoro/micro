<?php

namespace Psf\Database\Command;

use Phinx\Console\Command\AbstractCommand;
use Phinx\Db\Table\Column;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ModelGenerator extends AbstractCommand
{
    protected static $defaultName = 'make:model-from-table';

    protected function configure(): void
    {
        $this
            ->setDescription('Gera um modelo PSF (com PHP 8 Attributes) a partir de uma tabela do banco de dados')
            ->addArgument('table', InputArgument::REQUIRED, 'Nome da tabela no banco de dados')
            ->addArgument('class', InputArgument::REQUIRED, 'Nome completo da classe do modelo (ex: App\\Models\\User\\User)')
            ->setHelp('Gera um modelo PHP usando #[Table], #[Column], #[PrimaryKey] e outros Attributes do PSF.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $tableName = $input->getArgument('table');
        $className = $input->getArgument('class');

        try {
            $adapter = $this->getConfig()
                            ->getEnvironment($this->getConfig()->getDefaultEnvironment())
                            ->getAdapter();

            $columns = $adapter->getColumns($tableName);

            if (empty($columns)) {
                $io->error("Tabela '{$tableName}' não encontrada no banco de dados.");
                return 1;
            }

            $modelCode = $this->generateModelCode($tableName, $className, $columns);
            $filePath  = $this->getModelFilePath($className);

            $directory = dirname($filePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

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

    /**
     * Gera o código PHP do modelo usando PHP 8 Attributes do PSF.
     * Cada coluna vira uma propriedade com #[Column], #[Type], e atributos especiais
     * quando detectados (PrimaryKey, ColumnCreatedDate, ColumnUpdatedDate, Nullable).
     *
     * @param Column[] $columns
     */
    public function generateModelCode(string $tableName, string $className, array $columns): string
    {
        $namespace = $this->getNamespaceFromClassName($className);
        $shortName = $this->getShortClassName($className);

        $usedAttributes = ['Table', 'Column', 'PrimaryKey', 'Type'];
        $properties     = [];

        foreach ($columns as $column) {
            $colName  = $column->getName();
            $propName = $this->snakeToCamel($colName);
            $phpType  = $this->mapColumnToPhpType($column);
            $sqlType  = $this->mapColumnToSqlType($column);
            $lcName   = strtolower($colName);

            $lines = [];

            if ($column->isIdentity()) {
                $lines[] = '    #[PrimaryKey]';
            }

            $lines[] = "    #[Column('{$colName}')]";
            $lines[] = "    #[Type('{$sqlType}')]";

            if (str_contains($lcName, 'created')) {
                $lines[] = '    #[ColumnCreatedDate]';
                $usedAttributes[] = 'ColumnCreatedDate';
            } elseif (str_contains($lcName, 'updated') || str_contains($lcName, 'modified')) {
                $lines[] = '    #[ColumnUpdatedDate]';
                $usedAttributes[] = 'ColumnUpdatedDate';
            }

            if (!$column->isNull()) {
                $lines[] = '    #[Nullable(false)]';
                $usedAttributes[] = 'Nullable';
            }

            $lines[] = "    public ?{$phpType} \${$propName} = null;";
            $properties[] = implode("\n", $lines);
        }

        $uses = implode(', ', array_unique($usedAttributes));
        $body = implode("\n\n", $properties);

        return <<<PHP
        <?php

        namespace {$namespace};

        use Psf\Model\Model;
        use Psf\Model\Attributes\{{$uses}};

        #[Table('{$tableName}')]
        class {$shortName} extends Model
        {
        {$body}
        }
        PHP;
    }

    /** Converte snake_case para camelCase. Ex.: created_at → createdAt */
    public function snakeToCamel(string $snake): string
    {
        return lcfirst(str_replace('_', '', ucwords($snake, '_')));
    }

    /** Mapeia o tipo Phinx de uma coluna para o tipo PHP equivalente. */
    public function mapColumnToPhpType(Column $column): string
    {
        $type = $column->getType();

        if ($type === 'boolean') return 'bool';
        if ($type === 'tinyinteger' && $column->getLimit() === 1) return 'bool';
        if (in_array($type, ['integer', 'biginteger', 'tinyinteger', 'smallinteger'])) return 'int';
        if (in_array($type, ['float', 'decimal', 'double'])) return 'float';

        return 'string';
    }

    /**
     * Gera a string de tipo SQL para uso no #[Type(...)].
     * Inclui tamanho, UNSIGNED, NULL/NOT NULL e AUTO_INCREMENT quando aplicável.
     */
    public function mapColumnToSqlType(Column $column): string
    {
        static $typeMap = [
            'string'      => 'VARCHAR',
            'integer'     => 'INT',
            'biginteger'  => 'BIGINT',
            'tinyinteger' => 'TINYINT',
            'smallinteger'=> 'SMALLINT',
            'boolean'     => 'TINYINT(1)',
            'float'       => 'FLOAT',
            'decimal'     => 'DECIMAL',
            'double'      => 'DOUBLE',
            'date'        => 'DATE',
            'datetime'    => 'DATETIME',
            'timestamp'   => 'TIMESTAMP',
            'time'        => 'TIME',
            'text'        => 'TEXT',
            'uuid'        => 'VARCHAR(36)',
            'json'        => 'JSON',
            'blob'        => 'BLOB',
        ];

        $sqlBase = $typeMap[$column->getType()] ?? strtoupper($column->getType());

        $noLimit = ['boolean', 'text', 'blob', 'json', 'date', 'datetime', 'timestamp', 'time'];
        if (!in_array($column->getType(), $noLimit) && $column->getLimit() !== null && !str_contains($sqlBase, '(')) {
            $sqlBase .= '(' . $column->getLimit() . ')';
        }

        if (!$column->isSigned() && in_array($column->getType(), ['integer', 'biginteger', 'tinyinteger', 'smallinteger'])) {
            $sqlBase .= ' UNSIGNED';
        }

        $sqlBase .= $column->isNull() ? ' NULL' : ' NOT NULL';

        if ($column->isIdentity()) {
            $sqlBase .= ' AUTO_INCREMENT';
        }

        return $sqlBase;
    }

    private function getNamespaceFromClassName(string $className): string
    {
        $parts = explode('\\', $className);
        array_pop($parts);
        return implode('\\', $parts);
    }

    private function getShortClassName(string $className): string
    {
        return basename(str_replace('\\', '/', $className));
    }

    private function getModelFilePath(string $className): string
    {
        $path = str_replace('\\', '/', $className);
        if (str_starts_with($path, 'App/')) {
            $path = substr($path, 4);
        }
        return ROOT . '/app/models/' . $path . '.php';
    }
}
