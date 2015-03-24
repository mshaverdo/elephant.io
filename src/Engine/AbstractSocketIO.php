<?php
/**
 * This file is part of the Elephant.io package
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
 * @copyright Wisembly
 * @license   http://www.opensource.org/licenses/MIT-License MIT License
 */

namespace ElephantIO\Engine;

use DomainException;

use Psr\Log\LoggerInterface;

use ElephantIO\EngineInterface,
    ElephantIO\Payload\Decoder,
    ElephantIO\Exception\UnsupportedActionException;
use ElephantIO\Exception\ConnectionBrokenException;
use ElephantIO\Engine\SocketIO\Session;

abstract class AbstractSocketIO implements EngineInterface
{
	const CONNECT      = 0;
    const DISCONNECT   = 1;
    const EVENT        = 2;
    const ACK          = 3;
    const ERROR        = 4;
    const BINARY_EVENT = 5;
    const BINARY_ACK   = 6;

    /** @var string[] Parse url result */
    protected $url;

    /**
     * Session information
     *  
     * @var \ElephantIO\Engine\SocketIO\Session 
     */
    protected $session;

    /** @var mixed[] Array of options for the engine */
    protected $options;

    /** @var resource Resource to the connected stream */
    protected $stream;
    
    /**
     * Read timeout in microcesonds
     * 
     * @var int
     */
    protected $read_timeout = 0;

    public function __construct($url, array $options = [])
    {
        $this->url     = $this->parseUrl($url);
        $this->options = array_replace($this->getDefaultOptions(), $options);
    }

    /** {@inheritDoc} */
    public function connect()
    {
        throw new UnsupportedActionException($this, 'connect');
    }

    /** {@inheritDoc} */
    public function keepAlive()
    {
        throw new UnsupportedActionException($this, 'keepAlive');
    }

    /** {@inheritDoc} */
    public function close()
    {
        throw new UnsupportedActionException($this, 'close');
    }

    /** {@inheritDoc} */
    public function reset()
    {
        throw new UnsupportedActionException($this, 'close');
    }

    /**
     * Write the message to the socket
     *
     * @param integer $code    type of message (one of EngineInterface constants)
     * @param string  $message Message to send, correctly formatted
     */
    abstract public function write($code, $message = null);

    /** {@inheritDoc} */
    public function emit($event, $message)
    {
        throw new UnsupportedActionException($this, 'emit');
    }

    /**
     * {@inheritDoc}
     *
     * Be careful, this method may hang your script, as we're not in a non
     * blocking mode.
     */
    public function read() {
        if (!is_resource($this->stream)) {
            throw new ConnectionBrokenException("Trying to read data while connection broken");
        }
    	
        $waiting_expiration_time = empty($this->read_timeout)
        	? null
        	: microtime(true) + $this->read_timeout / 1000000;
        
        //set heartbeat check timeout
        $this->setStreamTimeoutRead();
        while (true) {
        	if (!empty($waiting_expiration_time) && microtime(true) > $waiting_expiration_time) {
        		//reading timeout reached, return empty string
        		$this->restoreStreamTimeoutDefault();
        		return null;
        	}
        	
        	//check, is heartbeat sending needed
       		$this->session->needsHeartbeat()
       			and $this->sendHeartbeat();
        		
        	//try to read message until check heartbeat timeout reached
        	$data = $this->readBlocking();

        	if (!isset($data)) {
	        	//no data received, continue from checking heartbeat
        		continue;
        	}
        	
        	if (EngineInterface::PONG == $this->getPacketType($data)) {
        		//skip pong packet
        		continue;
        	}
        	
        	//it seems we received some valuable data, break waiting loop and return it
        	$this->restoreStreamTimeoutDefault();
        	return $data;
        }
    }

    /**
     * Read frame in blocking mode and return null if timeout reached
     *
     */
    protected function readBlocking()
    {
    	if (!is_resource($this->stream)) {
            throw new ConnectionBrokenException("Trying to read data while connection broken");
        }
    	
        $data = fread($this->stream, 2);
        
        //data not read
        if (strlen($data) == 0) {
        	if (stream_get_meta_data($this->stream)['timed_out']) {
        		//just timed out, don't worry
        		return null;
        	} else {
		        //if connection broken
	        	//reset stream if connection broken
	        	$this->reset();
				throw new ConnectionBrokenException("Connection to server is broken");
        	}
		}
        
        /*
         * The first byte contains the FIN bit, the reserved bits, and the
         * opcode... We're not interested in them. Yet.
         * the second byte contains the mask bit and the payload's length
         */
        $bytes = unpack('C*', $data);
        $mask	= ($bytes[2] & 0b10000000) >> 7;
        $length	= $bytes[2] & 0b01111111;
        
        /*
         * Here is where it is getting tricky :
         *
         * - If the length <= 125, then we do not need to do anything ;
         * - if the length is 126, it means that it is coded over the next 2 bytes ;
         * - if the length is 127, it means that it is coded over the next 8 bytes.
         *
         * But,here's the trick : we cannot interpret a length over 127 if the
         * system does not support 64bits integers (such as Windows, or 32bits
         * processors architectures).
         */
    	switch ($length) {
            case 0x7D: // 125
            break;

            case 0x7E: // 126
                $data .= $extended_length_bin = fread($this->stream, 2);
                
            	$bytes = unpack('n', $extended_length_bin);
                if (empty($bytes[1])) {
                	throw new \RuntimeException('Invalid extended packet len');
                }

                $length = $bytes[1];
                
            break;

            case 0x7F: // 127
                // are (at least) 64 bits not supported by the architecture ?
                if (8 > PHP_INT_SIZE) {
                    throw new \DomainException('64 bits unsigned integer are not supported on this architecture');
                }

                /*
                 * As (un)pack does not support unpacking 64bits unsigned
                 * integer, we need to split the data
                 *
                 * {@link http://stackoverflow.com/questions/14405751/pack-and-unpack-64-bit-integer}
                 */
                $data .= $extended_length_bin = fread($this->stream, 8);
                list($left, $right) = array_values(unpack('N2', $extended_length_bin));
                $length = $left << 32 | $right;
            break;
        }

        // incorporate the mask key if the mask bit is 1
        if (true === $mask) {
            $data .= fread($this->stream, 4);
        }

        // Split the packet in case of the length > 16kb
        while ($length > 0 && $buffer = fread($this->stream, $length)) {
            $data   .= $buffer;
            $length -= strlen($buffer);
        }

        // decode the payload
        return (string) new Decoder($data);
    }

    /** {@inheritDoc} */
    public function getName()
    {
        return 'SocketIO';
    }

    /**
     * Parse an url into parts we may expect
     *
     * @return string[] information on the given URL
     */
    protected function parseUrl($url)
    {
        $parsed = parse_url($url);

        if (false === $parsed) {
            throw new MalformedUrlException($url);
        }

        $server = array_replace(['scheme' => 'http',
                                 'host'   => 'localhost',
                                 'query'  => [],
                                 'path'   => 'socket.io'], $parsed);

        if (!isset($server['port'])) {
            $server['port'] = 'https' === $server['scheme'] ? 443 : 80;
        }

        if (!is_array($server['query'])) {
            parse_str($server['query'], $query);
            $server['query'] = $query;
        }

        $server['secured'] = 'https' === $server['scheme'];

        return $server;
    }

    /**
     * Get the defaults options
     *
     * @return array mixed[] Defaults options for this engine
     */
    protected function getDefaultOptions()
    {
        return ['context' => [],
                'debug'   => false,
                'wait'    => 100*1000,
                'timeout' => ini_get("default_socket_timeout"),];
    }
    
    protected function sendHeartbeat() {
    	$this->write(EngineInterface::PING);
    }
    
    protected function setStreamTimeoutRead() {
    	$interval = $this->read_timeout > 0
    		? min($this->getHeartbeatCheckInterval(), $this->read_timeout)
    		: $this->getHeartbeatCheckInterval();
    	
    	$interval; //get interval in microsec
    	
    	stream_set_timeout($this->stream, floor($interval / 1000000), $interval % 1000000);
    }
    
    protected function restoreStreamTimeoutDefault() {
    	stream_set_timeout($this->stream, $this->options['timeout']);
    }
    
    /**
     * Return heartbeat check interval in microseconds. Fractional number supported
     * 
     * @return float
     */
    protected function getHeartbeatCheckInterval() {
    	return 1000000;
    }
    
    /**
     * Return packet data from raw frame payload
     * 
     * @param string $raw_packet_data
     */
    protected function getPacketType($raw_frame_payload) {
    	return $raw_frame_payload[0];
    }
    
    public function setReadTimeout($milliseconds) {
    	$this->read_timeout = $milliseconds * 1000;
    }
}

