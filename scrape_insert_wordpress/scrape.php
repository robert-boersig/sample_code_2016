<?php
/*
	Scrapes website data from Stripe https://stripe.com/docs/integrations
	Uses Watson Alchemy API to pull text from website
	saves as json file for processing later

*/



EXIT;

set_time_limit(500);
ini_set('memory_limit','300M');

$db_host        = 'localhost';
$db_username    = 'username';
$db_password    = 'password';
$db_name        = 'db_name';
$db_prefix      = 'pre_';


//--------------------------------------------------------------------------------
$html = file_get_contents('stripe_page.html');

//divide based on categories / sections
$sections = explode('<h2', $html);

//print_r($sections); exit;

unset($sections[0]);

$companies = array();

foreach($sections as $section){
	//need name of company - we will use clearbit to get this later, but get it now as a backup
	$heading_raw = '';
	preg_match('/>(.*?)</', $section, $heading_raw);
	//echo $heading_raw[1].PHP_EOL;
	
	$companies[trim($heading_raw[1])] = '';
	
	//break apart each row
	$rows = explode('<li', $section);
	
	$i = 0;
	foreach($rows as $row){
		//print_r($row); 
		//get link
		preg_match('/href="(.*?)"/', $row, $link_raw);
		$link = !empty($link_raw[1]) ? $link_raw[1] : false;
	
		
		if(!filter_var($link, FILTER_VALIDATE_URL)){continue;}
		
		//get name
		preg_match('/>(.*?)<\/a>/', $row, $name_raw_1);
		//print_r($name_raw_1); //continue;
		
		preg_match('/>.+/', $name_raw_1[1], $name_raw_2);
		//print_r($name_raw_2); continue;
		$name = str_replace('>', '', $name_raw_2[0]);
		
		
		//get desc
		preg_match('/<\/a>(.*?)<\/li/', $row, $desc_raw);
		//print_r($desc_raw);
		
		//add to companies array
		$companies[$heading_raw[1]][$i]['url']   = preg_replace('/\?(.*)/', '', $link);
		$companies[$heading_raw[1]][$i]['name']  = trim($name);
		$companies[$heading_raw[1]][$i]['desc']  = trim($name). ' ' . trim($desc_raw[1]);
		
		$i++;
	}
	
	//exit;
	
	
}

//print_r($companies); exit;


$dbc = mysqli_connect($db_host, $db_username, $db_password, $db_name) or die ('DB connect failed');

require('watson.class.php');
$watson = new Watson();

$ii = 0;
foreach($companies as $category_raw => $biz){
	//echo $category_raw ;
	//print_r($biz);
	
	//check if category exists in site
	$cat_clean = html_entity_decode($category_raw); //will use for slug
	//echo $cat_clean.PHP_EOL;
	
	//query wp
	$table = $db_prefix.'terms';
	$cat_esc = mysqli_real_escape_string($dbc, $category_raw);
	$query = "SELECT *  FROM `".$table."` WHERE `name`='".$cat_esc."' LIMIT 1 ";
	$result = mysqli_query($dbc, $query);
	
	if($result && $result->num_rows >0){
		$cat_info = mysqli_fetch_assoc($result);
	}else{
		echo $category_raw . ' NOT FOUND '.php_eol;
	}
	
	//print_r($cat_info);
	//exit;
	
	
	foreach($biz as $biz_data){
		$list[$ii]['cat_clean']     = $cat_clean;
		$list[$ii]['cat_raw']       = $category_raw;
		$list[$ii]['cat_id']        = $cat_info['term_id'];
		
		$list[$ii]['url']           = $biz_data['url'];
		$list[$ii]['name']          = $biz_data['name'];
		$list[$ii]['desc']          = $biz_data['desc'];
		
			
		### WATSON API - AlchemyLanguage
			//pull site data
			$data = $watson->curl_WatsonText(array('url'=>$biz_data['url']));
			$text = !empty($data['text']) ? $data['text'] : false;
			
			$list[$ii]['text']       = $text;
			

		//if($ii > 3){break 2; } //debug
		
		$ii++;
	}
	
}

$list_json = json_encode($list);

file_put_contents('company_data_FULL_'.time().'.json', $list_json);

print_r($list); exit;


