<?php

namespace App\Http\Websocket\Events;

use App\Models\Gfe;

class CallAcknowledgement extends Event
{

    public $event = "CallAcknowledgement" . PHP_EOL;

    protected function handle()
    {
        echo "CallAcknowledgement" . PHP_EOL;
        $gfe = Gfe::where('id', $this->gfe_id)->with('patientDetail')->first();

        if (!$gfe) {
            $e = new NotFound($this->getAttributes());
            $e->name = 'Received';
            $this->conn->send($e);
            return;
        }

        $e = new self($this->getAttributes());
        $e->name = 'Received';

        switch ($gfe->channel_id) {
            case 1:
                $toId = $gfe->assigned_to_user_id;
                break;
            case 2:
            case 3:
            case 5:
                $toId = $gfe->patient_user_id;
                break;
        }
        $this->controller->sendToUser($toId, $e);
    }
}
