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

class PirepProvider extends RestfulController
{
	public function _start()
	{
		$this->addAccessControlOrigin('*');
		$this->addAccessControlMethods([Request::METHOD_GET, Request::METHOD_OPTIONS]);
	}

	public function getIndex()
	{
		$obj = new stdClass();
		$obj->message = 'PIREP Resource';
		$obj->apis = [
			'flight' => '/pirep/flight?corridor=60&path=KDEN;KLAX',
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
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 2);
		$flightPath = (string)($_GET['path'] ?? '');
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT, [
				'dataSource' => 'aircraftreports',
				'requestType' => 'retrieve',
				'format' => 'xml',
				'flightPath' => $corridorWidth.';'.$flightPath,
				'hoursBeforeNow' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->AircraftReport as $pirep)
			{
				//Turbulence
				$turb = $pirep->turbulence_condition;
				foreach($turb as $condition)
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

				//Icing
				$ice = $pirep->icing_condition;
				foreach($ice as $condition)
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

				//Sky Conditions
				$sky = $pirep->sky_condition;
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
}