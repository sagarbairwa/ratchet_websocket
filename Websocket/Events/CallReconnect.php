<?php

namespace App\Http\Websocket\Events;

use App\Models\Call;
use App\Models\ExaminerLicenseState;
use App\Models\Gfe;
use App\Models\SmsGfeInvite;
use App\Models\User;

class CallReconnect extends Event
{
    public $event = "callReconnect" . PHP_EOL;

    protected function handle()
    {
        echo "CallReconnect" . PHP_EOL;
        $gfe = Gfe::where('id', $this->gfe_id)->with(
            'examinerDetail',
            'patientDetail',
            'locationDetail.location_doctors'
        )->first();

        if ((!$gfe)) {
            $e = new NotFound(['id' => $this->gfe_id]);
            $e->name = 'Received';
            $this->conn->send($e);
            return;
        }

        $t = new AgoraToken(['id' => $this->gfe_id, 'agora_token' => $gfe->agora_token]);
        $t->name = 'Received';
        $this->conn->send($t);

        $e = new self($gfe->toArray());
        $e->name = 'Received';

        $user = User::where('id', $this->conn->user->id)->first();

        if ($user && $user->role_id == 8) {
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

        $sent = $this->controller->sendToUser($toId, $e);

        if (!$sent) {
            $e = new UserOffline(['id' => $this->gfe_id]);
            $e->name = 'Received';
            $this->conn->send($e);
            return;
        }

        if ($gfe->channel_id == 2) {
            SmsGfeInvite::where('id', $this->sms_invite_id)->update([
                'token' => $this->token
            ]);
        }
    }
}
