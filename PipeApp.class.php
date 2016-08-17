<?php
/*
	This class checks pipedrive deals for specific status. If any are found in the desired status, it downloads any files associated with the deal, and then emails the deal info with the attached files to interested parties.
	
	If the process if successfull for each deal, it will change the status of the deal and also add a note to it in Pipedrive

*/




/*
	-add custom field to deals (This occurs once) ***
	
	-provide way to update email list (occurs as sepearte action independant of cron job) ***
	
	
	-pull all 'email banks' stage deals from pipedrive api

	
	-foreach deal
		-might need to pull file info from piperdive api
		-then download files from download link provided
		-build email, attahements, etc
		-send email
		-use pipedrive api to move deal to 'sent to lender' stage
		
		-keep track of all emails sent

		
	Pipedrive
	-----------------------------------
		->Users
			->Deals
				->Stages
		
		
*/


// pipedrive api
// database
// phpmailer / mail credentials

//docs: developers.pipedrive.com

class PipeApp{
	### config ###
	#------------------------------------------------------------------------------------------------------------------
	public $api_base_url           = 'https://api.pipedrive.com/v1/';
	public $api_master_token       = 'secret'; //   token
	
	public $master_pipeline_id     = 1; //id to master pipeline, used to retrieve list of stages, list of stages needed to build stage filter, based on stage id
	public $custom_email_field_id  = 123; //id of custom field which holds lender emails
	
	public $need_send_stage_id     = '107'; //id of stage containing emails that need to be sent
	public $SENT_stage_id          = '108'; //id of stage containing deals that have been sent to selected emails
	
	
	private $download_dir          = '/api_file_downloads'; // !!! make sure this path is correct or the wrong direcotry and files may get deleted later (see purgeOldDownloads() method) !!!! //this folder will be created if !exists
	private $php_mailer_path       = 'includes/PHPMailer/PHPMailerAutoload.php';
	private $email_template_path   = 'includes/email_template.php';
	
	//Database credentials
	protected $db = array(
		'host' => 'localhost',
		'user' => 'secret',
		'pass' => 'secret',
		'name' => 'secret'
	
	);
	
	#------------------------------------------------------------------------------------------------------------------
	
	### non-config
	public $api_method = '';
	
	
	### CLASS CONSTRUCT
	#---------------------------------------------------------------------
	
	public function __construct($path = ''){
		if(empty($path)){
			echo 'Path is required';
			EXIT;
		}
		
		$this->FILE_PATH = preg_replace('/\/$/', '', $path);
		
		////echo $this->FILE_PATH; EXIT;
		
		date_default_timezone_set('America/New_York');
		//$this->db_connect();
		set_time_limit(60 * 8);
		ini_set('memory_limit', '500M');
		$this->db_connect();
	}
	
	### DATABASE METHODS
	#---------------------------------------------------------------------
	
	public function db_connect(){
		//ping connection if it exists already and this method is called again
		if(!empty($this->dbc)){
			mysqli_ping($this->dbc);
		}
		
		//connect to database
		$this->dbc = mysqli_connect($this->db['host'], $this->db['user'], $this->db['pass'], $this->db['name']);
	}
	
	
	public function db_query($query){
		if(!isset($this->query_count)){$this->query_count = 0;}
		$this->query_count++;
		
		//re-establish connection if lost
		mysqli_ping($this->dbc);
		
		//save all queries in object for debug
		$this->query_log[$this->query_count]['query'] = $query;
		
		$result = mysqli_query($this->dbc, $query);
		
		//store results in object for debug
		$this->query_log[$this->query_count]['result']               = $result;
		$this->query_log[$this->query_count]['db_affected_rows']     = $this->dbc->affected_rows;
		$this->query_log[$this->query_count]['result_num_rows']      = isset($result->num_rows) ? $result->num_rows : '';
		$this->query_log[$this->query_count]['insert_id']            = isset($this->dbc->insert_id) ? $this->dbc->insert_id : '';
		$this->query_log[$this->query_count]['error']                = !empty($this->dbc->error) ? $this->dbc->error : '';
		
		
		
		//increment query counter - used for unique id
		return $this->query_log[$this->query_count];
		
	}
	
	public function db_escape($var = ''){
		return mysqli_real_escape_string($this->dbc, trim($var));
	}
	
	### API METHODS
	#---------------------------------------------------------------------
	
	public function setPipeDriveAPIauth($token= ''){
		if(empty($token)){RETURN FALSE;}
		$this->api_token = $token;
		
	}
	
	//send data to url. This function expects to be passed json data
	public function curl_API(){
		
		## Curl Headers
		//-------------------------------------------------------------------

		$headers[] = 'Content-Type: application/json';

		
		# URL /Request Method

		$request_data = $this->build_API_URL();
		
		//echo 'Request Data::'; print_r($request_data);
		//echo 'Method: '.$this->api_method;

		
		
		if(isset($request_data['url']) && !empty($request_data['url'])){
			$arr['api_url'] = $request_data['url'];
		}
		
		if(isset($request_data['method']) && !empty($request_data['method'])){
			$arr['request_method'] = $request_data['method'];
		}
		
		if(isset($request_data['content']) && !empty($request_data['content'])){
			$arr['content'] = $request_data['content'];
		}		
		
		
		//print_r($arr);exit;
		
		//URL
		$url = $arr['api_url'];
		
		### CURL SETUP
		//-------------------------------------------------------------------
		$curl = curl_init($url);
		
		curl_setopt($curl, CURLOPT_HEADER, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); //Send over SSL 
		
		if(isset($headers) && is_array($headers)){
			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		}
		
		
		
		curl_setopt($curl, CURLINFO_HEADER_OUT, 1); //RETURNS REQUEST HEADER
		
		## Curl Request type
		//-------------------------------------------------------------------
		if(isset($arr['request_method']) && !empty($arr['request_method'])){
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($arr['request_method']));
		}else{ //DEFAULT TO PUT REQUEST, AS IT WILL INSERT OR UPDATE, depending on if ID is supplied
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
		}
		
		## Curl Content
		//-------------------------------------------------------------------
		
		if(isset($arr['content']) && !empty($arr['content'])){
			//curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $arr['content']);
		}
		
		//--------------------------------------------------------------
		### CURL REQUEST
		//--------------------------------------------------------------
		$response                                = curl_exec($curl);
		
		//echo 'CurlError:'.curl_error($curl);
		
		### Separate status code, header, body
		
		$curl_info                               = curl_getinfo($curl);
		
		$http_status_code                        = $curl_info['http_code'];
		$header_size                             = $curl_info['header_size'];
		$request_header                          = isset($curl_info['request_header']) ? $curl_info['request_header'] : '';
		$header                                  = substr($response, 0, $header_size);
		$body                                    = substr($response, $header_size);
		
		curl_close($curl);
		
		### RESPONSE
		$this->curl_response = '';
		
		$this->curl_response['request']['url']                = $url;
		$this->curl_response['request']['header']             = $request_header;
		$this->curl_response['request']['time']               = date("Y-m-d H:i:s");
		$this->curl_response['request']['data']               = $arr['content'];
		
		$this->curl_response['response']['status_code']       = $http_status_code;
		$this->curl_response['response']['header']            = $header;
		$this->curl_response['response']['data']              = $body;
		
		$this->logAPIresponse();
		
		//deocde and store response in this object
		$this->response_decoded = json_decode($this->curl_response['response']['data']);
		
		return $this->curl_response;
		
	}
	
	
	public function logAPIresponse(){
		//connect to db
		$this->db_connect();
		
		//convert request data to json, if an array (OnDeck is already json format, Strategic is array)
		if(is_array($this->curl_response['request']['data'])){
			$request_data = json_encode($this->curl_response['request']['data']);
		}else{
			$request_data = $this->curl_response['request']['data'];
		}
		
		$esc['request_header']  = mysqli_real_escape_string($this->dbc, $this->curl_response['request']['header']);
		$esc['request_url']     = mysqli_real_escape_string($this->dbc, $this->curl_response['request']['url']);
		$esc['request_time']    = mysqli_real_escape_string($this->dbc, $this->curl_response['request']['time']);
		$esc['request_data']    = mysqli_real_escape_string($this->dbc, $request_data);
		
		$esc['response_code'] = mysqli_real_escape_string($this->dbc, $this->curl_response['response']['status_code']);
		$esc['response_header'] = mysqli_real_escape_string($this->dbc, $this->curl_response['response']['header']);
		$esc['response_body'] = mysqli_real_escape_string($this->dbc, $this->curl_response['response']['data']);
		
		$esc['api_method'] = mysqli_real_escape_string($this->dbc, $this->api_method);
		
		$esc['loaded_data'] = !empty($this->loaded_data) ? json_encode($this->loaded_data) : '';

		
		$query = "INSERT INTO `api_log` 
			(`api_method`, `api_request_header`, `api_url_called`, `api_send_time`, `api_resp_status_code`, `api_resp_header`, `api_resp_body`, `data_submitted`, `loaded_data`)
			VALUES('$esc[api_method]', '$esc[request_header]', '$esc[request_url]', '$esc[request_time]', '$esc[response_code]', '$esc[response_header]', '$esc[response_body]', '$esc[request_data]', '$esc[loaded_data]')";
		
		$query_data = $this->db_query($query);
		
		$this->last_log_id = '';		
		$this->last_log_id = $query_data['insert_id'];
	}
	
	
	
	public function build_API_URL(){
	
		$method = $this->api_method;
		
		$url = $this->api_base_url;
		
		switch($method){
			case 'customfield_UPDATE_EMAIL_LIST':
				//URL
				$url     = $url.'dealFields/'.$this->custom_email_field_id;
				$url     .= '?api_token='.$this->api_token;
				
				//METHOD
				$method  = 'PUT';
				
				//DATA
				$data = $this->build_API_request();
				$content = !empty($data) ? $data : '';
				
				break;
			

			case 'stagedeals_GET_SEND_STAGE':
				//URL
				$url     = $url.'stages/'.$this->need_send_stage_id.'/deals';
				$url    .= '?api_token='.$this->api_token;
				$url    .= '&everyone=1';
				
				//METHOD
				$method  = 'GET';
				
				//DATA
				$data = $this->build_API_request();
				$content = !empty($data) ? $data : '';
				
				break;	
			
			
			case 'deal_DETAILS':
				//URL
				$url     = $url.'deals/'.$this->deal_id;
				$url    .= '?api_token='.$this->api_token;
				
				//METHOD
				$method  = 'GET';
				
				//DATA
				$data = $this->build_API_request();
				$content = !empty($data) ? $data : '';
				
				break;	
			
			
			case 'deal_FILE_LIST':
				//URL
				$url     = $url.'deals/'.$this->deal_id.'/files';
				$url    .= '?api_token='.$this->api_token;
				
				//METHOD
				$method  = 'GET';
				
				//DATA
				$data = $this->build_API_request();
				$content = !empty($data) ? $data : '';
				
				break;	
			
			
			case 'customfield_GET_DETAILS':
				//URL
				$url     = $url.'dealFields/'.$this->custom_email_field_id;
				$url    .= '?api_token='.$this->api_token;
				
				//METHOD
				$method  = 'GET';
				
				//DATA
				$data = $this->build_API_request();
				$content = !empty($data) ? $data : '';
				
				break;	
			
			
			case 'deal_notes_ADD_NOTE':
				//URL
				$url     = $url.'notes/';
				$url    .= '?api_token='.$this->api_token;
				
				//METHOD
				$method  = 'POST';
				
				//DATA
				$data = $this->build_API_request();
				$content = !empty($data) ? $data : '';
				
				break;	
			
			case 'deal_MOVE_TO_SENT_STAGE':
				//URL
				$url     = $url.'deals/'.$this->deal_id;
				$url    .= '?api_token='.$this->api_token;
				
				//METHOD
				$method  = 'PUT';
				
				//DATA
				$data = $this->build_API_request();
				$content = !empty($data) ? $data : '';
				
				break;	
			
			
			
			
		}// end switch
		
		//return array
		$res['url']        = $url;
		$res['method']     = $method;
		$res['content']    = $content;
		
		return $res;
	}
	
	
	
	
	function getFilePath($filename = ''){
		if(empty($filename)){return false;}
		
		$directory = $this->FILE_PATH . $this->download_dir.'/'.$this->deal_id;
		
		//create dowload directory if it doesn't exit
		 if(!is_dir($directory)){
			 mkdir($directory, 0755, true);
		 }
		
		return $directory.'/'.$filename;   # ex: downloads/343434/filname.ext
	}
	
	
	function download_remote_file($file_url, $save_to){
		//download dir
		
		$content = file_get_contents($file_url);
		if(file_put_contents($save_to, $content)){
			return true;
		}else{
			return false;
		}
	}

	
	
	//loads data in this object for later use
	public function loadData($arr = ''){
		$this->loaded_data = $arr;
		
	}
	
	
	//builds json request data using loaded data for api based on current api method
	public function build_API_request(){
		
		switch($this->api_method){
			case 'customfield_UPDATE_EMAIL_LIST':
				if(!is_array($this->loaded_data)){return false;}
				
				foreach($this->loaded_data as $item){
					$js['options'][] = array('label'=>$item);
				}
				
				return json_encode($js);
				BREAK;
			/*
			case 'dealfilter_ADD':
				return '{"name":"New Filter","type":"deals","conditions":{"glue":"and","conditions":[{"glue":"and","conditions":[{"object":"deal","stage_id":"2","operator":"="}]},{"glue":"or","conditions":[]}]}}';
				BREAK;
			*/
			
			case 'deal_notes_ADD_NOTE':
				$js['deal_id'] = $this->deal_id;
				$js['content'] = $this->deal_note;
				$js['pinned_to_deal_flag'] = '1';
				
				return json_encode($js);
				BREAK;
			
		
			case 'deal_MOVE_TO_SENT_STAGE':
				$js['stage_id'] = $this->SENT_stage_id;
				
				return json_encode($js);
				BREAK;
			
			
			default:
				RETURN FALSE;
		}
	
	}
	
	
	public function set_API_routine($method_name){
		$this->api_method = preg_replace('/[A-Za-z1-9]{1,}::apifunction_/', '', trim($method_name));
		
	}
	
	/*
	public function routine_customfield_ADD(){
		$this->setPipeDriveAPIauth(self::api_master_token);

	}
	*/
	
	
	//save current local email list in lender dropdown for deals
	public function apifunction_customfield_UPDATE_EMAIL_LIST(){
		$this->set_API_routine(__METHOD__);
		
		//SET TOKEN
		$this->setPipeDriveAPIauth($this->api_master_token);
		
		//CURL API
		$this->curl_API();
			
	}
	
	//get deals from 'Send' stage
	public function apifunction_stagedeals_GET_SEND_STAGE(){
		$this->set_API_routine(__METHOD__);
		
		//SET TOKEN
		$this->setPipeDriveAPIauth($this->api_master_token);
		
		//CURL API
		$this->curl_API();
	
	}
	
	//get deal details by deal id
	public function apifunction_deal_DETAILS($id = ''){
		$this->set_API_routine(__METHOD__);
		
		if(empty($id) || !is_numeric($id)){
			$this->errors[] = __METHOD__ . ' requires a deal id';
			return false;
		}
		
		$this->deal_id = $id;
		
		//SET TOKEN
		$this->setPipeDriveAPIauth($this->api_master_token);
		
		//CURL API
		$this->curl_API();
		
		//RETURN API DATA
		if(!empty($this->response_decoded->data)){
			return $this->response_decoded->data;
		}
	
	}
	
	//move deal to sent stage
	public function apifunction_deal_MOVE_TO_SENT_STAGE($id = ''){
		$this->set_API_routine(__METHOD__);
		
		if(empty($id) || !is_numeric($id)){
			$this->errors[] = __METHOD__ . ' requires a deal id';
			return false;
		}
		
		$this->deal_id = $id;
		
		//SET TOKEN
		$this->setPipeDriveAPIauth($this->api_master_token);
		
		//CURL API
		$this->curl_API();
		
		if(!empty($this->response_decoded->data)){
			return $this->response_decoded->data;
		}
		
	}
	
	
	//get deal's files by deal id
	public function apifunction_deal_FILE_LIST($id = ''){
		$this->set_API_routine(__METHOD__);
		
		if(empty($id) || !is_numeric($id)){
			$this->errors[] = __METHOD__ . ' requires a deal id';
			return false;
		}
		
		$this->deal_id = $id;
		
		//SET TOKEN
		$this->setPipeDriveAPIauth($this->api_master_token);
		
		//CURL API
		$this->curl_API();
		
		if(!empty($this->response_decoded->data)){
			return $this->response_decoded->data;
		}
		
	}
	
	//download file using API - based on file id
	public function apifunction_deal_FILE_DOWNLOAD($file_id = ''){
		$this->set_API_routine(__METHOD__);
		
		if(empty($id) || !is_numeric($id)){
			$this->errors[] = __METHOD__ . ' requires a file id';
			return false;
		}
		
		$this->file_id = $fileid;
		
		//SET TOKEN
		$this->setPipeDriveAPIauth($this->api_master_token);
		
		//CURL API
		$this->curl_API();
	
	}
	
	//download file using API - based on file id
	public function apifunction_deal_notes_ADD_NOTE($deal_id, $note =''){
		$this->set_API_routine(__METHOD__);
		
		if(empty($deal_id) || !is_numeric($deal_id)){
			$this->errors[] = __METHOD__ . ' requires a deal id';
			return false;
		}
		
		if(empty($note)){
			$this->errors[] = __METHOD__ . ' requires a note';
			return false;
		}
		
		$this->deal_id = $deal_id;
		$this->deal_note = $note;
		
		//SET TOKEN
		$this->setPipeDriveAPIauth($this->api_master_token);
		
		//CURL API
		$this->curl_API();
	
	}
	
	
	//get custom field details based on custom field id
	public function apifunction_customfield_GET_DETAILS(){
		$this->set_API_routine(__METHOD__);
		
		//SET TOKEN
		$this->setPipeDriveAPIauth($this->api_master_token);
		
		//CURL API
		$this->curl_API();
		
		if(!empty($this->response_decoded->data)){
			return $this->response_decoded->data;
		}
		
	}
	
	
	//recursively deltes a folder and all its contents - be careful
	function DeleteFolder($path)
	{
		if (is_dir($path) === true)
		{
			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::CHILD_FIRST);

			foreach ($files as $file)
			{
				if (in_array($file->getBasename(), array('.', '..')) !== true)
				{
					if ($file->isDir() === true)
					{
						rmdir($file->getPathName());
					}

					else if (($file->isFile() === true) || ($file->isLink() === true))
					{
						unlink($file->getPathname());
					}
				}
			}

			return rmdir($path);
		}

		else if ((is_file($path) === true) || (is_link($path) === true))
		{
			return unlink($path);
		}

		return false;
	}
	
	
	public function purgeOldDownloads(){
		if(empty($this->download_dir)){
			$this->errors[] = 'Can\t delete old files, no download folder name has been provided';
			RETURN false;
		}
		
		$fullPath = $this->FILE_PATH.$this->download_dir;
		
		
		//get deal ids from database log within 30 days
		$recent_deals = $this->getRecentDeals();
		
		//get list of folder/files
		if (is_dir($fullPath)) {
			if ($dh = opendir($fullPath)) {
				while (($file = readdir($dh)) !== false) {
					if($file == '.' || $file == '..'){continue;}
					//dekete folder if not a recent deal
					if(is_array($recent_deals) && in_array($file, $recent_deals)){
						//skip
						continue;
					}else{
						//delete old deal folders
						$this->DeleteFolder($fullPath.'/'.$file);
					}
				}
			closedir($dh);
			}
		}
		
		return true;
	}
	
	
	
	public function getRecentDeals(){
		$this->db_connect();
		$query = "SELECT `deal_id` FROM `email_log` WHERE DATE(`created`) > DATE(DATE_SUB(curdate(), INTERVAL 60 DAY))";
		$result = $this->db_query($query);
		if($result['result_num_rows'] > 0){
			while($row = mysqli_fetch_assoc($result['result'])){
				$data[] = $row['deal_id'];
			}
		}else{
			return false;
		}
		
			//print_r($data);
		
		return is_array($data) ? $data : false;
		
		
	}
	
	public function purgeOldDatabase(){
		$query_arr[] = "DELETE FROM `routine_log` WHERE DATE(`created`) > DATE(DATE_ADD(curdate(), INTERVAL 45 DAY)) ";
		$query_arr[] = "DELETE FROM `email_log` WHERE DATE(`created`) > DATE(DATE_ADD(curdate(), INTERVAL 365 DAY)) ";
		$query_arr[] = "DELETE FROM `api_log` WHERE DATE(`created`) > DATE(DATE_ADD(curdate(), INTERVAL 45 DAY)) ";
		
		foreach($query_arr as $query){
			$res = $this->db_query($query);
			if(!empty($res['error'])){
				$this->errors[] =__METHOD__ . ' Database Error:'.$res['error'];
			}
		}
		
		
	}
	
	
	
	public function loadEmailTemplate(){
		if(empty($this->email_template)){	
			$this->email_template = file_get_contents($this->FILE_PATH.'/'.$this->email_template_path);
			
		}
		
		
		return $this->email_template;
	}
	
	function human_filesize($bytes, $decimals = 2) {
		$size = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
		$factor = floor((strlen($bytes) - 1) / 3);
		return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
	}
	
	
	//note: Office 365 has email rate limit of 30 emails per minute
	public function rateLimiter($mode = ''){
		if($mode == 'COUNT' && !isset($this->email_rounds)){
			$this->email_rounds = 1;
		}
		
		if($mode == 'COUNT'){
			$this->email_rounds++;
		}
		
		//when its geting close to limit, sleep
		if(!empty($this->email_rounds) && $this->email_rounds % 29 == 0){
			sleep(58);
		}
	
		
	}
	
	
	function routineTimer($method = '', $mode =''){
		if(empty($method) || empty($mode)){return false;}
		
		$mode = strtoupper($mode);
		
		if($mode == 'START'){
			$this->timer[$method]['start'] = microtime(true);
		}
	
		if($mode == 'STOP'){
			if(empty($this->timer[$method]['start'])){return false;}
			$this->timer[$method]['stop'] = microtime(true) - $this->timer[$method]['start'];
			
			return number_format($this->timer[$method]['stop'], 2);
		}
		
	}
		
	
	public function testTimer(){
		
		$this->routineTimer(__METHOD__, 'START');
		sleep(1);
		for($i=0;$i<343434;$i++){}
		
		echo $this->routineTimer(__METHOD__, 'STOP');
		
		
	}
	
	
	public function getRecentLogs(){
		$query = "SELECT *, DATE_FORMAT(`created`,'%c/%e/%Y') AS nice_date, DATE_FORMAT(`created`,'%l:%i%p') AS nice_time FROM `routine_log` ORDER BY `id` DESC LIMIT 10 ";
		$result = $this->db_query($query);
		if($result['result_num_rows'] > 0){
			while($row = mysqli_fetch_assoc($result['result'])){
				$data[] = $row;
			}
		}else{
			return false;
		}
		
		return is_array($data) ? $data : false;
	}
	
	
	public function getRecentEmailLogs(){
		$query = "SELECT *, DATE_FORMAT(`created`,'%c/%e/%Y') AS nice_date, DATE_FORMAT(`created`,'%l:%i%p') AS nice_time FROM `email_log` ORDER BY `id` DESC LIMIT 200 ";
		$result = $this->db_query($query);
		if($result['result_num_rows'] > 0){
			while($row = mysqli_fetch_assoc($result['result'])){
				$data[] = $row;
			}
		}else{
			return false;
		}
		
		return is_array($data) ? $data : false;
	}
	
	public function getLenders($mode = false){
		$query = "SELECT * FROM `lender_list` WHERE `deleted`!='1' ORDER BY `lender_name` ASC";
		$result = $this->db_query($query);
		if($result['result_num_rows'] > 0){
			while($row = mysqli_fetch_assoc($result['result'])){
				if($mode == 'NAMES_ONLY'){
					$data[] = $row['lender_name'];
				}else{
					$data[] = $row;
				}
				
			}
		}else{
			return false;
		}
		
			//print_r($data);
		
		return is_array($data) ? $data : false;
	}
	
	
	public function getLenderEmail($lender_name = ''){
		
		$query = "SELECT `lender_email` FROM `lender_list` WHERE `lender_name`='".$lender_name."' LIMIT 1 ";
		$result = $this->db_query($query);
		
		if($result['result_num_rows'] > 0){
			while($row = mysqli_fetch_assoc($result['result'])){
				$data = $row['lender_email'];
			}
		}else{
			return false;
		}
		
		return !empty($data) ? $data : false;
		
	}
	
	### ROUTINES
	#----------------------------------------------------------------------------------
	//runs all sub routines
	public function routine_general(){
		/*
			
			### foreach deal
				//API - Details (contains user info)
				//API - File List
					//API - Download file
				//API - Custom Field (Lender Emails)
				//send emails
				//use pipedrive api to move deal to 'sent to lender' stage
				//keep track of all emails sent
			
			//send report to Max?
			
		*/
		
		//id for database log
		$this->routine_id     = time();
		$this->routine_name   = __METHOD__;
		
		$this->routineTimer($this->routine_name, 'START');
		
		//get send stage deals
		$this->apifunction_stagedeals_GET_SEND_STAGE();
		
		//if api call success
		if($this->curl_response['response']['status_code'] == 200 && $this->response_decoded->success == 1){
			//store deals in current object
			$this->deals = is_array($this->response_decoded->data) ? $this->response_decoded->data : false;
		}
		
		//if deals
		if($this->deals != false){
			//include PHP Mailer
			require_once($this->FILE_PATH . '/'.$this->php_mailer_path);
			
			//$this->mail = new PHPMailer;
			$mail = new PHPMailer();
			$mail->isHTML(true); 
				//$mail->SMTPDebug = 3;                               // Enable verbose debug output
			
			
			// https://technet.microsoft.com/en-us/library/dn554323(v=exchg.150).aspx //rate limts smtp
			
			//smtp setup
			$mail->isSMTP();                                      # Set mailer to use SMTP

			$mail->Host = 'mail.com';                   # Specify main and backup SMTP servers
			$mail->SMTPAuth = true;                               # Enable SMTP authentication
			$mail->Username = 'test@test.com';   # SMTP username
			$mail->Password = 'secret';                         # SMTP password 
			$mail->SMTPSecure = 'tls';                            # Enable TLS encryption, `ssl` also accepted
			$mail->Port = 587;                                    # TCP port to connect to

			
			//include email template
			$email_body = $this->loadEmailTemplate();
			$mail->Body = $email_body;
			
			
			
			//get custom field details from api
			$this->custom_field_details = $this->apifunction_customfield_GET_DETAILS();
			if(empty($this->custom_field_details) || empty($this->custom_field_details->key)){$this->errors[] = 'Custom Field Data not found';}
			
			//set available recipients using custom field options
			if(count($this->custom_field_details->options) > 0){
				foreach($this->custom_field_details->options as $item){
					$this->available_recipients[$item->id] = $item->label;
				}
			}else{
				$this->available_recipients = false;
				$this->errors[] = 'No available recipients';
			}

			//print_r($this->available_recipients);
			
			//count deals
			$this->deal_count = count($this->deals);
			
			//loop through deals
			for($i = 0; $i < $this->deal_count; $i++){
				$this->iteration = $i;
				
				
				//will sleep to avoid email provdor wait limit
				$this->rateLimiter();
				
				
				//store deal in small var
				$current_deal = '';
				$current_deal = $this->deals[$i];

				
				unset($this->deal_id);
				$this->deal_id = $current_deal->id;
				
				//pull deal details based on deal id
				$deal_details = '';
				$deal_details = $this->apifunction_deal_DETAILS($this->deal_id);
				$this->deals[$i]->api_data['deal_details'] = $deal_details;
				
				
				//print_r($current_deal);
				//print_r($deal_details);
				
				//check custom field for email addresses
				$custom_field_key = $this->custom_field_details->key;
				
				//echo 'Customfieldkey: '.$custom_field_key;
				
				$this->deals[$i]->api_data['custom_key'] = $custom_field_key;
				
				
				if(empty($current_deal->$custom_field_key)){
					$this->errors[] = 'Deal: '.$current_deal->title.' (#'.$current_deal->id.') does not have any lenders selected';
					continue; //skip this deal
				}

				
				$recip_ids = explode(',', $current_deal->$custom_field_key);
				$this->deals[$i]->api_data['recip_ids'] = $recip_ids;
				
				$recipients = '';
				foreach($recip_ids as $item){
					$recipients[$item] = $this->available_recipients[$item];
				}
				
				
				$this->deals[$i]->api_data['recipients'] = $recipients;
				
				//need to pull custom field selections and check what has been sent in database
				
				//pull file list
				$file_list = '';
				echo 'Pulling files from Deal: '.$current_deal->id;
				$file_list = $this->apifunction_deal_FILE_LIST($current_deal->id);
				
				
				$this->deals[$i]->api_data['file_list'] = $file_list;
				
				$total_file_size = '';
				
				//clear attachments
				$attachments = '';
				
				//check for list fo files - returned from api
				if(!empty($file_list) && is_array($file_list)){
					//loop through deal's file list
					foreach($file_list as $file){
						$have_file = false;
						//set file handle
						$file_handle = $this->getFilePath($file->file_name);
						
						//check for local file
						if(file_exists($file_handle)){
							$have_file = true;
						//if no local file, download and store
						}else{
							$download_url = $file->url.'?api_token='.$this->api_token;
							$this->download_remote_file($download_url, $file_handle);
							
							//check for local file
							$have_file = file_exists($file_handle);
						}
						
						//if we have local file
						if($have_file){
							//add to attachments
							$attachments[]  = $file_handle;
							$total_file_size += filesize($file_handle);
						}else{
							$this->error[] = 'Error retrieving file: '.$file_handle;
						}
						
					}//end filelist loop
					
					
					$this->deals[$i]->api_data['attachments'] = $attachments;
					
					if(!empty($attachments)){
						
						//Email
						#----------------------------------------
						
							//clear previous data from phpmailer
							$mail->ClearAddresses();
							$mail->ClearBCCs();
							$mail->clearReplyTos();
							$mail->ClearAllRecipients();
							$mail->clearAttachments();
							
							//print_r($deal_details);
							
							//reply to
							$mail->addReplyTo($deal_details->user_id->email, $deal_details->user_id->name);
							
							$mail->addCC($deal_details->user_id->email, $deal_details->user_id->name); //new
							
							$mail->setFrom('test@test.com', $deal_details->user_id->name);
							
							//subject
							$mail->Subject = !empty($deal_details->org_id->name) ? $deal_details->org_id->name.' / Name capital' : 'New Application / Name capital';
						
							//loop through attachements and add to email
							foreach($attachments as $file){
								$mail->addAttachment($file);
							}
							
							$email_notes = '';
							$emn_i = 0;
							
							//print_r($mail); exit;
							
							print_r($recipients); 
							
							$move_deal = false; //will switch to true if at least one email is sent
							
							//send emails individually
							foreach($recipients as $lender_name){
								ECHO $lender_name; 
								
								//RETRIEVE LENDER EMAIL FROM DB
								$lender_email = false;
								$lender_email = $this->getLenderEmail($lender_name);
								
								echo $lender_email;
								
								if($lender_email == false || empty($lender_email)){
									$this->errors[] = 'Lender Email not found for '.$lender_name;
								}
								
								
								/*
								//This commented out section is for preventing emails from being sent twice, if someone puts them back into 'needs send' stage
								//note this section will no longer with if uncommented since the lender list has changed to names and not emails, I left the code as example if they request this funcitonality
								
								$email_esc = mysqli_real_escape_string($this->dbc, $lender_name);
								$id_esc = mysqli_real_escape_string($this->dbc, $current_deal->id);
								
								$query_sent = "SELECT * FROM `email_log` WHERE `deal_id`='".$id_esc."' AND `recipient_email`='".$email_esc."' AND `success`='1' ";
								$check = $this->db_query($query_sent);
								
								//check if already sent, skip if so
								if($check['result'] && $check['result_num_rows'] > 0){
									$this->errors[] = 'Email to '.$lender_name.' already sent for '.$current_deal->title.' (#'.$current_deal->id.')';
									CONTINUE;
									
								}
								*/
								
								//SET LENDER ADDRESS IN MAILER
								$mail->addAddress($lender_email); //LIVE !!!
	
								
								
								$sent = false;
								//send email
								$sent = $mail->send();
								
								if($sent){
									$move_deal = true;
								}
								
								//echo 'SENT: '.$sent; 
								//print_r($mail->ErrorInfo);
								//exit;
								
								//increment rate limiter counter
								$this->rateLimiter('COUNT');
								
								$email_notes[$emn_i]['msg']['name']   = $lender_name;
								$email_notes[$emn_i]['msg']['email']   = $lender_name;
								$email_notes[$emn_i]['msg']['sent']    = $sent;
								
								
								
								//log in db
								#----------------------------------------
									$qitems['routine_id']                    = $this->routine_id;
									$qitems['deal_id']                       = $current_deal->id;
									$qitems['deal_title']                    = $current_deal->title;
									$qitems['deal_user_id']                  = $deal_details->user_id->id;
									$qitems['deal_user_email']               = $deal_details->user_id->email;
									$qitems['deal_user_name']                = $deal_details->user_id->name;
									$qitems['number_attachments']            = count($attachments);
									$qitems['attachments_list']              = json_encode($attachments);
									$qitems['total_file_size_attachments']   = $this->human_filesize($total_file_size, 2);
									$qitems['lender_name']                   = $lender_name;
									$qitems['recipient_email']               = $lender_email;
									$qitems['success']                       = $sent;
									$qitems['mailer_error']                  = !empty($mail->ErrorInfo) ? $mail->ErrorInfo : '';
									
									//default empty to null in databse
									foreach($qitems as $key => $val){
										if(empty($val)){
											unset($qitems[$key]);
										}elseif($key != 'attachments_list'){ //escape item, unless its json data
											$qitems[$key] = mysqli_real_escape_string($this->dbc, $val);
										}
									}
									
									
									//log foreach recipient???
									
									$query = "INSERT INTO `email_log` (`".implode("`, `", array_keys($qitems))."`)
										VALUES('".implode('\', \'', $qitems)."')";
									
									echo $query;
									
									$this->db_query($query);		
									
								
								// Clear all addresses and attachments for next loop
								#----------------------------------------
									$mail->clearAddresses();
									//$mail->clearAttachments();
							
								//increment email note array key	
								$emn_i++;
								
							}//end loop through recipients / email
							
							$this->deals[$i]->api_data['email_notes'] = !empty($email_notes) ? $email_notes : '';
							
							//set note in deal, using APIM if emails were sent/attempted
							if(!empty($email_notes)){
								$note_html = '';
								$note_html .= 'Auto Email Send List ('.date("F j, Y, g:i a").'):<br>';
								//add list of emails with sent status
								foreach($email_notes as $note_item){
									$note_html .= $note_item['msg']['name'].' ('.($note_item['msg']['sent'] == 1 ? 'Sent' : 'Failed to send').') <br>';
								}
								
								//send note to api
								$this->apifunction_deal_notes_ADD_NOTE($current_deal->id, $note_html);
							}
						

						//Move deal to sent stage
						#----------------------------------------
							if($move_deal){
								$this->apifunction_deal_MOVE_TO_SENT_STAGE($current_deal->id);
							}
					}else{
						$this->errors[] = 'Deal: '.$current_deal->title.' (#'.$current_deal->id.') does not have any attachments or an error occured downloading them';
					}
					
					
				}else{
					$this->errors[] = 'Deal: '.$current_deal->title.' (#'.$current_deal->id.') does not have any attachments in Pipedrive';
				}
				
			
					
			}//end deal loop

			
		}else{
			$this->errors[] = 'No Deals found to process during this routine';
		}
	
		
		///remove old files from downloads folder
		#----------------------------------------	
			$this->purgeOldDownloads();
		
		//purge old database records
		#----------------------------------------
			$this->purgeOldDatabase();
		
		
		//LOG ROUTINE
		#----------------------------------------
			$runtime = $this->routineTimer($this->routine_name, 'STOP');
			
			
			$r_query['routine_id']          = $this->routine_id;
			$r_query['routine_name']        = $this->routine_name;
			$r_query['routine_total_time']  = $runtime;
			$r_query['memory_usage']        = memory_get_peak_usage(false) / 1024 / 1024;
			$r_query['errors']              = json_encode($this->errors);
			//$r_query['object']              = json_encode($this);
			
			foreach($r_query as $key => $val){
				if(empty($val)){
					unset($r_query[$key]);
				}elseif($key != 'errors' && $key != 'object'){ //escape, unless its json data
					$r_query[$key] = mysqli_real_escape_string($this->dbc, $val);
				}
			}
			
			
			$query = "INSERT INTO `routine_log` (`".implode("`, `", array_keys($r_query))."`)
					VALUES('".implode('\', \'', $r_query)."')";

			
			$this->db_query($query);	
		
	}
	
	
}//end class



### TESTING
#--------------------------------------------------------------------------
if(!empty($_GET['test']) && $_GET['test'] == 'test_mode'){
	echo 'TEST MODE';
	//echo $_SERVER["DOCUMENT_ROOT"];
	
	$pipedrive = new PipeApp($_SERVER["DOCUMENT_ROOT"].'/pipedrive');
	
	
	/*
	$clean_emails = ARRAY('tet@tst.com', 't2@ee.com', 'aa@www.com');

	$pipedrive->loadData($clean_emails);
	
	$pipedrive->apifunction_customfield_UPDATE_EMAIL_LIST();
	*/
	
	//$pipedrive->apifunction_stagedeals_GET_SEND_STAGE();
	
	//$res = $pipedrive->apifunction_deal_DETAILS(5);
		//print_r($res);
	
	//$res = $pipedrive->apifunction_deal_notes_ADD_NOTE(2, 'Testing api notese');
		//print_r($res);
	

	
	//$res = $pipedrive->apifunction_customfield_GET_DETAILS();
		//print_r($res);
		
		
		
		
	//$pipedrive->routine_general();
	
	
	//$pipedrive->testTimer();
	
	
	//print_r($pipedrive->deals);
	
	//$pipedrive->purgeOldDownloads();	
	
	//$pipedrive->rateLimiter();	
	
	
	
	//print_r($pipedrive);
	
	//$pipedrive->api_method = 'customfield_UPDATE_EMAIL_LIST'; 
	//$json =$pipedrive->build_API_request();
	//print_r($pipedrive->loaded_data);
	//print_r($json);
	
}
