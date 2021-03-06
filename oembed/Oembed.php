<?php
/**
 * Format of the Oembed config. Autodiscover allows discovery of all URLs.
 *
 * Endpoint set to true means autodiscovery for this specific provider is
 * allowed (even if autodiscovery in general has been disabled).
 *
 * <code>
 *
 * name: Oembed
 * ---
 * Oembed:
 *   providers:
 *     'http://*.youtube.com/watch*':
 *     'http://www.youtube.com/oembed/'
 *   autodiscover:
 *     true
 * </code>
 *
 * @package framework
 * @subpackage oembed
 */

class Oembed implements ShortcodeHandler {

	public static function is_enabled() {
		return Config::inst()->get('Oembed', 'enabled');
	}

	/**
	 * Gets the autodiscover setting from the config.
	 */
	public static function get_autodiscover() {
		return Config::inst()->get('Oembed', 'autodiscover');
	}

	/**
	 * Gets providers from config.
	 */
	public static function get_providers() {
		return Config::inst()->get('Oembed', 'providers');
	}

	/**
	 * Returns an endpoint (a base Oembed URL) from first matching provider.
	 *
	 * @param $url Human-readable URL.
	 * @returns string/bool URL of an endpoint, or false if no matching provider exists.
	 */
	protected static function find_endpoint($url) {
		foreach(self::get_providers() as $scheme=>$endpoint) {
			if(self::matches_scheme($url, $scheme)) {
				$protocol = Director::is_https() ? 'https' : 'http';

				if (is_array($endpoint)) {
					if (array_key_exists($protocol, $endpoint)) $endpoint = $endpoint[$protocol];
					else $endpoint = reset($endpoint);
				}

				return $endpoint;
			}
		}
		return false;
	}

	/**
	 * Checks the URL if it matches against the scheme (pattern).
	 *
	 * @param $url Human-readable URL to be checked.
	 * @param $scheme Pattern to be matched against.
	 * @returns bool Whether the pattern matches or not.
	 */
	protected static function matches_scheme($url, $scheme) {
		$urlInfo = parse_url($url);
		$schemeInfo = parse_url($scheme);

		foreach($schemeInfo as $k=>$v) {
			if(!array_key_exists($k, $urlInfo)) {
				return false;
			}
			if(strpos($v, '*') !== false) {
				$v = preg_quote($v, '/');
				$v = str_replace('\*', '.*', $v);
				if($k == 'host') {
					$v = str_replace('*\.', '*', $v);
				}
				if(!preg_match('/' . $v . '/', $urlInfo[$k])) {
					return false;
				}
			} elseif(strcasecmp($urlInfo[$k], $v)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Performs a HTTP request to the URL and scans the response for resource links
	 * that mention oembed in their type.
	 *
	 * @param $url Human readable URL.
	 * @returns string/bool Oembed URL, or false.
	 */
	protected static function autodiscover_from_url($url)
	{
		$timeout   = 5;
		$sapphireInfo = new SapphireInfo();
		$useragent = 'SilverStripe/' . $sapphireInfo->Version();
		$curlRequest = curl_init();
		curl_setopt_array(
			$curlRequest,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => 1,
				CURLOPT_USERAGENT      => $useragent,
				CURLOPT_CONNECTTIMEOUT => $timeout,
				CURLOPT_FOLLOWLOCATION => 1,

			)
		);

		$response = curl_exec($curlRequest);
		$headers = curl_getinfo($curlRequest);
		if(!$response || $headers['http_code'] !== 200) {
			return false;
		}
		$body = $response;		
		return static::autodiscover_from_body($body);
	}

	/**
	 * Given a response body, determine if there is an autodiscover url
	 *
	 * @param string $body
	 * @return bool|string
	 */
	public static function autodiscover_from_body($body) {
		// Look within the body for an oembed link.
		$pcreOmbed = '#<link[^>]+?(?:href=[\'"](?<first>[^\'"]+?)[\'"][^>]+?)'
			. '?type=["\']application/json\+oembed["\']'
			. '(?:[^>]+?href=[\'"](?<second>[^\'"]+?)[\'"])?#';

		if(preg_match_all($pcreOmbed, $body, $matches, PREG_SET_ORDER)) {
			$match = $matches[0];
			if(!empty($match['second'])) {
				return html_entity_decode($match['second']);
			}
			if(!empty($match['first'])) {
				return html_entity_decode($match['first']);
			}
		}
		return false;
	}

	/**
	 * Takes the human-readable URL of an embeddable resource and converts it into an
	 * Oembed_Result descriptor (which contains a full Oembed resource URL).
	 *
	 * @param $url Human-readable URL
	 * @param $type ?
	 * @param $options array Options to be used for constructing the resulting descriptor.
	 * @returns Oembed_Result/bool An Oembed descriptor, or false
	 */
	public static function get_oembed_from_url($url, $type = false, array $options = array()) {
		if(!self::is_enabled()) return false;

		// Find or build the Oembed URL.
		$endpoint = self::find_endpoint($url);
		$oembedUrl = false;
		if(!$endpoint) {
			if(self::get_autodiscover()) {
				$oembedUrl = self::autodiscover_from_url($url);
			}
		} elseif($endpoint === true) {
			$oembedUrl = self::autodiscover_from_url($url);
		} else {
			// Build the url manually - we gave all needed information.
			$oembedUrl = Controller::join_links($endpoint, '?format=json&url=' . rawurlencode($url));
		}

		// If autodescovery failed the resource might be a direct link to a file
		if(!$oembedUrl) {
			if(File::get_app_category(File::get_file_extension($url)) == "image") {
				return new Oembed_Result($url, $url, $type, $options);
			}
		}

		if($oembedUrl) {
			// Inject the options into the Oembed URL.
			if($options) {
				if(isset($options['width']) && !isset($options['maxwidth'])) {
					$options['maxwidth'] = $options['width'];
				}
				if(isset($options['height']) && !isset($options['maxheight'])) {
					$options['maxheight'] = $options['height'];
				}
				$oembedUrl = Controller::join_links($oembedUrl, '?' . http_build_query($options, '', '&'));
			}

			return new Oembed_Result($oembedUrl, $url, $type, $options);
		}

		// No matching Oembed resource found.
		return false;
	}

	public static function get_shortcodes() {
		return 'embed';
	}

	public static function handle_shortcode($arguments, $content, $parser, $shortcode, $extra = array()) {
		if(isset($arguments['type'])) {
			$type = $arguments['type'];
			unset($arguments['type']);
		} else {
			$type = false;
		}
		$oembed = self::get_oembed_from_url($content, $type, $arguments);
		if($oembed && $oembed->exists()) {
			return $oembed->forTemplate();
		} else {
			return '<a href="' . $content . '">' . $content . '</a>';
		}
	}
}

/**
 * @property string $Type Oembed type
 * @property string $Title Title
 * @property string $URL URL to asset
 * @property string $Provider_URL Url for provider
 * @property int $Width
 * @property int $Height
 * @property string $Info Descriptive text for this oembed
 *
 * @package framework
 * @subpackage oembed
 */
class Oembed_Result extends ViewableData {
	/**
	 * JSON data fetched from the Oembed URL.
	 * This data is accessed dynamically by getField and hasField.
	 */
	protected $data = false;

	/**
	 * Human readable URL
	 */
	protected $origin = false;

	/**
	 * ?
	 */
	protected $type = false;

	/**
	 * Oembed URL
	 */
	protected $url;

	/**
	 * Class to be injected into the resulting HTML element.
	 */
	protected $extraClass;

	private static $casting = array(
		'html' => 'HTMLText',
	);

	public function __construct($url, $origin = false, $type = false, array $options = array()) {
		$this->url = $url;
		$this->origin = $origin;
		$this->type = $type;

		if(isset($options['class'])) {
			$this->extraClass = $options['class'];
		}
		if($options) {
			if(isset($options['width'])) {
				$this->Width = $options['width'];
			}
			if(isset($options['height'])) {
				$this->Height = $options['height'];
			}
		}
		parent::__construct();
	}

	public function getOembedURL() {
		return $this->url;
	}

	/**
	 * Fetches the JSON data from the Oembed URL (cached).
	 * Only sets the internal variable.
	 */
	protected function loadData() {
		if($this->data !== false) {
			return;
		}
		$timeout   = 5;
		$sapphireInfo = new SapphireInfo();
		$useragent = 'SilverStripe/' . $sapphireInfo->Version();
		$curlRequest = curl_init();
		curl_setopt_array(
			$curlRequest,
			array(
				CURLOPT_URL            => $this->url,
				CURLOPT_RETURNTRANSFER => 1,
				CURLOPT_USERAGENT      => $useragent,
				CURLOPT_CONNECTTIMEOUT => $timeout,
				CURLOPT_FOLLOWLOCATION => 1,

			)
		);

		$response = curl_exec($curlRequest);
		$headers = curl_getinfo($curlRequest);
		if(!$response || $headers['http_code'] !== 200) {
			$this->data = array();
			return;
		}
		$body = $response;
		$data = json_decode($body, true);
		if(!$data) {
			// if the response is no valid JSON we might have received a binary stream to an image
			$data = array();
			if (!function_exists('imagecreatefromstring')) {
				throw new LogicException('imagecreatefromstring function does not exist - Please make sure GD is installed');
			}
			$image = imagecreatefromstring($body);
			if($image !== FALSE) {
				preg_match("/^(http:\/\/)?([^\/]+)/i", $this->url, $matches);
				$protocoll = $matches[1];
				$host = $matches[2];
				$data['type'] = "photo";
				$data['title'] = basename($this->url) . " ($host)";
				$data['url'] = $this->url;
				$data['provider_url'] = $protocoll.$host;
				$data['width'] = imagesx($image);
				$data['height'] = imagesy($image);
				$data['info'] = _t('UploadField.HOTLINKINFO',
					'Info: This image will be hotlinked. Please ensure you have permissions from the'
					. ' original site creator to do so.');
			}
		}

		// Convert all keys to lowercase
		$data = array_change_key_case($data, CASE_LOWER);

		// Check if we can guess thumbnail
		if(empty($data['thumbnail_url']) && $thumbnail = $this->findThumbnail($data)) {
			$data['thumbnail_url'] = $thumbnail;
		}

		// Purge everything if the type does not match.
		if($this->type && $this->type != $data['type']) {
			$data = array();
		}

		$this->data = $data;
	}

	/**
	 * Find thumbnail if omitted from data
	 *
	 * @param array $data
	 * @return string
	 */
	public function findThumbnail($data) {
		if(!empty($data['thumbnail_url'])) {
			return $data['thumbnail_url'];
		}

		// Hack in facebook graph thumbnail
		if(!empty($data['provider_name']) && $data['provider_name'] === 'Facebook') {
			$id = preg_replace("/.*\\/(\\d+?)\\/?($|\\?.*)/", "$1", $data["url"]);
			return "https://graph.facebook.com/{$id}/picture";
		}

		// no thumbnail found
		return null;
	}

	/**
	 * Wrap the check for looking into Oembed JSON within $this->data.
	 */
	public function hasField($field) {
		$this->loadData();
		return array_key_exists(strtolower($field), $this->data);
	}

	/**
	 * Wrap the field calls to fetch data from Oembed JSON (within $this->data)
	 */
	public function getField($field) {
		$field = strtolower($field);
		if($this->hasField($field)) {
			return $this->data[$field];
		}
	}

	public function forTemplate() {
		$this->loadData();
		switch($this->Type) {
		case 'video':
		case 'rich':
			if($this->extraClass) {
				return "<div class='media $this->extraClass'>$this->HTML</div>";
			} else {
				return "<div class='media'>$this->HTML</div>";
			}
			break;
		case 'link':
			return '<a class="' . $this->extraClass . '" href="' . $this->origin . '">' . $this->Title . '</a>';
			break;
		case 'photo':
			return "<img src='$this->URL' width='$this->Width' height='$this->Height' class='$this->extraClass' />";
			break;
		}
	}

	public function exists() {
		$this->loadData();
		return count($this->data) > 0;
	}
}

