<?php
/*
	- Extracts data from email pipe, adds leads to database, and sends leads with phone numbers to five9
	
	- Leads are only added to database if they are not found in either table
	
	- Phone number containing leads ar always sent to five9, since five9 doesn't seem to provide a way to check if they exist using api, however it appears that five9 api will return a successful response for duplicate lead submissions, but does not appear to create a duplicate entry...

*/

#############################################################################
class Scraper{
	public $text = '';
	public $type = '';
	public $source = '';
	
	public $errors = ''; 
	
	protected $sourcesArr = array(
			"ReachLocal"=> array(
				'source_name'=>'ReachLocal', //must be same as key
				'reg_pattern'=>'reachlocal', //pattern to search text for in order to confirm source
				'threshold'=>5,               //minimum amount of times which reg_pattern should appear to confirm this source
				'type_detection_method_name'=>'typeReachLocal',  //name of method to call in order to confirm text type for this source
				'process_info_method_name'=>'processInfo_ReachLocal',  //name of method to call to process data after it is extracted
				),
			"ClickDesk"=> array(
				'source_name'=>'ClickDesk',
				'reg_pattern'=>'clickdesk',
				'threshold' =>3,
				'type_detection_method_name'=>'typeClickDesk',
				'process_info_method_name'=>'processInfo_ClickDesk'
				)		
		);
	
	protected $locationArr = array(
			"Brooklyn"=>array(
				"table"=>'Brooklyn',
				"keywords"=>array('queens', 'brooklyn', 'jamiaca', 'broklyn')
			),
			"Manhattan"=>array(
				"table"=>'Manhattan',
				"keywords"=>array('manhattan', 'manahttan', 'manhatan', 'houston', 'rivington', '112 ridge')
			)
		);
	
	/*
	protected $extractArr = array(
			"ReachLocal_SingleLead"=>'extract'
		);
	*/
	
	//pass text into object
	function __construct($text=''){
		if(empty($text)){
			$this->errors[] = 'Text is empty';
		}
		$this->text = $text;
	}
	
	//run the entire scraping process for the provided text
	public function routineScrape(){
		$this->detectSource();
		$this->detectType();
		$this->extractData();
		$this->processInfo();
		
	}
	
	//determine the type of text, requires Source to be set
	public function detectType(){
		//make sure text source has been determined
		if(!isset($this->source) || empty($this->source)){
			$errors[] = 'Trying to detect text type, when no Source has been set';
			return;
		}
		
		//make sure method has been defined for this Source
		$type_detection_method_name = $this->sourcesArr[$this->source]['type_detection_method_name'];
		if(!isset($type_detection_method_name) || empty($type_detection_method_name)){
			$errors[] = 'Trying to detect text type, when no type detection method name has been provided for this Source';
			return;
		}
		
		//make sure method exists
		if(!method_exists($this, $type_detection_method_name)){
			$errors[] = 'Trying to detect text type, when the type detection method does not exist in this class';
			return;
		}
		
		//if no errors, call this Sources' type detection method to determine and set type of text
		$type = $this->$type_detection_method_name();
		
		return $this->type = $type;
	}
	
	//determine the Source of the text
	public function detectSource(){
		$source_name = '';
		//loop through possible sources
		foreach($this->sourcesArr as $source){
			$matches = '';
			$pattern = "/".$source['reg_pattern']."/i";
			preg_match_all($pattern, $this->text, $matches);
			//print_r($matches);
			//count matches for source's regex pattern
			$match_count = count($matches[0]);
			
			//if the match count meets threshold, accept this source as the source
			if($match_count >= $source['threshold']){
				$source_name = $source['source_name'];
				break;//exit foreach loop of sources, we have found the match
			}	
		}
		//set error if no source is found
		if(empty($source_name)){
			$this->errors[] = 'No Source found';
		}
		
		//set and return object source name
		return $this->source = $source_name;
	}
		
	//calls specific class method to scrape desired text for this source and type	
	public function extractData(){
		$type_method = 'extract_'.$this->source.'_'.$this->type;
		if(empty($type_method) || !method_exists($this, $type_method)){
			$this->errors[] = 'Extraction Method for this Source and Type does not exist';
			return;
		}
		
		$this->$type_method();
	}	
	
	public function processInfo(){
		$process_info_method_name = $this->sourcesArr[$this->source]['process_info_method_name'];
		if(!isset($process_info_method_name) || empty($process_info_method_name)){
			$errors[] = 'Trying to process information, when no process info method name has been provided for this Source';
			return;
		}
		
		$this->$process_info_method_name();
		
	}

	
	#################################################################
	### Source Specific Methods
	
	public function typeReachLocal(){
		$typesArr = array(
			"SingleLead" => array(
				"text_type" => 'SingleLead',
				"terms" => array('Notification', 'Call Length', 'Campaign', 'CALLER ID')
			)
		);
		
		$match = false;
		foreach($typesArr as $type){
			$match = false;
			foreach($type['terms'] as $patt){
				$pattern = "/".$patt."/";
				if(preg_match($pattern, $this->text)){
					$match = true;
				}else{
					$match = false;
				}
			}
			if($match === true){
				$text_type = $type['text_type'];
				break;
			}
		}
		if(!isset($text_type) || empty($text_type)){
			$this->errors[] = 'Text Type can not be determined for this Source and Text';
		}
			
		return $this->text_type = $text_type;	
	}
	
	public function typeClickDesk(){
		$typesArr = array(
			"Chat" => array(
				"text_type" => 'Chat',
				"terms" => array('Email:', 'Name:', 'chat')
			)
		);
		
		$match = false;
		foreach($typesArr as $type){
			$match = false;
			foreach($type['terms'] as $patt){
				$pattern = "/".$patt."/";
				if(preg_match($pattern, $this->text)){
					$match = true;
				}else{
					$match = false;
				}
			}
			if($match === true){
				$text_type = $type['text_type'];
				break;
			}
		}
		if(!isset($text_type) || empty($text_type)){
			$this->errors[] = 'Text Type can not be determined for this Source and Text';
		}
			
		return $this->text_type = $text_type;	
	}
	
	public function formatName($fullname = ''){
		return ucwords(strtolower(trim($fullname)));
	}	
	
	public function formatPhone($phone = ''){
		$phone = preg_replace('/\s/', '', $phone);
		if(strlen($phone) > 10){
			$phone = preg_replace('/^1/', '', $phone);
			$phone = preg_replace('/\+1/', '', $phone);
		}
		
		return $phone;
	}
	
	
	public function extract_ReachLocal_SingleLead(){
		//get campaign
		$campaign_term = 'Campaign: ';
		preg_match('/'.$campaign_term.'.+/', $this->text, $matches);
		$data['campaign'] = (isset($matches[0]) && !empty($matches[0])) ? trim(str_replace($campaign_term, '', $matches[0])) : '';
		unset($matches);
		
		//phone & name
		preg_match("/Subject: (.*)Mime/s", $this->text, $matches);
		if(isset($matches[1]) && !empty($matches[1])){
			//remove outer whitespace
			$subject_line = trim($matches[1]);
			//print_r($subject_line);
			
			//PHONE
			preg_match('/([0-9]{1} [0-9]{3} [0-9]{3} [0-9]{4})|(\+[0-9]{1,}$)|([0-9]{10,11} )|([0-9]{1} [0-9]{3} [0-9]{3}\n [0-9]{4})|([0-9]{1} [0-9]{3} [0-9]{3}[\s]+\n[\s]+[0-9]{4})/m', $subject_line, $phone_matches);
			//print_r($phone_matches);
			$data['phone'] = $this->formatPhone($phone_matches[0]);
			
			//another extraction method added when phone number is split to new line 9/11/2015
			if(empty($phone_matches[0]) || empty($data['phone'])){
				$exp = explode('-', $subject_line);
				if(!empty($exp[1])){
					preg_match_all('/[0-9]/', $exp[1], $num_matches);
					$data['phone'] = is_array($num_matches[0]) ? $this->formatPhone(implode('', $num_matches[0])) : '';
				}
			
			}
			
			
			//NAME
			//preg_match('/: (.*) - \+/i', $subject_line, $name_matches); //original, broke
			preg_match('/: (.*) [-+]{1}/i', $subject_line, $name_matches); //fix 9/10/2015
			//print_r($name_matches);
			if(isset($name_matches[1])){
				$data['full_name'] = $this->formatName($name_matches[1]);
			}
			
		}
		
		$this->ext_data = $data;
	}
	
	public function extract_ClickDesk_Chat(){
		//name
		preg_match('/Name: (.*)/', $this->text, $name_matches);
		//print_r($name_matches);
		if(isset($name_matches[1])){
			$data['name'] = trim($name_matches[1]);
			$data['name'] = $this->niceCase($data['name']);
		}
		
		//email
		preg_match('/Email: (.*)/', $this->text, $email_matches);
		//print_r($email_matches); 
		if(isset($email_matches[1])){
			preg_match('/mailto:(.*)>/', $email_matches[1], $email_m);
			//print_r($email_m);
			if(isset($email_m[1])){
				$data['email'] = trim($email_m[1]);
			}else{
				$email_m = trim($email_matches[1]);
				if(isset($email_m) && !empty($email_m)){
					$data['email'] = $email_m;
				}
			}
		}
		//visitor url
		preg_match('/Visitor URL: (.*)/', $this->text, $url_matches);
		//print_r($url_matches);
		$mat = trim($url_matches[1]);
		if(isset($url_matches[1]) && !empty($mat)){
			preg_match('/<(.*)>/', $url_matches[1], $url_m);
			//print_r($url_m);
			if(isset($url_m[1])){
				$data['referral_url'] = trim($url_m[1]);
			}
		}else{
			$exp = explode('Visitor URL:', $this->text);
			//print_r($exp[1]);
			$exp_2 = explode('Client', $exp[1]);
			$url = trim($exp_2[0]);
			if(isset($url) && !empty($url)){
				$data['referral_url'] = $url;
			}else{
				$this->notice[] = 'Visitor URL could not be extracted';
			}
		}	
		//echo 'extracted data';
		$this->ext_data = $data;
	}
	
	public function niceCase($name = ''){
		return ucwords(strtolower($name));
	}
	
	//function: switch databse connection based on table name
	public function switchDatabase($table){
		if($table == 'hidden'){
			$host = "localhost"; 
			$user = "secret"; 
			$pass = "secret"; 
			$db_name = "secret";
		}elseif($table == 'hidden_2'){
			$host = "localhost";        
			$user = "secret";   
			$pass = "secret";     
			$db_name = "secret";  
		}
		$this->table_used = $table;
		return mysqli_connect($host, $user, $pass, $db_name);	
	}
	
	public function getOtherTable($table = ''){
		if($table == 'hidden'){
			return 'hidden_2';
		}elseif($table = 'hidden_2'){
			return 'hidden';
		}
		
		return false;
	}
	
	
	//stores info in database
	public function processInfo_ClickDesk(){
		//make sure data isn't empty
		if(!isset($this->ext_data['email']) || empty($this->ext_data['email'])){
			$this->errors[] = 'Email missing when trying to process extracted data';
			return;
		}
		
		//try to determine location
		$this->ext_data['brooklyn_count'] = 0;
		foreach($this->locationArr['Brooklyn']['keywords'] as $term){
			$patt = '';
			$patt = '/'.$term.'/i';
			preg_match_all($patt, $this->text, $matches);
			$this->ext_data['brooklyn_count'] += count($matches[0]);
		}

		//echo $this->ext_data['brooklyn_count'];

		$this->ext_data['manhattan_count'] = 0;
		foreach($this->locationArr['Manhattan']['keywords'] as $term){
			$patt = '';
			$patt = '/'.$term.'/i';
			preg_match_all($patt, $this->text, $matches);
			$this->ext_data['manhattan_count'] += count($matches[0]);
		}

		//echo $this->ext_data['manhattan_count'];
		
		switch(true){
			case ($this->ext_data['manhattan_count'] > $this->ext_data['brooklyn_count']):
				$insert_table = 'hidden';
				break;
			case ($this->ext_data['manhattan_count'] < $this->ext_data['brooklyn_count']):
				$insert_table = 'hidden_2';
				break;
			default:
			$insert_table = 'hidden_2';
		}
		
		$dbc = $this->switchDatabase($insert_table);
		
		$email_esc = mysqli_real_escape_string($dbc, $this->ext_data['email']);
		$full_name_esc = mysqli_real_escape_string($dbc, $this->ext_data['name']);
		
		$ref_url = 'ClickDesk: ';
		$ref_url .= (isset($this->ext_data['referral_url']) && !empty($this->ext_data['referral_url'])) ? $this->ext_data['referral_url']  : '';
		
		$ref_url_esc = mysqli_real_escape_string($dbc, $ref_url);
		
		$notes = "Source: Email Pipe - ClickDesk Chat - (".date('m/d/Y h:i A').")";
		
		//lookup
		$query = "S2ECT * FROM $insert_table WHERE `email_address`='$email_esc' ";
		$this->queries[] = $query;
		$result = mysqli_query($dbc, $query);
		
		if($result->num_rows >= 1){
			$this->notice[] = "This email already exists in '".$insert_table."' table.";
			return;
		}
		
		//switch table and check for lead
		$insert_table = $this->getOtherTable($insert_table);
		$dbc = $this->switchDatabase($insert_table);
		
		$query = "S2ECT * FROM $insert_table WHERE `email_address`='$email_esc' ";
		$result = mysqli_query($dbc, $query);
		$this->queries[] = $query;
		if($result->num_rows >= 1){
			$this->notice[] = "This email already exists in '".$insert_table."' table.";
			return;
		}
		
		
		//if found - exit, log
		
		//not found add to db
		$query = "INSERT INTO $insert_table (`name`, `phone`, `email`, `ref_page`, `note`)
			VALUES('$full_name_esc', 'na', '$email_esc', '$ref_url_esc', '$notes') ";
		$this->queries[] = $query;
		$result = mysqli_query($dbc, $query);
		if($result == 1){
			$this->notice[] = "Lead added to '".$insert_table."'.";
		}
	}
	
	public function processInfo_ReachLocal(){
		//make sure data isn't empty
		if(!isset($this->ext_data['phone']) || empty($this->ext_data['phone'])){
			$this->errors[] = 'Phone missing when trying to process extracted data.';
			return;
		}	
		
		if(preg_match('/(queens|brooklyn)/i', $this->ext_data['campaign'])){
			$insert_table = 'hidden_2';
		}else{
			$insert_table = 'hidden';
		}
		
		
		//send to five9
		$response = $this->pushFive9($this->ext_data);
		
		$this->notice[] = 'Lead sent to Five9 - see log for response status';
		
		
		$dbc = $this->switchDatabase($insert_table);
		
		$phone_esc = mysqli_real_escape_string($dbc, $this->ext_data['phone']);
		$full_name_esc = mysqli_real_escape_string($dbc, $this->ext_data['full_name']);
		$campaign_esc = mysqli_real_escape_string($dbc, $this->ext_data['campaign']);
		
		$ref_url = 'ReachLocal: ';
		$ref_url .= isset($this->ext_data['campaign']) ? $this->ext_data['campaign'] : '';
		
		$ref_url_esc = mysqli_real_escape_string($dbc, $ref_url);
		
		$notes = "Source: Email Pipe - ReachLocal Lead (".date('m/d/Y h:i m').")";
		
		//lookup
		$query = "S2ECT * FROM $insert_table WHERE `phone_number`='$phone_esc' ";
		$this->queries[] = $query;
		$result = mysqli_query($dbc, $query);
		
		if($result->num_rows >= 1){
			$this->notice[] = "This phone number already exists in '".$insert_table."' table.";
			return;
		}
		
		//switch table and check for lead
		$insert_table = $this->getOtherTable($insert_table);
		$dbc = $this->switchDatabase($insert_table);
		
		$query = "S2ECT * FROM $insert_table WHERE `phone_number`='$phone_esc' ";
		$this->queries[] = $query;
		$result = mysqli_query($dbc, $query);
		
		if($result->num_rows >= 1){
			$this->notice[] = "This phone number already exists in '".$insert_table."' table.";
			return;
		}
		
		
		//not found add to db
		$query = "INSERT INTO $insert_table (`full_name`, `phone_number`, `referral_landing_page`, `notes`)
			VALUES('$full_name_esc', '$phone_esc', '$ref_url_esc', '$notes') ";
		$this->queries[] = $query;
		$result = mysqli_query($dbc, $query);
		if($result == 1){
			$this->notice[] = "Lead added to '".$insert_table."'.";
		}
		
	}
	
	
	public function pushFive9($arr = ''){
		if(empty($arr)){return;}
		// send api request
		$url  = 'https://api.five9.com/web2campaign/AddToList?F9domain=All%20My%20Children&F9list=landingWEB&number1='.preg_replace('/[^0-9]/', '', $arr['phone']);
		$url .= '&last_name='.urlencode($arr['full_name']);
		//$url .= '&email_address='.urlencode($_POST['post']['e-mail']);
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_TIMEOUT, 12);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		
		$response = curl_exec($curl); // returns 201 code on successful lead pass.
		
		$curl_info                               = curl_getinfo($curl);		
		
		$http_status_code                        = $curl_info['http_code'];
		$header_size                             = $curl_info['header_size'];
		$request_header                          = isset($curl_info['request_header']) ? $curl_info['request_header'] : '';
		$header                                  = substr($response, 0, $header_size);
		$body                                    = substr($response, $header_size);
		
		curl_close($curl);
		
		### RESPONSE
		$this->curl_response['request']['url']                = $url;
		//$this->curl_response['request']['header']             = $request_header;
		$this->curl_response['request']['time']               = date("Y-m-d H:i:s");
		//$this->curl_response['request']['data']               = $arr['content'];
		
		$this->curl_response['response']['status_code']       = $http_status_code;
		//$this->curl_response['response']['header']            = $header;
		//$this->curl_response['response']['data']              = $body;
		$this->curl_response['response']['data']              = $response;
		
		//check for error code & description
		preg_match("/name=\"F9errCode\" value=\"(.*?)\"/", $response, $matches);
		if(isset($matches[1]) && !empty($matches[1])){
			$this->curl_response['response']['five9_error_code'] = $matches[1];
		}
		
		unset($matches);
		preg_match("/name=\"F9errDesc\" value=\"(.*?)\"/", $response, $matches);
		if(isset($matches[1]) && !empty($matches[1])){
			$this->curl_response['response']['five9_error_description'] = $matches[1];
		}
		
		return $this->curl_response;
		
	}
	
}


