<?php

namespace App\Http\Websocket;

use App\Models\Call;
use App\Models\CallAction;
use App\Models\Gfe;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use App\Http\Websocket\Events\{Connected, Closed, OnClose, UserOffline};
use App\BlockedUser;
use App\Models\ChatMessages;
use App\Models\Role;
use Illuminate\Support\Facades\Log;

class WebSocketController extends Controller implements MessageComponentInterface
{
    /**
     * Array of connections
     *
     * @var array $connections
     */
    private $connections = [];

    /**
     * Array of connection mapped with user ids
     *
     * @var array $connectionMap
     */
    private $connectionMap = [];
    
    /**
     * When a new connection is opened it will be passed to this method
     *
     * @param  ConnectionInterface $conn The socket/connection that just connected to your application
     *
     * @throws \Exception
     */
    function onOpen(ConnectionInterface $conn)
    {
        $conn->authorized = false;
        $this->connections[$conn->resourceId] = $conn;
        // echo $conn->resourceId . PHP_EOL;
        $conn->send(new Connected());
    }
    
    /**
     * This is called before or after a socket is closed (depends on how it's closed).  SendMessage to $conn will not result in an error if it has already been closed.
     * @param  ConnectionInterface $conn The socket/connection that is closing/closed
     * @throws \Exception
     */
    function onClose(ConnectionInterface $conn)
    {
        $conn->send(new Closed());
        $disconnectedId = $conn->resourceId;
        // echo $disconnectedId . ' connection closed' . PHP_EOL;
        echo '<' . $disconnectedId . '> closed' . PHP_EOL . PHP_EOL;

        foreach($this->connectionMap as $userId => $connections) {
            if (in_array($disconnectedId, $connections)) {
                echo '(' . $userId . ') userId' . PHP_EOL . PHP_EOL;
            }
        }

        if ($conn->user && $conn->user->role_id == 8) {
            $callActions = CallAction::where('examiner_user_id', $conn->user->id)->with('call')->get();
            CallAction::where('examiner_user_id', $conn->user->id)->delete();

            if (isset($callActions) && count($callActions)) {
                $toArr = [];
                foreach ($callActions as $key => $value) {
                    if (empty(CallAction::where('call_id', $value->call_id)->count())) {
                        $gfe = Gfe::select('id', 'channel_id', 'assigned_to_user_id', 'patient_user_id')->where('id', $value->call->gfe_id)->first();
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
                        if (!in_array($toId, $toArr)) {
                            $e = new UserOffline(['id' => $gfe->id, 'connection_closed' => 1]);
                            $e->name = 'Received';
                            $this->sendToUser($toId, $e);

                            $toArr[] = $toId;
                        }
                        
                    }
                }
            }

            if (isset($this->connections[$disconnectedId])) {
                unset($this->connections[$disconnectedId]);
            }
        } elseif ($conn->user->role_id == 7) {

            if (isset($conn->gfe_id)) {
                $checkChatMessages = ChatMessages::where(function($a) use($conn) {
                    $a->whereHas('participants', function($b) use($conn) {
                        $b->where('user_id', $conn->user->id);
                    })
                    ->whereHas('conversation', function($c) use($conn) {
                        $c->whereHas('gfeAidaNotification', function($d) use($conn) {
                            $d->where('status', 'scheduled')
                            ->where('id', $conn->gfe_id);
                        });
                    });
                })
                ->orderBy('id', 'DESC')
                ->first();
    
                if($checkChatMessages) {
                    ChatMessages::create(
                        [
                            'chat_conversation_id' => $checkChatMessages->chat_conversation_id,
                            'chat_participation_id' => $checkChatMessages->chat_participation_id,
                            'body' => json_encode(
                                [
                                    'data' => [
                                        "message" => 'User Exited Chat Before Completing Follow-up',
                                        "response_msg" => 'User Exited Chat Before Completing Follow-up'
                                    ],
                                    "event" => "SendMsg"
                                ]
                            )
                        ]
                    );
                }
    
                $conn->gfe_id = null;
            }
        }
    }
    
    /**
     * If there is an error with one of the sockets, or somewhere in the application where an Exception is thrown,
     * the Exception is sent back down the stack, handled by the Server and bubbled back up the application through this method
     * @param  ConnectionInterface $conn
     * @param  \Exception $e
     * @throws \Exception
     */
    function onError(ConnectionInterface $conn, \Exception $e)
    {
        
        echo "An error has occurred with connection {$e->getMessage()}\n";
        if (isset($this->connections[$conn->resourceId])) {
            unset($this->connections[$conn->resourceId]);
        }
        //$conn->close();
    }
    
    /**
     * Triggered when a client sends data through the socket
     * @param  \Ratchet\ConnectionInterface $conn The socket/connection that sent the message to your application
     * @param  string $msg The message received
     * @throws \Exception
     */
    function onMessage(ConnectionInterface $conn, $msg)
    {
        EventListener::raw($this, $conn, $msg);
    }

    /**
     * Map connection id with user id
     *
     * @param int $userId User ID
     * @param int $resourceId Resource ID
     *
     * @var array
     */
    public function mapConnection($userId, $resourceId)
    {
        if (!isset($this->connectionMap[$userId])) {
            $this->connectionMap[$userId] = [];
        }
        if (!in_array($resourceId, $this->connectionMap[$userId])) {
            $this->connectionMap[$userId][] = $resourceId;
        }
    }

    /**
     * Send event to user
     *
     * @param int $userId User ID
     * @param int $event Event
     *
     * @var array
     */
    public function sendToUser($userId, $event)
    {
        if (!isset($this->connectionMap[$userId])) {
            return false;
        }

        $resourceIds = $this->connectionMap[$userId];
        $count = 0;
        foreach ($resourceIds as $resourceId) {
            if (isset($this->connections[$resourceId])) {
                $conn = $this->connections[$resourceId];
                $conn->send($event);
                $count = 1;
            } else {
                if (isset($this->connectionMap[$userId][$resourceId])) {
                    unset($this->connectionMap[$userId][$resourceId]);
                }
            }
        }
        return $count;
    }

    /**
     * Send event to resource
     *
     * @param int $resourceId Resource ID
     * @param int $event Event
     *
     * @var array
     */
    public function sendToResource($resourceId, $event)
    {
        if (!isset($this->connections[$resourceId])) {
            return false;
        }
        $conn = $this->connections[$resourceId];
        $conn->send($event);
    }

    /**
     * check user Status
     *
     * @param Request $user_id integer
     * @param Request $blocked_user_id integer
     *
     * @return string
     */
    public function checkBlockedStatus($user_id, $blocked_user_id)
    {
        return BlockedUser::where(
            [
                'user_id' => $user_id,
                'blocked_user_id' => $blocked_user_id
            ]
        )
        ->count();
    }
}
