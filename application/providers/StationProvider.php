<?php

use Staple\Controller\RestfulController;
use Staple\Exception\RestException;
use Staple\Json;
use Staple\Request;
use Staple\Rest\Rest;

/**
 * Created by PhpStorm.
 * User: ironpilot
 * Date: 1/3/2018
 * Time: 10:12 PM
 */

class StationProvider extends RestfulController
{
	public function _start()
	{
		$this->addAccessControlOrigin('*');
		$this->addAccessControlMethods([Request::METHOD_GET, Request::METHOD_OPTIONS]);
	}

	public function getIndex()
	{
		$obj = new stdClass();
		$obj->message = 'Station Resource';
		$obj->apis = [
			'info' => '/station/info/[station]',
			'list' => '/station/list?stations=KDEN,KLAX',
			'local' => '/station/local?distance=50&latitude=39&longitude=-104',
			'flight' => '/station/flight?path=KDEN;KLAX&corridor=50',
		];
		return Json::success($obj);
	}

	/**
	 * @param $ident
	 * @return mixed
	 */
	public function getInfo($ident)
	{
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/stationinfo', [
				'format' => 'xml',
				'ids' => strtoupper((string)$ident)
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get local METAR data
	 * @return null|string
	 */
	public function getLocal()
	{
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 3);
		$distance = (int)$_GET['distance'] ?? null;
		$latitude = (float)$_GET['latitude'] ?? null;
		$longitude = (float)$_GET['longitude'] ?? null;

		$box = $this->boundingBoxMiles($distance, $latitude, $longitude);
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/stationinfo', [
				'bbox' => $box['minLat'].','.$box['minLon'].','.$box['maxLat'].','.$box['maxLon'],
				'format' => 'xml',
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	public function getList()
	{
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 3);
		$stationString = (string)($_GET['stations'] ?? '');
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/stationinfo', [
				'format' => 'xml',
				'ids' => $stationString,
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get stations available along specified flight path.
	 * @return string|null
	 */
	public function getFlight()
	{
		$corridorWidth = (float)($_GET['corridor'] ?? 60);
		$flightPath = (string)($_GET['path'] ?? '');

		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/stationinfo', [
				'format' => 'xml',
				'flightPath' => $corridorWidth.';'.$flightPath,
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Calculates a bounding box in degrees of latitude and longitude based on a distance in miles
	 * from a given geographic point.
	 *
	 * @param float $distanceMiles Distance in miles from the central point to each edge of the bounding box.
	 * @param float $latitude Latitude of the center point in decimal degrees.
	 * @param float $longitude Longitude of the center point in decimal degrees.
	 *
	 * @return array An associative array with the keys 'minLat', 'maxLat', 'minLon', and 'maxLon'
	 *               representing the bounding box coordinates.
	 */
	protected function boundingBoxMiles(float $distanceMiles, float $latitude, float $longitude): array
	{
		// Convert miles to km
		$distanceKm = $distanceMiles * 1.609344;

		// Half side length (from center to edge)
		$half = $distanceKm / 2.0;

		// 1° latitude ~ 110.574 km
		// 1° longitude ~ 111.320 * cos(latitude) km
		$kmPerDegLat = 110.574;
		$kmPerDegLon = 111.320 * cos(deg2rad($latitude));

		$deltaLat = $half / $kmPerDegLat;

		// Handle poles: if cos(lat) ~ 0, longitude spans entire range
		if (abs($kmPerDegLon) < 1e-9)
		{
			$minLon = -180.0;
			$maxLon = 180.0;
		} else
		{
			$deltaLon = $half / $kmPerDegLon;
			$minLon = $longitude - $deltaLon;
			$maxLon = $longitude + $deltaLon;
		}



		// Clamp latitude to valid range
		$minLat = floor(max(-90.0, $latitude - $deltaLat)*100)/100;
		$maxLat = floor(min(90.0, $latitude + $deltaLat)*100)/100;

		// Normalize longitudes to [-180, 180]
		$minLon = floor($this->normalizeLon($minLon)*100)/100;
		$maxLon = floor($this->normalizeLon($maxLon)*100)/100;

		return [
			'minLat' => $minLat,
			'maxLat' => $maxLat,
			'minLon' => $minLon,
			'maxLon' => $maxLon,
		];
	}

	/**
	 * Normalize a longitude value to ensure it falls within the range of [-180, 180] degrees.
	 *
	 * @param float $lon The longitude value to normalize.
	 * @return float The normalized longitude.
	 */
	protected function normalizeLon(float $lon): float
	{
		// Wrap longitude into [-180, 180]
		$lon = fmod($lon + 180.0, 360.0);
		if ($lon < 0)
		{
			$lon += 360.0;
		}
		return $lon - 180.0;
	}
}