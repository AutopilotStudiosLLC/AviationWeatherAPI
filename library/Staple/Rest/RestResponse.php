<?php
/**
 * Class to wrap RESTful responses.
 *
 * @author Ironpilot
 * @copyright Copyright (c) 2011, STAPLE CODE
 *
 * This file is part of the STAPLE Framework.
 *
 * The STAPLE Framework is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your option)
 * any later version.
 *
 * The STAPLE Framework is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU Lesser General Public License for
 * more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with the STAPLE Framework.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Staple\Rest;


class RestResponse
{
	/**
	 * HTTP Status code of the response.
	 * @var int
	 */
	public $statusCode;
	/**
	 * The response to the request.
	 * @var mixed
	 */
	public $data;
	/**
	 * The raw response without any conversion.
	 * @var string
	 */
	public $rawData;
	/**
	 * @var array
	 */
	public $headers = array();
	public function __construct($status, $response, $headers)
	{
		$this->setStatusCode($status);
		$this->setData($response);
		$this->setHeaders($headers);
	}
	/**
	 * @return int
	 */
	public function getStatusCode()
	{
		return $this->statusCode;
	}
	/**
	 * @param int $statusCode
	 * @return $this
	 */
	protected function setStatusCode($statusCode)
	{
		$this->statusCode = (int)$statusCode;
		return $this;
	}
	/**
	 * @return mixed
	 */
	public function getData()
	{
		return $this->data;
	}
	/**
	 * @param mixed $data
	 * @return $this
	 */
	protected function setData($data)
	{
		$this->data = $data;
		return $this;
	}
	/**
	 * Get the raw data
	 * @return string
	 */
	public function getRawData()
	{
		return $this->rawData;
	}
	/**
	 * @return array
	 */
	public function getHeaders()
	{
		return $this->headers;
	}
	/**
	 * @param array $headers
	 * @return $this
	 */
	protected function setHeaders($headers)
	{
		if(!is_array($headers))
		{
			foreach($headers as $key=>$value)
			{
				$this->headers[$key] = $value;
			}
		} else {
			$this->headers = $headers;
		}
		return $this;
	}
}