<?php
/*
	Uploads Image from url, sets as featured post image
	
	expects $post_id
	expects $domain
	expects $format
*/

/*
$domain = 'stripe.com';
$post_id = '1';
$format = 'png'; //png or jpg
*/


EXIT;



require_once('..'.DIRECTORY_SEPARATOR.'wp-load.php');



// Add Featured Image to Post
//$image_url  = 'http://s.wordpress.org/style/images/wp-header-logo.png'; // Define the image URL here
$image_url  = 'https://logo.clearbit.com/'.$domain.'?size=200&format='.$format; // Define the image URL here

//echo $image_url; exit;

$upload_dir = wp_upload_dir(); // Set upload folder
//$image_data = file_get_contents($image_url); // Get image data //original, needs ini allow_url_fopen 
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $image_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$image_data = curl_exec($ch);

$err = curl_error($ch);
//print_r($err);

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

// Include image.php
require_once(ABSPATH . 'wp-admin/includes/image.php');

// Define attachment metadata
$attach_data = wp_generate_attachment_metadata( $attach_id, $file );

// Assign metadata to attachment
wp_update_attachment_metadata( $attach_id, $attach_data );

// And finally assign featured image to post
set_post_thumbnail( $post_id, $attach_id );