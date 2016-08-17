<?php

class Database{
	public $dbc = '';
	private $hostname = '';
	private $user = '';
	private $password = '';
	private $database = '';
	
	public $cleanArr = '';
	
	//db connection is always established when object is created
	public function __construct($arr){
		$this->hostname = $arr['hostname'];
		$this->user = $arr['username'];
		$this->password = $arr['password'];
		$this->database = $arr['database_name'];
		
		//connect to database
		$this->dbc = mysqli_connect($this->hostname, $this->user, $this->password, $this->database);
	}
	
	//escapes variable or array for database 
	public function escapeData($mixed, $quotes = false){
		//clear storage variable
		$this->cleanArr ='';

		//for array
		if(is_array($mixed) || is_object($mixed)){
			foreach($mixed as $key => $val){
				if($quotes == true){
					$this->cleanArr[$key] = "'".mysqli_real_escape_string($this->dbc, $val)."'";
				}else{
					$this->cleanArr[$key] = mysqli_real_escape_string($this->dbc, $val);
				}
				 
			}
		//for variable
		}else{ 
			if(!empty($mixed)){
				if($quotes == true){
					$this->cleanArr = "'".mysqli_real_escape_string($this->dbc, $mixed)."'";
				}else{
					$this->cleanArr = mysqli_real_escape_string($this->dbc, $mixed);
				}
			}
		}
		
		//return escaped arr/item
		if(!empty($this->cleanArr)){
			return $this->cleanArr;
		}
	}
	
	//new escape method - escapes multidimensions arrays and objects, as well as single item
	public function quickEscape($arr = ''){
        if(is_Array($arr) || is_object($arr)){
            foreach($arr as $key => $val){
                $clean[$key] = $this->quickEscape($val);
            }
        }else{
            $clean = mysqli_real_escape_string($this->dbc, trim($arr));
        }

        return $clean;
    }
	
	
	//for passing a written mysql query
	public function runQuery($query){
		if(!isset($this->query_count)){
			$this->query_count = 0;
		}else{
			$this->query_count++;
		}
		
		//for debug
		$this->query_log[$this->query_count]['query'] = $query;
		
		$this->q_info = '';
		
		//query
		if(isset($query) && !empty($query)){
			$this->result = mysqli_query($this->dbc, $query);
			$this->q_info['result'] = $this->result;
		}
		
		//num rows
		$this->q_info['num_rows']           = isset($this->result->num_rows) ? $this->result->num_rows : '';
		//affected rows
		$this->q_info['affected_rows']      = isset($this->dbc->affected_rows) ? $this->dbc->affected_rows : '';
		//insert id
		$this->q_info['insert_id']          = isset($this->dbc->insert_id) ? $this->dbc->insert_id : '';
		//errors
		$this->q_info['errors']             = isset($this->dbc->error) ? $this->dbc->error : '';
		
		//log result in object
		$this->query_log[$this->query_count]['query_results'] = $this->q_info;
		
		return $this->q_info;
		
		
	}
	
	//pull data from DB
	public function pullData($query, $mode = ''){
		if(!isset($this->query_count)){
			$this->query_count = 0;
		}else{
			$this->query_count++;
		}
		
		//for debug
		$this->query_log[$this->query_count]['query'] = $query;
		
		//query
		if(isset($query) && !empty($query)){
			$this->result = mysqli_query($this->dbc, $query);
		}
		
		
		if(isset($this->result) && !empty($this->result)){
			while($row = mysqli_fetch_assoc($this->result)){
				if($mode == 'ID'){
					$data[$row['id']] = $row;
				}elseif(!empty($mode) && $mode != 'END'){
					$data[$row[$mode]] = $row;
				}elseif(!empty($mode) && $mode == 'END'){
					$data = $row;
				}else{
					$data[] = $row;
				}
				
			}
		}
		$this->q_info = '';
		
		//num rows
		$this->q_info['num_rows']           = isset($this->result->num_rows) ? $this->result->num_rows : '';
		//affected rows
		$this->q_info['affected_rows']      = isset($this->dbc->affected_rows) ? $this->dbc->affected_rows : '';
		//insert id
		$this->q_info['insert_id']          = isset($this->dbc->insert_id) ? $this->dbc->insert_id : '';
		//errors
		$this->q_info['errors']             = isset($this->dbc->error) ? $this->dbc->error : '';
		
		$this->query_log[$this->query_count]['query_results'] = $this->q_info;
		
		if(isset($data) && !empty($data)){
			return $data;
		}else{
			return false;
		}
	}
	
	//pass single dimension array and it will convert key names and vals into INSERT query 
	public function array2query($arr, $table, $escape = false){
		$col_array = array_keys($arr);
		//print_r($cols);
		
		foreach($arr as $key => $val){
			if(empty($val)){continue;}
			$cols[] = "`".$key."`";
			if($escape && method_exists($this, 'escapeData')){
				$vals[] = "'".$this->escapeData($val, false)."'";
			}else{
				$vals[] = "'".$val."'";
			}
			
		}
		
		//print_r($cols);
		//print_r($vals);
		
		$query = "INSERT INTO `".$table."` (".implode(', ', $cols).") 
			VALUES(".implode(", ", $vals).")";
		
		return $query;
	}
	
	
	//convert 1 dimension array to update portion of query
	public function array2update($arr = ''){
		if(empty($arr) || !is_array){return false;}
		
		$q = '';
		
		foreach($arr as $key => $val){
			if(empty($val)){continue;}
			$q .= "`".trim($key)."`='".trim($val)."',";
		}
		
		$q = preg_replace('/,$/', '', trim($q));
		
		return $q;
	}
	
}


