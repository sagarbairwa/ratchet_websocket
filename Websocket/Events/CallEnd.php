<?php

namespace App\Http\Websocket\Events;

use App\Jobs\SendPushJob;
use App\Models\Call;
use App\Models\ExaminerLicenseState;
use App\Models\Gfe;
use App\Models\SmsGfeInvite;
use App\Models\User;

class CallEnd extends Event
{
    public $event = "callEnd" . PHP_EOL;

    protected function handle()
    {
        echo "CallEnd" . PHP_EOL . $this->conn->user->id;
        $gfe = Gfe::where('id', $this->gfe_id)->with('patientDetail')->first();

        if ((!$gfe)) {
            $e = new NotFound(['id' => $this->gfe_id]);
            $e->name = 'Received';
            $this->conn->send($e);
            return;
        }

        $g = new GfeApprovalStatus(['id' => $this->gfe_id, 'approval_status' => $gfe->approval_status]);
        $g->name = 'Received';
        $this->conn->send($g);

        $e = new self($gfe->toArray());
        $e->name = 'Received';

        $user = User::where('id', $this->conn->user->id)->first();

        $call = Call::where('gfe_id', $this->gfe_id)->first();

        if ($user->role_id != 8) {
            if (isset($call) && !empty($call->examiner_user_id)) {
                $this->controller->sendToUser($call->examiner_user_id, $e);
            } else {
                $examiners = ExaminerLicenseState::whereHas('state', function($q) use ($gfe) {
                    $q->where('states.name', $gfe->state);
                })->get();
                foreach ($examiners as $key => $value) {
                    $deviceToken = User::where('id', $value->examiner_user_id)->value('device_token');
                    if ($deviceToken && !empty($deviceToken)) {
                        $data['to_ids'][] = $value->examiner_user_id;
                        $data['deviceTokens'][] = $deviceToken;
                    }
                    $this->controller->sendToUser($value->examiner_user_id, $e);
                }

                if (isset($call)) {
                    $call->delete();
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
                    $data['type'] = 'MISSED_CALL';
                    $data['title'] = __('custom.MISSED_CALL_PUSH_TITLE');
                    $data['body'] = __('custom.MISSED_CALL_PUSH_BODY') . $pName;
                    dispatch(new SendPushJob($data))->onQueue('push-notifications');
                }

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
        } else {
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

        Call::where('gfe_id', $this->gfe_id)->update(['is_ended' => 1]);

        if ($gfe->channel_id == 2) {
            SmsGfeInvite::where('token', $this->token)->update([
                'token' => null
            ]);
        }
    }
}
