<?php

namespace App\Http\Websocket\Events;

use App\Models\Gfe;

class GfeApprovalStatus extends Event
{
    public $event = "gfeApprovalStatus" . PHP_EOL;

    protected function handle()
    {
        echo "GfeApprovalStatus" . PHP_EOL . $this->conn->user->id;
        $gfe = Gfe::select('approval_status')->where('id', $this->gfe_id)->first();

        if ((!$gfe)) {
            $e = new NotFound(['id' => $this->gfe_id]);
            $e->name = 'Received';
            $this->conn->send($e);
            return;
        }

        $e = new self(['id' => $this->gfe_id, 'approval_status' => $gfe->approval_status]);
        $e->name = 'Received';
        $this->conn->send($e);
    }
}
