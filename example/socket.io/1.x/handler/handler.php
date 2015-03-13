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

use ElephantIO\Client,
	ElephantIO\EventListener,
    ElephantIO\Engine\SocketIO\Version1X;
use ElephantIO\EventHandler;

require __DIR__ . '/../../../../vendor/autoload.php';

$client = new \ElephantIO\Client(new \ElephantIO\Engine\SocketIO\Version1X('http://127.0.0.1:8080'));

$client->initialize();
$client->emit('message', ['foo' => time()]);

$handler = new EventHandler($client);

$handler->addListener('message', function ($message, $handler) { echo "Received message: {$message->getData()}\n"; });

$handler->start();
