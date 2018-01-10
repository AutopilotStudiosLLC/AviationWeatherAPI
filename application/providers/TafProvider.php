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
	 * Get recent METAR data
	 * @param string $identifier
	 * @param int $hoursBeforeNow
	 * @return null|string
	 */
	public function getTaf($identifier = 'KSEA', $hoursBeforeNow = 4)
	{
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT, [
				'dataSource' => 'tafs',
				'requestType' => 'retrieve',
				'format' => 'xml',
				'stationString' => strtoupper((string)$identifier),
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
					if(count($sky) == 0) continue;
					$unsetters = [];
					foreach($sky->attributes() as $key => $value)
					{
						$sky->addChild($key, $value);
						$unsetters[] = $key;
					}
					foreach($unsetters as $attribute)
					{
						unset($sky[$attribute]);
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