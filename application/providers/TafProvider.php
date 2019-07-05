<?php

use Staple\Controller\RestfulController;
use Staple\Exception\RestException;
use Staple\Json;
use Staple\Request;
use Staple\Rest\Rest;

/**
 * Class TafProvider
 * Get information from TAF stations
 */

class TafProvider extends RestfulController
{
	public function _start()
	{
		$this->addAccessControlOrigin('*');
		$this->addAccessControlMethods([Request::METHOD_GET, Request::METHOD_OPTIONS]);
	}

	/**
	 * @return string|null
	 */
	public function getIndex()
	{
		$obj = new stdClass();
		$obj->message = 'TAF Resource';
		$obj->apis = [
			'recent' => '/taf/recent/[station]',
			'local' => '/taf/local?distance=50&latitude=39&longitude=-104',
			'list' => '/tat/list?stations=KDEN,KLAX',
			'flight' => '/tat/flight?corridor=60&path=KDEN;KLAX',
		];
		return Json::success($obj);
	}

	/**
	 * Get recent METAR data
	 * @param string $identifier
	 * @param int $hoursBeforeNow
	 * @return null|string
	 */
	public function getTaf($identifier = 'KSEA', $hoursBeforeNow = 4)
	{
		try
		{
			$mostRecent = ($_GET['mostRecent'] === 'true') ? 'true' : 'false';

			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT, [
				'dataSource' => 'tafs',
				'requestType' => 'retrieve',
				'format' => 'xml',
				'stationString' => strtoupper((string)$identifier),
				'hoursBeforeNow' => (int)$hoursBeforeNow,
				'mostRecent' => $mostRecent,
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->TAF as $taf)
			{
				foreach($taf->forecast as $forecast)
				{
					$sky = $forecast->sky_condition;
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
			}
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get a list of TAFs based on a list of stations.
	 * @return null|string
	 */
	public function getList()
	{
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 2);
		$stationString = (string)($_GET['stations'] ?? '');
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT, [
				'dataSource' => 'tafs',
				'requestType' => 'retrieve',
				'format' => 'xml',
				'stationString' => $stationString,
				'hoursBeforeNow' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->TAF as $taf)
			{
				foreach($taf->forecast as $forecast)
				{
					$sky = $forecast->sky_condition;
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
			}
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get local TAF data
	 * @return null|string
	 */
	public function getLocal()
	{
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 3);
		$distance = (int)$_GET['distance'] ?? null;
		$latitude = (float)$_GET['latitude'] ?? null;
		$longitude = (float)$_GET['longitude'] ?? null;
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT, [
				'dataSource' => 'tafs',
				'requestType' => 'retrieve',
				'format' => 'xml',
				'radialDistance' => $distance.';'.$longitude.','.$latitude,
				'hoursBeforeNow' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->TAF as $taf)
			{
				foreach($taf->forecast as $forecast)
				{
					$sky = $forecast->sky_condition;
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
			}
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get TAF data along a flight path
	 * @return null|string
	 */
	public function getFlight()
	{
		$corridorWidth = (float)($_GET['corridor'] ?? 60);
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 2);
		$flightPath = (string)($_GET['path'] ?? '');

		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT, [
				'dataSource' => 'tafs',
				'requestType' => 'retrieve',
				'format' => 'xml',
				'flightPath' => $corridorWidth.';'.$flightPath,
				'hoursBeforeNow' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->TAF as $taf)
			{
				foreach($taf->forecast as $forecast)
				{
					$sky = $forecast->sky_condition;
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
			}
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}
}