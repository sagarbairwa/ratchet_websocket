<?php

namespace App\Http\Websocket\Events;

use DB;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\ValidationData;
use App\User;

class Connected extends Event
{
	/**
     * Event text to be sent
     *
     * @var string $event
     */
    public $event = "connected";
}
