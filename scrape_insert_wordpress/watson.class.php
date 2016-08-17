<?php

class Watson {
	
	protected $api_key = 'secret';

	
	
	//uses Watson api to pull/extract text from website (1 page)
	function curl_WatsonText($arr = ''){
		set_time_limit(100); 
		
		$data = array(
			'apikey'        => $this->api_key,
			'outputMode'    => 'json',
			'url'           => $arr['url']
		);
		

		$url = 'https://gateway-a.watsonplatform.net/calls/url/URLGetText';
	
		
		$ch = curl_init($url);                                                                      
		
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		
		//curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));                                                                  
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
                                        
		
		//curl_setopt($ch, CURLOPT_VERBOSE, 1);
		//curl_setopt($ch, CURLOPT_HEADER, 1);
		
		$result = curl_exec($ch);
		
	    //	print_r($result);
		
		if(!$result){
			die('Error: "' . curl_error($ch) . '" - Code: ' . curl_errno($ch));
		}
		
		//$info = curl_getinfo($ch);
		//print_r($info);
		
		return json_decode($result, true);
	}

}

/*
$test = new Watson();

$data = $test->curl_WatsonText(array('url'=>'https://receiptful.com/email-receipts-for-stripe/'));

print_r($data);
*/