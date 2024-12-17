<?php

namespace App\Http\Websocket\Events;

class Closed extends Event
{
	/**
     * Event text to be sent
     *
     * @var string $event
     */
    public $event = "closed";
}