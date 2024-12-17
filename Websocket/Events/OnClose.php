<?php

namespace App\Http\Websocket\Events;

use App\Models\Call;
use App\Models\CallAction;

class OnClose extends Event
{

    public $event = "onClose" . PHP_EOL;

    protected function handle()
    {
        echo "OnClose" . PHP_EOL;
        echo $this->conn->user->id;
        echo '-----' . $this->conn->user->role_id;
        return;

        echo 9 . '---';
        foreach ($callActions as $key => $value) {
            echo 10 . '---';
            if (empty(CallAction::where('call_id', $value->call_id)->count())) {
                echo 11 . '---';
                $call = Call::select('channel_id', 'assigned_to_user_id', 'patient_user_id')->where('id', $value->call_id)->first();
                echo 12 . '---';
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
                echo 13 . '---';
                $e = new UserOffline();
                $e->name = 'Received';
                $this->controller->sendToUser($toId, $e);
            }
        }
    }
}
