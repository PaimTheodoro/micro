<?php

namespace Psf\Database\Command;

use Phinx\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Psf\Model\MetadataCache;
use PDO;

class ModelAwareMigration extends AbstractCommand{
    protected function configure(){
        $this->setName('make:migration-from-model')
             ->setDescription('Creates a new migration file by comparing a Model class with the current database state.')
             ->addArgument('model', InputArgument::REQUIRED, 'The fully qualified class name of the Model (e.g., App\\Models\\User).');
    }

    protected function execute(InputInterface $input, OutputInterface $output){
        $className = $input->getArgument('model');
        if (!class_exists($className)) {
            $output->writeln("<error>Model class '{$className}' not found.</error>");
            return self::CODE_ERROR;
        }

        $output->writeln("<info>Analyzing model: {$className}</info>");

        $tableName = MetadataCache::getTable($className);
        $dbName = MetadataCache::getDatabase($className);
        $columnsFromModel = MetadataCache::getColumnMap($className);

        // Obter o adaptador Phinx para o ambiente atual
        $environment = $this->getManager()->getEnvironment($this->getManager()->getDefaultEnvironment());
        /** @var \Phinx\Db\Adapter\AdapterInterface $adapter */
        $adapter = $environment->getAdapter();
        
        $tableExists = $adapter->hasTable($tableName);
        
        $upSql = '';
        $downSql = '';

        if (!$tableExists) {
            list($upSql, $downSql) = $this->generateCreateTableSql($tableName, $columnsFromModel, $className);
            $output->writeln("<comment>Table '{$tableName}' does not exist. Generating CREATE TABLE statement.</comment>");
            $migrationName = 'Create' . basename(str_replace('\\', '/', $className)) . 'Table';
        } else {
            list($upSql, $downSql) = $this->generateAlterTableSql($tableName, $columnsFromModel, $className, $adapter, $output);
            $migrationName = 'Update' . basename(str_replace('\\', '/', $className)) . 'Table';
        }

        if (empty($upSql)) {
            $output->writeln("<info>No database changes detected for model {$className}.</info>");
            return self::CODE_SUCCESS;
        }

        // Gerar o nome da migration
        // $migrationName = 'Create' . basename(str_replace('\\', '/', $className)) . 'Table';
        
        // Delegar a criação do arquivo para o comando 'create' do Phinx
        return $this->createMigrationFile($migrationName, $upSql, $downSql, $output);
    }
    
    private function generateCreateTableSql(string $tableName, array $columns, string $className): array{
        $sql = "CREATE TABLE `{$tableName}` (\n";
        
        foreach($columns as $prop => $colName) {
            // Procura pelo tipo da coluna no atributo.
            $definition = MetadataCache::getColumnType($className, $prop);

            // Se não encontrar, usa o padrão para retrocompatibilidade.
            if ($definition === null) {
                $definition = 'VARCHAR(255) NOT NULL';
            }

            $sql .= "  `{$colName}` {$definition},\n";
        }
        
        // Adicionar chaves primárias
        $primaryKeys = MetadataCache::getPrimaryKey($className);
        if (!empty($primaryKeys)) {
            $pkCols = '`' . implode('`, `', $primaryKeys) . '`';
            $sql .= "  PRIMARY KEY ({$pkCols}),\n";
        }
        
        $sql = rtrim($sql, ",\n") . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        $downSql = "DROP TABLE `{$tableName}`;";
        
        return [$sql, $downSql];
    }

    private function generateAlterTableSql(string $tableName, array $modelColumns, string $className, \Phinx\Db\Adapter\AdapterInterface $adapter, OutputInterface $output): array{
        $upClauses = [];
        $downClauses = [];

        $dbColumns = $adapter->getColumns($tableName);
        $dbColumnMap = [];
        foreach ($dbColumns as $col) {
            $dbColumnMap[$col->getName()] = $col;
        }

        $modelColumnMap = [];
        foreach ($modelColumns as $prop => $colName) {
            $modelColumnMap[$colName] = [
                'type' => MetadataCache::getColumnType($className, $prop) ?? 'VARCHAR(255) NOT NULL',
                'property' => $prop
            ];
        }

        $primaryKeys = array_flip(MetadataCache::getPrimaryKey($className) ?? []);

        // Comparar Model -> DB
        foreach ($modelColumnMap as $colName => $colDetails) {
            // 1. ADICIONAR nova coluna
            if (!isset($dbColumnMap[$colName])) {
                $upClauses[] = "ADD COLUMN `{$colName}` {$colDetails['type']}";
                $downClauses[] = "DROP COLUMN `{$colName}`";
                $output->writeln("  <fg=green>+ Adding column `{$colName}`</>");
                continue;
            }
            
            // 2. MODIFICAR coluna existente (se não for chave primária)
            if (isset($primaryKeys[$colName])) {
                continue; // Pula a modificação de chaves primárias por segurança
            }

            $dbColumn = $dbColumnMap[$colName];
            $modelDefinition = $colDetails['type'];
            $dbDefinition = $this->getDefinitionFromDbColumn($dbColumn);
            
            $normalizedModelDef = $this->normalizeDefinition($modelDefinition);
            $normalizedDbDef = $this->normalizeDefinition($dbDefinition);

            if ($normalizedModelDef !== $normalizedDbDef) {
                $upClauses[] = "MODIFY COLUMN `{$colName}` {$modelDefinition}";
                $downClauses[] = "MODIFY COLUMN `{$colName}` {$dbDefinition}";
                $output->writeln("  <fg=cyan>~ Modifying column `{$colName}`</>");
                $output->writeln("    <fg=cyan>  From: {$dbDefinition}</>");
                $output->writeln("    <fg=cyan>  To:   {$modelDefinition}</>");
            }
        }

        // 3. AVISAR sobre colunas removidas
        foreach ($dbColumnMap as $colName => $col) {
            if (!array_key_exists($colName, $modelColumnMap)) {
                 $output->writeln("  <fg=yellow>- Column `{$colName}` exists in DB but not in Model. Consider creating a manual migration to drop it.</>");
            }
        }
        
        if (empty($upClauses)) {
            return ['', ''];
        }

        $finalUpSql = "ALTER TABLE `{$tableName}` " . implode(', ', $upClauses) . ";";
        $finalDownSql = "ALTER TABLE `{$tableName}` " . implode(', ', array_reverse($downClauses)) . ";";

        return [$finalUpSql, $finalDownSql];
    }

    private function getDefinitionFromDbColumn(\Phinx\Db\Table\Column $column): string{
        // Mapeamento simplificado de tipos Phinx para SQL (MySQL)
        $typeMap = [
            'string'    => 'VARCHAR', 'integer'   => 'INT', 'biginteger'=> 'BIGINT',
            'text'      => 'TEXT', 'datetime'  => 'DATETIME', 'boolean'   => 'TINYINT',
            'decimal'   => 'DECIMAL', 'float'     => 'FLOAT', 'date'      => 'DATE',
            'time'      => 'TIME', 'timestamp' => 'TIMESTAMP', 'blob' => 'BLOB'
        ];
        $sqlType = $typeMap[$column->getType()] ?? strtoupper($column->getType());

        if ($sqlType === 'TINYINT' && $column->getLimit() == 1) {
            // Não mostra o (1) para booleanos, para ser consistente
        } elseif ($column->getLimit() !== null) {
            $sqlType .= '(' . $column->getLimit() . ')';
        }
        
        $definition = $sqlType;

        if (!$column->isSigned() && in_array($column->getType(), ['integer', 'biginteger'])) {
            $definition .= ' UNSIGNED';
        }

        $definition .= $column->isNull() ? ' NULL' : ' NOT NULL';

        $default = $column->getDefault();
        if ($default !== null) {
            if (in_array(strtoupper($default), ['CURRENT_TIMESTAMP'])) {
                 $definition .= " DEFAULT {$default}";
            } else {
                 $definition .= " DEFAULT '{$default}'";
            }
        } elseif ($column->isNull()) {
            $definition .= ' DEFAULT NULL';
        }
        
        if ($column->isIdentity()) {
            $definition .= ' AUTO_INCREMENT';
        }
        
        return $definition;
    }

    private function normalizeDefinition(string $def): string{
        $def = strtoupper($def);
        $def = preg_replace('/\s+/', ' ', $def);
        $def = str_replace([' (', ') '], ['(', ')'], $def);
        return trim($def);
    }

    private function createMigrationFile(string $migrationName, string $upSql, string $downSql, OutputInterface $output){
        // Precisamos criar um arquivo de migration customizado com o SQL gerado.
        // O Phinx não suporta isso diretamente, então vamos criar o arquivo manualmente.

        $path = $this->getManager()->getMigrationPath();
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        // Formato do nome do arquivo: YYYYMMDDHHMMSS_migration_name.php
        $filename = date('YmdHis') . '_' . \Phinx\Util\Util::camelcaseToUnderscore($migrationName) . '.php';
        $filepath = $path . DIRECTORY_SEPARATOR . $filename;

        // Escapa as aspas para injetar no template
        $upSqlEscaped = str_replace('"', '\"', $upSql);
        $downSqlEscaped = str_replace('"', '\"', $downSql);

        $classNameForFile = \Phinx\Util\Util::mapClassNameToFileName($migrationName);
        $classNameForFile = basename(str_replace('.php', '', $classNameForFile));

        $template = <<<PHP
<?php
use Phinx\Migration\AbstractMigration;

class {$classNameForFile} extends AbstractMigration{
    public function up(){
        \$this->execute("{$upSqlEscaped}");
    }

    public function down(){
        \$this->execute("{$downSqlEscaped}");
    }
}
PHP;
        if (file_put_contents($filepath, $template) === false) {
            throw new \RuntimeException("Could not write migration file to {$filepath}");
        }

        $output->writeln("<info>Migration file created: {$filepath}</info>");
        return self::CODE_SUCCESS;
    }
}