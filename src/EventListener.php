<?php
namespace ElephantIO;

use ElephantIO\Payload\Message;
use ElephantIO\Exception\ConnectionBrokenException;
class EventListener {
	/**
	 * 
	 * @var Client
	 */
	protected $client;
	
	/**
	 * 
	 * @var multitype:callable
	 */
	protected $handlers;
	
	/**
	 * 
	 * @var callable
	 */
	protected $default_handler;
	
	protected $is_running;
	
	public function __construct(Client $client) {
		$this->client = $client;
		$this->handlers = array();
		$this->default_handler = function(Message $message, EventListener $event_listener) {
			return $this->defaultHandler($message, $event_listener);
		};
	}
	
	public function start() {
		$this->is_running = true;
		while($this->is_running) {
			$raw_payload = $this->client->read();
			
			if (empty($raw_payload)) {
				continue;
			}
			
			$message = Message::createFromRawPayload($raw_payload);

			if (!empty($this->handlers[$message->getType()])) {
				call_user_func($this->handlers[$message->getType()], $message, $this);
			} else {
				call_user_func($this->default_handler, $message, $this);
			}
		}
	}
	
	protected function defaultHandler(Message $message, EventListener $event_listener) {
		var_dump($message);
	}
	
	public function addHandler($message_type, callable $listener) {
		$this->handlers[$message_type] = $listener;
	}
	
	public function removeHandler($message_type) {
		unset($this->handlers[$message_type]);
	}
	
	public function stop() {
		$this->is_running = false;
	}
	
	public function getClient() {
		return $this->client;
	}
}

?>