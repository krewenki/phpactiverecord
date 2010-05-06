<?php

#--
# Copyright (c) 2004-2009 Warren Krewenki
#
# Permission is hereby granted, free of charge, to any person obtaining
# a copy of this software and associated documentation files (the
# "Software"), to deal in the Software without restriction, including
# without limitation the rights to use, copy, modify, merge, publish,
# distribute, sublicense, and/or sell copies of the Software, and to
# permit persons to whom the Software is furnished to do so, subject to
# the following conditions:
#
# The above copyright notice and this permission notice shall be
# included in all copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
# EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
# MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
# NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
# LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
# OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
# WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
#++

require_once("MDB2.php");


class ActiveRecord {

	/**
	 * Pointer to a MDB2 singleton
	 **/
	public static $db;
	
	/**
	 * Holds the number of returned results, to pass off to size()
	 */
	public $_number_of_results;
	
	/**
	 * Is this object dirty?
	 */
	public $_is_dirty = false;
	
	/**
	 * Hold the list of associated many->many relationships
	 */
	public $_has_many = array();
	
	/**
	 * Hold the list of associated one->one relationships
	 */
	public $_has_one = array();
	
	/**
	 * Hold the list of enforced one->one relationships
	 */
	private $_belongs_to;

	/**
	 * Holds the name of the implemented class.
	 **/
	private $_class;

	/**
	 * Array that holds the name of every column in the row
	 **/
	private $_columns;
	
	/**
	 * Array that holds the type of each column
	 **/
	private $_column_types = array();

	/**
	 * Array that holds the value of every column in the row
	 **/
	private $_values;

	/**
	 * String that holds the name of the table we're going to query for this object
	 **/
	private $_tablename;

	/**
	 * Holds the MDB2 result object for cycling through multiple records
	 **/
	private $_result;

	/**
	 * Holds the name of the primary key column in a table.
	 **/
	private $_key_column;

	/**
	 * Holds a list of events and the functions that fire when they're triggered
	 **/
	private $_events = array();
	
	
	/**
	 * __construct
	 *
	 * 
	 * 
	 * @param integer $id 
	 * @author Warren Krewenki
	 */
	public function __construct($id=null){
		$this->_class = get_class($this);
		$this->_tablename = $this->_tablename == '' ? strtolower(get_class($this).'s') : $this->_tablename;
		$this->create_empty_object_from_table($this->_tablename);
		
		if(!is_null($id) && $id > 0){
			$this->id = $id;
			$result = ActiveRecord::$db->query("SELECT * FROM ".$this->_tablename." WHERE ".$this->_key_column."='".$id."'");
			$array = $result->fetchRow(MDB2_FETCHMODE_ASSOC);
			foreach($array as $key => $val){
				$this->_values[$key] = $val;
			}
		} else {
			$this->id = 0;
			$this->_is_dirty = true;
		}
	}

	/**
	 * create_empty_object_from_table
	 *
	 * @param string $table 
	 * @return bool
	 * @author Warren Krewenki
	 */
	private function create_empty_object_from_table($table=NULL){
		if(is_null($table)){
			return false;
		}
		$cols = ActiveRecord::$db->queryAll("DESCRIBE {$table}");
		foreach($cols as $array){
			
			$this->_columns[] = $array[0];
			$this->_column_types[$array[0]] = array_shift(explode('(',$array[1]));
			if($array[3] == 'PRI'){
				$this->_key_column = $array[0];
			}
		}
		if($this->_key_column == ''){
			$this->_key_column = 'id';
		}
		return true;
	}
	
	/**
	 * connect
	 *
	 * If we have a DSN, return an MDB2 singleton
	 *
	 * @param string $dsn 
	 * @return void
	 * @author Warren Krewenki
	 */
	public static function connect($dsn=false){
		if($dsn){
			ActiveRecord::$db =& MDB2::singleton($dsn);
			if (PEAR::isError(ActiveRecord::$db)) {
			    return ActiveRecord::$db->getMessage();
			}
		}
		return true;
	}
	
	/**
	 * create
	 *
	 * Given an associative array, create a new object, or overwrite properties of the existing
	 *
	 * @param string $array 
	 * @param bool $is_schemaless
	 * @return void
	 * @author Warren Krewenki
	 */
	public function create($array, $is_schemaless=false){
		if(is_array($array)){
			foreach($array as $key=>$val){
				$this->$key = $val;
			}
		}
		return true;
	}
	
	
	/**
	 * __get
	 *
	 * Overloaded __get().  Return object properties
	 *
	 * @param string $var 
	 * @return void
	 * @author Warren Krewenki
	 */
	public function __get($var){
		if(array_search($var,$this->_columns)){
			return $this->_values[$var];
		}
		
		if(in_array($var,$this->_has_many)){
			$objname = substr($var,0,strlen($var)-1);
			$obj = new $objname;
			$name = $$obj;
			$find_function = 'find' . strtolower($this->_class) . '_id';
			
			$obj->find(strtolower($this->_class).'_id = '.$this->id);
			
			return $obj;
		}
		
		if(in_array($var,$this->_has_one)){
			$objname = $var;
			$property = strtolower($var) . '_id';
			$obj = new $objname($this->$property);
			return $obj;
		}
		return true;
	}
	
	
	/**
	 * __set
	 *
	 * Overloaded __set. Set DB columns where applicable, store local properties otherwise
	 *
	 * @param string $var 
	 * @param string $value 
	 * @return void
	 * @author Warren Krewenki
	 */
	public function __set($var,$value){
		if(is_array($this->_columns) && array_search($var,$this->_columns)){
			#if($this->_values[$var] != $value){
			#	$this->_is_dirty = true;
			#}
			$this->_values[$var] = $value;
		}
		$this->$var = $value;
		return true;
	}
	
	/**
	 * size
	 *
	 * Return the size of an object
	 *
	 * @return void
	 * @author Warren Krewenki
	 */
	public function size(){
		return $this->_number_of_results;
	}
	
	/**
	 * __call
	 *
	 * Overloaded __call().  Delivers dynamic find_by_<property> methods
	 *
	 * @param string $function 
	 * @param string $args 
	 * @return void
	 * @author Warren Krewenki
	 */
	public function __call($function, $args){
		if(substr($function,0,12) == 'find_all_by_'){
			$column_name = str_replace('find_all_by_',null,$function);
			return $this->find_all("{$column_name}='{$args[0]}'");
		}
		if(substr($function,0,8) == 'find_by_'){
			$column_name = str_replace('find_by_',null,$function);
			return $this->find("{$column_name}='{$args[0]}'");
		}
		return $this->$function($args);
	}
	
	/**
	 * find
	 *
	 * A find method that queries your database to find all related objects
	 *
	 * @param string $conditions 
	 * @return bool
	 * @author Warren Krewenki
	 */
	public function find($conditions=null){
		if(is_array($conditions)){
			$conditions = implode(' AND ',$conditions);
		}
		
		$conditions = !is_null($conditions) ? " WHERE {$conditions}":null;
		$query = "SELECT * FROM ".$this->_tablename." {$conditions}";
		$this->_result = ActiveRecord::$db->query($query);
		$this->_number_of_results = $this->_result->numRows();
		
		return true;
	}
	
	
	public function find_all($conditions=null){
		if(is_array($conditions)){
			$conditions = implode(' AND ',$conditions);
		}
		$conditions = !is_null($conditions) ? " WHERE {$conditions}":null;
		$query = ActiveRecord::$db->query("SELECT * FROM ".$this->_tablename." {$conditions}");
		
		$result = $query->fetchAll(MDB2_FETCHMODE_ASSOC);
		foreach($result as $array){
			$out[] = new $this->_class($array[$this->_key_column]);
		}
		
		return $out;
	}
	
	public function find_by_sql($sql){
		
	}
	
	public function next(){
		$o = $this->_result->fetchRow(MDB2_FETCHMODE_ASSOC);
		$obj = $o['id'] > 0 ? new $this->_class($o['id']) : false;

		return $obj;
	}
	
	public function jump_to_result($n){
		if((int)$n > 0 && (int)$n < $this->size()){
			#$this->_result->rewind();
			for($i=0; $i<$n; $i++){
				$this->next();
			}
		}
		return $this;
	}
	
	public function save(){
		$this->hook('BEFORE_SAVE');
		if($this->id > 0){
			$NEW = false;
			$query = 'UPDATE '.$this->_tablename .' ';
			$where = ' WHERE '.$this->_key_column.'=\''.$this->id.'\'';
		} else {
			$NEW = true;
			$query = 'INSERT INTO '.$this->_tablename .' ';
		}
		
		$key_column = $this->_key_column;
		
		foreach($this->_columns as $column){
			if(($column == 'id' || $column == $this->_key_column) && $this->$key_column <= 0){
				$value = NULL;
			} elseif($column == 'created_on' || $column == 'updated_on') {
				if(($column == 'created_on' && $NEW) || $column == 'updated_on'){
					$value = date("Y-m-d H:i:s");
				}
			} else {
				$value = mysql_real_escape_string($this->$column);
			}
			
			if(array_search($this->_column_types[$column], array('int','float','decimal'))){
				$value = filter_var($value,FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION);
			}
			
			if($column != 'id'){
				$columns[] = $column;
				$properties[] = $value;
				$values[] = " {$column} = ? ";
			}
		}
		$query .= 'SET ' . implode(',',$values) . $where;
	
		$statement = ActiveRecord::$db->prepare($query);
		$resultset = $statement->execute($properties);
		
		if(PEAR::isError($resultset)) {
		    die('failed... ' . $resultset->getMessage());
		}
		
		$statement->Free();

		$last_id = ActiveRecord::$db->lastInsertID();

		$this->hook('AFTER_SAVE');

		if($last_id > 0){
			$this->$key_column = $last_id;
			return true;
		} else {
			return false;
		}
		
		
	}
	
	/**
	 * delete
	 * Exactly what you expect. be careful.
	 **/
	public function delete(){
		if($this->id > 0){
			$query = 'DELETE FROM '.$this->_tablename .' WHERE '. $this->key_column ."='".$this->id."' LIMIT 1";
			ActiveRecord::$db->query($query);
		}
		return true;
	}	
	
	public function has_many($obj=null){
		if(class_exists(substr($obj,0,strlen($obj)-1))){
			$this->_has_many[] = $obj;
		}
		return true;
	}
	
	public function has_one($obj=null){
		if(class_exists($obj)){
			$this->_has_one[] = $obj;
		}
		return true;
	}
	
	public function hook($event=null){
		if(method_exists($this, $this->_events[$event]) || function_exists($this->_events[$event])){
			if(function_exists($this->_events[$event])){
				$this->_events[$event]();
			} else {
				$func = $this->events[$event];
				$this->$func();
			}
		}
		
	}
	
	public function before_save($func){
		$this->events['BEFORE_SAVE'] = $func;
		return true;
	}
	
	public function __toString(){
		return $this->_class . ' object';
	}
	
	public function __toArray(){
		$out = array();
		foreach($this->_columns as $column){
			$out[$column] = $this->$column; 
		}
		return $out;
	}
}

?>
