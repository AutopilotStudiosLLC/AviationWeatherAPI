<?php

use Staple\Controller\RestfulController;
use Staple\Exception\BadRequestException;
use Staple\Exception\RestException;
use Staple\Json;
use Staple\Request;
use Staple\Rest\Rest;

/**
 * Class MetarProvider
 * Get data from METAR stations
 */

class MetarProvider extends RestfulController
{
	public function _start(): void
	{
		$this->addAccessControlOrigin('*');
		$this->addAccessControlMethods([Request::METHOD_GET, Request::METHOD_OPTIONS]);
	}

	/**
	 * @return string|null
	 */
	public function getIndex(): ?string
	{
		$obj = new stdClass();
		$obj->message = 'METAR Resource';
		$obj->apis = [
			'recent' => '/metar/recent/[station]',
			'local' => '/metar/local?distance=50&latitude=39&longitude=-104',
			'list' => '/metar/list?stations=KDEN,KLAX',
			'flight' => '/metar/flight?corridor=60&path=KDEN;KLAX',
		];
		return Json::success($obj);
	}

	/**
	 * Get local METAR data
	 * @return null|string
	 */
	public function getLocal(): ?string
	{
		$hoursBeforeNow = (float)($_GET['hoursBeforeNow'] ?? 3);
		$distance = (int)$_GET['distance'] ?? null;
		$latitude = (float)$_GET['latitude'] ?? null;
		$longitude = (float)$_GET['longitude'] ?? null;

		$box = $this->boundingBoxMiles($distance, $latitude, $longitude);
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/metar', [
				'bbox' => $box['minLat'].','.$box['minLon'].','.$box['maxLat'].','.$box['maxLon'],
				'format' => 'xml',
				'hours' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->METAR as $metar)
			{
				$sky = $metar->sky_condition;
				if(count($sky) == 0) continue;
				$unsetters = [];
				foreach($sky->attributes() as $key=>$value)
				{
					$sky->addChild($key, $value);
					$unsetters[] = $key;
				}
				foreach($unsetters as $attribute)
				{
					unset($sky[$attribute]);
				}
			}
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get recent METAR data
	 * @param string $identifier
	 * @param float $hoursBeforeNow
	 * @return null|string
	 */
	public function getRecent(string $identifier = 'KSEA', float $hoursBeforeNow = 3): ?string
	{
		if(!ctype_alnum(str_replace(',', '', $identifier))) {
			return new BadRequestException();
		}

		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/metar', [
				'format' => 'xml',
				'taf' => 'false',
				'ids' => strtoupper((string)$identifier),
				'hours' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->METAR as $metar)
			{
				$sky = $metar->sky_condition;
				if(count($sky) == 0) continue;
				foreach($sky as $condition)
				{
					$unsetters = [];
					foreach($condition->attributes() as $key => $value)
					{
						$condition->addChild($key, $value);
						$unsetters[] = $key;
					}
					foreach($unsetters as $attribute)
					{
						unset($condition[$attribute]);
					}
				}
			}
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get a list of Metars based on a list of stations.
	 * @return null|string
	 */
	public function getList()
	{
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 3);
		$stationString = (string)($_GET['stations'] ?? '');
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/metar', [
				'format' => 'xml',
				'ids' => $stationString,
				'hours' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->METAR as $metar)
			{
				$sky = $metar->sky_condition;
				if(count($sky) == 0) continue;
				foreach($sky as $condition)
				{
					$unsetters = [];
					foreach($condition->attributes() as $key => $value)
					{
						$condition->addChild($key, $value);
						$unsetters[] = $key;
					}
					foreach($unsetters as $attribute)
					{
						unset($condition[$attribute]);
					}
				}
			}
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get a list of Metars based on a list of stations.
	 * @return null|string
	 */
	public function getFlight()
	{
		$corridorWidth = (float)($_GET['corridor'] ?? 60);
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 2);
		$flightPath = (string)($_GET['path'] ?? '');
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/metar', [
				'format' => 'xml',
				'flightPath' => $corridorWidth.';'.$flightPath,
				'hoursBeforeNow' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->METAR as $metar)
			{
				$sky = $metar->sky_condition;
				if(count($sky) == 0) continue;
				foreach($sky as $condition)
				{
					$unsetters = [];
					foreach($condition->attributes() as $key => $value)
					{
						$condition->addChild($key, $value);
						$unsetters[] = $key;
					}
					foreach($unsetters as $attribute)
					{
						unset($condition[$attribute]);
					}
				}
			}
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}


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