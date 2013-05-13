<?php
/**
 * Claromentis CSV to Database Importer
 * @author Milos Vnucko [milos@claromenits.com]
 * @copyright Claromentis
 * @version 0.1.0 [08/05/2013]
 */
require_once('../common/documents_upload_common.php');
class Importer extends ErrorHandler
{
	private $pattern;
	private $callback_functions;
	private $validation_entries;
	private $filename;
	private $filepath; 
	private $headers;
	private $allowed_extensions = array('csv');
	private $document;
	private $table_name;
	
	private $save_container;
	
	//Statistics
	private $total_rows;
	private $imported_rows;
	private $errors;
	private $status;
	private $import_time;
	
	const STRING = "string";
	const INT = "int";
	const FLOAT = "float";
	
	function __construct($filename_mixed)
	{
		if(is_array($filename_mixed)) //Assume this is POST form data
		{
			if(!empty($filename_mixed['name']) && !empty($filename_mixed['tmp_name']))
			{
				$this->filename = $filename_mixed['name'];
				$this->filepath = $filename_mixed['tmp_name'];
			}
		}
		else if(!empty($filename)) //string filename
		{
			$this->filename = $filename_mixed;
			$this->filepath = $filename_mixed;
		}
		
		$pattern = array();
		$callback_functions = array();
		$validation_entries = array();
		
		$this->total_rows = 0;
		$this->imported_rows = 0;
		$this->errors = array();
		$this->import_time = 0;
		$this->save_container = array();
		$this->status = false;
	}
	
	/**
	 * Import CSV data into DB
	 * @param DB Object child $import_class
	 */
	public function Import($import_class)
	{		
		if(empty($import_class)) return $this->SetErrMsg("Wrong import or missing import class");
		
		//Get Table Name
		if($import_class instanceof DBObject)
			$this->table_name = $import_class->GetTableName();
		
		//Check if table name not empty
		if(empty($this->table_name)) return $this->SetErrMsg("Missing table name");
		
		//Validate DB headers from AddLink() methods
		if(!$this->ValidateDbHeaders()) return $this->GetErrMsg();
		
		//Pending Errors
		if($this->IsError()) return $this->GetErrMsg();
		
		//If pattern is empty
		if(empty($this->pattern)) return $this->SetErrMsg("No CSV - DB pattern link specified, Pease add patterns links by using AddLink(x,y)");
		
		//Validate filename
		if(!$this->ValidateFilename()) return $this->GetErrMsg();
		
		//Parse CSV
		$this->document = get_csv_data_from_file($this->filepath);
		
		//Fetch headers from CSV
		if(!$this->GetHeaders($this->document)) return $this->SetErrMsg("No headers in CSV document. The Document is probably empty");	
		
		//Validate CSV headers
		if(!$this->ValidateCsvHeaders()) return $this->GetErrMsg();
		
		$this->ImportData($import_class);
		
		return true;
	}
	
	/**
	 * Import CSV data to database
	 * @param Object $class
	 */
	private function ImportData($class)
	{			
		$time_start = microtime(true);
		
		foreach($this->document as $row_no => $row)
		{		
			$stop = false;	
			foreach($this->pattern as $xml_key=>$db_pattern)
			{
				//If callback set for this row
				if(isset($this->callback_functions[$xml_key]))
				{
					$callback_val = call_user_func($this->callback_functions[$xml_key], $row[$xml_key]);

					//Validation
					if(isset($this->validation_entries[$xml_key]))
					{					
						//If Mandatory
						if($this->validation_entries[$xml_key]['is_mandatory'] && empty($callback_val))
						{
							if(empty($row[$xml_key]))
								$this->errors[] = "CSV Line:({$row_no}), ERROR: Value on column {$this->headers[$xml_key]} is empty!";
							else
								$this->errors[] = "CSV Line:({$row_no}), ERROR: Column {$this->headers[$xml_key]} with value {$row[$xml_key]} is unknown for system!";

							$stop = true;
							break;
						}
						
						//Assign default value
						if(empty($callback_val) || $callback_val == 0)
							$callback_val = $this->validation_entries[$xml_key]['default_value'];
							
						//Casting
						settype($callback_val, $this->validation_entries[$xml_key]['type']);
						$class->$db_pattern = $callback_val;
					}
					else  //No Validation
					{	
						$class->$db_pattern = $callback_val;
					}
					
					//DEBUG
					//my_print_r($db_pattern);
					//my_print_r($callback_val);
				}	
				else
				{
					//Validation
					if(isset($this->validation_entries[$xml_key]))
					{
						//Mandatory
						if($this->validation_entries[$xml_key]['is_mandatory'] && ($row[$xml_key]=="" || is_null($row[$xml_key])))
						{		
							if(empty($row[$xml_key]))
								$this->errors[] = "CSV Line:({$row_no}), ERROR: Value on column {$this->headers[$xml_key]} is empty!";
							else
								$this->errors[] = "CSV Line:({$row_no}), ERROR: Column {$this->headers[$xml_key]} with value {$row[$xml_key]} is unknown for system!";
								
							$stop = true;
							break;
						}
							
						//check for correct data type: validation if value is numeric
						if(empty($row[$xml_key]) == false && is_numeric($row[$xml_key]) == false && ($this->validation_entries[$xml_key]['type'] == self::INT || $this->validation_entries[$xml_key]['type'] == self::FLOAT))
						{						
							$this->errors[] = "CSV Line:({$row_no}), ERROR: Column {$this->headers[$xml_key]} with value {$row[$xml_key]} has to numeric";
							$stop = true;
							break;	
						}
						
						//Assign default value
						if(empty($row[$xml_key]))
							$row[$xml_key] = $this->validation_entries[$xml_key]['default_value'];
						
						//Casting
						settype($row[$xml_key], $this->validation_entries[$xml_key]['type']);
						$class->$db_pattern = $row[$xml_key];
					} 
					else //No validation
					{
						$class->$db_pattern = $row[$xml_key];	
					}
				}
			}

			//if no errors
			if(!$stop)
			{ 
				$this->save_container[] = clone $class;
				//$class->Save();
			}
		}
		
		//execution time
		$time_end = microtime(true);
		$time = $time_end - $time_start;
		$this->import_time = number_format($time,3);
		
		return true;
	}
	
	/**
	 * 
	 * Main Save function
	 */
	public function Save()
	{
		if(!empty($this->errors)) return false;
		
		foreach($this->save_container as $class)
		{
			$class->Save();
			$this->imported_rows++;
		}
		$this->status = true;
	}
	
	/**
	 * Validate the filename entered
	 */
	private function ValidateFilename()
	{
		//File Exists
		if(!file_exists($this->filepath)) 
		{ 
			$this->SetErrMsg("Filename: {$this->filename} doesn't exist!"); 
			return false; 
		}
		
		//File Extension
		if(!in_array(end(explode('.',$this->filename)), $this->allowed_extensions)) 
		{ 
			$this->SetErrMsg("Wrong file extension in {$this->filename}"); 
			return false; 
		}
		
		return true;
	}
	
	/**
	 * Separate headers from the CSV Document
	 * @param unknown_type $csv
	 */
	private function GetHeaders($csv)
	{
		$this->headers = $csv[0];
		if(empty($this->headers))
		{ 
			return false;
		}
		else
		{
			unset($this->document[0]);
		}	
		
		//Stats
		$this->total_rows = count($this->document);
		return true;
	}
	
	/**
	 * Validate all CSV headers in Links / Callbacks / Validations
	 */
	private function ValidateCsvHeaders()
	{						
		$this->pattern = $this->ValidateCsvHeader($this->pattern);
		$this->callback_functions = $this->ValidateCsvHeader($this->callback_functions);
		$this->validation_entries = $this->ValidateCsvHeader($this->validation_entries);
				
		if($this->IsError()) return false;
		
		return true;
	}
	
	/**
	 * Make sure the column names exists in CSV
	 * @param array $arr
	 */
	private function ValidateCsvHeader($arr)
	{
		if(empty($arr)) return array();
		
		foreach($arr as $xml_header => $data)
		{			
			if(in_array($xml_header, $this->headers))
			{
				$header_key = array_search($xml_header, $this->headers);			
				$arr[$header_key] = $arr[$xml_header];
				unset($arr[$xml_header]);
			} else {
				$this->SetErrMsg("{$xml_header} header name not found in CSV document!");
				return false;
			}
		}
		
		return $arr;
	}
	
	/**
	 * Make sure the column names exists in DB
	 */
	private function ValidateDbHeaders()
	{
		global $db;
		foreach($this->pattern as $db_column)
		{
			if(empty($db_column))
			{
				$this->SetErrMsg("Missing column name on one of the AddLinks()");
				return false;
			} 
			
			$res = $db->query("SHOW COLUMNS FROM {$this->table_name} LIKE '{$db_column}'");
			
			if($res->numRows() < 1)
			{
				$this->SetErrMsg("Column name {$db_column} doesn't exists in the {$this->table_name} table");
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Add custom callbact to the csv comlumn
	 * @param string $csv_column_name
	 * @param string $callback_function_name
	 */
	public function AddCallback($csv_column_name, $callback_function_name)
	{
		$this->callback_functions[$csv_column_name] = $callback_function_name;
	}
	
	/**
	 * Add structure link between CSV and dabtabase
	 * @param string $csv_column_name
	 * @param string $db_column_name
	 */
	public function AddLink($csv_column_name, $db_column_name)
	{			
		$this->pattern[$csv_column_name] = $db_column_name;
	}
	
	public function AddValidation($csv_column_name, $default_value=null, $type=self::STRING, $is_mandatory=false)
	{		
		$this->validation_entries[$csv_column_name] = array('default_value' => $default_value, 'type' => $type, 'is_mandatory' => $is_mandatory);	
	}
	
	/**
	 * Get statistics for import action
	 * @return array
	 */
	public function GetStatistics()
	{
		$r = array(
		
			'total_rows' => $this->total_rows,
			'imported_rows' => $this->imported_rows,
			'errors' => $this->errors,
			'import_time' => $this->import_time
		);
		
		return $r;
	}
	
	/**
	 * Get errors in datasrc format
	 * @return array
	 */
	public function GetErrorDatasrc()
	{
		$data = array();
		foreach($this->errors as $err)
		{
			$data[] = array('error_desc.body'=> $err); 
		}
		
		return $data;
	}
}
?>