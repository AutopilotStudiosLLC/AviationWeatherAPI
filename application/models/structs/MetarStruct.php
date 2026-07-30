<?php
namespace models\structs;

require_once('MetarCloudStruct.php');
use \models\structs\MetarCloudStruct;
use stdClass;

class MetarStruct
{
	public string $icaoId;
	public ?string $receiptTime;
	public int $obsTime;
	public ?string $reportTime;
	public ?float $temp;
	public ?float $dewp;
	public int | string | null $wdir;
	public ?int $wspd;
	public ?int $wgst;
    public ?string $visib;
    public ?float $altim;
    public ?float $slp;
    public ?int $qcField;
    public ?string $metarType;
    public string $rawOb;
    public ?float $lat;
    public ?float $lon;
    public ?int $elev;
    public ?string $name;
    public ?string $cover;
    public array $clouds;
    public ?string $fltCat;

	static function import(stdClass $metar): static {
		$struct = new MetarStruct();
		$struct->icaoId = $metar->icaoId;
		$struct->receiptTime = $metar->receiptTime ?? null;
		$struct->obsTime = $metar->obsTime ?? null;
		$struct->reportTime = $metar->reportTime ?? null;
		$struct->temp = $metar->temp ?? null;
		$struct->dewp = $metar->dewp ?? null;
		$struct->wdir = $metar->wdir ?? null;
		$struct->wspd = $metar->wspd ?? null;
		$struct->wgst = $metar->wgst ?? null;
		$struct->visib = $metar->visib ?? null;
		$struct->altim = $metar->altim ?? null;
		$struct->slp = $metar->slp ?? null;
		$struct->qcField = $metar->qcField ?? null;
		$struct->metarType = $metar->metarType ?? null;
		$struct->rawOb = $metar->rawOb ?? null;
		$struct->lat = $metar->lat ?? null;
		$struct->lon = $metar->lon ?? null;
		$struct->elev = $metar->elev ?? null;
		$struct->name = $metar->name ?? null;
		$struct->cover = $metar->cover ?? null;
		$struct->clouds = isset($metar->clouds) ? MetarCloudStruct::importArray($metar->clouds) : [];
		$struct->fltCat = $metar->fltCat ?? null;
		return $struct;
	}
}