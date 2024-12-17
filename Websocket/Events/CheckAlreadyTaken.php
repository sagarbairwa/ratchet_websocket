<?php

namespace App\Http\Websocket\Events;

use App\Models\Call;

class CheckAlreadyTaken extends Event
{
	public $event = "checkAlreadyTaken";

    protected function handle()
    {
        echo "CheckAlreadyTaken" . PHP_EOL;

        $call = Call::where('gfe_id', $this->gfe_id)->first();
        if ($call) {
        	if (is_null($call->examiner_user_id)) {
        		$e = new CallNotTaken(['id' => $this->gfe_id]);
        	} else {
        		$e = new CallAlreadyTaken(['id' => $this->gfe_id]);
        	}

        	$e->name = 'Received';
        	$this->conn->send($e);
        }
    }
}
