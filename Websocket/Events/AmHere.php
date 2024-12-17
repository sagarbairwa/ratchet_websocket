<?php

namespace App\Http\Websocket\Events;

use App\Models\Gfe;
use App\Models\Role;
use App\Models\User;

class AmHere extends Event
{

    public $event = "amHere";

    protected function handle()
    {
        echo "AmHere" . PHP_EOL;
        $gfe = Gfe::where('id', $this->gfe_id)->select('id', 'patient_user_id', 'assigned_to_user_id', 'examiner_user_id')->first();

        if (!$gfe) {
            $e = new NotFound(['id' => $this->gfe_id]);
            $e->name = 'Received';
            $this->conn->send($e);
            return;
        }

        $user = User::where('id', $this->conn->user->id)->select('id', 'role_id')->first();

        $e = new AmHere(['id' => $this->gfe_id]);
        $e->name = 'Received';

        if ($user && $user->role_id == Role::where('role', 'examiner')->value('id')) {
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
        } else {
            $toId = $gfe->examiner_user_id;
        }

        $this->controller->sendToUser($toId, $e);
    }
}
