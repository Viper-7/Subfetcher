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

// Check the user's home page activity feed for new items, tends to show a random list of "some" new content
$use_activity_scan = true;

// Types of actiivty post to display, valid types are: bulletin, channelItem, comment, favourite, like, playlistItem, recommendation, social, subscription, upload
$types = array('upload','recommendation','bulletin');

// Check each of the user's subscribed channels for new uploads (slow)
$use_channel_scan = false;

// Filter results to only the past n days
$filter_days = 14;


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
$downloading = '';
$formats = array(18 => '360p', 22 => '720p', 5 => '240p');
$videos = array();

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
			if(!file_exists($video_path)) mkdir($video_path);
			chdir($video_path);
			
			$running = shell_exec('ps aux | grep youtube-dl | grep python');
			if($running) {
				$count = substr_count($running, "\n") - 1;
				if($count > 0) {
					$download_threads -= $count;
					$downloading = "<p>{$count} video(s) currently downloading</p>";
				}
				
				if($download_threads < 1)
					$download_threads = 1;
			}

			$files = glob($video_path . '/*.part');
			$files = array_combine($files, array_map('filesize', $files));
			
			$oldfiles = @file_get_contents(__DIR__ . '/.youtube.parts');
			$oldfiles = json_decode($oldfiles, true);
			if(!$oldfiles) $oldfiles = array();
			
			if($oldfiles) {
				$cmd = array();
				foreach($files as $file => $size) {
					if(isset($oldfiles[$file]) && $oldfiles[$file] == $size && time() - filemtime($file) > 30) {
						// Download has failed, resume it
						if(preg_match('/-([a-zA-Z0-9_-]+)\.\w+\.part$/', $file, $match)) {
							$url = "http://www.youtube.com/watch?v=" . $match[1];
							$cmd[] = "{$youtubedl_path} {$url} -f 18 3>/dev/null 2>/dev/null >/dev/null";
						}
					}
				}
				
				if($cmd) {
					$batch = array();
					$i = 0;
					foreach($cmd as $c) {
						$batch[$i++ % $download_threads][] = $c;
					}
					foreach($batch as $cmd) {
						pclose(popen(implode(' && ', $cmd) . ' & disown', 'r'));
					}
					$message .= "<p>Resumed {$i} stalled video(s) in " . count($batch) . " threads</p>";
				}
				
			}
			
			file_put_contents(__DIR__ . '/.youtube.parts', json_encode($files));
			
			if(!empty($_POST['videoid'])) {
				$cmd = array();
				foreach(explode(',', $_POST['videoid']) as $id) {
					if(preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
						$url = "http://www.youtube.com/watch?v={$id}";
						$queued = @file_get_contents(__DIR__ . '/.youtube.downloaded');
						$queued = json_decode($queued, true);
						if(!$queued) $queued = array();
						$queued[$id] = true;
						file_put_contents(__DIR__ . '/.youtube.downloaded', json_encode($queued));
						$cmd[] = "{$youtubedl_path} {$url} -f 18 2>/dev/null >/dev/null";
					}
				}
				
				if($cmd) {
					$batch = array();
					$i = 0;
					foreach($cmd as $c) {
						$batch[$i++ % $download_threads][] = $c;
					}
					foreach($batch as $cmd) {
						pclose(popen(implode(' && ', $cmd) . ' & disown', 'r'));
					}
					$message .= "<p>Queued {$i} video(s) in " . count($batch) . " threads</p>";
				}
			} else {
				if(isset($_POST['download'])) {
					$i=0;
					
					$cmd = array();
					foreach($_POST['download'] as $id => $download) {
						if($download) {
							if(isset($_POST['format'][$id]))
								$format = $_POST['format'][$id];
							else
								$format = 18;
							
							$url = "http://www.youtube.com/watch?v={$id}";
							$queued = @file_get_contents(__DIR__ . '/.youtube.downloaded');
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
							pclose(popen(implode(' && ', $cmd) . ' & disown', 'r'));
						}
						$message .= "<p>Queued {$i} video(s) in " . count($batch) . " threads</p>";
					}
				}
				
				if(isset($_POST['crap'])) {
					$i=0;
					foreach($_POST['crap'] as $id => $download) {
						if($download) {
							$queued = @file_get_contents(__DIR__ . '/.youtube.hidden');
							$queued = json_decode($queued, true);
							if(!$queued) $queued = array();
							$queued[$id] = true;
							file_put_contents(__DIR__ . '/.youtube.hidden', json_encode($queued));
							$i++;
						}
					}
					
					if($i)
						$message .= "<p>Removed {$i} video(s)</p>";
				}
			}
		}
		
		$alert = false;
		$vidfile = __DIR__ . '/.youtube.videos';
		
		if(empty($_POST) && (!file_exists($vidfile) || !$use_channel_scan || time() - filemtime($vidfile) > 300)) {
			$client = new Google_Client();
			$client->setClientId($OAUTH2_CLIENT_ID);
			$client->setClientSecret($OAUTH2_CLIENT_SECRET);
			$client->setAccessType('offline');
			$client->setApprovalPrompt('force');
			$client->setApplicationName('Subfetcher');
			$client->setScopes('https://www.googleapis.com/auth/youtube');
			$redirect = filter_var('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'],
				FILTER_SANITIZE_URL);
			$client->setRedirectUri($redirect);

			try {
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
				
				if (isset($_SESSION['token'])) {
					$client->setAccessToken($_SESSION['token']);
					
					//json decode the session token and save it in a variable as object
					$sessionToken = json_decode($_SESSION['token']);

					//Save the refresh token (object->refresh_token) into a cookie called 'token' and make last for 1 month
					if(isset($sessionToken->refresh_token))
						setcookie('token', $sessionToken->refresh_token, time() + 86400 * 30);
				} elseif(isset($_COOKIE['token'])) {
					$client->refreshToken($_COOKIE['token']);
				}

				// Check to ensure that the access token was successfully acquired.
				if ($client->getAccessToken()) {
				  
				  $youtubeService = new Google_Service_YouTube($client);
				  if($use_channel_scan) {
					  $channels = array();
					  $subs = $youtubeService->subscriptions->listSubscriptions('snippet', array('mine' => true));
					  foreach($subs as $sub) {
						$channels[] = $sub->snippet->getResourceId()->channelId;
					  }
					  foreach($channels as $channel) {
						if($filter_days) $fil = $filter_days; else $fil = 14;
						$date = date('Y-m-d', strtotime('-'.$fil.' days')) . 'T' . date('H:i:s') . 'Z';
						$vids = $youtubeService->search->listSearch('snippet', array('channelId' => $channel, 'publishedAfter' => $date, 'maxResults' => 50, 'order' => 'date'));
						foreach($vids as $vid) {
							$videos[$vid->id->videoId] = array( 
								$vid->snippet->title,
								$vid->snippet->thumbnails->default->url,
								$vid->snippet->publishedAt,
								$vid->snippet->channelTitle,
								'upload'
							);
						}
					  }
				  }
				  
				  if($use_activity_scan) {
					  $subscriptions = $youtubeService->activities->listActivities('id,snippet,contentDetails', array('home' => true, 'maxResults' => 50, 'fields' => 'items(contentDetails,id,snippet)'));
					  foreach($subscriptions as $sub) {
						
						$details = $sub->contentDetails;
						$id = null;
						if(in_array($type = $sub->snippet->type, $types)) {
							if(isset($details->$type->videoId))
								$id = $details->$type->videoId;
							else
								$id = $details->$type->getResourceId()->videoId;
						}

						if($id && !isset($videos[$id])) {
							$videos[$id] = array(
								$sub->snippet->title,
								$sub->snippet->thumbnails->default->url,
								$sub->snippet->publishedAt,
								$sub->snippet->channelTitle,
								$sub->snippet->type
							);
						}
					  }
				  }
				  
				  file_put_contents($vidfile, json_encode($videos));
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
			  <audio src="alert.mp3" autoplay></audio>
END;
		}
		  } else {
			$videos = json_decode(file_get_contents($vidfile), true);
		  }
		  
		  $queued = @file_get_contents(__DIR__ . '/.youtube.downloaded');
		  $queued = json_decode($queued, true);
		  if(!$queued) $queued = array();

		  $crap = @file_get_contents(__DIR__ . '/.youtube.hidden');
		  $crap = json_decode($crap, true);
		  if(!$crap) $crap = array();

		  $videos = array_diff_key($videos, $queued);
		  $videos = array_diff_key($videos, $crap);

		  if($filter_days) {
			  $videos = array_filter($videos, function($e) use ($filter_days) {
				return strtotime($e[2]) > strtotime('-' . $filter_days . ' days');
			  });
		  }
		  
		  $alerts = @file_get_contents($alertfile = __DIR__ . '/.youtube.alerts');
		  $alerts = json_decode($alerts, true);
		  if(!$alerts) $alerts = array();
		  if($diff = array_diff_key($videos, $alerts)) {
			$alert = true;
			file_put_contents($alertfile, json_encode(array_flip(array_keys($videos))));
		  }
		  
		  uasort($videos, function($a, $b) { return strtotime($b[2]) - strtotime($a[2]); });
		  if(empty($htmlBody)) {
		  ob_start();
		  if($alert) { ?>
			<audio src="alert.mp3" autoplay></audio>
		  <?php } ?>
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
			<div class="button">
				<input type="submit" value="Go"/>
			</div>
			<table><thead><tr><th>Download</th><th>Image</th><th>Author</th><th>Name</th><th>Published</th><th>Format</th><th>Post Type</th><th>Hide</th></tr></thead><tfoot></tfoot><tbody>
			<?php foreach($videos as $id => $data) { list($title, $image, $date, $author, $type) = $data; $date = date('Y-m-d h:i:s', strtotime($date)); ?>
				<tr><td><input class="download" type="checkbox" name="download[<?php echo $id ?>]"></td><td><img src="<?php echo $image ?>"></td><td><?php echo $author ?></td><td><?php echo $title ?></td><td><?php echo $date ?></td><td>
				<select name="format[<?php echo $id ?>]">
				<?php foreach($formats as $num => $format) { ?>
					<option value="<?php echo $num ?>"><?php echo $format ?></option>
				<?php } ?>
				</select>
				</td><td><?php echo ucfirst($type) ?></td><td class="hide"><input type="checkbox" class="hide" name="crap[<?php echo $id ?>]"></td></tr>
			<?php } ?>
			</tbody></table>
		  <?php
		  $htmlBody = ob_get_clean();
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
  <div class="downloading">
	<?php echo $downloading ?>
  </div>
  <div class="message">
	<?php echo $message ?>
  </div>
  <form method="post" action="">
	<?php if(!empty($htmlBody)) { echo $htmlBody; } ?>
	<label>Manual download by ID <input type="text" name="videoid" size="30"></label><br>
  </form>
</body>
<script src="//code.jquery.com/jquery-1.11.3.min.js"></script>
<script src="//code.jquery.com/jquery-migrate-1.2.1.min.js"></script>
<script type="text/javascript">
	var lastUpdate;
	if (!Date.now) {
	  Date.now = function now() {
		return new Date().getTime();
	  };
	}
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
		lastUpdate = new Date();
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
	jQuery('select').click(function(e) {
		lastUpdate = new Date();
		e.stopPropagation();
	});
	
	jQuery(function() {
		lastUpdate = new Date();
		
		setTimeout(function() {
			jQuery('div.message').hide();
		}, 10000);
		
		setInterval(function() {
			if(new Date().getTime() - lastUpdate.getTime() > 30000) {
				lastUpdate = new Date();
				window.location = window.location;
			}
		}, 5000);
	});
</script>
</html>
