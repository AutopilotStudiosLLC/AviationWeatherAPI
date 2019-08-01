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

	/**
	 * @return string|null
	 * @throws Exception
	 */
	public function getIndex()
	{
		$startTime = new DateTime('now', new DateTimeZone('UTC'));
		$endTime = clone $startTime;
		$endTime->add(new DateInterval('PT1H'));

		$obj = new stdClass();
		$obj->message = 'AIRMET/SIGMET Resource';
		$obj->apis = [
			'flight' => '/airsigmet/flight?corridor=60&path=KDEN;KLAX&startTime='.urlencode($startTime->format(DateTime::ISO8601)).'&endTime='.urlencode($endTime->format(DateTime::ISO8601)),
		];
		return Json::success($obj);
	}

	/**
	 * Get a list of Metars based on a list of stations.
	 * @return null|string
	 * @throws Exception
	 */
	public function getFlight()
	{
		$corridorWidth = (float)($_GET['corridor'] ?? 60);
		$flightPath = (string)($_GET['path'] ?? '');

		$requestParams = [
			'dataSource' => 'airsigmets',
			'requestType' => 'retrieve',
			'format' => 'xml',
			'flightPath' => $corridorWidth.';'.$flightPath,
		];

		if(isset($_GET['hoursBeforeNow']))
		{
			$requestParams['hoursBeforeNow'] = (int)$_GET['hoursBeforeNow'];
		}
		else
		{
			$requestParams['startTime'] = (string)($_GET['startTime'] ??
				(new DateTime('now', new DateTimeZone('UTC')))
					->format(DateTime::ISO8601)
			);
			$requestParams['endTime'] = (string)($_GET['endTime'] ??
				(new DateTime('now', new DateTimeZone('UTC')))
					->add(new DateInterval('PT1H'))
					->format(DateTime::ISO8601));
		}

		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT, $requestParams);

			//Check for an error response.
			if($response->errors->children()->count() > 0) {
				return Json::error($response->errors);
			}

			//Process the results.
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->AIRSIGMET as $airsigmet)
			{
				//Altitude
				$alt = $airsigmet->altitude;
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