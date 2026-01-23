<?php

namespace Psf\Model;

use \Psf\Database\{Connect, Create, Delete, Update};
use \Psf\Model\ModelQuery;
use \Psf\Helper\UUID;
use \Psf\Http\Http;

use \Psf\Enumerators\{DBDriver};

class Model{
	public function __construct(){
		if(method_exists($this, 'onConstruct') && is_callable([$this, 'onConstruct'])){
			call_user_func([$this, 'onConstruct']);
		}
	}

	/**
	 * Verifica se um atributo é do tipo especificado
	 */
	private static function isAttributeType($attribute, string $type): bool {
		$className = $attribute->getName();
		// Usa substr_compare para compatibilidade com PHP < 8.0
		return substr_compare($className, $type, -strlen($type)) === 0 || $className === $type;
	}

	/**
	 * Encontra um atributo do tipo especificado
	 */
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

		if($query){
	       	if(count($primarys) == 1){
	       		return $driver === DBDriver::MySQL ? ("`". Model::getTable($this) ."`.`".$primarys[0]."` = ".$this->{$primarys[0]}) : ($driver === DBDriver::SQLServer ? ($primarys[0]." = ".$this->{$primarys[0]}) : []);
	       	}else if(count($primarys) > 1){
	       		$string = "";
	       		$count = 0;

	       		foreach($primarys as $item){
	       			if($count > 0){
	       				$string .= " AND ";
	       			}
	       			if($driver === DBDriver::MySQL){
	       				$string .= " `" . Model::getTable($this) ."`.`". $item . "` = " . $this->{$item};
	       			}else if($driver === DBDriver::SQLServer){
	       				$string .= " " . $item . " = " . $this->{$item};
	       			}
	       			$count++;
	       		}

	       		return $string;
	       	}
	    }

	    return $primarys;
	}

	public function getColunsForTable(){
		return Connect::getColunsForTable(Model::getTable($this), Model::getDatabase($this));
	}

	public static function serializeFields($object, $removePrimarys = FALSE) : array{
		$refClass = new \ReflectionClass($object::class);
		foreach($refClass->getProperties() as $property){
			$attributes = $property->getAttributes();

			$column = Model::findAttributeByType($attributes, 'Column');

			if(!empty($column)){
				$args = $column[0]->getArguments();
				$colName = null;
				if (isset($args[0])) {
					$colName = $args[0];
				} elseif (isset($args['name'])) {
					$colName = $args['name'];
				}
				$column = $colName;

				if($removePrimarys){
					$isPrimaryKey = Model::findAttributeByType($attributes, 'PrimaryKey');

					if(!empty($isPrimaryKey)){
						continue;
					}
				}

				$typeValue = Model::findAttributeByType($attributes, 'Type');
				if(!empty($typeValue) && isset($typeValue[0])){
					$args = $typeValue[0]->getArguments();
					if (isset($args[0])) {
						$typeValue = $args[0];
					} elseif (isset($args['type'])) {
						$typeValue = $args['type'];
					} else {
						$typeValue = NULL;
					}
				}else{
					$typeValue = NULL;
				}

				$standardValue = Model::findAttributeByType($attributes, 'Standard');
				if(!empty($standardValue) && isset($standardValue[0]) && empty($object->{$property->getName()})){
					$args = $standardValue[0]->getArguments();
					$stdValue = null;
					if (isset($args[0])) {
						$stdValue = $args[0];
					} elseif (isset($args['value'])) {
						$stdValue = $args['value'];
					}
					
					if($stdValue !== null){
						if(is_numeric($stdValue)){
							$object->{$property->getName()} = $stdValue;
						}else if(property_exists($stdValue, 'value')){
							$object->{$property->getName()} = $stdValue->value;
						}else{
							if(strtoupper($stdValue) === 'NOW()'){
								$object->{$property->getName()} = date('Y-m-d H:i:s');
							}else if(strtoupper($stdValue) === 'UUIDV4'){
								$object->{$property->getName()} = UUID::generate('4');
							}else{
								$object->{$property->getName()} = $stdValue;
							}
						}

						$fields[$column] = $object->{$property->getName()};
					}
				}

				$columnCreatedDate = Model::findAttributeByType($attributes, 'ColumnCreatedDate');
				if(!empty($columnCreatedDate) && empty($object->{$property->getName()})){
					if(in_array($typeValue, ['timestamp', 'bigint'])){
						$newValue = time();
					}else{
						$newValue = date('Y-m-d H:i:s');
					}

					$object->{$property->getName()} = $newValue;
					$fields[$column] = $newValue;
				}

				$columnUpdatedDate = Model::findAttributeByType($attributes, 'ColumnUpdatedDate');
				if(!empty($columnUpdatedDate) && empty($object->{$property->getName()})){
					if(in_array($typeValue, ['timestamp', 'bigint'])){
						$newValue = time();
					}else{
						$newValue = date('Y-m-d H:i:s');
					}

					$object->{$property->getName()} = $newValue;
					$fields[$column] = $newValue;
				}

				$required = Model::findAttributeByType($attributes, 'Nullable');
				if(!empty($required) && isset($required[0])){
					$args = $required[0]->getArguments();
					if (isset($args[0])) {
						$required = $args[0];
					} elseif (isset($args['nullable'])) {
						$required = $args['nullable'];
					} else {
						$required = true; // padrão
					}
				}

				if($required === FALSE && empty($object->{$property->getName()})){
					return throw new \Exception("O campo '" . $property->getName() . "' não pode ser nulo");
				}else if(!empty($required)){
					$fields[$column] = $object->{$property->getName()};
				}

				if((!empty($object->{$property->getName()}) || $object->{$property->getName()} == 0) && empty($fields[$column])){
					$fields[$column] = $object->{$property->getName()};
				}
			}
		}

		return $fields ?? [];
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

		$Update = Update::exe(
			table: Model::getTable($this::class), 
			data: $fields, 
			terms: 'WHERE ' . $this->getPrimarysQuery(true), 
			database: Model::getDatabase($this::class)
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
			Delete::exe(
				table: Model::getTable($this::class),
				terms: 'WHERE ' . $this->getPrimarysQuery(true),
				database: Model::getDatabase($this::class)
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
		$configDb 	= \PSF::getConfig()->db;
		$driver 	= !empty($configDb[$this->database]['driver']) ? $configDb[$this->database]['driver'] : DBDriver::MySQL;

		$db = Connect::getConnection($this->database);

		if($driver == DBDriver::SQLServer){
			$query = "SELECT COLUMN_NAME
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_NAME = '" . $this->table . "' AND COLUMNPROPERTY(OBJECT_ID(TABLE_SCHEMA + '.' + TABLE_NAME), COLUMN_NAME, 'IsIdentity') = 1";
		}

		if(isset($query)){
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

	public static function serializeData($class, array $data, bool $asArray = FALSE, null|array $joins = []) : object|array|null{
		$response = new $class;
		$refClass = new \ReflectionClass($class);

		if(!$asArray){
			foreach($refClass->getProperties() as $property){
				$attributes = $property->getAttributes();

				$column = Model::findAttributeByType($attributes, 'Column');

				if(!empty($column)){
					$args = $column[0]->getArguments();
					$columnName = null;
					if (isset($args[0])) {
						$columnName = $args[0];
					} elseif (isset($args['name'])) {
						$columnName = $args['name'];
					}
					$response->{$property->getName()} = !empty($data[$columnName]) || (isset($data[$columnName]) && $data[$columnName] == 0) ? $data[$columnName] : NULL;
				}else{
					if(!empty($joins)){
						$findJoinForProperty = array_filter($joins, function($item) use ($property){
							return !empty($item['andSelect']) && $item['andSelect'] == $property->getName();
						});

						if(!empty($findJoinForProperty)){
							$findJoinForProperty = $findJoinForProperty[0];
							
							foreach ($data as $key => $value) {
								if(str_starts_with($key, $findJoinForProperty['andSelect'] . '_')){
									$classRelation = is_array($findJoinForProperty['table']) ? $findJoinForProperty['table'][0] : $findJoinForProperty['table'];

									if(!is_object($response->{$findJoinForProperty['andSelect']})){
										$response->{$findJoinForProperty['andSelect']} = new $classRelation;
									}

									$objectField = explode('_', $key);
									unset($objectField[0]); 

									$getColumnRelation = Model::getPropByColumn($classRelation, implode('_', $objectField));

									if(!empty($getColumnRelation)){
										$response->{$findJoinForProperty['andSelect']}->{$getColumnRelation} = $value;
									}
								}							
							}
						}
					}

					if(isset($data[$property->getName()])){
						if(!$property->isStatic()){
							$response->{$property->getName()} = $data[$property->getName()];
						}
					}
				}
			}
		}else{
			$response = [];
			
			foreach(array_keys($data) as $key){
				$findColumnExist = array_values(array_filter($refClass->getProperties(), function($prop) use ($key){
					$attributes = $prop->getAttributes();

					$column = Model::findAttributeByType($attributes, 'Column');
					if(!empty($column) && isset($column[0])){
						$args = $column[0]->getArguments();
						$colName = null;
						if (isset($args[0])) {
							$colName = $args[0];
						} elseif (isset($args['name'])) {
							$colName = $args['name'];
						}
						if($colName == $key){
							return $colName;
						}
					}
				}));

				if(!empty($findColumnExist)){
					$response[$findColumnExist[0]->getName()] = $data[$key];

					$verifyIsEnum = Model::propIsEnum($class, Model::getPropByColumn($class, $key));
					if(!empty($verifyIsEnum)){
						
					}
				}else{
					if(!empty($joins)){
						$findJoinForProperty = array_filter($joins, function($item) use ($key){
							return !empty($item['andSelect']) && $item['andSelect'];
						});

						if(!empty($findJoinForProperty)){
							$findJoinForProperty = $findJoinForProperty[0];
							
							if(str_starts_with($key, $findJoinForProperty['andSelect'] . '_')){
								$classRelation = is_array($findJoinForProperty['table']) ? $findJoinForProperty['table'][0] : $findJoinForProperty['table'];

								$objectField = explode('_', $key);
								unset($objectField[0]); 

								$getColumnRelation = Model::getPropByColumn($classRelation, implode('_', $objectField));

								if(!empty($getColumnRelation)){
									$response[$findJoinForProperty['andSelect']][$getColumnRelation] = $data[$key];
									continue;
								}	
							}else if(str_starts_with($key, $findJoinForProperty['andSelect'] . '#')){
								$classRelation = is_array($findJoinForProperty['table']) ? $findJoinForProperty['table'][0] : $findJoinForProperty['table'];

								$objectField = explode('#', $key);

								$newFields = $objectField;
								unset($newFields[0]);

								$newFields = implode('_', $newFields);
								$objectFieldAttr = explode('_', $newFields);

								$findJoinForPropertyTwo = array_values(array_filter($joins, function($item) use ($objectField, $objectFieldAttr){
									return !empty($item['andSelect']) && ($item['andSelect'] == ($objectField[0] . '.' . $objectFieldAttr[0]));
								}));

								if($findJoinForPropertyTwo){
									$findJoinForPropertyTwo = $findJoinForPropertyTwo[0];
									$classRelationTwo = is_array($findJoinForPropertyTwo['table']) ? $findJoinForPropertyTwo['table'][0] : $findJoinForPropertyTwo['table'];

									$fieldTwo = $objectFieldAttr[0];
									unset($objectFieldAttr[0]);
									$objectFieldAttr = array_values($objectFieldAttr);
									$getColumnRelation = Model::getPropByColumn($classRelationTwo, implode('_', $objectFieldAttr));
									
									if(!empty($getColumnRelation)){
										$response[$findJoinForProperty['andSelect']][$fieldTwo][$getColumnRelation] = $data[$key];
										continue;
									}
									
								}
							}
						}
					}

					$response[$key] = $data[$key];
				}
			}
		}

		return $response;
	}

	public static function getPropByColumn($class, $column){
		$refClass = new \ReflectionClass($class);

		if(!empty($refClass->getProperties())){
			foreach ($refClass->getProperties() as $prop) {
				$getAttrs = $prop->getAttributes();

				if(!empty($getAttrs)){
					$findColumn = Model::findAttributeByType($getAttrs, 'Column');

		            if(!empty($findColumn) && isset($findColumn[0])){
		            	$args = $findColumn[0]->getArguments();
		            	$colName = null;
		            	if (isset($args[0])) {
		            		$colName = $args[0];
		            	} elseif (isset($args['name'])) {
		            		$colName = $args['name'];
		            	}
		            	
		            	if($colName == $column){
		            		return $prop->getName();
		            		break;
		            	}
		            }
				}
			}
		}

		return FALSE;
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

	public static function getColumnByProp($class, $prop = null) : string|array|bool{
		$refClass = new \ReflectionClass($class);

		if(!empty($prop)){
			if(is_array($prop)){
				$findPropertie = array_values(array_filter($refClass->getProperties(), function($item) use ($prop){
		            return in_array($item->getName(), $prop);
		        }));
			}else{
		        $findPropertie = array_values(array_filter($refClass->getProperties(), function($item) use ($prop){
		            return $prop === $item->getName();
		        }));
		    }
	    }else{
	    	$findPropertie = $refClass->getProperties();
	    }

        if(!empty($findPropertie)){
        	foreach($findPropertie as $propItem){
        		$attributes = $propItem->getAttributes();

	            $column = Model::findAttributeByType($attributes, 'Column');

	            if(!empty($column) && isset($column[0])){
	            	$args = $column[0]->getArguments();
	            	$colName = null;
	            	if (isset($args[0])) {
	            		$colName = $args[0];
	            	} elseif (isset($args['name'])) {
	            		$colName = $args['name'];
	            	}
	            	if ($colName !== null) {
	            		$columns[] = $colName;
	            	}
	            }
        	}

        	return empty($prop) || is_array($prop) ? $columns : (!empty($columns) ? $columns[0] : FALSE);
        }

		return FALSE;
	}

	public static function propIsEnum($class, $property){
		$refClass = new \ReflectionClass($class);

		$findPropEnum = array_values(array_filter(array_map(function($prop) use ($property){
			if($prop->getName() != $property){
				return FALSE;
			}

			$attributes = $prop->getAttributes();

			$enum = Model::findAttributeByType($attributes, 'Enum');

			if(empty($enum)){
				return FALSE;
			}else{
				if (isset($enum[0])) {
					$args = $enum[0]->getArguments();
					if (isset($args[0])) {
						$enum = $args[0];
					} elseif (isset($args['enumClass'])) {
						$enum = $args['enumClass'];
					} else {
						$enum = null;
					}
				} else {
					$enum = null;
				}
				return $enum;
			}
		}, $refClass->getProperties()), fn($if) => !empty($if)));

		return empty($findPropEnum) ? FALSE : $findPropEnum[0]; 
	}
}