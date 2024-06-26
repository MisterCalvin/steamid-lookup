<?php
require __DIR__ . '/../../vendor/autoload.php';

// This can be any string, like just the steamid, or a link to the profile
$UserInput = (string)filter_input( INPUT_GET, 'steamid' );
$shouldRedirect = filter_input(INPUT_GET, 'redirect');
$WebAPIKey = getenv('STEAM_API_KEY');
$steamidserver = htmlspecialchars($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST']);

$redis = null;
$redisCacheTime = getenv('REDIS_CACHE_TTL') ?: 3600;

function getRedisConnection() {
    static $attempted = false;
    static $redis = null;

    if (!$attempted) {
        $attempted = true; // Mark that a connection attempt has been made

        $redisHost = getenv('REDIS_HOST');
        $redisPort = getenv('REDIS_PORT') ?: 6379;
        $redisPassword = getenv('REDIS_PASSWORD');
        
        if ($redisHost) {
            try {
                $redis = new Redis();
                $redis->connect($redisHost, $redisPort);
                
                if (!empty($redisPassword)) {
                    $redis->auth($redisPassword);
                }
            } catch (Exception $e) {
                error_log("Redis connection failed: " . $e->getMessage());
                $redis = null; // Ensure $redis is null if connection fails
            }
        }
    }

    return $redis; // Return the Redis object or null if connection failed or not attempted
}

function getPlayerSummaries($steamID64, $WebAPIKey, $redis, $redisCacheTime = null) {

	if ($redisCacheTime === null) {
        $redisCacheTime = getenv('REDIS_CACHE_TTL') ?: 3600; // Fetch default if not provided
    }

    $cacheKey = "playerSummaries_{$steamID64}";

    if ($redis) {
        $cachedData = $redis->get($cacheKey);
        if ($cachedData) {
            return json_decode($cachedData, true);
        }
    }

    // If we reach this point, it means there was no cache entry,
    // so we need to fetch data via cURL
    $url = "https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key={$WebAPIKey}&steamids={$steamID64}";
    $ch = curl_init();
    curl_setopt_array($ch, [
		CURLOPT_USERAGENT      => 'Steam Player Summary Lookup',
		CURLOPT_ENCODING       => 'gzip',
        CURLOPT_RETURNTRANSFER => true,
		CURLOPT_URL => $url,
        CURLOPT_TIMEOUT => 5,
		CURLOPT_CONNECTTIMEOUT => 5
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    // If cURL failed, handle it appropriately
    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);

    // Store the response in Redis if available
    if ($redis && isset($data)) {
        $redis->set($cacheKey, json_encode($data), $redisCacheTime);
    }

    return $data;
}

function getLocationDetails($loccountrycode, $locstatecode = null, $loccityid = null, $redis, $redisCacheTime = null) {
	
	if ($redisCacheTime === null) {
        $redisCacheTime = getenv('REDIS_CACHE_TTL') ?: 3600; // Fetch default if not provided
    }

    // Creating a unique cache key based on the inputs
    $cacheKey = "locationDetails_{$loccountrycode}_{$locstatecode}_{$loccityid}";
    
	if ($redis) {
        $cachedData = $redis->get($cacheKey);
        if ($cachedData) {
            return $cachedData;
        }
    }

    // If not cached, proceed with fetching the data
    if ($loccityid !== null && $locstatecode !== null) {
        $url = "https://steamcommunity.com/actions/QueryLocations/$loccountrycode/$locstatecode/";
        $ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_USERAGENT      	=> 'Steam Country Lookup',
			CURLOPT_ENCODING       	=> 'gzip',
			CURLOPT_RETURNTRANSFER 	=> true,
			CURLOPT_URL 			=> $url,
			CURLOPT_CONNECTTIMEOUT 	=> 5,
			CURLOPT_TIMEOUT        	=> 5
		]);

        $response = curl_exec($ch);
        curl_close($ch);
        $locations = json_decode($response, true);

        foreach ($locations as $location) {
            if ($location['cityid'] == $loccityid) {
                $locationDetails = $location['cityname'] . ", " . $locstatecode . ", " . $loccountrycode;

                // Check if $redis is not null before attempting to cache the response
                if ($redis) {
                    $redis->set($cacheKey, json_encode($locationDetails), $redisCacheTime); // Ensure to encode as JSON
                }
                return $locationDetails;
            }
        }
    }

    // Fallbacks if detailed city info isn't available or needed
    $locationDetails = "Unknown";
    if ($locstatecode !== null) {
        $locationDetails = $locstatecode . ", " . $loccountrycode;
    } elseif ($loccountrycode !== null) {
        $locationDetails = $loccountrycode;
    }

	if ($redis) {
    	// Cache the fallback response as well to avoid repeated empty queries
    	$redis->set($cacheKey, $locationDetails, $redisCacheTime);
	}

    return $locationDetails;
}

try
{
	// SetFromURL does all the heavy lifing of parsing the input
	// This callback is only called to resolve vanity urls when required
	$SteamID = SteamID::SetFromURL( $UserInput, function( string $URL, int $Type ) use ( $WebAPIKey )
	{

		$Parameters =
		[
			'format' => 'json',
			'key' => $WebAPIKey,
			'vanityurl' => $URL,
			'url_type' => $Type
		];

		$c = curl_init( );

		curl_setopt_array( $c, [
			CURLOPT_USERAGENT      => 'Steam Vanity URL Lookup',
			CURLOPT_ENCODING       => 'gzip',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_URL            => 'https://api.steampowered.com/ISteamUser/ResolveVanityURL/v1/?' . http_build_query( $Parameters ),
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 5
		] );

		$Response = curl_exec( $c );

		curl_close( $c );

		$Response = json_decode( (string)$Response, true );

		if( isset( $Response[ 'response' ][ 'success' ] ) )
		{
			switch( (int)$Response[ 'response' ][ 'success' ] )
			{
				case 1: return $Response[ 'response' ][ 'steamid' ];
				case 42: return null;
			}
		}

		throw new Exception( 'Failed to perform API request' );
	} );

	// Check that account type is actually a user profile
	if( $SteamID->GetAccountType() !== SteamID::TypeIndividual )
	{
		throw new InvalidArgumentException( 'We only support looking up individual profiles.' );
	}

	// Instance does not matter, reset it to the default one
	$SteamID->SetAccountInstance( SteamID::DesktopInstance );

	// Only public universe is available on Steam, so just reset it as well
	$SteamID->SetAccountUniverse( SteamID::UniversePublic );

	$interpretedType = '';
	$steamProfileName = '';
	$steamIDValue = '';

	if (filter_var($UserInput, FILTER_VALIDATE_URL)) {
		$urlParts = parse_url($UserInput);
		$pathParts = explode('/', trim($urlParts['path'], '/'));
	
		// Detecting if the URL is a Steam custom URL
		if (in_array('id', $pathParts)) {
			$key = array_search('id', $pathParts);
			if ($key !== false && isset($pathParts[$key + 1])) {
				$steamProfileName = $pathParts[$key + 1];
				$interpretedType = 'customURL';
			}
		} else {
			$interpretedType = 'Invalid SteamID';
			$steamProfileName = '';
		}
	} elseif (preg_match('/^765/', $UserInput)) {
		$interpretedType = 'SteamID64';
		$steamIDValue = $SteamID->ConvertToUInt64();
	} elseif (preg_match('/^\[U:1:\d+\]$/', $UserInput)) {
		$interpretedType = 'SteamID3';
		$steamIDValue = $SteamID->RenderSteam3();
	} else {
		$interpretedType = 'SteamID';
		$steamIDValue = $SteamID->RenderSteam2();
	}

		echo '<div class="result-item">';
		echo '<label>SteamID:</label>';
		echo '<span><a href="' . $steamidserver . '/lookup/' . htmlspecialchars($SteamID->RenderSteam2()) . '">' . htmlspecialchars($SteamID->RenderSteam2()) . '</a></span>';
		echo '<img src="/images/copy.svg" class="icon-copy" alt="Copy SteamID" title="Copy to clipboard" width="24" height="24" data-copy-value="'.htmlspecialchars($SteamID->RenderSteam2()).'"/>';
		echo '<span class="message-copied">Copied</span>';
		echo '</div>';	
		echo '<div class="result-item">';
		echo '<label>SteamID3:</label>';
		echo '<span><a href="' . $steamidserver . '/lookup/' . htmlspecialchars($SteamID->RenderSteam3()) . '">' . htmlspecialchars($SteamID->RenderSteam3()) . '</a></span>';
		echo '<img src="/images/copy.svg" class="icon-copy" alt="Copy SteamID3" title="Copy to clipboard" width="24" height="24" data-copy-value="'.htmlspecialchars($SteamID->RenderSteam3()).'"/>';
		echo '<span class="message-copied">Copied</span>';
		echo '</div>';
		echo '<div class="result-item">';
		echo '<label>SteamID64:</label>';
		echo '<span><a href="' . $steamidserver . '/lookup/' . htmlspecialchars($SteamID->ConvertToUInt64()) . '">' . htmlspecialchars($SteamID->ConvertToUInt64()) . '</a></span>';
		echo '<img src="/images/copy.svg" class="icon-copy" alt="Copy SteamID64" title="Copy to clipboard" width="24" height="24" data-copy-value="'.htmlspecialchars($SteamID->ConvertToUInt64()).'"/>';
		echo '<span class="message-copied">Copied</span>';
		echo '</div>';

		$playerSummaries = getPlayerSummaries($SteamID->ConvertToUInt64(), $WebAPIKey, $redis, $redisCacheTime);

		if (isset($playerSummaries['response']['players'][0])) {
			$player = $playerSummaries['response']['players'][0];

			$customURL = 'None';
			$profileUrl = '';
	
			$customURL = 'None';
			$profileUrl = '';
			
			if (isset($player['profileurl'])) {
				// Split the URL by slashes and get the last part
				$urlParts = explode('/', rtrim($player['profileurl'], '/'));
				$lastPart = end($urlParts);
			
				// Check if the last part is not numeric, which means it's a custom URL
				if (!is_numeric($lastPart)) {
					$customURL = $lastPart;
					$profileUrl = $player['profileurl'];
				}
			}
		
			// Status text with game info if in-game
			if (!empty($player['gameextrainfo']) && !empty($player['gameid'])) {
				$statusText = 'In-Game: <a href="https://store.steampowered.com/app/' . htmlspecialchars($player['gameid']) . '" target="_blank">' . htmlspecialchars($player['gameextrainfo']) . '</a>';
				$watchLink = '<a href="https://steamcommunity.com/broadcast/watch/' . htmlspecialchars($SteamID->ConvertToUInt64()) . ' " target="_blank">
								<img src="/images/watch.svg" class="icon-watch" alt="Watch Game" title="Watch ' . $player['personaname'] . '\'s game" width="24" height="24"/>
							  </a>';
			} else {
				// Set status based on personastate
				switch ($player['personastate']) {
					case 0: $statusText = 'Offline'; break;
					case 1: $statusText = 'Online'; break;
					case 2: $statusText = 'Busy'; break;
					case 3: $statusText = 'Away'; break;
					case 4: $statusText = 'Snooze'; break;
					case 5: $statusText = 'Looking to Trade'; break;
					case 6: $statusText = 'Looking to Play'; break;
					default: $statusText = 'Unknown'; break;
				}
				$watchLink = ''; // No game, no watch link
			}

				if (isset($playerSummaries['response']['players'][0])) {
					$player = $playerSummaries['response']['players'][0];
					$loccountrycode = $player['loccountrycode'] ?? null;
					$locstatecode = $player['locstatecode'] ?? null;
					$loccityid = $player['loccityid'] ?? null;
						
					$locationDetails = getLocationDetails($loccountrycode, $locstatecode, $loccityid, $redis, $redisCacheTime);

				}
				
					echo '<div class="result-item">';
					echo '<label>CustomURL:</label>';
					echo '<span>' . ($customURL != 'None' ? '<a href="' . htmlspecialchars($profileUrl) . '" target="_blank">' . htmlspecialchars($customURL) . '</a>' : 'None') . '</span>';
					echo '</div>';
					echo '<div class="result-item"><label>Profile State:</label><span>' . (isset($player['communityvisibilitystate']) ? ($player['communityvisibilitystate'] == 3 ? 'Public' : 'Private') : 'Unknown') . '</span></div>';
					echo '<div class="result-item"><label>Profile Created:</label><span>' . (isset($player['timecreated']) ? date('F j, Y', $player['timecreated']) : 'Unknown') . '</span></div>';
					echo '<div class="result-item"><label>Name:</label><span>' . (isset($player['personaname']) ? htmlspecialchars($player['personaname']) : 'Unknown') . '</span></div>';
					echo '<div class="result-item"><label>Location:</label><span>' . $locationDetails . '</span></div>';
					echo '<div class="result-item"><label>Status:</label><span>' . $statusText . '</span>' . $watchLink . '</div>';
			}

		echo '<div class="result-item">';
		echo '<label>Profile:</label>';
		echo '<span><a href="https://steamcommunity.com/profiles/' .  htmlspecialchars($SteamID->ConvertToUInt64()). '" target="_blank"">' . 'https://steamcommunity.com/profiles/' . htmlspecialchars($SteamID->ConvertToUInt64()) . '</a></span>';
		echo '<a href="https://steamcommunity.com/profiles/' . htmlspecialchars($SteamID->ConvertToUInt64()) . '" target=_blank"">' . '<img src="/images/steam.svg" class="icon-copy" alt="Steam icon" title="View on steamcommunity.com" width="24" height="24"/></a>';
		echo '</div>';

    if ($shouldRedirect !== null) {
      // Redirect to the Steam profile URL
      header('Location: ' . 'https://steamcommunity.com/profiles/' . htmlspecialchars($SteamID->ConvertToUInt64()));
      exit; // Important to prevent the script from executing further
  }

		$responseArray = [
			'html' => ob_get_clean(), // Captured HTML output
			'interpretedType' => $interpretedType,
			'steamProfileName' => $steamProfileName,
			'steamIDValue' => $steamIDValue
		];

    header('Content-Type: application/json');
    echo json_encode($responseArray);
    exit;
}

catch( Exception $e )
{
	exit( $e->getMessage() );
	header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}