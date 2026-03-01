<?php

class AddsModel
{
	const DATETIME_FORMAT = 'Y-m-d\TH:i:s.v\Z';
	const HTTP_SOURCE_ROOT = 'https://aviationweather.gov/api/data';

	public function metar()
	{

	}

	/**
	 * Calculates a bounding box in degrees of latitude and longitude based on a distance in miles
	 * from a given geographic point.
	 *
	 * @param float $distanceMiles Distance in miles from the central point to each edge of the bounding box.
	 * @param float $latitude Latitude of the center point in decimal degrees.
	 * @param float $longitude Longitude of the center point in decimal degrees.
	 *
	 * @return array An associative array with the keys 'minLat', 'maxLat', 'minLon', and 'maxLon'
	 *               representing the bounding box coordinates.
	 */
	public static function boundingBoxMiles(float $distanceMiles, float $latitude, float $longitude): array
	{
		// Convert miles to km
		$distanceKm = $distanceMiles * 1.609344;

		// Half side length (from center to edge)
		$half = $distanceKm / 2.0;

		// 1° latitude ~ 110.574 km
		// 1° longitude ~ 111.320 * cos(latitude) km
		$kmPerDegLat = 110.574;
		$kmPerDegLon = 111.320 * cos(deg2rad($latitude));

		$deltaLat = $half / $kmPerDegLat;

		// Handle poles: if cos(lat) ~ 0, longitude spans entire range
		if (abs($kmPerDegLon) < 1e-9)
		{
			$minLon = -180.0;
			$maxLon = 180.0;
		} else
		{
			$deltaLon = $half / $kmPerDegLon;
			$minLon = $longitude - $deltaLon;
			$maxLon = $longitude + $deltaLon;
		}



		// Clamp latitude to valid range
		$minLat = floor(max(-90.0, $latitude - $deltaLat)*100)/100;
		$maxLat = floor(min(90.0, $latitude + $deltaLat)*100)/100;

		// Normalize longitudes to [-180, 180]
		$minLon = floor(static::normalizeLon($minLon)*100)/100;
		$maxLon = floor(static::normalizeLon($maxLon)*100)/100;

		return [
			'minLat' => $minLat,
			'maxLat' => $maxLat,
			'minLon' => $minLon,
			'maxLon' => $maxLon,
		];
	}

	/**
	 * Normalize a longitude value to ensure it falls within the range of [-180, 180] degrees.
	 *
	 * @param float $lon The longitude value to normalize.
	 * @return float The normalized longitude.
	 */
	public static function normalizeLon(float $lon): float
	{
		// Wrap longitude into [-180, 180]
		$lon = fmod($lon + 180.0, 360.0);
		if ($lon < 0)
		{
			$lon += 360.0;
		}
		return $lon - 180.0;
	}
}