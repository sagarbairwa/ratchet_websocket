<?php

namespace App\Http\Websocket;

use App\Http\Websocket\WebSocketController;
use Ratchet\ConnectionInterface;
use App\Http\Websocket\Events\{UnAuthorize,UnknownEvent};
use Exception;

class EventListener
{

    /**
     * Generate raw event to send to a connected user
     *
     * @param WebSocketController $controller WebSocketController
     * @param ConnectionInterface $conn ConnectionInterface
     * @param string $msg message
     *
     * @return string
     */
    public static function raw(WebSocketController $controller, ConnectionInterface $conn, string $msg)
    {
        $decoded = json_decode($msg, true);

        if (empty($decoded['event'])) {
            $conn->send(new UnknownEvent());
            throw new Exception('Unknown event.');
        }

        $event = ucfirst($decoded['event']);
        $data = $decoded['data'];

        // if (!$conn->authorized && $event !== 'Authorize') {
        //     $conn->send(new UnAuthorize());
        //     return;
        // }
        if (!$conn->authorized && !in_array($event, ['Authorize', 'AidaAuthorize'])) {
            $conn->send(new UnAuthorize());
            return;
        }

        $className = "App\\Http\\Websocket\\Events\\{$event}";

        if (!class_exists($className)) {
            $conn->send(new UnknownEvent());
            return;
        }

        $instance = new $className($data);
        $instance->setController($controller);
        $instance->setConnection($conn);
        $instance->dispatch();
    }
}
