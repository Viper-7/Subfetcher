<?php
/*
 * Youtube Subscription Downloader
 * by Viper-7 <viper7@viper-7.com>
 *
 * Displays your current home page feed from Youtube, including your subscriptions, 
 * and provides a simple tickbox list to download or hide videos.
 *
 * Uses youtube-dl to enable downloading of protected videos and can download
 * multiple videos in parallel.
 */


// Path for downloaded videos, can be on a network share
$video_path = '/mnt/F/Stuff';

// Maximum concurrent downloads
$download_threads = 3;

// Youtube-dl path. If not found the script will attempt to install it
$youtubedl_path = 'youtube-dl';

// Google API path. If not found the script will attempt to install it
$googleapi_path = 'google-api-php-client';

/*
 * You can acquire an OAuth 2.0 client ID and client secret from the
 * {{ Google Cloud Console }} <{{ https://cloud.google.com/console }}>
 * For more information about using OAuth 2.0 to access Google APIs, please see:
 * <https://developers.google.com/youtube/v3/guides/authentication>
 * Please ensure that you have enabled the YouTube Data API for your project.
 */
$OAUTH2_CLIENT_ID = 'changeme';
$OAUTH2_CLIENT_SECRET = 'changeme';



/*
 * ----------------------------------------------------------------------------
 * "THE BEER-WARE LICENSE" (Revision 42):
 * <viper7@viper-7.com> wrote this file.  As long as you retain this notice you
 * can do whatever you want with this stuff. If we meet some day, and you think
 * this stuff is worth it, you can buy me a beer in return.         Dale Horton
 * ----------------------------------------------------------------------------
 */
session_start();
$message = '';

if(!file_exists($googleapi_path . '/src/Google/autoload.php')) {
	@exec('git clone --depth 1 https://github.com/google/google-api-php-client.git ' . $googleapi_path);
	if(file_exists($googleapi_path . '/src/Google/autoload.php'))
		$message .= '<p>Installed Google PHP API</p>';
	else
		$message .= '<p>Error installing Google PHP API</p>';
}
if(!file_exists($googleapi_path . '/src/Google/autoload.php')) {
	$message .= '<p>Google PHP API could not be found or installed. Subscriptions cannot be viewed</p>';
} else {
	if($OAUTH2_CLIENT_ID == 'changeme' || $OAUTH2_CLIENT_SECRET == 'changeme') {
		$message .= '<p>Google API OAUTH2 Client ID & Secret must be set in the script. Subscriptions cannot be viewed</p>';
	} else {
		require_once "{$googleapi_path}/src/Google/autoload.php";

		if(!file_exists($youtubedl_path)) {
			copy('https://yt-dl.org/downloads/2015.06.04.1/youtube-dl', $youtubedl_path);
			if(file_exists($youtubedl_path))
				$message .= '<p>Installed youtube-dl</p>';
			else
				$message .= '<p>Error installing youtube-dl</p>';
		}
		if(!is_executable($youtubedl_path)) {
			chmod($youtubedl_path, 0755);
		}
		if(!is_executable($youtubedl_path)) {
			$message .= '<p>youtube-dl could not be found or installed. Videos cannot be downloaded</p>';
		} else {
			if(isset($_POST['download'])) {
				$i=0;
				
				if(!file_exists($video_path)) mkdir($video_path);
				chdir($video_path);
				$cmd = array();
				foreach($_POST['download'] as $id => $download) {
					if($download) {
						$url = "http://www.youtube.com/watch?v={$id}";
						$queued = file_get_contents(__DIR__ . '/.youtube.downloaded');
						$queued = json_decode($queued, true);
						if(!$queued) $queued = array();
						$queued[$id] = true;
						file_put_contents(__DIR__ . '/.youtube.downloaded', json_encode($queued));
						$cmd[] = "{$youtubedl_path} {$url} -f 18 2>/dev/null >/dev/null";
						$i++;
					}
				}
				
				if($cmd) {
					$batch = array();
					$i = 0;
					foreach($cmd as $c) {
						$batch[$i++ % $download_threads][] = $c;
					}
					foreach($batch as $cmd) {
						exec(implode(' && ', $cmd) . ' & disown');
					}
					$message .= "<p>Queued {$i} videos in " . count($batch) . " threads</p>";
				}
			}
			
			if(isset($_POST['crap'])) {
				$i=0;
				foreach($_POST['crap'] as $id => $download) {
					if($download) {
						$queued = file_get_contents(__DIR__ . '/.youtube.hidden');
						$queued = json_decode($queued, true);
						if(!$queued) $queued = array();
						$queued[$id] = true;
						file_put_contents(__DIR__ . '/.youtube.hidden', json_encode($queued));
						$i++;
					}
				}
				
				if($i)
					$message .= "<p>Removed {$i} videos</p>";
			}
		}

		$client = new Google_Client();
		$client->setClientId($OAUTH2_CLIENT_ID);
		$client->setClientSecret($OAUTH2_CLIENT_SECRET);
		$client->setScopes('https://www.googleapis.com/auth/youtube');
		$redirect = filter_var('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'],
			FILTER_SANITIZE_URL);
		$client->setRedirectUri($redirect);

		// Define an object that will be used to make all API requests.
		$youtube = new Google_Service_YouTube($client);

		if (isset($_GET['code'])) {
		  if (strval($_SESSION['state']) !== strval($_GET['state'])) {
			die('The session state did not match.');
		  }

		  $client->authenticate($_GET['code']);
		  $_SESSION['token'] = $client->getAccessToken();
		  header('Location: ' . $redirect);
		}

		try {
			if (isset($_SESSION['token'])) {
			  $client->setAccessToken($_SESSION['token']);
			}

			// Check to ensure that the access token was successfully acquired.
			if ($client->getAccessToken()) {

			  $youtubeService = new Google_Service_YouTube($client);
			  $subscriptions = $youtubeService->activities->listActivities('snippet,contentDetails', array('home' => true, 'maxResults' => 50, 'fields' => 'items(contentDetails,id,snippet)'));
			  foreach($subscriptions as $sub) {
				
				$details = $sub->contentDetails;
				$id = null;
				if(isset($details->upload)) {
					$id = $details->upload->videoId;
//				} elseif(isset($details->playlistItem)) {
//					$id = $details->playlistItem->getResourceId()->videoId;
//				} elseif(isset($details->recommendation)) {
//					$id = $details->recommendation->getResourceId()->videoId;
//				} elseif(isset($details->bulletin)) {
//					$id = $details->bulletin->getResourceId()->videoId;
				}
				
				if($id && !isset($videos[$id])) {
					$videos[$id] = array(
						$sub->snippet->title,
						$sub->snippet->thumbnails->default->url,
						$sub->snippet->publishedAt,
						$sub->snippet->channelTitle
					);
				}
			  }
			  
			  $queued = @file_get_contents(__DIR__ . '/.youtube.downloaded');
			  $queued = json_decode($queued, true);
			  if(!$queued) $queued = array();

			  $crap = @file_get_contents(__DIR__ . '/.youtube.hidden');
			  $crap = json_decode($crap, true);
			  if(!$crap) $crap = array();

			  uasort($videos, function($a, $b) { return strtotime($b[2]) - strtotime($a[2]); });
			  ob_start();
			  ?>
			  <style type="text/css">
				table { width: 80%; margin: 0 auto; }
				tr { opacity: 0.8; }
				td.hide { background-color: #fff0f0; }
				tr.checked { background-color: #efe; opacity: 1; }
				tr.hidden { background-color: #fee; opacity: 0.4; } 
				tr.checked td.hide { background-color: #efe; }
				input.download, input.hide { display: block; margin: 0 auto; }
				img { vertical-align: middle; padding: 0 24px; }
				div.button { position: fixed; right: 10px; top: 10px; }
			  </style>
			  <form method="post" action="">
				<div class="button">
					<input type="submit" value="Download"/>
				</div>
				<table><thead><tr><th>Download</th><th>Image</th><th>Author</th><th>Name</th><th>Published</th><th>Hide</th></tr></thead><tfoot></tfoot><tbody>
				<?php foreach($videos as $id => $data) { list($title, $image, $date, $author) = $data; if(isset($queued[$id]) || isset($crap[$id])) continue; $date = date('Y-m-d h:i:s', strtotime($date)); ?>
					<tr><td><input class="download" type="checkbox" name="download[<?php echo $id ?>]"></td><td><img src="<?php echo $image ?>"></td><td><?php echo $author ?></td><td><?php echo $title ?></td><td><?php echo $date ?></td><td class="hide"><input type="checkbox" class="hide" name="crap[<?php echo $id ?>]"></td></tr>
				<?php } ?>
				</tbody></table>
			  </form>
			  <?php
			  $htmlBody = ob_get_clean();
			  $_SESSION['token'] = $client->getAccessToken();
			} else {
			  // If the user hasn't authorized the app, initiate the OAuth flow
			  $state = mt_rand();
			  $client->setState($state);
			  $_SESSION['state'] = $state;

			  $authUrl = $client->createAuthUrl();
			  $htmlBody = <<<END
			  <h3>Authorization Required</h3>
			  <p>You need to <a href="$authUrl">authorize access</a> before proceeding.<p>
END;
			}
		} catch(Google_Auth_Exception $e) {
			  $state = mt_rand();
			  $client->setState($state);
			  $_SESSION['state'] = $state;

			  $authUrl = $client->createAuthUrl();
			  $htmlBody = <<<END
			  <h3>Authorization Required</h3>
			  <p>You need to <a href="$authUrl">authorize access</a> before proceeding.<p>
END;
		}
	}
}	
?>

<!doctype html>
<html>
<head>
<title>Subscription Downloader</title>
</head>
<body>
  <?php echo $message ?>
  <?php if(!empty($htmlBody)) { echo $htmlBody; } ?>
</body>
<script src="//code.jquery.com/jquery-1.11.3.min.js"></script>
<script src="//code.jquery.com/jquery-migrate-1.2.1.min.js"></script>
<script type="text/javascript">
	jQuery('tr').click(function() {
		jQuery(this).find('input')[0].click();
	});
	jQuery('td.hide').click(function(e) {
		jQuery(this).find('input').click();
		e.stopPropagation();
	});
	jQuery('input').click(function(e) {
		e.stopPropagation();
		if(jQuery(this).hasClass('download')) {
			jQuery(this).closest('tr').find('input.hide').prop("checked", 0);
		} else {
			jQuery(this).closest('tr').find('input.download').prop("checked", 0);
		}
	});
	jQuery('input').change(function() {
		var tr = jQuery(this).closest('tr');
		if(tr.find('input:checked').is(':checked')) {
			if(tr.find('input.download').is(':checked')) {
				// download
				tr.addClass('checked');
				tr.removeClass('hidden');
			} else {
				// hidden
				tr.removeClass('checked');
				tr.addClass('hidden');
			}
		} else {
			// normal
			tr.removeClass('checked');
			tr.removeClass('hidden');
		}
	});
</script>
</html>
