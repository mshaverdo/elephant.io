<?php

namespace ElephantIO\Payload;

use ElephantIO\Exception\InvalidPayloadException;
class Message {
	protected $type;
	protected $data;
	
	/**
	 * @return the $type
	 */
	public function getType() {
		return $this->type;
	}

	/**
	 * @return the $data
	 */
	public function getData() {
		return $this->data;
	}

	public function __construct($type = null, $data = null) {
		$this->type = $type;
		$this->data = $data;
	}
	
	public static function createFromRawPayload($raw_payload) {
		//trim first two bytes from payload
		$parsed_payload = json_decode(substr($raw_payload, 2), true);
		
		if (empty($parsed_payload[0]) || !isset($parsed_payload[1]) || count($parsed_payload) != 2) {
			throw new InvalidPayloadException("Payload is invalid: {$raw_payload}");
		}
		
		return new static($parsed_payload[0], $parsed_payload[1]);
	}
}

?>