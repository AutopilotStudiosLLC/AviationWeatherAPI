<?php

use Staple\Controller\RestfulController;
use Staple\Json;

class IndexProvider extends RestfulController
{
	public function getIndex()
	{
		$obj = new stdClass();
		$obj->message = 'Welcome to the Aviation Weather API';
		$obj->apis = [
			'metars' => '/metar',
			'stations' => '/station',
			'tafs' => '/taf',
			'pirep' => '/pirep',
			'airsigmet' => '/airsigmet'
		];
		return Json::success($obj);
	}
}