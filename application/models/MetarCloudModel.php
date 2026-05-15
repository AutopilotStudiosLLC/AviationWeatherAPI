<?php
require_once('structs/MetarCloudStruct.php');

use models\structs\MetarCloudStruct;
use Staple\Exception\QueryException;
use Staple\Model;

class MetarCloudModel extends Model
{
    function __jsonSerialize(): stdClass
    {
        return MetarCloudModel::toResultFormat($this);
    }

    function __construct(MetarCloudStruct|null $metar = null)
    {
        parent::__construct();
        if(isset($metar))
        {
            $this->import($metar);
        }
    }

	function getRawText()
	{
		return $this->raw_text;
	}

    /**
     * @throws QueryException
     * @throws Exception
     */
    static function cache(array $clouds): void
    {
        $cloudStructs = MetarCloudStruct::importArray($clouds);
        foreach($cloudStructs as $cloudStruct)
        {
            $metarCloudModel = new static($cloudStruct);
            try
            {
                $metarCloudModel->save();
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
    static function importArray(array $clouds): array
    {
        $results = [];
        foreach($clouds as $cloud) {
            $entry = new static();
            $entry->cloud_base = $cloud->base;
            $entry->cloud_cover = $cloud->cover;
            $entry->cloud_type = $cloud->type;
            $results[] = $entry;
        }
        return $results;
    }

    function import(MetarCloudStruct $cloud): void
    {
        $this->cloud_base = $cloud->base;
        $this->cloud_cover = $cloud->cover;
        $this->cloud_type = $cloud->type;
    }

    public static function toResultFormat(MetarCloudModel $cloud): stdClass {
        $json = new stdClass();
        if(isset($cloud->cloud_cover) && strlen($cloud->cloud_cover) > 0)
            $json->sky_cover = $cloud->cloud_cover;
        if(isset($cloud->cloud_base) && strlen($cloud->cloud_base) > 0)
            $json->cloud_base_ft_ag = $cloud->cloud_base;
        if(isset($cloud->cloud_type) && strlen($cloud->cloud_type) > 0)
            $json->cloud_type = $cloud->cloud_type;
        return $json;
    }

    public static function toResultFormatArray(array $clouds): array {
        $results = [];
        foreach($clouds as $cloud) {
            $json = new stdClass();
            if(isset($cloud->cloud_cover) && strlen($cloud->cloud_cover) > 0)
                $json->sky_cover = $cloud->cloud_cover;
            if(isset($cloud->cloud_base) && strlen($cloud->cloud_base) > 0)
                $json->cloud_base_ft_ag = $cloud->cloud_base;
            if(isset($cloud->cloud_type) && strlen($cloud->cloud_type) > 0)
                $json->cloud_type = $cloud->cloud_type;
            $results[] = $json;
        }
        return $results;
    }
}