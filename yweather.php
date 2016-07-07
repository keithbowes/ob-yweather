#!/bin/env php
<?php

/* Usage: yweather.php [zip [units [format]]] */

/* Change these defaults to prevent having to specify via the command line */
define('DEFAULT_ZIP', 'New York, NY'); // zip code or location name
define('DEFAULT_UNITS', 'imperial'); // 'imperial' or 'metric';
define('DEFAULT_FORMAT', 'json'); // can be 'json' (faster download) or 'xml'
define('CACHE_FOR', 6 * 60 * 60); // Default: 6 hours
define('WEATHER_URL', 'https://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20weather.forecast%20where%20woeid%20in%20(select%20woeid%20from%20geo.places(1)%20where%20text%3D%22<zip>%22)&format=<format>&u=c&env=store%3A%2F%2Fdatatables.org%2Falltableswithkeys'); // Should work with different services

// To use Weather Underground instead of Yahoo!:
//define('WEATHER_URL', 'https://www.wunderground.com/auto/rss_full/<zip>.<format>');

interface WeatherDataFormat
{
	public function __construct($data);
	public function getAttribute($elem, $attr);
	public function getElement($elem, $is_top_level = false);
	public function getElements($elem, $is_top_level = false);
	public function getElementName($elem);
	public function getElementValue($elem);
	public function getSubElement($parent, $element);
	public function isEmpty($elem);
}

class JsonWeather implements WeatherDataFormat
{
	private $json;
	private $props;

	public function __construct($data)
	{
		$this->json = json_decode($data);
		$this->json = $this->json->query->results->channel;
	}

	public function getAttribute($elem, $attr)
	{
		return $elem->{$attr};
	}

	public function getElement($elem, $is_top_level = false)
	{
		return $this->getElements($elem, $is_top_level);
	}

	public function getElements($elem, $is_top_level = false)
	{
		$obj = ($is_top_level) ? $this->json : $this->json->item;
		$obj = $obj->{$elem};
		$obj = $this->setElementName($obj, $elem);

		return $obj;
	}

	public function getElementName($elem)
	{
		if (FALSE !== array_search(gettype($elem), array('array', 'object')))
		{
			$obj = (object) $elem;
			return $obj->name;
		}
	}

	private function setElementName($elem, $name)
	{
		if ('object' == gettype($elem))
		{
			$elem->name = $name;
		}
		elseif ('array' == gettype($elem))
		{
			foreach ($elem as $element)
				if ('object' == gettype($element))
					$element->name = $name;
				elseif ('array' == gettype($element))
					$this->setElementName($element, $name);
		}

		return $elem;
	}

	public function getElementValue($elem)
	{
		return $elem;
	}

	public function getSubElement($parent, $element)
	{
		return $parent->{$element};
	}

	public function isEmpty($elem)
	{
		return NULL == $elem;
	}
}


class XmlWeather implements WeatherDataFormat
{
	const WEATHER_NS = 'http://xml.weather.yahoo.com/ns/rss/1.0';

	private $document;

	public function __construct($data)
	{
		$this->document = new DOMDocument();
		$this->document->loadXML($data);
	}

	public function getAttribute($elem, $attr)
	{
		if (is_object($elem))
		return $elem->getAttribute($attr);
	}

	public function getElement($elem, $is_top_level = false, $use_namespace = false)
	{
		return $this->getElements($elem, $is_top_level, $use_namespace)->item(0);
	}

	public function getElements($elem, $is_top_level = false, $use_namespace = false)
	{
		$obj = !$is_top_level ? $this->document->getElementsByTagName('item')->item(0) : $this->document->documentElement;

		if (!$use_namespace)
			$elems = $obj->getElementsByTagName($elem);
		else
			$elems = $obj->getElementsByTagNameNS(self::WEATHER_NS, $elem);

		return $elems;
	}

	public function getElementName($elem)
	{
		return !isset($elem->localName) ? $elem->tagName : $elem->localName;
	}

	public function getElementValue($elem)
	{
		return $elem->nodeValue;
	}

	public function getSubElement($parent, $element)
	{
		return $parent->getElementsByTagName($element)->item(0);
	}

	public function isEmpty($elem)
	{
		return 0 == $elem->length;
	}
}

class WeatherData
{
	private $current_conditions;
	private $forecasts;
	private $title;
	private $pubDate;
	private $location;
	private $wind;
	private $atmosphere;
	private $astronomy;
	private $units;

	private $unit;
	private $weather;

	public function __construct($zip, $unit, $format)
	{
		$this->unit = strtolower($unit);
		$format = strtolower($format);

		try
		{
			$classname = $format . 'Weather';
			if (class_exists($classname))
				$this->weather = new $classname($this->retrieveData($zip, $format));
			else
				throw new Exception("Unsupported format $format specified.");
		}
		catch (Exception $e)
		{
			echo $e->getMessage() . "\n";
			return;
		}

		$this->current_condition = $this->weather->getElement('condition', false, true);
		$this->forecasts = $this->weather->getElements('forecast', false, true);
		$this->title = $this->weather->getElement('title');
		$this->pubDate = $this->weather->getElement('pubDate', false);
		$this->location = $this->weather->getElement('location', true, true);
		$this->wind = $this->weather->getElement('wind', true, true);
		$this->atmosphere = $this->weather->getElement('atmosphere', true, true);;
		$this->astronomy = $this->weather->getElement('astronomy', true, true);
		$this->units = $this->weather->getElement('units', true, true);

		/* If the feed uses the standard <item> rather than Yahoo's proprietary <yahooweather:forecast> */
		if ($this->weather->isEmpty($this->forecasts))
			$this->forecasts = $this->weather->getElements('item', true);
	}

	public function printMenu()
	{
		if (!is_object($this->weather))
			return;


		echo "<openbox_pipe_menu>\n";

		//$this->createSeparator(array($this->weather->getAttribute($this->location, 'city'), ' ', $this->weather->getElementValue($this->pubDate)));
		//$this->createSeparator('Current conditions');
		$this->createSeparator($this->weather->getElementValue($this->title));

		if (!$this->weather->isEmpty($this->current_condition))
		{
			$this->createItem(array('Weather: ', $this->weather->getAttribute($this->current_condition, 'text')));
			$this->createItem(array('Temperature: ', $this->weather->getAttribute($this->current_condition, 'temp'), ' ', $this->weather->getAttribute($this->units, 'temperature')));
		}

		if (!$this->weather->isEmpty($this->atmosphere))
		{
			$this->createItem(array('Humidity: ', $this->weather->getAttribute($this->atmosphere, 'humidity'), '%'));
			$this->createItem(array('Visibility: ', $this->weather->getAttribute($this->atmosphere, 'visibility'), ' ', $this->weather->getAttribute($this->units, 'distance')));

			$pressure_state = $this->weather->getAttribute($this->atmosphere, 'rising') ? 'rising' : 'steady';
			$this->createItem(array('Pressure: ', $this->weather->getAttribute($this->atmosphere, 'pressure'), ' ', $this->weather->getAttribute($this->units, 'pressure'), ' ', $pressure_state));
		}

		if (!$this->weather->isEmpty($this->wind))
		{
			$this->createItem(array('Wind chill: ', $this->weather->getAttribute($this->wind, 'chill'), ' ', $this->weather->getAttribute($this->units, 'temperature')));
			$this->createItem(array('Wind direction: ', $this->weather->getAttribute($this->wind, 'direction'), ' degrees'));
			$this->createItem(array('Wind speed: ', $this->weather->getAttribute($this->wind, 'speed'), ' ', $this->weather->getAttribute($this->units, 'speed')));
		}

		if (!$this->weather->isEmpty($this->astronomy))
		{
			$this->createItem(array('Sunrise: ', $this->weather->getAttribute($this->astronomy, 'sunrise')));
			$this->createItem(array('Sunset: ', $this->weather->getAttribute($this->astronomy, 'sunset')));
		}

		foreach ((object) $this->forecasts as $forecast)
		{
			if ('forecast' == $this->weather->getElementName($forecast))
			{
				$this->createSeparator(array('Forecast: ', $this->weather->getAttribute($forecast, 'day')));
				$this->createItem(array('Weather: ', $this->weather->getAttribute($forecast, 'text')));
				$this->createItem(array('Min temperature: ', $this->weather->getAttribute($forecast, 'low'), ' ', $this->weather->getAttribute($this->units, 'temperature')));
				$this->createItem(array('Max temperature: ', $this->weather->getAttribute($forecast, 'high'), ' ', $this->weather->getAttribute($this->units, 'temperature')));
			}
			else
			{
				$this->createSeparator(htmlspecialchars($this->weather->getElementValue($this->weather->getSubElement($forecast, 'title'))));
				$this->createItem(htmlspecialchars($this->weather->getElementValue($this->weather->getSubElement($forecast, 'description'))));
			}
		}

		echo "</openbox_pipe_menu>\n";
	}

	private function createElement($elem, $label)
	{
		if (is_array($label))
			$label = implode('', $label);

		/* Do metric conversions */
		if ('imperial' != $this->unit)
		{
			$pattern = '/([.\d]+)(\s*)(F|in|mi|mph)/';
			preg_match($pattern, $label, $matches);
			list($full, $number, $space, $unit) = $matches;
			switch ($unit)
			{
			case 'F':
				$conv = floor(($number - 32) * 5 / 9);
				$newunit = 'C';
				break;
			case 'in':
				$conv = round($number * 2.54 / 100, 1);
				$newunit = 'm';
				break;
			case 'mi':
			case 'mph':
				$conv = round($number * 1.61, 1);
				$newunit = ('mi' == $unit) ? 'km' : 'kpm';
				break;
			}
			$label = preg_replace($pattern, "$conv$2$newunit", $label);
		}

		printf("<$elem label=\"%s\" />\n", $label);
	}

	private function createSeparator($label)
	{
		$this->createElement('separator', $label);
	}

	private function createItem($label)
	{
		$this->createElement('item', $label);
	}

	private function retrieveData($zip, $format)
	{
		$cache_file = "yweather.$zip.$format.cache";

		if (getenv('XDG_CACHE_HOME'))
			$cache_dir = getenv('XDG_CACHE_HOME');
		elseif (is_dir(getenv('HOME') . '/.cache'))
			$cache_dir = getenv('HOME') . '/.cache';
		else
			$cache_dir = getenv('HOME');

		if (is_dir($cache_dir . '/openbox'))
			$cache_dir .= '/openbox';
		else
			$cache_file = '.' . $cache_file;

		$cache_file = $cache_dir . '/' . $cache_file;
		if (file_exists($cache_file) && time() - filemtime($cache_file) < CACHE_FOR)
			return file_get_contents($cache_file);

		if (!extension_loaded('curl'))
			throw new Exception('This script requires the PHP curl extension');

		$url = str_replace('<zip>', urlencode($zip), str_replace('<format>', $format, WEATHER_URL));
		$curl = curl_init($url);

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_USERAGENT, 'Yweather/0.2');

		if (FALSE === ($fcontents = curl_exec($curl)))
			throw new Exception("Couldn't retrieve $url");

		curl_close($curl);
		file_put_contents($cache_file, $fcontents);
		return $fcontents;
	}

	private function isEmpty($obj)
	{
		return (2 > count((array) $obj));
	}
}

$zip = isset($argv[1]) ? $argv[1] : DEFAULT_ZIP;
$unit = isset($argv[2]) ? $argv[2] : DEFAULT_UNITS;
/* Prefer JSON, just because it's smaller than XML */
$format = isset($argv[3]) ? $argv[3] : DEFAULT_FORMAT;

$weather = new WeatherData($zip, $unit, $format);
$weather->printMenu();

?>