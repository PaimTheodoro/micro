<?php

namespace Psf\Database\Command;

use Phinx\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Psf\Model\MetadataCache;

class TableAnalyzer extends AbstractCommand{
    protected function configure(){
        $this->setName('analyze:table-vs-model')
             ->setDescription('Analyzes a database table and compares it with an existing model class')
             ->addArgument('table', InputArgument::REQUIRED, 'The table name to analyze')
             ->addArgument('model', InputArgument::REQUIRED, 'The fully qualified model class name (e.g., App\\Models\\User)')
             ->addOption('suggest-fixes', 's', InputOption::VALUE_NONE, 'Generate suggested fixes for the model')
             ->addOption('output-file', 'o', InputOption::VALUE_OPTIONAL, 'Output file for suggestions (default: table_analysis.txt)');
    }

    protected function execute(InputInterface $input, OutputInterface $output){
        $tableName = $input->getArgument('table');
        $modelClass = $input->getArgument('model');
        $suggestFixes = $input->getOption('suggest-fixes');
        $outputFile = $input->getOption('output-file') ?: 'table_analysis.txt';

        $output->writeln("<info>Analyzing table '{$tableName}' vs model '{$modelClass}'</info>");

        // Verificar se a classe do modelo existe
        if (!class_exists($modelClass)) {
            $output->writeln("<error>Model class '{$modelClass}' not found.</error>");
            return self::CODE_ERROR;
        }

        // Obter o adaptador Phinx para o ambiente atual
        $environment = $this->getManager()->getEnvironment($this->getManager()->getDefaultEnvironment());
        /** @var \Phinx\Db\Adapter\AdapterInterface $adapter */
        $adapter = $environment->getAdapter();

        if (!$adapter->hasTable($tableName)) {
            $output->writeln("<error>Table '{$tableName}' does not exist in the database.</error>");
            return self::CODE_ERROR;
        }

        // Obter informações da tabela
        $dbColumns = $adapter->getColumns($tableName);
        $dbIndexes = $adapter->getIndexes($tableName);
        $dbForeignKeys = $adapter->getForeignKeys($tableName);

        // Obter informações do modelo
        $modelTable = MetadataCache::getTable($modelClass);
        $modelColumns = MetadataCache::getColumnMap($modelClass);
        $modelPrimaryKeys = MetadataCache::getPrimaryKey($modelClass);

        $output->writeln("\n<comment>=== ANALYSIS RESULTS ===</comment>");

        // 1. Verificar se a tabela do modelo corresponde
        if ($modelTable !== $tableName) {
            $output->writeln("<error>❌ Table mismatch: Model expects '{$modelTable}' but analyzing '{$tableName}'</error>");
        } else {
            $output->writeln("<info>✅ Table name matches: {$tableName}</info>");
        }

        // 2. Analisar colunas
        $this->analyzeColumns($output, $dbColumns, $modelColumns, $modelClass);

        // 3. Analisar chaves primárias
        $this->analyzePrimaryKeys($output, $dbIndexes, $modelPrimaryKeys);

        // 4. Analisar foreign keys
        $this->analyzeForeignKeys($output, $dbForeignKeys, $modelColumns);

        // 5. Gerar sugestões se solicitado
        if ($suggestFixes) {
            $suggestions = $this->generateSuggestions($tableName, $modelClass, $dbColumns, $modelColumns, $dbIndexes, $modelPrimaryKeys);
            $this->saveSuggestions($suggestions, $outputFile, $output);
        }

        return self::CODE_SUCCESS;
    }

    private function analyzeColumns(OutputInterface $output, array $dbColumns, array $modelColumns, string $modelClass){
        $output->writeln("\n<comment>--- COLUMN ANALYSIS ---</comment>");

        $dbColumnMap = [];
        foreach ($dbColumns as $col) {
            $dbColumnMap[$col->getName()] = $col;
        }

        $modelColumnMap = [];
        foreach ($modelColumns as $prop => $colName) {
            $modelColumnMap[$colName] = [
                'property' => $prop,
                'type' => MetadataCache::getColumnType($modelClass, $prop)
            ];
        }

        // Colunas no banco mas não no modelo
        $missingInModel = array_diff(array_keys($dbColumnMap), array_keys($modelColumnMap));
        if (!empty($missingInModel)) {
            $output->writeln("<warning>⚠️  Columns in DB but missing in Model:</warning>");
            foreach ($missingInModel as $colName) {
                $col = $dbColumnMap[$colName];
                $output->writeln("   - {$colName} ({$this->getColumnDefinition($col)})");
            }
        }

        // Colunas no modelo mas não no banco
        $missingInDb = array_diff(array_keys($modelColumnMap), array_keys($dbColumnMap));
        if (!empty($missingInDb)) {
            $output->writeln("<error>❌ Columns in Model but missing in DB:</error>");
            foreach ($missingInDb as $colName) {
                $output->writeln("   - {$colName}");
            }
        }

        // Comparar tipos de colunas existentes
        $output->writeln("\n<comment>Column type comparisons:</comment>");
        foreach ($modelColumnMap as $colName => $modelInfo) {
            if (isset($dbColumnMap[$colName])) {
                $dbCol = $dbColumnMap[$colName];
                $dbDef = $this->getColumnDefinition($dbCol);
                $modelDef = $modelInfo['type'] ?? 'VARCHAR(255) NOT NULL';

                if ($this->normalizeDefinition($dbDef) !== $this->normalizeDefinition($modelDef)) {
                    $output->writeln("<warning>⚠️  Type mismatch for '{$colName}':</warning>");
                    $output->writeln("   DB:   {$dbDef}");
                    $output->writeln("   Model: {$modelDef}");
                } else {
                    $output->writeln("<info>✅ {$colName}: {$dbDef}</info>");
                }
            }
        }
    }

    private function analyzePrimaryKeys(OutputInterface $output, array $dbIndexes, array $modelPrimaryKeys){
        $output->writeln("\n<comment>--- PRIMARY KEY ANALYSIS ---</comment>");

        $dbPrimaryKeys = [];
        foreach ($dbIndexes as $index) {
            if ($index['type'] === 'PRIMARY') {
                $dbPrimaryKeys = $index['columns'];
                break;
            }
        }

        if (empty($dbPrimaryKeys)) {
            $output->writeln("<warning>⚠️  No primary key found in database table</warning>");
        } else {
            $output->writeln("<info>✅ DB Primary Keys: " . implode(', ', $dbPrimaryKeys) . "</info>");
        }

        if (empty($modelPrimaryKeys)) {
            $output->writeln("<warning>⚠️  No primary key defined in model</warning>");
        } else {
            $output->writeln("<info>✅ Model Primary Keys: " . implode(', ', $modelPrimaryKeys) . "</info>");
        }

        if (!empty($dbPrimaryKeys) && !empty($modelPrimaryKeys)) {
            $diff = array_diff($dbPrimaryKeys, $modelPrimaryKeys);
            if (!empty($diff)) {
                $output->writeln("<error>❌ Primary key mismatch: " . implode(', ', $diff) . " in DB but not in Model</error>");
            }
        }
    }

    private function analyzeForeignKeys(OutputInterface $output, array $dbForeignKeys, array $modelColumns){
        $output->writeln("\n<comment>--- FOREIGN KEY ANALYSIS ---</comment>");

        if (empty($dbForeignKeys)) {
            $output->writeln("<info>ℹ️  No foreign keys found in database</info>");
            return;
        }

        foreach ($dbForeignKeys as $fk) {
            $fkColumns = implode(', ', $fk['columns']);
            $referencedTable = $fk['referenced_table'];
            $referencedColumns = implode(', ', $fk['referenced_columns']);

            $output->writeln("<info>🔗 Foreign Key: {$fkColumns} → {$referencedTable}.{$referencedColumns}</info>");

            // Verificar se as colunas FK estão no modelo
            foreach ($fk['columns'] as $fkColumn) {
                if (!array_search($fkColumn, $modelColumns)) {
                    $output->writeln("<warning>⚠️  Foreign key column '{$fkColumn}' not found in model</warning>");
                }
            }
        }
    }

    private function generateSuggestions(string $tableName, string $modelClass, array $dbColumns, array $modelColumns, array $dbIndexes, array $modelPrimaryKeys): string{
        $suggestions = "# Analysis Suggestions for {$modelClass}\n\n";
        $suggestions .= "Generated on: " . date('Y-m-d H:i:s') . "\n\n";

        $dbColumnMap = [];
        foreach ($dbColumns as $col) {
            $dbColumnMap[$col->getName()] = $col;
        }

        $modelColumnMap = [];
        foreach ($modelColumns as $prop => $colName) {
            $modelColumnMap[$colName] = $prop;
        }

        // Sugestões para colunas faltantes no modelo
        $missingInModel = array_diff(array_keys($dbColumnMap), array_keys($modelColumnMap));
        if (!empty($missingInModel)) {
            $suggestions .= "## Missing Properties in Model\n\n";
            foreach ($missingInModel as $colName) {
                $col = $dbColumnMap[$colName];
                $propertyName = $this->camelCase($colName);
                $attributes = $this->generateAttributesForColumn($col, $dbIndexes);
                
                $suggestions .= "```php\n";
                $suggestions .= "#[{$attributes}]\n";
                $suggestions .= "public \${$propertyName};\n";
                $suggestions .= "```\n\n";
            }
        }

        // Sugestões para tipos incorretos
        $suggestions .= "## Type Corrections\n\n";
        foreach ($modelColumnMap as $colName => $prop) {
            if (isset($dbColumnMap[$colName])) {
                $dbCol = $dbColumnMap[$colName];
                $dbDef = $this->getColumnDefinition($dbCol);
                $modelDef = MetadataCache::getColumnType($modelClass, $prop) ?? 'VARCHAR(255) NOT NULL';

                if ($this->normalizeDefinition($dbDef) !== $this->normalizeDefinition($modelDef)) {
                    $suggestions .= "**{$colName}** (property: {$prop}):\n";
                    $suggestions .= "- Current: `{$modelDef}`\n";
                    $suggestions .= "- Should be: `{$dbDef}`\n\n";
                }
            }
        }

        return $suggestions;
    }

    private function saveSuggestions(string $suggestions, string $outputFile, OutputInterface $output){
        if (file_put_contents($outputFile, $suggestions) === false) {
            $output->writeln("<error>Could not write suggestions to: {$outputFile}</error>");
            return;
        }

        $output->writeln("<info>✅ Suggestions saved to: {$outputFile}</info>");
    }

    private function getColumnDefinition($column): string{
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

        $definition = $sqlType;

        if (!$column->isNull()) {
            $definition .= ' NOT NULL';
        }

        $default = $column->getDefault();
        if ($default !== null) {
            if (in_array(strtoupper($default), ['CURRENT_TIMESTAMP'])) {
                $definition .= " DEFAULT {$default}";
            } else {
                $definition .= " DEFAULT '{$default}'";
            }
        }

        if ($column->isIdentity()) {
            $definition .= ' AUTO_INCREMENT';
        }

        return $definition;
    }

    private function generateAttributesForColumn($column, array $indexes): string{
        $attributes = ["Column('{$column->getName()}')"];

        // Verificar se é chave primária
        foreach ($indexes as $index) {
            if ($index['type'] === 'PRIMARY' && in_array($column->getName(), $index['columns'])) {
                $attributes[] = 'PrimaryKey';
                break;
            }
        }

        // Type
        $sqlType = $this->getColumnDefinition($column);
        $attributes[] = "Type('{$sqlType}')";

        // Nullable
        if ($column->isNull()) {
            $attributes[] = 'Nullable';
        }

        return implode(', ', $attributes);
    }

    private function normalizeDefinition(string $def): string{
        $def = strtoupper($def);
        $def = preg_replace('/\s+/', ' ', $def);
        $def = str_replace([' (', ') '], ['(', ')'], $def);
        return trim($def);
    }

    private function camelCase(string $string): string{
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
    }
} 