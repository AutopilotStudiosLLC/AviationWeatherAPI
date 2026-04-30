<?php
require_once('structs/StationStruct.php');

use models\structs\StationStruct;
use Staple\Exception\ConfigurationException;
use Staple\Exception\ModelNotFoundException;
use Staple\Exception\QueryException;
use Staple\Model;
use Staple\Query\Query;

/**
 * Taf Model
 */
class StationModel extends Model
{
	const string STATION_CACHING_INTERVAL = '1 MONTH';

    const array NAME_REPLACEMENTS = [
        'airfield' => [' arfld'],
        'airport' => [' arpt'],
        'airstrip' => [' astrp'],
        'county' => [' cnty'],
        'field' => [' fld'],
        'industrial' => [' ind'],
        'international' => [' intl'],
        'municipal' => [' muni'],
        'regional' => [' rgnl'],
    ];

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
                Query::insert('stations', [...$stationModel->_data, 'retrieved_at' => date('Y-m-d H:i:s')])
                    ->setUpdateOnDuplicate(true)
                    ->setUpdateColumns(['iata_id', 'faa_id', 'wmo_id', 'site_name', 'latitude', 'longitude', 'elevation', 'state', 'country', 'priority', 'types', 'retrieved_at'])
                    ->execute();
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
        $this->site_name = StationModel::normalizeSiteName($station->siteName);
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
        if (is_array($station->types)) {
            $json->site_type = $station->types;
        } else {
            $json->site_type = strlen($station->types) > 0 ? explode(',', $station->types) : [];
        }
        $json->source = 'cached';
        return $json;
    }

    /**
     * @param mixed $siteName
     * @return array|mixed|string|string[]
     */
    public static function normalizeSiteName(mixed $siteName): mixed
    {
        foreach (static::NAME_REPLACEMENTS as $replacement => $terms) {
            $siteName = str_ireplace($terms, ' '.ucfirst(strtolower($replacement)), $siteName);
        }
        return $siteName;
    }

    /**
     * Get TAF data within a bounding box from the database
     * @param array $boundingBox
     * @return TafModel[]
     * @throws ConfigurationException
     * @throws QueryException
     */
    public static function getLocalStations(array $boundingBox): array
    {
        try
        {
            $results = StationModel::select()
                ->whereBetween('latitude', $boundingBox['minLat'], $boundingBox['maxLat'])
                ->whereBetween('longitude', $boundingBox['minLon'], $boundingBox['maxLon'])
                ->get()
                ->toArray();

            $stations = [];
            foreach ($results as $result) {
                $station = new StationModel();
                $station->icao_id = $result->icao_id;
                $station->iata_id = $result->iata_id;
                $station->faa_id = $result->faa_id;
                $station->wmo_id = $result->wmo_id;
                $station->site_name = $result->site_name;
                $station->latitude = $result->latitude;
                $station->longitude = $result->longitude;
                $station->elevation = $result->elevation;
                $station->state = $result->state;
                $station->country = $result->country;
                $station->priority = $result->priority;
                $station->types = strlen($result->types) > 0 ? explode(',', $result->types) : [];
                $station->retrieved_at = $result->retrieved_at;
                $stations[] = $station;
            }
            return $stations;
        }
        catch (ModelNotFoundException)
        {
            // Return empty array
            return [];
        }
    }
}