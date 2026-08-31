<?php

namespace Psf\Model;

use \Psf\Database\{Connect, Create, Delete, Update};
use \Psf\Database\Dialect\DialectFactory;
use \Psf\Model\ModelQuery;
use \Psf\Model\ModelSerializer;
use \Psf\Model\ModelHydrator;
use \Psf\Model\MetadataCache;
use \Psf\Http\Http;

use \Psf\Enumerators\{DBDriver};

class Model{
	public function __construct(){
		if(method_exists($this, 'onConstruct') && is_callable([$this, 'onConstruct'])){
			call_user_func([$this, 'onConstruct']);
		}
	}

	private static function isAttributeType($attribute, string $type): bool {
		$className = $attribute->getName();
		return substr_compare($className, $type, -strlen($type)) === 0 || $className === $type;
	}

	private static function findAttributeByType($attributes, string $type) {
		return array_values(array_filter($attributes, function($attr) use ($type) {
			return self::isAttributeType($attr, $type);
		}));
	}

	public function getPrimarysKeys(){
		return array_values(array_map(function($item){
			return $item->Field;
		}, array_filter($this->getColunsForTable(), function($item){
			return $item->Key === 'PRI';
		})));
	}

	/**
	 * Retorna WHERE clause parametrizada para a(s) chave(s) primária(s).
	 * Evita injeção SQL ao substituir a interpolação direta de valores por bindings PDO.
	 * @return array{terms: string, params: array<string, mixed>}
	 */
	private function getPrimaryBindings(): array {
		$configDb = \PSF::getConfig()->db[Model::getDatabase($this)];
		$driver   = !empty($configDb['driver']) ? $configDb['driver'] : DBDriver::MySQL;

		$primarys = [];
		$refClass = new \ReflectionClass($this::class);
		foreach ($refClass->getProperties() as $property) {
			$attributes  = $property->getAttributes();
			$primarysKey = Model::findAttributeByType($attributes, 'PrimaryKey');
			if (!empty($primarysKey)) {
				foreach ($primarysKey as $column) {
					$primarys[] = $property->getName();
				}
				break;
			}
		}

		if (empty($primarys)) {
			throw new \Exception("DB Error - Primary Key Not Found");
		}

		$table   = Model::getTable($this);
		$dialect = DialectFactory::fromConfig($configDb);
		$parts   = [];
		$params  = [];

		foreach ($primarys as $prop) {
			$placeholder           = 'pk_bind_' . $prop;
			$column                = MetadataCache::getColumnByProp($this::class, $prop) ?: $prop;
			$parts[]               = $dialect->quoteIdentifier($table) . '.' . $dialect->quoteIdentifier($column) . ' = :' . $placeholder;
			$params[$placeholder]  = $this->{$prop};
		}

		return ['terms' => 'WHERE ' . implode(' AND ', $parts), 'params' => $params];
	}

	public function getPrimarysQuery(bool $query = false){
		$configDb 	= \PSF::getConfig()->db[Model::getDatabase($this)];
		$driver 	= !empty($configDb['driver']) ? $configDb['driver'] : DBDriver::MySQL;
		$primarys = [];

		$refClass = new \ReflectionClass($this::class);
		foreach($refClass->getProperties() as $property){
			$attributes = $property->getAttributes();

			$primarysKey = Model::findAttributeByType($attributes, 'PrimaryKey');

			if(!empty($primarysKey)){
				foreach($primarysKey as $column){
					$primarys[] = $property->getName();
				}
				break;
			}
		}

		if(empty($primarys)){
			throw new \Exception("DB Error - Primary Key Not Found");
		}

		if ($query) {
			$configDb2 = \PSF::getConfig()->db[Model::getDatabase($this)];
			$dialect   = DialectFactory::fromConfig($configDb2);
			$table     = Model::getTable($this);
			$parts     = array_map(
				fn($item) => $dialect->quoteIdentifier($table) . '.' . $dialect->quoteIdentifier(MetadataCache::getColumnByProp($this::class, $item) ?: $item) . ' = ' . $this->{$item},
				$primarys
			);
			return match (true) {
				count($primarys) >= 1 => implode(' AND ', $parts),
				default               => [],
			};
		}

	    return $primarys;
	}

	public function getColunsForTable(){
		return Connect::getColunsForTable(Model::getTable($this), Model::getDatabase($this));
	}

	/** @see ModelSerializer::serializeFields() */
	public static function serializeFields($object, $removePrimarys = FALSE) : array{
		return ModelSerializer::serializeFields($object, $removePrimarys);
	}

	public function create(){
		$fields = Model::serializeFields(object: $this, removePrimarys: TRUE);

		$Create = Create::exe(
			table: Model::getTable($this),
			data: $fields,
			database: Model::getDatabase($this)
		);

		if(!empty($Create)){
			if(property_exists($this::class, 'id')){
				$this->id = $Create->getResult();
			}

			return TRUE;
		}

		return FALSE;
	}

	public function save(){
		$primarysKey = $this->getPrimarysQuery();
		$fields = Model::serializeFields($this);

		$fieldsExclude = array_filter(array_keys($fields), function($item) use ($primarysKey){
			return in_array($item, $primarysKey);
		});

		if(!empty($fieldsExclude)){
			foreach($fieldsExclude as $field){
				unset($fields[$field]);
			}
		}

		$bindings = $this->getPrimaryBindings();
		$Update = Update::exe(
			table: Model::getTable($this::class),
			data: $fields,
			terms: $bindings['terms'],
			database: Model::getDatabase($this::class),
			termsParams: $bindings['params']
		);

		return $Update->getResult();
	}

	public function delete(){
		$softDelete = FALSE;

		$refClass = new \ReflectionClass($this::class);
		foreach($refClass->getProperties() as $property){
			$attributes = $property->getAttributes();

			$column = Model::findAttributeByType($attributes, 'Column');

			if(!empty($column) && isset($column[0])){
				$args = $column[0]->getArguments();
				$colName = null;
				if (isset($args[0])) {
					$colName = $args[0];
				} elseif (isset($args['name'])) {
					$colName = $args['name'];
				}
				$column = $colName;

				$columnDeleted = Model::findAttributeByType($attributes, 'ColumnDeletedDate');

				if(!empty($columnDeleted)){
					$softDelete = $column;
					$typeValue = null;
					$typeAttr = Model::findAttributeByType($attributes, 'Type');
					if(!empty($typeAttr) && isset($typeAttr[0])){
						$typeArgs = $typeAttr[0]->getArguments();
						if(isset($typeArgs[0])){
							$typeValue = $typeArgs[0];
						}
					}
					if(in_array($typeValue, ['timestamp', 'bigint'])){
						$newValue = time();
					}else{
						$newValue = date('Y-m-d H:i:s');
					}
					$this->{$property->getName()} = $newValue;
					break;
				}
			}
		}

		if(!$softDelete){
			$bindings = $this->getPrimaryBindings();
			Delete::exe(
				table: Model::getTable($this::class),
				terms: $bindings['terms'],
				database: Model::getDatabase($this::class),
				termsParams: $bindings['params']
			);

			return TRUE;
		}

		return $this->save();
	}

	public function assign(object|array $values, bool $force = false){
		foreach($values as $key => $value){
			if($force){
				$this->$key = $value;
			}else{
				if(property_exists($this, $key)){
					$this->$key = $value;
				}
			}
		}
		return $this;
	}

	public function toArray() : array {
		foreach($this->getColunsForTable() as $item){
			if(property_exists($this, $item->Field)){
				$fieldName = $item->Field;
				$arrReturn[$item->Field] = $this->$fieldName;
			}
		}
		return $arrReturn ?? [];
	}

	public function getTableName(){
		return Model::getTable($this::class);
	}

	public function getIdentityColumn(){
		$configDb = \PSF::getConfig()->db[Model::getDatabase($this)];

		$driver 	= !empty($configDb['driver']) ? $configDb['driver'] : DBDriver::MySQL;

		$query = match ($driver) {
			DBDriver::SQLServer => "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '" . $this->table . "' AND COLUMNPROPERTY(OBJECT_ID(TABLE_SCHEMA + '.' + TABLE_NAME), COLUMN_NAME, 'IsIdentity') = 1",
			default             => null,
		};

		if ($query !== null) {
			$statement = $db->prepare($query);

			try{
	            $statement->execute();
	            $coluns = $statement->fetchAll(\PDO::FETCH_ASSOC);

	            if($coluns){
	            	return $coluns[0]['COLUMN_NAME'];
	            }

	            return $coluns;
	        }catch (\PDOException $e){
	            explodeException($e);
	            return FALSE;
	        }
		}

		return FALSE;
	}

	public static function getTable($class){
		$table = Model::findAttributeByType((new \ReflectionClass($class))->getAttributes(), 'Table');

		if (!empty($table) && isset($table[0])) {
			$args = $table[0]->getArguments();
			if (isset($args[0])) {
				return $args[0];
			} elseif (isset($args['name'])) {
				return $args['name'];
			}
		}
		return FALSE;
	}

	public static function getDatabase($class){
		$database = Model::findAttributeByType((new \ReflectionClass($class))->getAttributes(), 'Database');

		if (!empty($database) && isset($database[0])) {
			$args = $database[0]->getArguments();
			if (isset($args[0])) {
				return $args[0];
			} elseif (isset($args['name'])) {
				return $args['name'];
			}
		}
		return 'default';
	}

	/** @see ModelSerializer::serializeData() */
	public static function serializeData($class, array $data, bool $asArray = FALSE, null|array $joins = []) : object|array|null{
		return ModelSerializer::serializeData($class, $data, $asArray, $joins);
	}

	/** @see ModelHydrator::getPropByColumn() */
	public static function getPropByColumn($class, $column){
		return ModelHydrator::getPropByColumn($class, $column);
	}

	public static function getPrimaryKey($class, $type = 'column'){
		$refClass = new \ReflectionClass($class);
		foreach($refClass->getProperties() as $property){
			$attributes = $property->getAttributes();

			$primarysKey = Model::findAttributeByType($attributes, 'PrimaryKey');

			if(!empty($primarysKey)){
				foreach($primarysKey as $column){
					$primarys[] = $type == 'column' ? Model::getPropByColumn($class, $property->getName()) : $property->getName();
				}
				break;
			}
		}

		return !empty($primarys) ? $primarys : NULL;
	}

	/** @see ModelHydrator::getColumnByProp() */
	public static function getColumnByProp($class, $prop = null) : string|array|bool{
		return ModelHydrator::getColumnByProp($class, $prop);
	}

	/** @see ModelHydrator::propIsEnum() */
	public static function propIsEnum($class, $property){
		return ModelHydrator::propIsEnum($class, $property);
	}
}
