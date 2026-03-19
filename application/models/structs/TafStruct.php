<?php
namespace models\structs;

require_once('TafForecastStruct.php');
use \models\structs\TafForecastStruct;
use stdClass;

class TafStruct
{
	public string $icaoId;
	public string $dbPopTime;
	public string $bulletinTime;
	public string $issueTime;
	public int $validTimeFrom;
	public int $validTimeTo;
	public string $rawTAF;
	public bool $mostRecent;
	public string $remarks;
	public float $lat;
	public float $lon;
	public int $elev;
	public string $name;
	public array $fcsts;

	static function import(stdClass $taf): static {
		$struct = new TafStruct();
		$struct->icaoId = $taf->icaoId;
		$struct->dbPopTime = $taf->dbPopTime;
		$struct->bulletinTime = $taf->bulletinTime;
		$struct->issueTime = $taf->issueTime;
		$struct->validTimeFrom = $taf->validTimeFrom;
		$struct->validTimeTo = $taf->validTimeTo;
		$struct->rawTAF = $taf->rawTAF;
		$struct->mostRecent = (bool)$taf->mostRecent;
		$struct->remarks = $taf->remarks;
		$struct->lat = $taf->lat;
		$struct->lon = $taf->lon;
		$struct->elev = $taf->elev;
		$struct->name = $taf->name;
		$struct->fcsts = TafForecastStruct::importArray($taf->fcsts);
		return $struct;
	}
}