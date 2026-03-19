<?php
namespace models\structs;
use stdClass;

class TafForecastStruct
{
	public int|null $timeFrom;
	public int|null $timeTo;
	public int|null $timeBec;
	public string|null $fcstChange;
	public string|null $probability;
	public int|null $wdir;
	public int|null $wspd;
	public int|null $wgst;
	public int|null $wshearHgt;
	public int|null $wshearDir;
	public int|null $wshearSpd;
	public string|int|null $visib;
	public int|null $altim;
	public int|null $vertVis;
	public string|null $wxString;
	public string|null $notDecoded;

	public array|null $clouds;

	static function importArray($fcstArray)
	{
		$array = [];
		foreach($fcstArray as $fcst)
		{
			$struct = new TafForecastStruct();
			foreach ($fcst as $key => $value)
			{
				if (property_exists($struct, $key))
				{
					$struct->$key = $value;
				}
			}
			$array[] = $struct;
		}
		return $array;
	}
}