<?php

set_time_limit(400);

class Scraper{
	protected $mode = ''; // DEV or LIVE
	
	### scrape url
	protected $scrape_url_base = 'http://biz.yahoo.com/research/earncal/'; //base portion of url to scrape
	protected $scrape_url_ext = '.html';
	
	
	public $apiURL = ''; //needs to be public as it is set in external scripts sometimes
	public $apiTOKEN = ''; //needs to be public as it is set in external scripts sometimes

	
	public $api_url_array = array( //BE SURE forward slash is at end of url (/) //this array must stay public
		"earnings_scrape"=> array(
			"url"               => 'http://link.com',
			"auth_token"        => 'secret'
		),
		"api_news"=>array(
			"url"               => 'http://link.com',
			"auth_token"        => 'secret'
		),
		"api_broadcast"=>array(
			"url"               => 'http://link.com',
			"auth_token"        => 'secret'

			
		)
	);
	
	
	### database credntials
	protected $db_host = 'localhost';
	protected $db_user = 'secret';
	protected $db_pass = 'secret';
	protected $db_database = 'secret';

	### stores current round of date cycle
	protected $current_round = '';
	
	### stores formatted earnings data from scrape
	protected $extracted_data = array();
	
	### class vars
	public $dbc = ''; //db connection
	public $html = ''; //stores scraped html
	public $breakpoint = ''; //stores main portion of html to break data from
	public $table_html = ''; //stores main html code from table os its easier to work with
	
	public $errors = array();
	public $dates = '';
	
	
	#############################################################################################
	
	public function __construct($arr) {
		//currently the main thing dev vs. live is used for is to either scrape local file(dev) or yahoo(live)
		if(isset($arr['mode']) && strtolower($arr['mode']) == 'dev'){
			$this->mode = 'DEV';
		}else{
			$this->mode = 'LIVE';
			
		}
		
		//run routine
		
		### ROUTINE
		//$this->generateDays();                       //determine days to scrape
		//loop through days and scrape
		
		//$this->curlPull($this->scrape_url_base);     //curl request for stock data
		//$this->extractBase();                        //extract base html table section
		//$this->extractTable();                      //extract table rows/cells
		//$this->buildJSON();                          //format data in json
		//$this->logScrape(); 
	}
	
	
	public function runRoutine($arr){
		$routine = $arr['routine'];
		switch($routine){
			case 'scrape_and_send':
				//set api url
				$this->apiURL = $this->api_url_array['earnings_scrape']['url'];
				$this->apiTOKEN = $this->api_url_array['earnings_scrape']['auth_token'];
				
				$this->generateDays();                     # determine days to scrape	
				
				$this->setCurrentScrape();                 # Pull scrape record for current day
					/* 
						Note: 'runRoutine' is called multiple times per day as a cron job, we only want to scrape once per day, and possibly reesend data in database in case the api was down or whatver
					*/
				
				### IF EARNINGS HAVE NOT BEEN SCRAPED TODAY
				if(!isset($this->current_earnings) || empty($this->current_earnings)){
					
					//LOOP THROUGH DATES TO SCRAPE
					foreach($this->dates['future'] as $date){
						//set current round from date
						$this->current_round = $date;          # this var is used to build the url to be scraped
						
						# adjust url for date
						$this->scrape_url = $this->scrape_url_base.$this->current_round[0].$this->scrape_url_ext;  
						
						$this->curlPull($this->scrape_url);    # curl request for stock earnings data
						sleep(2);                              # add slight delay here so we dont access site too fast--don't want to get ip blocked by Yahoo
						$this->extractBase();                  # extract base html table section
						$this->extractTable();                 # extract table rows/cells
					}
					
					$this->buildJSON();                        # convert stock data into json
					$this->curlPushJSON($this->json_data);     # push json to 3rd party API
					$this->logScrape();                        # create log for this scrape in db
					$this->clearOldEarnings();                 # remove old (45 day ++) log entries in database
				}elseif($this->current_earnings['api_resp_status_code'] != 200){ //resend if already scraped, but not receive by api
					$this->sendEarninigs2API($this->current_earnings['id']);	
				}
				## RESEND ANY NEWS ARTICLES WITH ERROR CODE
				$this->resendBadNews();
				
			break;
		}
	}
	
	public function resendBadNews(){
		$this->db_connect();
		$query = "SELECT * FROM `mk_api_news_articles` WHERE `api_resp_status_code` !=200";
		$result = mysqli_query($this->dbc, $query);
		while($row = mysqli_fetch_assoc($result)){
			$data[] = $row;
		}
		if(isset($data) && !empty($data)){
			foreach($data as $news){
				//store news data internally
				$this->news_data = $news;
				//json enocde
				$this->jsonEncodeNews($news);
				
				if(strtolower($data['destination']) == 'broadcast'){
					unset($data['ticker_symbol']);
					$api_type = 'api_broadcast';
					//$arr['request'] = 'POST';
				}else{
					$api_type = 'api_news';
					//$arr['request'] = '';
				}
				
				//set api url
				$this->apiURL = $this->api_url_array[$api_type]['url'];
				$this->apiTOKEN = $this->api_url_array[$api_type]['auth_token'];
				
				
				//send to api
				//$this->json_data = 333; //for testing AJAX resend error
				$this->curlPushJSON($this->json_data);
				//log api status/info --NOTE: NEED TO SAVE API ID if returned
				$this->logNews();
			}
		}
	}
	
	public function setCurrentScrape(){
		$this->db_connect();
		$today = $this->dates['today_sql'];
		$query ="SELECT * FROM `earnings_api_log` WHERE date(`created`)='".$today."' LIMIT 1"; //Note: There should only be 1 row per 'date' in db
		$result = mysqli_query($this->dbc, $query);
		while($row = mysqli_fetch_assoc($result)){
			$data = $row;
		}
		if(isset($data) && !empty($data)){
			$this->current_earnings = $data;
			//print_r($this->current_earnings);
		}
	}
	
	//pull stock data
	public function curlPull($url) {
		//echo $url; exit;
		
		//if dev, pull local file instead of making numerous requests
		if($this->mode == 'DEV'){
			//$data = file_get_contents('sample_html.html');
			$data = file_get_contents('sample_html2.html');
			
		}else{
			$header = array(
				"Connection: keep-alive",
				"Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
				"User-Agent: Mozilla/5.0 (X11; Linux i686) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/36.0.1985.125 Safari/537.36",
				"DNT: 1",
				"Accept-Language: en-US,en;q=0.8"
			);
			
			$ch = curl_init();
			$timeout = 45;
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
			curl_setopt($ch,  CURLOPT_HTTPHEADER, $header);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			//curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
			$data = curl_exec($ch);
			
			curl_close($ch);	
		}
		
		//echo $data;
		$this->html = ''; //clear previous html
		$this->html =  $data;
		//echo $this->html; exit;
	}
	
	//send data to url. This function expects to be passed json data
	public function curlPushJSON($content = '', $arr = ''){
		//echo 'in curl';
		//print_r($arr);
		
		### URL SETUP
		if(isset($arr['api_id']) && !empty($arr['api_id']) && is_numeric($arr['api_id'])){
			$url = $this->apiURL.$arr['api_id']; //it is assumed that url ends with a forward slash(/)
		}else{
			$url = $this->apiURL;
		}
		
		$arr['api_url'] = $url;
		
		### CURL SETUP
		$curl = curl_init($url);
		
		curl_setopt($curl, CURLOPT_HEADER, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json", "Authorization: ".$this->apiTOKEN));
		
		curl_setopt($curl, CURLINFO_HEADER_OUT, 1); //RETURNS REQUEST HEADER
		
		if(isset($arr['request']) && !empty($arr['request'])){
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($arr['request']));
		}else{ //DEFAULT TO PUT REQUEST, AS IT WILL INSERT OR UPDATE, depending on if ID is supplied
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
		}
		
		if(isset($content) && !empty($content)){
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
		}
		
		//------------------------------------------------------------------------------------------
		### CURL REQUEST
		$response                                = curl_exec($curl);
		
		
		### Separate status code, header, body
		
		//$http_status_code                        = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		//$header_size                             = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
		
		$curl_info                               = curl_getinfo($curl);
		
		$http_status_code                        = $curl_info['http_code'];
		$header_size                             = $curl_info['header_size'];
		$request_header                          = isset($curl_info['request_header']) ? $curl_info['request_header'] : '';
		$header                                  = substr($response, 0, $header_size);
		$body                                    = substr($response, $header_size);
		
		curl_close($curl);
		
		### RESPONSE
		$this->API_response['url']              = $url;
		$this->API_response['request_header']   = $request_header;
		$this->API_response['request_time']     = date("Y-m-d H:i:s");
		$this->API_response['status_code']      = $http_status_code;
		$this->API_response['header']           = $header;
		$this->API_response['body']             = $body;
		
		return $this->API_response;
		
	}

	
	
	public function db_connect(){
		$this->dbc = mysqli_connect($this->db_host, $this->db_user, $this->db_pass, $this->db_database);
	}
	
	//pulls main data-containing portion of html page
	public function extractBase(){
		//if(!isset($this->html) || empty($this->html)) {$this->errors[] = 'HTML contents missing'; return;}
		
		$this->breakpoint = 'Next Week</a></center><p><table';
		
		$exp = explode($this->breakpoint, $this->html);
		if($exp == false || !isset($exp) || count($exp) < 2){$this->errors[] = 'HTML data changed: Table Breakpoint is missing'; return;}
		
		//print_r($exp);
		unset($exp[0]);
		//echo $exp[1];
		
		//remove end of table
		$exp2 = explode('</table', $exp[1]);
		$this->table_html =  $exp2[0];
	}
	
	
	public function extractTable(){
		//if(!isset($this->table_html) || empty($this->table_html)) {$this->errors[] = 'Table contents missing'; return;}
		//echo $this->table_html;
		
		$this->table_html == ''; //clear previous table
		$rows = explode('</tr>', $this->table_html);
		unset($rows[0]); //extra table data
		unset($rows[1]); //table heading row
		//print_r($rows);
		
		foreach($rows as $row){
			$cells = explode('</td', $row);
			
			if(count($cells) < 4){continue;} //not an earnings row
			
			//print_r($this->current_round);
			//print_r($cells);
			
			$arr['id'] = $this->cleanItem($cells[1]);
			$arr['date'] = $this->time2sql($this->cleanItem($cells[3]));
			//add flag, based on date
			$arr['flag'] = $this->flagData($this->cleanItem($cells[3]));
			
			if(empty($arr['id'])){continue;} //some stocks listed on yahoo's earnings calendar don't provide symbol
			
			array_push($this->extracted_data, $arr); //add to internal array
			//print_r($arr);

		}	
	}
	
	//flags stock data based on date
	public function flagData($date){
		switch(strtolower($date)){
			case 'time not supplied':
				$flag = 'NS';
				break;
			case 'before market open':
				$flag = 'BO';
				break;
			case 'after market close':
				$flag = 'AC';
				break;
			default : //FOR DATE PROVIDED
				$flag = 'OD';
		}
		return $flag;
	}
	
	//creates an array of future dates, including today
	public function generateDays(){
		//today
		$this->dates['today'] = date('Ymd');
		$this->dates['today_sql'] = date('Y-m-d');
		$this->dates['today_name'] = date('l');
		$this->dates['data']['year'] = date('Y');
		$this->dates['data']['month'] = date('m');
		$this->dates['data']['day'] = date('d');
		
		//determine next 16 dates
		$this->dates['future'][0][0] = $this->dates['today'];
		$this->dates['future'][0][1] = $this->dates['today_sql'];
		$this->dates['future'][0][2] = $this->dates['today_name'];
		
		//next 16 days
		//for($i = 1; $i<=15; $i++){ //LIVE //Note: the api that we were sending this data to wasn't accepting this quanity of scraped data, so we lowered it
		
		// 3 days
		for($i = 1; $i<=3; $i++){ //LIVE
		//for($i = 1; $i<=3; $i++){   //TESTING
			$next_day_epoch = mktime(0, 0, 0, $this->dates['data']['month'], $this->dates['data']['day'] + $i, $this->dates['data']['year']);
			$this->dates['future'][$i] = array();
			array_push($this->dates['future'][$i], date('Ymd', $next_day_epoch));
			array_push($this->dates['future'][$i], date('Y-m-d', $next_day_epoch));
			array_push($this->dates['future'][$i], strtolower(date('l', $next_day_epoch))); //might need this for determining weekends
		}
		
		//print_r($this->dates);
		
	}
	
	
	public function time2sql($t){
		$current_date = $this->current_round[1];//set from current round
		
		$empty = $current_date.' 00:00:00'; //returned for missing dates/times
		//note: we are assuming all times are in ET timezone
		$t = trim($t);
		$arr = array('time not supplied', 'before market open', 'after market close');
		//make sure its a time and not text
		if(in_array($t, $arr)){
			return $empty;
		}
		$exp = explode(' ', $t);
		//print_r($exp);
		//validate
		if(!isset($exp[0]) || !isset($exp[1]) || !preg_match('/[0-9]{1,2}:[0-9]{2}/', $exp[0])){
			return $empty;
		}
		if(!isset($exp[1]) || !preg_match('/(am|pm)/i', $exp[1])){
			return $empty;
		}
		###if we've made it this far, it should be a valid time and we can convert it to timestamp
		//break apart time to convert to 24hour
		$exp_time = explode(':', $exp[0]);
		if($exp_time[0] <= 12 && strtolower($exp[1]) == 'pm'){
			$exp_time[0] = $exp_time[0] + 12;
		}
		
		$time = $exp_time[0].':'.$exp_time[1].':00';
		$timestamp = $current_date.' '.$time;
		//echo 'timestamp:'.$timestamp;
		return $timestamp;
	}
	
	//removes html entities and other characters from scraped table cells
	public function cleanItem($item){
		$new = strip_tags($item);
		$new = preg_replace('/>/', '', $new);
		return trim($new);
	}
	
	public function buildJSON(){
		//$json['auth_token'] = $this->auth_token; //removed from api structure
		$json = $this->extracted_data; 
		$this->json_data = json_encode(array_values($json)); //added array_values to remove numeric keys in json output
	}
	
	public function buildAPI_log(){
		$data['json_data']            = isset($this->json_data) ? $this->json_data : '';
		$data['api_request_header']   = isset($this->API_response['request_header']) ? $this->API_response['request_header'] : '';
		$data['api_send_time']        = isset($this->API_response['request_time']) ? $this->API_response['request_time'] : '';
		$data['api_status_code']      = isset($this->API_response['status_code']) ? $this->API_response['status_code'] : '';
		$data['api_header']           = isset($this->API_response['header']) ? $this->API_response['header'] : '';
		$data['api_body']             = isset($this->API_response['body']) ? $this->API_response['body'] : '';
		$data['api_url']              = isset($this->API_response['url']) ? $this->API_response['url'] : '';
		
		return $data;
	}
	
	//store data in database
	public function logScrape(){
		$data = $this->buildAPI_log();
		
		//count tickers submitted
		$data['ticker_count'] = isset($this->extracted_data) ? count($this->extracted_data) : '';
		
		//send out warning email is ticker count is low -- HTML has probably changed on Yahoo's page
		if($data['ticker_count'] < 10){
			$subject = 'WARNING: Earnings Scrape';
			$message = 'This message is notify you that a possible change has been detected in the either the URL or the HTML structure of Yahoo\'s Earnings Calendar';
			$message .= PHP_EOL; //SPACE
			$message .= PHP_EOL; //SPACE
			$message .= 'This email was sent from: '.$_SERVER['SCRIPT_FILENAME'];
			mail('myemail@email.com', $subject, $message);

		}
		
		//connect to db
		$this->db_connect();
		
		//escape array
		foreach($data as $key => $val){
			$data_esc[$key] = mysqli_real_escape_string($this->dbc, $val);
		}
		//print_r($data_esc);
		
		$query = "INSERT INTO `earnings_api_log` 
			(`ticker_count`, `scrape_time`, `scrape_attempts`, `send_attempts`, `api_request_header`, `api_url_called`, `api_send_time`, `api_resp_status_code`, `api_resp_header`, `api_resp_body`, `data_submitted`)
			VALUES('$data[ticker_count]', NOW(), '1', '0', '$data[api_request_header]', '$data[api_url]', '$data_esc[api_send_time]', '$data_esc[api_status_code]', '$data_esc[api_header]', '$data_esc[api_body]', '$data_esc[json_data]')";
		$result = mysqli_query($this->dbc, $query);
		
	}
	
	public function logNews(){
		$data = $this->buildAPI_log();
		
		//connect to db
		$this->db_connect();
		
		$data['id'] = $this->news_data['id'];
		
		//print_r($data);
		
		//get api_id from inserts to use on future updates
		if($data['api_status_code'] == 200){
			//print_r(json_decode($data['api_body']));
			$json_response = json_decode($data['api_body']);
			
			//print_R($json_response);
			$data['api_id'] = (string)$json_response->id;
		}else{
			$data['api_id'] = 'null';
		}
		
		//escape array
		foreach($data as $key => $val){
			$data_esc[$key] = mysqli_real_escape_string($this->dbc, $val);
		}
		//print_r($data_esc);
		
		$query = "UPDATE `articles` SET
			`api_url_called`='$data[api_url]',
			`api_request_header`='$data[api_request_header]',
			`api_send_time`='$data_esc[api_send_time]',
			`api_resp_status_code`='$data_esc[api_status_code]',
			`api_resp_header`='$data_esc[api_header]',
			`api_resp_body`='$data_esc[api_body]',
			`data_submitted`='$data_esc[json_data]',
			`api_id`='$data_esc[api_id]'
			
			WHERE `id`='$data[id]' ";
		
		//echo $query;
		
		$result = mysqli_query($this->dbc, $query);
		
	}
	
	public function jsonEncodeNews($arr){
		$data['symbol_id']           = $arr['ticker_symbol'];
		$data['title']               = $arr['title'];
		$data['url']                 = $arr['link'];
		$data['image']               = $arr['image_link'];
		$data['description']         = $arr['content'];
		$data['date_range_begin']    = $arr['start_date'];
		$data['date_range__end']     = $arr['end_date'];
		
		//determine whether this is an update or insert; If an id is sent, data will be updated
		if(isset($arr['api_id']) && !empty($arr['api_id']) && $arr['api_id'] > 0){
			$data['id'] = $arr['api_id'];
		}
		
		$this->json_data = json_encode($data);
	}

	
	//sends news articles to API, based on id number from DB
	public function sendNews2API($id){
		if(!isset($id) || !is_numeric($id)){return;}
		//select id from db
		$this->db_connect();
		$query = "SELECT * FROM `articles` WHERE `id`='".$id."' LIMIT 1";
		$result = mysqli_query($this->dbc, $query);
		while($row = mysqli_fetch_assoc($result)){
			$data = $row;
		}
		
		if(strtolower($data['destination']) == 'broadcast'){
			unset($data['ticker_symbol']);
			$api_type = 'api_broadcast';
			//$arr['request'] = 'POST';
		}else{
			$api_type = 'api_news';
			//$arr = '';
		}
		
		//store news data internally
		$this->news_data = $data;
		//json enocde
		$this->jsonEncodeNews($data);
		//set api url

		$this->apiURL = $this->api_url_array[$api_type]['url'];
		$this->apiTOKEN = $this->api_url_array[$api_type]['auth_token'];
		
		//send to api
		//$this->json_data = 333; //for testing AJAX resend error
		$this->curlPushJSON($this->json_data);
		//log api status/info --NOTE: NEED TO SAVE API ID if returned
		$this->logNews();
	}
	
	//sends earninings data to API, based on id number from DB
	public function sendEarninigs2API($id){
		if(!isset($id) || !is_numeric($id)){return;}
		//select id from db
		$this->db_connect();
		$query = "SELECT * FROM `api_logs` WHERE `id`='".$id."' LIMIT 1";
		$result = mysqli_query($this->dbc, $query);
		while($row = mysqli_fetch_assoc($result)){
			$data = $row;
		}
		//store news data internally
		$this->earnings_data = $data;
		//set api url
		$this->apiURL = $this->api_url_array['api_news']['url'];
		$this->apiTOKEN = $this->api_url_array['api_news']['auth_token'];
		//send to api
		$this->json_data = $data['data_submitted']; //earnings data is already json encoded in DB
		$this->curlPushJSON($this->json_data);
		$this->logEarnings();
	}
	
	public function logEarnings(){
		$data = $this->buildAPI_log();
		
		//connect to db
		$this->db_connect();
		
		$data['id'] = $this->earnings_data['id'];
		
		//print_r($data);
		
		
		//escape array
		foreach($data as $key => $val){
			$data_esc[$key] = mysqli_real_escape_string($this->dbc, $val);
		}
		//print_r($data_esc);
		
		$query = "UPDATE `api_logs` SET
			`api_url_called`='$data[api_url]',
			`api_send_time`='$data_esc[api_send_time]',
			`api_request_header`='$data_esc[api_request_header]',
			`api_resp_status_code`='$data_esc[api_status_code]',
			`api_resp_header`='$data_esc[api_header]',
			`api_resp_body`='$data_esc[api_body]',
			`data_submitted`='$data_esc[json_data]'
			
			WHERE `id`='$data[id]' ";
		
		//echo $query;
		
		$result = mysqli_query($this->dbc, $query);
		
	}
	
	//clear old earnings api logs from database
	public function clearOldEarnings(){
		$this->db_connect();
		$query = "DELETE FROM `api_logs` WHERE `created` < DATE_SUB(NOW(), INTERVAL 45 DAY)";
		$result = mysqli_query($this->dbc, $query);
	}
	
	//log test response
	public function test_data_log($json, $response){
		$publicLog = 'test_response_log_'.date('F-Y').'.txt';

		$log_msg = PHP_EOL.PHP_EOL.date("l jS \of F Y h:i:s A").PHP_EOL;
		$log_msg .= '------------------------------------------------------------------------------------------------------------------'.PHP_EOL;
		$log_msg .= 'Log ID:'.substr(time(), -5).PHP_EOL.PHP_EOL;
		$log_msg .= 'URL sent to:'.$this->apiURL.PHP_EOL.PHP_EOL;
		//$log_msg .= 'IP:'.$_SERVER['REMOTE_ADDR'].PHP_EOL;

		$log_msg .= "JSON Data:::".PHP_EOL;
		$log_msg .= print_r($json, true).PHP_EOL.PHP_EOL;

		$log_msg .= "Formatted Data:::".PHP_EOL;
		$log_msg .= print_r(json_decode($json), true);
		
		$log_msg .= "Response:::".PHP_EOL;
		$log_msg .= print_r($response, true).PHP_EOL.PHP_EOL;
		
		error_log($log_msg, 3, $publicLog); 
	}
	
	
	
	//remove old logs from db
	public function cleanLogs(){
		
	}
}

########################################################
## TESTING
$testing = 'off';
if($testing == 'on' && $_SERVER['REMOTE_ADDR'] == '75.127.213.98'){
	ini_set('display_errors',1);
	ini_set('display_startup_errors',1);
	error_reporting(-1);
	
	//$arr['mode'] = 'dev';
	$arr['mode'] = '';

	$Scraper_obj = new Scraper($arr);
	//$Scraper_obj->sendNews2API(6);
	### TEST api Resend
	//$Scraper_obj->sendNews2API(7); //send news to api
	//$Scraper_obj->sendEarninigs2API(57);
	
	
	### Test Scrape Routine
	//$arr['routine'] = 'scrape_and_send';
	//$Scraper_obj->runRoutine($arr);
	
	### Test generated dates - used for scrape routine
	//$Scraper_obj->generateDays();
	//print_r($Scraper_obj->dates);

}