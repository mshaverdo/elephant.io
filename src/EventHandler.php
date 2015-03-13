<?php
namespace ElephantIO;

use ElephantIO\Payload\Message;
use ElephantIO\Exception\ConnectionBrokenException;
class EventHandler {
	protected $message_checking_interval;
	
	/**
	 * 
	 * @var Client
	 */
	protected $client;
	
	/**
	 * 
	 * @var multitype:callable
	 */
	protected $listeners;
	
	/**
	 * 
	 * @var callable
	 */
	protected $default_listener;
	
	protected $is_running;
	
	public function __construct(Client $client) {
		$this->client = $client;
		$this->listeners = array();
		$this->message_checking_interval = 0.1;
		$this->default_listener = function(Message $message, EventHandler $event_listener) {
			return $this->defaultListener($message, $event_listener);
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

			if (!empty($this->listeners[$message->getType()])) {
				call_user_func($this->listeners[$message->getType()], $message, $this);
			} else {
				call_user_func($this->default_listener, $message, $this);
			}
		}
	}
	
	protected function defaultListener(Message $message, EventHandler $event_listener) {
		var_dump($message);
	}
	
	public function addListener($message_type, callable $listener) {
		$this->listeners[$message_type] = $listener;
	}
	
	public function removeListener($message_type) {
		unset($this->listeners[$message_type]);
	}
	
	public function stop() {
		$this->is_running = false;
	}
	
	public function getClient() {
		return $this->client;
	}
}

?>