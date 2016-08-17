<?php
/*
	Uses saved json daa from Stripe to insert into wordpress database, also pulls company logo from clearbit and saves in wordpress

*/


EXIT;

set_time_limit(500);
ini_set('memory_limit','300M');


$json_data = file_get_contents('company_data_FULL_1469327607.json');

$data = json_decode($json_data, true);

//print_r($data); EXIT;
//echo count($data);



if(!is_array($data)){echo 'No Array data found'; exit;}

require('..'.DIRECTORY_SEPARATOR.'wp-load.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');

/*


// Gather post data.
$my_post = array(
    'post_title'    => 'My post',
    'post_content'  => 'This is my post.',
    'post_status'   => 'publish',
    'post_author'   => 1,
    'post_category' => array( 8,39 )
);
 
// Insert the post into the database.
$post_id = wp_insert_post( $my_post );
//add meta url
add_post_meta($post_id, $meta_key, $meta_value, $unique);

*/

foreach($data as $site){
	//reaplce mutliple spaces in text
	$site['text'] = preg_replace('!\s+!', ' ', trim($site['text']));
	$site['text'] = htmlspecialchars($site['text'], ENT_HTML5);
	
	$parse = parse_url($site['url']);
	$site['domain'] = $parse['host'];
	
	//check if exists first(?)
	
	
	unset($post_data);
	$post_data = array(
		'ID'            => 0,                # will update post id if not == 0
		'post_title'    => $site['name'],
		'post_content'  => $site['text'],
		'post_status'   => 'publish',
		'post_author'   => 1,
		'post_category' => array($site['cat_id'])
	);
	
	$query = "";
	
	//add/update post
	$post_id = wp_insert_post($post_data);
	
	//add meta data
	add_post_meta($post_id, 'site_name', $site['domain'], true);
	add_post_meta($post_id, 'site_url', $site['url'], true);
	add_post_meta($post_id, 'site_summary', $site['desc'], true);
	
	//print_r($post_data);
	//echo $site['domain'] . PHP_EOL . PHP_EOL;
	
	$domain = $site['domain'];
	$format = 'png'; //png or jpg
	
	if(empty($domain)){continue;}
	
	$image_url  = 'https://logo.clearbit.com/'.$domain.'?size=200&format='.$format; // Define the image URL here
	
	/* -------------------------------------------- */
	$upload_dir = wp_upload_dir(); // Set upload folder

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $image_url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

	$image_data = curl_exec($ch);
	
	if(empty($image_data)){continue;}

	//header('Content-type: image/png');
	//echo $image_data; exit;


	//$filename   = basename($image_url); // Create image file name
	$filename   = $domain . '_logo.'.$format; // Create image file name

	// Check folder permission and define file location
	if( wp_mkdir_p( $upload_dir['path'] ) ) {
		$file = $upload_dir['path'] . '/' . $filename;
	} else {
		$file = $upload_dir['basedir'] . '/' . $filename;
	}

	// Create the image  file on the server
	file_put_contents( $file, $image_data );

	// Check image file type
	$wp_filetype = wp_check_filetype( $filename, null );

	// Set attachment data
	$attachment = array(
		'post_mime_type' => $wp_filetype['type'],
		'post_title'     => sanitize_file_name( $filename ),
		'post_content'   => '',
		'post_status'    => 'inherit'
	);

	// Create the attachment
	$attach_id = wp_insert_attachment( $attachment, $file, $post_id );

	// Define attachment metadata
	$attach_data = wp_generate_attachment_metadata( $attach_id, $file );

	// Assign metadata to attachment
	wp_update_attachment_metadata( $attach_id, $attach_data );

	// And finally assign featured image to post
	set_post_thumbnail( $post_id, $attach_id );
		
	/* -------------------------------------------- */
	
}