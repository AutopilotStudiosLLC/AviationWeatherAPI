<?php

use Staple\Controller\RestfulController;
use Staple\Exception\RestException;
use Staple\Json;
use Staple\Request;
use Staple\Rest\Rest;

/**
 * Class MetarProvider
 * Get data from METAR stations
 */

class AirsigmetProvider extends RestfulController
{
	public function _start()
	{
		$this->addAccessControlOrigin('*');
		$this->addAccessControlMethods([Request::METHOD_GET, Request::METHOD_OPTIONS]);
	}

	public function getIndex()
	{
		$obj = new stdClass();
		$obj->message = 'AIRMET/SIGMET Resource';
		$obj->apis = [
			'flight' => '/airsigmet/flight?corridor=60&path=KDEN;KLAX',
		];
		return Json::success($obj);
	}

	/**
	 * Get a list of Metars based on a list of stations.
	 * @return null|string
	 */
	public function getFlight()
	{
		$corridorWidth = (float)($_GET['corridor'] ?? 60);
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 3);
		$flightPath = (string)($_GET['path'] ?? '');
		$mostRecent = (string)($_GET['mostRecent'] ?? 'true');
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT, [
				'dataSource' => 'airsigmets',
				'requestType' => 'retrieve',
				'format' => 'xml',
				'mostRecent' => $mostRecent,
				'flightPath' => $corridorWidth.';'.$flightPath,
				'hoursBeforeNow' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->AIRSIGMET as $airsigmet)
			{
				//Altitude
				$alt = $airsigmet->altitude;
				if(count($alt) == 0) continue;
				foreach($alt as $condition)
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

				//Hazard
				$hazard = $airsigmet->hazard;
				if(count($hazard) == 0) continue;
				foreach($hazard as $condition)
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

				//Hazard
				$hazard = $airsigmet->hazard;
				if(count($hazard) == 0) continue;
				foreach($hazard as $condition)
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

				//Area
				$area = $airsigmet->area;
				if(count($area) == 0) continue;
				foreach($area as $condition)
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
}