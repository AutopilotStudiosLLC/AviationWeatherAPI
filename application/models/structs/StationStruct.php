<?php
namespace models\structs;

use stdClass;

class StationStruct
{
	public string $icaoId;
	public string|null $iataId;
	public string|null $faaId;
	public string|null $wmoId;
	public string|null $siteName;
	public float $latitude;
	public float $longitude;
	public int|null $elevation;
	public string|null $state;
	public string|null $country;
	public int|null $priority;
	public array|null $siteTypes;

	static function import(stdClass $station): static {
		$struct = new StationStruct();
		$struct->icaoId = $station->icaoId;
		$struct->iataId = $station->iataId;
		$struct->faaId = $station->faaId;
		$struct->wmoId = $station->wmoId;
		$struct->siteName = $station->site;
		$struct->latitude = (float)$station->lat;
		$struct->longitude = (float)$station->lon;
		$struct->elevation = (int)$station->elev;
		$struct->state = $station->state;
		$struct->country = $station->country;
		$struct->priority = $station->priority;
		$struct->siteTypes = $station->siteType;
		return $struct;
	}
}