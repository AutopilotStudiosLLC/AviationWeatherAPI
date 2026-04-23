<?php
require_once('structs/StationStruct.php');

use models\structs\StationStruct;
use Staple\Exception\QueryException;
use Staple\Model;

/**
 * Taf Model
 */
class StationModel extends Model
{
	const STATION_CACHING_INTERVAL = '1 MONTH';

    function __jsonSerialize(): stdClass
	{
        return StationModel::toResultFormat($this);
    }

	/**
	 * @throws Exception
	 */
	function __construct(StationStruct|null $station = null)
	{
		parent::__construct();
		if(isset($station))
		{
			$this->import($station);
		}
	}

	function getRawText(): string
	{
		return $this->raw_text;
	}

	/**
	 * @throws QueryException
	 * @throws Exception
	 */
	static function cache(array $stations): void
	{
		foreach($stations as $station)
		{
			$stationModel = new static(StationStruct::import($station));
			try
			{
				$stationModel->save();
			}
			catch(QueryException|Exception $e)
			{
				throw new Exception($e->getMessage());
			}
		}
	}

	/**
	 * @throws Exception
	 */
	function import(StationStruct $station): void
	{
		$this->icao_id = $station->icaoId;
		$this->iata_id = $station->iataId;
		$this->faa_id = $station->faaId;
		$this->wmo_id = $station->wmoId;
		$this->site_name = $station->siteName;
		$this->latitude = $station->latitude;
		$this->longitude = $station->longitude;
		$this->elevation = $station->elevation;
		$this->state = $station->state;
		$this->country = $station->country;
		$this->priority = $station->priority;
		$this->types = implode(',', $station->siteTypes);
	}

    public static function toResultFormat(StationModel $station): stdClass {
        $json = new stdClass();
		$json->station_id = $station->icao_id;
      	$json->icao_id = $station->icao_id;
      	$json->iata_id = $station->iata_id;
		$json->wmo_id = $station->wmo_id;
		$json->faa_id = $station->faa_id;
		$json->latitude = $station->latitude;
		$json->longitude = $station->longitude;
      	$json->elevation_m = $station->elevation;
      	$json->site = $station->site_name;
      	$json->state = $station->state;
      	$json->country = $station->country;
	    $json->site_type = explode(',', $station->types);
        $json->source = 'cached';
        return $json;
    }
}