<?php

namespace App\Http\Websocket\Events;

class Ping extends Event
{
	/**
     * Event text to be sent
     *
     * @var string $event
     */
    public $event = "ping";

    protected function handle()
    {
        echo "ping" . PHP_EOL;

        $e = new Pong();
	    $e->name = 'Received';
	    $this->conn->send($e);
    }
}
