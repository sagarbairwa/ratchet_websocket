<?php

namespace App\Http\Websocket\Events;

use App\Models\Call;
use App\Models\ExaminerLicenseState;
use App\Models\Gfe;

class CallAccept extends Event
{

    public $event = "callAccept";

    protected function handle()
    {
        echo "CallAccept" . PHP_EOL;
        $gfe = Gfe::where('id', $this->gfe_id)->first();
        if ($gfe) {
            $gfe->update(['examiner_user_id' => $this->conn->user->id]);
        }

        if (!$gfe) {
            $e = new NotFound(['id' => $this->gfe_id]);
            $e->name = 'Received';
            $this->conn->send($e);
            return;
        }

        $gfe = Gfe::where('id', $this->gfe_id)->with('examinerDetail')->first();
        $e = new self($gfe->toArray());
        $e->name = 'Received';

        $call = Call::where('gfe_id', $this->gfe_id)->first();
        if (!$call) {
            $e = new NotFound(['id' => $this->gfe_id]);
            $e->name = 'Received';
            $this->conn->send($e);
            return;
        }

        if (!$this->again) {
            // if ($call && !empty($call->examiner_user_id) && $call->examiner_user_id != $this->conn->user->id) {
            if ($call && !empty($call->examiner_user_id)) {
                $ecat = new CallAlreadyTaken(['id' => $this->gfe_id]);
                $ecat->name = 'Received';

                $this->conn->send($ecat);
                return;
            }
        }

        $call->update(['examiner_user_id' => $this->conn->user->id]);

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

        $examiners = ExaminerLicenseState::where(
            'examiner_user_id',
            '!=',
            $this->conn->user->id
        )->whereHas('state', function($q) use ($gfe) {
            $q->where('states.name', $gfe->state);
        })->get();

        if (isset($examiners) && count($examiners)) {
            $ece = new CallExpired(['id' => $this->gfe_id]);
            $ece->name = 'Received';
            foreach ($examiners as $key => $value) {
                $this->controller->sendToUser($value->examiner_user_id, $ece);
            }
        }

        $this->controller->sendToUser($toId, $e);

        $aheadCalls = Call::with('gfe')->whereNull('examiner_user_id')->whereHas('gfe', function ($q) use ($gfe) {
            $q->where('state', $gfe->state)->where('id', '!=', $gfe->id);
        })->get();

        $epa = new PeopleAhead(['id' => $this->gfe_id, 'count' => $aheadCalls->count() - 1]);
        $epa->name = 'Received';

        if (isset($aheadCalls) && count($aheadCalls)) {
            foreach ($aheadCalls as $key => $value) {
                switch ($value->gfe->channel_id) {
                    case 1:
                        $toId = $value->gfe->assigned_to_user_id;
                        break;
                    case 2:
                    case 3:
                    case 5:
                        $toId = $value->gfe->patient_user_id;
                        break;
                }
                $this->controller->sendToUser($toId, $epa);
            }
        }
    }
}
