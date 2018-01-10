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
			'tafs' => '/taf'
		];
		return Json::success($obj);
	}
}