<?php

namespace App\Http\Websocket\Events;

use App\Jobs\SendPushJob;
use App\Models\Call;
use App\Models\CallAction;
use App\Models\ExaminerLicenseState;
use App\Models\Gfe;
use App\Models\User;
use App\Http\Services\AgoraService;

class CallRequest extends Event
{
    public $event = "callRequest" . PHP_EOL;

    protected function handle()
    {
        echo "CallRequest" . PHP_EOL;
        $gfe = Gfe::where('id', $this->gfe_id)->with(
            'patientDetail',
            'locationDetail.location_doctors',
            'reqTreatments.treatment',
            'reqTreatments.services.service',
            'treatments.treatment',
            'treatments.services.service'
        )->first();

        if (!$gfe) {
            $e = new NotFound(['id' => $this->gfe_id]);
            $e->name = 'Received';
            $this->conn->send($e);
            return;
        }

        $examiners = ExaminerLicenseState::whereHas('state', function($q) use ($gfe) {
            $q->where('states.name', $gfe->state);
        })->get();

        if (isset($examiners) && count($examiners)) {
            $agoraService = new AgoraService();
            $token = $agoraService->generateToken($this->gfe_id);

            $gfe->update(['agora_token' => $token]);
        } else {
            $e = new UserOffline(['id' => $this->gfe_id]);
            $e->name = 'Received';
            $this->conn->send($e);
            return;
        }
        //$this->conn->send('CallRequested event sent to '. $userId);

        $t = new AgoraToken(['id' => $this->gfe_id, 'agora_token' => $token]);
        $t->name = 'Received';
        $this->conn->send($t);

        $gfeArr = $gfe->toArray();
        if ($this->again) {
            $gfeArr['again'] = $this->again;
        }

        $e = new self($gfeArr);
        $e->name = 'Received';

        $count = 0;
        $data = [];
        $examinerIds = [];
        foreach ($examiners as $key => $value) {
            $sent = $this->controller->sendToUser($value->examiner_user_id, $e);

            if ($sent) {
                echo 'Examiner: ' . $value->examiner_user_id . ' :' . PHP_EOL;
                $examinerIds[] = $value->examiner_user_id;
                $count++;
            }

            $deviceToken = User::where('id', $value->examiner_user_id)->value('device_token');
            if ($deviceToken && !empty($deviceToken)) {
                $data['to_ids'][] = $value->examiner_user_id;
                $data['deviceTokens'][] = $deviceToken;
            }
        }

        if (isset($data['deviceTokens']) && count($data['deviceTokens'])) {
            $pName = $gfe->first_name;
            if (!empty($gfe->last_name)) {
                $pName .= ' ' . $gfe->last_name;
            }
            $data['from_id'] = $gfe->patient_user_id;
            $data['module'] = 'GFE';
            $data['module_id'] = $gfe->id;
            $data['first_name'] = $gfe->first_name;
            $data['last_name'] = $gfe->last_name;
            $data['type'] = 'NEW_CALL';
            $data['title'] = __('custom.NEW_CALL_PUSH_TITLE');
            $data['body'] = __('custom.NEW_CALL_PUSH_BODY') . $pName;
            dispatch(new SendPushJob($data))->onQueue('push-notifications');
        }

        if (!$count) {
            $e = new UserOffline(['id' => $this->gfe_id]);
            $e->name = 'Received';
            $this->conn->send($e);
            return;
        }

        $aheadCalls = Call::with('gfe')->whereNull('examiner_user_id')->whereHas('gfe', function ($q) use ($gfe) {
            $q->where('state', $gfe->state)->where('id', '!=', $gfe->id);
        })->get();

        $epa = new PeopleAhead(['id' => $this->gfe_id, 'count' => $aheadCalls->count()]);
        $epa->name = 'Received';

        $this->conn->send($epa);

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

        $call = Call::firstOrCreate(['gfe_id' => $this->gfe_id]);

        if (isset($examinerIds) && count($examinerIds)) {
            foreach ($examinerIds as $examinerId) {
                CallAction::firstOrCreate(['call_id' => $call->id, 'examiner_user_id' => $examinerId]);
            }
        }
    }
}
