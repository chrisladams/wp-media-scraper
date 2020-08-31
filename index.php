<?php
/*
Plugin Name: Wordpress Media Scraper
Description: Imports all media from another Wordpress site
Version: 1.0
Author: Braid
Author URI: https://wearebraid.com
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

function register_import_media_page(){
    add_submenu_page( 
    	'options-general.php',
        'Import Media',
        'Import Media',
        'manage_options',
        'import_wp_media',
        'import_wp_media__page',
        '',
        6
    ); 
}
add_action('admin_menu', 'register_import_media_page');
 
function import_wp_media__page()
{
	?>
		<h1>WP Media Scraper</h1>

		<div id="mediaScraperMessage" class="updated notice" style="display: none;">
			<p>Media scraping in progress, please wait...</p>
		</div>

		<div id="mediaScraperForm">
			<p>
				URL:
				<input id="mediaScraperUrl" type="text" name="url" />
			</p>
			<p>
				Start at Page:
				<input id="mediaScraperPage" type="number" name="page" value="1" />
			</p>
			<p>
				<input id="startMediaScraper" class="button" type="submit" value="Import all media from Wordpress Site">
			</p>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var itemsImported = 0;
			var totalpages = 0;
			function initMediaScraper(page) {
				$.ajax(
					{
						type: 'POST',
						timeout: 30000,
						url: '<?php echo admin_url('admin-ajax.php'); ?>', 
						data: {
							action: 'media_scraper',
							url: $('#mediaScraperUrl').val(),
							page: page,
						}
					}
				)
					.done(function(response) {
						if (response.complete === false) {
							itemsImported += response.progress
							totalpages = response.pages
							$('#mediaScraperMessage p').text('Media scraping in progress, please wait... ' + response.percent.toFixed(2) + '% completed...' )
							setTimeout(initMediaScraper(page + 1), 500)
						} else {
							$('#mediaScraperMessage p').text('Scraping completed! ' + itemsImported + ' items imported!');
						}
					})
					.fail(function() {
						$('#mediaScraperMessage p').text('Scraping snagged at page ' + page + (totalpages > 0 ? ' of ' + totalpages : '') + ' - Resuming in 5 seconds.');
						setTimeout(initMediaScraper(page), 5000);
					})
			}

			$('#startMediaScraper').on('click', function(e) {
				var itemsImported = 0;
				$('#mediaScraperForm').hide();
				$('#mediaScraperMessage p').text('Media scraping in progress, please wait... ');
				$('#mediaScraperMessage').show();
				initMediaScraper(parseInt($('#mediaScraperPage').val()))
			})
		});
		</script>
	<?php
}

function import_wp_media_page_ajax() {
	global $wpdb;
	$output = ['complete' => true];

	if(isset($_POST['url']) && !empty($_POST['url'])):
		$url = $_POST['url'];
		$page = $_POST['page'];
		$progress = isset($_POST['progress']) ? $_POST['progress'] : 0;
		$perpage = 10;
		$upload_dir = wp_upload_dir();

		$images = wp_remote_get(
			$url . '/wp-json/wp/v2/media/?per_page='.$perpage.'&page=' . $page,
			[ 'timeout' => 3000 ]
		);

		if ($images['response']['code'] == 200) {
			foreach (json_decode($images['body']) as $i) {

				// CHECK IF IMAGE ALREADY IMPORTED
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) as id_exists FROM $wpdb->postmeta
							WHERE meta_key = 'media_scraper__id' AND meta_value='%d'",
						$i->id
					)
				);

				if (!$exists) {
					$file = rtrim($url, '/') . '/wp-content/uploads/' . $i->media_details->file;
					$filename = basename($file);

					$_filter = $upload_dir['basedir'] . '/' . rtrim(str_replace($filename, '', $i->media_details->file), '/');
					add_filter('upload_dir', function($arr) use (&$_filter) {
						if ($_filter) {
							$arr['path'] = $_filter;
							$arr['url'] = $_filter;
						}
						return $arr;
					});

					$upload_file = wp_upload_bits($filename, null, file_get_contents($file));
					
					if (!$upload_file['error']) {
						$wp_filetype = wp_check_filetype($filename, null );
						$attachment = array(
							'post_mime_type' => $wp_filetype['type'],
							'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
							'post_content' => '',
							'post_status' => 'inherit',
							'post_date' => $i->date,
							'post_date_gmt' => $i->date_gmt,
							'post_modified' => $i->modified,
							'post_modified_gmt' => $i->modified_gmt,
						);
						$attachment_id = wp_insert_attachment( $attachment, $upload_file['file'] );
						update_post_meta($attachment_id, 'media_scraper__id', $i->id);

						if (!is_wp_error($attachment_id)) {
							require_once(ABSPATH . "wp-admin" . '/includes/image.php');
							$attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload_file['file'] );
							wp_update_attachment_metadata( $attachment_id,  $attachment_data );
						}

						$wpdb->update(
							$wpdb->prefix . 'postmeta',
							[ 'meta_value' => $attachment_id ],
							[ 'meta_value' => $i->id, 'meta_key' => '_thumbnail_id' ]);
					}
				}
				$progress++;
			}

			$output = [
				'complete' => $page >= $images['headers']['x-wp-totalpages'],
				'progress' => $progress,
				'pages' => $images['headers']['x-wp-totalpages'],
				'percent' => $page / $images['headers']['x-wp-totalpages'] * 100
			];
		}
	endif;
	wp_send_json($output);
	wp_die();
}
add_action( 'wp_ajax_media_scraper', 'import_wp_media_page_ajax' );
