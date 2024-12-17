<?php

namespace App\Http\Websocket\Events;

use App\Http\Services\NotificationService;
use App\Models\AidaOption;
use App\Models\User;
use App\Models\ChatConversation;
use App\Models\ChatParticipations;
use App\Models\ChatMessages;
use App\Models\DoctorAppointmentSchedule;
use App\Models\FlowMessage;
use App\Models\Gfe;
use App\Models\TrCard;
use App\Models\GfeAidaNotification;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Role;
use App\Models\ServiceSymptom;
use App\Models\SeverityRanges;
use App\Models\SmsGfeInvite;
use App\Models\TrCardReceipt;
use App\Models\TreatmentInformation;
use App\Models\UserDetail;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use App\Traits\common;
use Kutia\Larafirebase\Facades\Larafirebase;
use Carbon\Carbon;
use App\Http\Services\SmsService;
use App\Models\TrCardSkinProduct;

class SendMsg extends Event
{
    use common;

    // This event will get at send's self dashboard meaning sender get its data back.

    public $event = "SendMsg";

    protected function handle()
    {
        echo "SendMsg--" . "Userid: " . $this->conn->user->id . "ConId: ". $this->conn->resourceId . PHP_EOL;
        $gfe = TrCard::where('id', $this->tr_card_id)->first();
        $this->conn->gfe_id = $this->gfe_aida_notification_id;

        //Insert Data In Chat Conversation
        $conversation = ChatConversation::updateOrCreate(
            [
                'gfe_aida_notification_id' => $this->gfe_aida_notification_id,
                'user_id' => $gfe->patient_user_id
            ],
            [
                'gfe_aida_notification_id' => $this->gfe_aida_notification_id,
                'user_id' => $gfe->patient_user_id
            ]
        );

        if($conversation) {
            $sender_participations = ChatParticipations::updateOrCreate(
                [
                    'chat_conversation_id' => $conversation->id,
                    'user_id' => $this->conn->user->id
                ],
                [
                    'chat_conversation_id' => $conversation->id,
                    'user_id' => $this->conn->user->id
                ]
            );

            $receiver_participations = ChatParticipations::updateOrCreate(
                [
                    'chat_conversation_id' => $conversation->id,
                    'user_id' => null
                ],
                [
                    'chat_conversation_id' => $conversation->id,
                    'user_id' => null
                ]
            );
        }

        // Notify self
        $sender['message'] = $this->message;
        $sender['type'] = $this->type;
        $sender['message_id'] = $this->message_id;
        $sender['created_at']= now();

        $es = new self($sender);
        $this->conn->send($es);

        $action = $this->action;

        if(!$action || $action == '' || $action == null ) {
            $nt = new NotFound();
            $nt->name = 'Received';
            $this->conn->send($nt);
            return;
        }

        $ChatFound = GfeAidaNotification::where(
            [
                'id' => $this->gfe_aida_notification_id,
                'status' => 'sent'
            ]
        )->first();

        if($ChatFound) {
            $nt = new ChatFound();
            $nt->name = 'Received';
            $this->conn->send($nt);
        }

        switch($action) {
            case "initial" :
                    $responseBack['response_msg'] = "<div><h5>Hello, I'm AIDA...</h5><p>I am here to provide you follow-up support after your treatment.</p></div>";
                    $responseBack['option'] = [["title" => "", "action" => "start"]];
                break;
            case "start" :
                    $user = User::select('id', 'fname', 'mname', 'lname')->where('id', $this->conn->user->id)->first();
                    $aidaInfo = GfeAidaNotification::where('id', $this->gfe_aida_notification_id)->first();
                    $difference = $aidaInfo->created_at->diffInDays(now(), false);

                    if($difference > 0 && $difference <= 1) {
                        $responseBack['response_msg'] = "Hi " . ucfirst($user->fname) . ", we wanted to check in with you after your " . Product::where('id', $this->product_id)->value('title') . " treatment " . "yesterday?";
                    } elseif( $difference > 1 && $difference < 8 ){
                        $responseBack['response_msg'] = "Hi " . ucfirst($user->fname) . ", it's been a week since your " . Product::where('id', $this->product_id)->value('title') . " treatment. I wanted to see how things are progressing";
                    } elseif($difference > 7 && $difference < 15){
                        $todate = "2 weeks";
                        $responseBack['response_msg'] = "Hi " . ucfirst($user->fname) . ", it's been about " . $todate. " since your " . Product::where('id', $this->product_id)->value('title') . " treatment. I wanted to see how things are progressing";
                    } elseif($difference > 14 && $difference < 22){
                        $todate = "3 weeks";
                        $responseBack['response_msg'] = "Hi " . ucfirst($user->fname) . ", it's been about " . $todate. " since your " . Product::where('id', $this->product_id)->value('title') . " treatment. I wanted to see how things are progressing";
                    } elseif($difference > 21 && $difference < 31){
                        $todate = "4 weeks";
                        $responseBack['response_msg'] = "Hi " . ucfirst($user->fname) . ", it's been about " . $todate. " since your " . Product::where('id', $this->product_id)->value('title') . " treatment. I wanted to see how things are progressing";
                    } else {
                        $todate = "more than 4 weeks";
                        $responseBack['response_msg'] = "Hi " . ucfirst($user->fname) . ", it's been about " . $todate. " since your " . Product::where('id', $this->product_id)->value('title') . " treatment. I wanted to see how things are progressing";
                    }

                    $responseBack['option'] = [["title"=>"","action"=>"treatment_issue_list"]];
                break;

            case "choose_one_treatment" :
                    $name = Product::where('id', $this->product_id)->first();

                    $responseBack['response_msg'] = "Hello " . ucfirst($gfe->first_name) . ", I wanted to check in with you to see how you are feeling since your ' $name->title ' treatment? Are you experiencing any discomfort, pain, or noticed any abnormalities in the treated areas?";
                    $responseBack['option'] = [["title"=>"","action"=>"treatment_issue_list"]];
                break;

            case "treatment_issue_list" :
                    $user = User::select('id', 'fname', 'mname', 'lname')->where('id', $this->conn->user->id)->first();
                    $responseBack['modal_title'] = "Are you experiencing any discomfort, pain, or noticed any abnormalities in the treated areas?";
                    $responseBack['response_msg'] = "";
                    $responseBack['option'] = [["multiple"=> AidaOption::all(),"action"=>"treatment_issue_1"]];
                break;

            /* treatment issue 1 --( I’m feeling great! No discomfort or pain at all. ) */
            case "treatment_issue_1" :
                    $GfeAidaNotification= GfeAidaNotification::with('aidaSchedule')
                    ->where('id', $this->gfe_aida_notification_id)
                    ->first();

                    if($GfeAidaNotification) {
                        $GfeAidaNotification->update(
                            [
                                'provider_informed' => 0,
                                'followup_status' =>  "No Issues",
                                'bg_color' => 'great'
                            ]
                        );
                    }

                    $responseBack['modal_title'] = "Great! Have you been following the post procedure care instructions?";
                    $responseBack['response_msg'] = "Great! Have you been following the post procedure care instructions?";
                    $responseBack['option'] = [["title"=>"Yes","action"=>"treatment_issue_1_yes", "popup" =>1],["title"=>"No","action"=>"treatment_issue_1_no","popup" =>1]];
                break;

            case "treatment_issue_1_yes" :
                    $responseBack['response_msg'] = "Perfect! If its a convenient time please, take a moment to upload a photo of the treatment area. It will be held confidential and more importantly, it will help me further evaluate your progress and provide any additional feedback in case I notice something that you may have not.";
                    $responseBack['option'] = [["title"=>"Click Photo","action"=>"treatment_issue_1_yes_camera"]];
                break;

            case "treatment_issue_1_camera_not_right_now" :
                    if($this->not_now == 1) {
                        $responseBack['response_msg'] = "That's not a problem. However, as they say, pictures are worth 1,000 words and would be very helpful to help us follow your progress. We don't use it for any other reason, other than that; your privacy is top priority for us.";
                        $responseBack['option'] = [["title"=>"Click Photo","action"=>"treatment_issue_1_yes_camera"]];
                    } else {
                        $responseBack['response_msg'] = "Thanks for that. I have all we need for now. We'll check up on you in a week to make sure you are still well.";
                        $responseBack['option'] = [["title"=>"I need something else","action"=>"treatment_issue_list"],["title"=>"Close session","action"=>"end"]];
                    }
                break;

            case "treatment_issue_1_no" :
                    $responseBack['response_msg'] = "Please follow the post procedure instructions.";
                    $responseBack['option'] = [["title"=>"Continue","action"=>"treatment_issue_1_no_msg"]];
                break;

            case "treatment_issue_1_no_msg" :
                    $responseBack['response_msg'] = "Thanks for that. I have all we need for now. We'll check up on you in a week to make sure you are still well.";
                    $responseBack['option'] = [["title"=>"I need something else","action"=>"treatment_issue_list"],["title"=>"Close session","action"=>"end"]];
                break;

            case "treatment_issue_1_yes_camera" :
                    $responseBack['response_msg'] = "Thanks for that. I have all we need for now. We'll check up on you in a week to make sure you are still well.";
                    $responseBack['option'] = [["title"=>"I need something else","action"=>"treatment_issue_list"],["title"=>"Close session","action"=>"end"]];
                break;

            /** treatment issue 2 -- ( I’m experiencing some mild discomfort. ) */
            case "treatment_issue_2" :

                    //Code for Send Message
                    $msg = $this->message;
                    $provider = User::where('id', $gfe->assigned_to_user_id)->first();

                    $user = User::select('id', 'fname', 'mname', 'lname', 'email')
                    ->where('id', $this->conn->user->id)
                    ->first();

                    if( $provider && $provider->country_code && $provider->phone ) {
                        $smsService = new SmsService();
                        $sms = $smsService->send(
                            $provider->country_code.$provider->phone,
                            "Patient : " . ucfirst($user->fname) . "\n" . "Email : " . $user->email . "\n" . "Message" . "\n" . $msg
                        );
                    }

                    $d_token = $provider->device_token;

                    $notification = Notification::create(
                        [
                            'from_id' => $user->id,
                            'to_id' => $provider->id,
                            'module' => 'AIDA',
                            'module_id' => null,
                            'type' => 'AIDA_MESSAGE',
                            'title' => ucfirst($user->fname) . ' facing issue',
                            'body' => $msg
                        ]
                    );

                    if($d_token) {
                        $push =Larafirebase::withTitle(ucfirst($user->fname) . ' facing issue' )
                        ->withBody($msg)
                        ->withSound('default')
                        ->withPriority('high')
                        ->withAdditionalData([
                            'patient_id' => $user->id,
                            'email' => $user->email,
                            'notification_id' => $notification->id,
                            'tr_crad_id' => $gfe->id
                        ])
                        ->sendNotification($d_token);
                    }

                    switch ($this->message) {
                        case "I'm experiencing some mild discomfort.":
                            $selected_issue_message = "I'm sorry that you are experiencing some mild discomfort. However, it may or may not be of too much concern. Let me ask a few questions.";
                            $setMessage = "Some Issues";
                            $bgColor = 'warning';
                            break;

                        case "I have some moderate pain and tenderness at the treatment areas.":
                            $selected_issue_message = "I'm sorry that you are experiencing some moderate pain and tenderness. However, it may or may not be of too much concern. Let me ask a few questions.";
                            $setMessage = "Some Issues";
                            $bgColor = 'warning';
                            break;

                        case "I'm experiencing significant discomfort and pain.":
                            $selected_issue_message = "I'm sorry that you are experiencing significant discomfort and pain. However, it may or may not be of too much concern. Let me ask a few questions.";
                            $setMessage = "Emergency";
                            $bgColor = 'danger';
                            break;

                        default:
                            $selected_issue_message = "I'm sorry that you are experiencing issue. However, it may or may not be of too much concern. Let me ask a few questions.";
                            $setMessage = "Some Issues";
                            $bgColor = 'warning';
                            break;
                    }

                    $GfeAidaNotification= GfeAidaNotification::with('aidaSchedule')
                    ->where('id', $this->gfe_aida_notification_id)
                    ->first();

                    if($GfeAidaNotification) {
                        $GfeAidaNotification->update(
                            [
                                'provider_informed' => 1,
                                'followup_status' => $setMessage,
                                'bg_color' => $bgColor
                            ]
                        );
                    }

                    $responseBack['modal_title'] = "However, it may or may not be of too much concern. Let me ask a few questions.";
                    $responseBack['response_msg'] = $selected_issue_message;
                    $responseBack['option'] = [["title"=>"Continue","action"=>"treatment_issue_2_continue"]];
                break;

            case "treatment_issue_2_continue" :
                    $responseBack['modal_title'] = "Have you been following the post procedure care instructions?";
                    $responseBack['response_msg'] = "Great! Have you been following the post procedure care instructions?";
                    $responseBack['option'] = [["title"=>"Yes","action"=>"treatment_issue_2_yes"],["title"=>"No","action"=>"treatment_issue_2_no"]];
                break;

            case "treatment_issue_2_yes" :
                    $symtom_opt = ServiceSymptom::where('service_product_id', $this->product_id)
                    ->select('id', 'service_product_id', 'symptoms_id')
                    ->with('symptom:id,title')
                    ->get();

                    $arr = []; $i = 0;
                    foreach($symtom_opt as $so) {
                        $arr[$i]['id'] = $so->symptom[0]->id;
                        $arr[$i]['title'] = $so->symptom[0]->title;
                        $arr[$i]['action'] = $this->action;
                        $i++;
                    }

                    if(count($arr) > 0) {
                    } else {
                        $nt = new NotFound();
                        $nt->name = 'Received';
                        $this->conn->send($nt);
                        return;
                    }

                    $responseBack['modal_title'] = "Which option best describes your symptoms?";
                    $responseBack['response_msg'] = "Great! Which option best describes your symptoms?";
                    $responseBack['option'] = [["multiple" => $arr,"action"=>"treatment_issue_2_yes_symptom"]];
                break;

            case "treatment_issue_2_no" :
                    $responseBack['response_msg'] = "Please follow the post procedure instructions.";
                    $responseBack['option'] = [["title"=>"Continue","action"=>"treatment_issue_2_no_msg"]];
                break;

            case "treatment_issue_2_no_msg" :
                    $responseBack['response_msg'] = "Thanks for that. I have all we need for now. We'll check up on you in a week to make sure you are still well.";
                    $responseBack['option'] = [["title"=>"I need something else","action"=>"treatment_issue_list"],["title"=>"Close session","action"=>"end"]];
                break;

            case "treatment_issue_2_yes_symptom" :
                    $sym_title = ($this->symptoms_title)?$this->symptoms_title:'symptom';

                    $responseBack['response_msg'] = "What is the degree of " . $sym_title . " on a scale of 1 to 10";
                    $responseBack['option'] = [["title"=>"View Options","action"=>"treatment_issue_2_yes_symptom_range"]];
                break;

            case "treatment_issue_2_yes_symptom_range" :
                    $sym_title = $this->symptoms_title;

                    $symtom_opt = ServiceSymptom::where(['service_product_id' => $this->product_id])
                    ->whereHas('symptom', function($e) use($sym_title) {
                        return $e->where('title', 'like', '%'.$sym_title.'%');
                    })->first();

                    if(!$symtom_opt) {
                        $nt = new NotFound();
                        $nt->name = 'Received';
                        $this->conn->send($nt);
                        return;
                    }

                    SeverityRanges::create(
                        [
                            'user_id' => $this->conn->user->id,
                            'service_symptom_id' => $symtom_opt->id,
                            'range' => $this->message
                        ]
                    );

                    $responseBack['response_msg'] = "Perfect! If it's convenient, please take a moment to upload a photo of the treatment area. It will be held confidential and more importantly, it will help me further evaluate your progress and provide any additional feedback in case I notice something that you may have not.";
                    $responseBack['option'] = [["title"=>"Click Photo","action"=>"treatment_issue_2_yes_camera"]];
                break;

            case "treatment_issue_2_yes_camera" :
                    $responseBack['response_msg'] = "Awesome! At any point, if you have any questions or discomfort, you can access me via the PAC. I'm here to answer any questions you have. Last but not least, be sure to follow the post-treatment care plan for best results";
                    $responseBack['option'] = [["title"=>"I need something else","action"=>"escalate_further_option"],["title"=>"Close session","action"=>"end"]];
                break;

            case "treatment_issue_2_camera_not_right_now" :
                    if($this->not_now == 1) {
                        $responseBack['response_msg'] = "That's not a problem. However, as they say, pictures are worth 1,000 words and would be very helpful to help us follow your progress. We don't use it for any other reason, other than that; your privacy is top priority for us.";
                        $responseBack['option'] = [["title"=>"Click Photo","action"=>"treatment_issue_2_yes_camera"]];
                    } else {
                        $responseBack['response_msg'] = "Thanks for that. I have all we need for now. We'll check up on you in a week to make sure you are still well.";
                        $responseBack['option'] = [["title"=>"I need something else","action"=>"treatment_issue_list"],["title"=>"Close session","action"=>"end"]];
                    }
                break;

            // Escalate Further Third Flow
            case "escalate_further_option" :
                    $responseBack['modal_title'] = "Would you like for us to provide some different options to assist you?";
                    $responseBack['response_msg'] = "It's very important you understand the impact of your symptoms. Would you like for us to provide some different options to assist you?";
                    $responseBack['option'] = [["title"=>"Yes","action"=>"escalate_further_option_list"],["title"=>"No","action"=>"end"]];
                break;

            case "escalate_further_option_list" :
                    $responseBack['response_msg'] = "Please select from the following options.";

                    $TrCardD = TrCard::select('id','gfe_id','type')
                    ->where(['id' => $this->tr_card_id, 'type' => 'aida'])
                    ->first();

                    if($TrCardD) {
                        $responseBack['option'] = [
                            ["title"=>"Schedule a meeting with your provider","action"=>"escalate_further_option_2"],
                            ["title"=>"Leave a message for your provider","action"=>"escalate_further_option_4"],
                            ["title"=>"Read more about the symptoms","action"=>"escalate_further_option_3"]
                        ];
                    } else {
                        $responseBack['option'] = [
                            ["title"=>"Talk to a doctor","action"=>"escalate_further_option_1"],
                            ["title"=>"Schedule a meeting with your provider","action"=>"escalate_further_option_2"],
                            ["title"=>"Leave a message for your provider","action"=>"escalate_further_option_4"],
                            ["title"=>"Read more about the symptoms","action"=>"escalate_further_option_3"]
                        ];
                    }
                break;

            // Talk to a Doctor
            case "escalate_further_option_1" :
                    $responseBack['response_msg'] = "";
                    $responseBack['option'] = [["title"=>"Continue","action"=>"escalate_further_option_1_call_doc"]];
                break;

            case "escalate_further_option_1_call_error" :
                    $responseBack['response_msg'] = "Phone number is not registered with us. Please register your phone number to get in touch with doctor.";
                    $responseBack['option'] = [["title"=>"I need something else","action"=>"treatment_issue_list"],["title"=>"Close session","action"=>"end"]];
                break;

            case "escalate_further_option_1_call_doc" :
                    $responseBack['response_msg'] = "Link sent!";
                    $responseBack['option'] = [["title"=>"I need something else","action"=>"treatment_issue_list"],["title"=>"Close session","action"=>"end"]];
                break;

            // Schedule Meeting
            case "escalate_further_option_2" :
                    $link2 = UserDetail::where('user_id', $gfe->assigned_to_user_id)->value('schedule_calendar');

                    if($this->flow_msg_id && $this->flow_msg_id != null) {
                        $nestMessageId = FlowMessage::with('children.childMessage')
                        ->where('id', $this->flow_msg_id )
                        ->first();

                        $nestMessageId = ($nestMessageId->children[0]->message_id);

                        if(!$nestMessageId) {
                            $nt = new NotFound();
                            $nt->name = 'Received';
                            $this->conn->send($nt);
                            return;
                        }

                        $data = json_encode(
                            [
                                [
                                    'title' => 'Okay',
                                    'id' => $nestMessageId
                                ]
                            ]
                        );

                        if(!$link2) {
                            $responseBackresponse_msg = "Provider doesn't have any calendar to schedule appointments. Please get in touch with your provider.";
                        } else {
                            $responseBackresponse_msg = '<a href="'. $link2 .'">' .  $link2 . '</a>';
                        }

                        $responseBack['new_flow'] = 1;
                        $responseBack['button_title'] = 'Continue';
                        $responseBack['response_msg'] = $responseBackresponse_msg;
                        $responseBack['option'] = json_decode($data, true);
                        break;
                    }

                    if(!$link2) {
                        $responseBack['response_msg'] = "Provider doesn't have any calendar to schedule appointments. Please get in touch with your provider.";
                        $responseBack['option'] = [["title"=>"I need something else","action"=>"treatment_issue_list"],["title"=>"Close session","action"=>"end"],["title"=>"No_found_link1"]];
                        break;
                    } else {
                        $responseBack['response_msg'] = "Thank you for scheduling a meeting with a provider. After you click on calendar link your AIDA session will get closed."; //. $link2->schedule_calendar . "";
                        $responseBack['option'] = [["title"=>"Open link", "link"=> $link2 ],["title"=>"I need something else","action"=>"treatment_issue_list"],["title"=>"Close session","action"=>"end"]];
                        break;
                    }

            // Treatement Info Page
            case "escalate_further_option_3" :
                    $responseBack['response_msg'] = "";
                    $responseBack['option'] = [["title"=>"Continue","action"=>"escalate_further_option_3_info"]];
                break;

            case "escalate_further_option_3_info" :
                $link = TreatmentInformation::where('product_id', $this->product_id)->value('instruction_link'); //get link of the page from DB
                    if(!$link) {
                        $name = Product::where('id', $this->product_id)->first();
                        $responseBack['response_msg'] = $name->title;
                        $responseBack['option'] = [["title"=>"I need something else","action"=>"treatment_issue_list"],["title"=>"Close session","action"=>"end"],["title"=>"No_found_link2"]];
                    } else {
                        $responseBack['response_msg'] = "Here is some valuable information. Be sure to stick to yourpost procedure care instructions provided by your provider to achieve the best results. If there is nothing else, I'll follow up later this week to see if things have gotten better.";
                        $responseBack['option'] = [["title"=>"I need something else","action"=>"treatment_issue_list"],["title"=>"Close session","action"=>"end"],["title"=>"Open link","link"=>$link]];
                    }
                break;

            // Leave Message
            case "escalate_further_option_4" :
                    $responseBack['response_msg'] = "Leave a message for your provider";
                    $responseBack['option'] = [["title"=>"popup","action"=>"escalate_further_option_4_msg_send"]];
                break;

            case "escalate_further_option_4_msg_send" :
                // Code for Send Message
                $msg = $this->message;
                $provider = User::where('id', $gfe->assigned_to_user_id)->first();
                $user = User::select('id', 'fname', 'mname', 'lname', 'email')->where('id', $this->conn->user->id)->first();

                if( $provider && $provider->country_code && $provider->phone ) {
                    $smsService = new SmsService();
                    $sms = $smsService->send(
                        $provider->country_code.$provider->phone,
                        "Patient : " . ucfirst($user->fname) . "\n" . "Email : " . $user->email . "\n" . "Message" . "\n" . $msg
                    );
                }

                $d_token = $provider->device_token;

                $notification = Notification::create(
                    [
                        'from_id' => $user->id,
                        'to_id' => $provider->id,
                        'module' => 'AIDA',
                        'module_id' => null,
                        'type' => 'AIDA_MESSAGE',
                        'title' => ucfirst($user->fname) . ' facing issue',
                        'body' => $msg
                    ]
                );

                if($d_token) {
                    $push =Larafirebase::withTitle(ucfirst($user->fname) . ' facing issue' )
                    ->withBody($msg)
                    ->withSound('default')
                    ->withPriority('high')
                    ->withAdditionalData([
                        'patient_id' => $user->id,
                        'email' => $user->email,
                        'notification_id' => $notification->id,
                        'tr_crad_id' => $gfe->id
                    ])
                    ->sendNotification($d_token);
                }

                if($this->flow_msg_id && $this->flow_msg_id != null) {
                    $nestMessageId = FlowMessage::with('children.childMessage')
                    ->where('id', $this->flow_msg_id )
                    ->first();

                    $nestMessageId = ($nestMessageId->children[0]->message_id);

                    if(!$nestMessageId) {
                        $nt = new NotFound();
                        $nt->name = 'Received';
                        $this->conn->send($nt);
                        return;
                    }

                    $data = json_encode(
                        [
                            [
                                'title' => 'Okay',
                                'id' => $nestMessageId
                            ]
                        ]
                    );

                    $responseBack['new_flow'] = 1;
                    $responseBack['button_title'] = 'Continue';
                    $responseBack['response_msg'] = "Your message has been sent to the provider.";
                    $responseBack['option'] = json_decode($data, true);
                    break;
                }

                $responseBack['response_msg'] = "Your message has been sent to the provider.";
                $responseBack['option'] = [["title"=>"I need something else","action"=>"treatment_issue_list"],["title"=>"Close session","action"=>"end"]];
                break;

            // Speak Case
            case "treatment_issue_5" :

                //Code for Send Message
                $msg = $this->message;
                $provider = User::where('id', $gfe->assigned_to_user_id)->first();

                $user = User::select('id', 'fname', 'mname', 'lname', 'email')
                ->where('id', $this->conn->user->id)
                ->first();

                if( $provider && $provider->country_code && $provider->phone ) {
                    $smsService = new SmsService();
                    $sms = $smsService->send(
                        $provider->country_code.$provider->phone,
                        "Patient : " . ucfirst($user->fname) . "\n" . "Email : " . $user->email . "\n" . "Message" . "\n" . $msg
                    );
                }

                $d_token = $provider->device_token;

                $notification = Notification::create(
                    [
                        'from_id' => $user->id,
                        'to_id' => $provider->id,
                        'module' => 'AIDA',
                        'module_id' => null,
                        'type' => 'AIDA_MESSAGE',
                        'title' => ucfirst($user->fname) . ' facing issue',
                        'body' => $msg
                    ]
                );

                if($d_token) {
                    $push =Larafirebase::withTitle(ucfirst($user->fname) . ' facing issue' )
                    ->withBody($msg)
                    ->withSound('default')
                    ->withPriority('high')
                    ->withAdditionalData([
                        'patient_id' => $user->id,
                        'email' => $user->email,
                        'notification_id' => $notification->id,
                        'tr_crad_id' => $gfe->id
                    ])
                    ->sendNotification($d_token);
                }

                $GfeAidaNotification= GfeAidaNotification::with('aidaSchedule')
                ->where('id', $this->gfe_aida_notification_id)
                ->first();

                if($GfeAidaNotification) {
                    $GfeAidaNotification->update(
                        [
                            'provider_informed' => 1,
                            'followup_status' => "Some Issues",
                            'bg_color' => 'warning'
                        ]
                    );
                }

                $responseBack['modal_title'] = "Great! Have you been following the post procedure care instructions?";
                $responseBack['response_msg'] = "Great! Have you been following the post procedure care instructions?";
                $responseBack['option'] = [["title"=>"Yes","action"=>"treatment_issue_1_yes"],["title"=>"No","action"=>"treatment_issue_1_no"]];
                break;

            // Change Ans
            case "change_answer_list" :
                    $responseBack['modal_title'] = "Are you experiencing any discomfort, pain, or noticed any abnormalities in the treated areas?";
                    $responseBack['response_msg'] = "";
                    $responseBack['option'] = [["multiple"=> AidaOption::all(),"action"=>"treatment_issue_1"]];
                break;
            case "change_treatment_issue_1" :
                    $responseBack['modal_title'] = "Great! Have you been following the post procedure care instructions?";
                    $responseBack['response_msg'] = "Great! Have you been following the post procedure care instructions?";
                    $responseBack['option'] = [["title"=>"Yes","action"=>"treatment_issue_1_yes"],["title"=>"No","action"=>"treatment_issue_1_no"]];
                break;

            case "change_treatment_issue_2_yes" :
                    $symtom_opt = ServiceSymptom::where('service_product_id', $this->product_id)
                    ->select('id', 'service_product_id', 'symptoms_id')
                    ->with('symptom:id,title')
                    ->get();

                    $arr = []; $i = 0;
                    foreach($symtom_opt as $so) {
                        $arr[$i]['id'] = $so->symptom[0]->id;
                        $arr[$i]['title'] = $so->symptom[0]->title;
                        $arr[$i]['action'] = $this->action;
                        $i++;
                    }

                    if(count($arr) > 0) {
                    } else {
                        $nt = new NotFound();
                        $nt->name = 'Received';
                        $this->conn->send($nt);
                        return;
                    }

                    $responseBack['modal_title'] = "Which option best describes your symptoms?";
                    $responseBack['response_msg'] = "Great! Which option best describes your symptoms?";
                    $responseBack['option'] = [["multiple" => $arr,"action"=>"treatment_issue_2_yes_symptom"]];
                break;
            case "on_demand_care" :

                $provider = User::where('id', $gfe->assigned_to_user_id)->first();
                $link2 = UserDetail::where('user_id', $gfe->assigned_to_user_id)->value('schedule_calendar');

                $responseBack['response_msg'] = "On Demand Care";
                $responseBack['option'] = [
                    [
                        "title"=>"Leave a message with " . strtoupper($provider->fname), "action"=>"escalate_further_option_4"
                    ],
                    [
                        "title" => "Would you like to book an appointment with " . strtoupper($provider->fname), "action"=>"escalate_further_option_2"
                    ],
                    // [
                    //     "title"=>"Would you like to initiate a text chat with a healthcare professional from the Aesthedika Health Team?","action"=>"#"
                    // ],
                    // [
                    //     "title"=>"Would you like to initiate a video chat with a healthcare professional from the Aesthedika Health Team?","action"=>"#"
                    // ]
                ];
                break;
            case "bundle_info_old" :

                    $user = User::select('id', 'fname')
                    ->where('id', $this->conn->user->id)
                    ->first();

                    $aidaInfo = Notification::where('type', 'NEW_BUNDLE')
                    ->whereJsonContains('payload->id', $this->gfe_aida_notification_id)
                    ->first();

                    $createdAt = Carbon::parse(Carbon::parse($aidaInfo->created_at)->format('H:i:s'));

                    $aidaInfo_payload = json_decode($aidaInfo->payload);
                    $aidaInfo_payload_bundle_sku = $aidaInfo_payload->bundle_sku;

                    if(empty($aidaInfo_payload_bundle_sku) || $aidaInfo_payload_bundle_sku == null) {
                        $nt = new NotFound();
                        $nt->name = 'Received';
                        $this->conn->send($nt);
                        return;
                    }

                    if($aidaInfo_payload_bundle_sku == 1001) {
                        switch ($this->schedule_no) {
                            case 1 :
                                    $gm = "Hello " .ucfirst($user->fname). ", I'm here to assist you with your new morning skin care routine. We will go through a detailed guide on how to use each product. Are you ready?";
                                    $ge = "Hello " .ucfirst($user->fname). ", I'm here to assist you with your new evening skin care routine. We will go through a detailed guide on how to use each product. Are you ready?";
                                break;
                                case 2 :
                                    $gm = "<div><p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>For your morning skin regimen, this routine incorporates steps preparing your skin to absorb active ingredients in products more effectively, followed by specific treatments to prevent and correct specific skin concerns.</p></div>";
                                    $ge = "<div><p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>For your evening skin regimen, this routine also incorporates steps preparing your skin to absorb active ingredients in products more effectively, followed by specific treatments to prevent and correct specific skin concerns.</p></div>";
                                break;
                                case 3 :
                                    $gm = "<div><h4 style='font-size: 20px; margin-bottom: 5px;'>STEP ONE </h4><h5 style='font-size: 16px; margin-bottom: 5px;'>GENTLE CLEANSER Gentle cleanser for ALL skin types. </h5><h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px'><br/>**How to use**: </h4><p style='font-size: 14px; margin-bottom: 5px; margin-top: 0px;'>Apply a small amount to damp skin. Gently massage into the face and neck for about 60 seconds to remove oil & dead skin caused by natural exfoliation. Rinse thoroughly with lukewarm water. Can be used in both AM/PM. (If Balancing Cleansing Emulsion is used in PM regimen, for best result, Gentle Cleanser can be used as a second/double cleanse method cleanser.) </p></div>";
                                    $ge = "<div><h4 style='font-size: 20px; margin-bottom: 5px;'>STEP ONE </h4><h5 style='font-size: 16px; margin-bottom: 5px;'>BALANCING CLEANSING <i>EMULSION</i> A clinically proven, conditioning gel-to-milk leanser that supports the protective skin barrier leaving sensitized + post-treatment skin soothed, supple and strong. Fragrance, dye- and sulfate-free Notes: Best used to remove makeup. Gentle Cleanser can follow if needed. </h5><h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px'><br/>**How to use**:</h4> <p style='font-size: 14px; margin-bottom: 5px;'> Apply a pea size amount onto completely dry skin with dry clean hands. Gently massage over the face using upward, circular motions for at least 1 min, removing all makeup and sunscreen. Rinse thoroughly and pat dry. If you feel that a double cleanse is needed, you can follow up with Gentle Cleanser.</p></div>";
                                break;
                                case 4 :
                                    $gm = "<div><h4 style='font-size: 20px; margin-bottom: 5px;'>STEP TWO</h4><h5 style='font-size: 16px; margin-bottom: 5px;'>EXFOLIATING POLISH Uniformed Magnesium crystals exfoliate dead skin cells </h5><h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px;'><br/>**How to use**</h4><p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Start with 2-3 times a week, building tolerance for daily use as needed. After cleansing, with a dampen face, apply a small amount of polish (tip of index finger). Gently massage in circular motions, avoiding the eye area. Rinse thoroughly. May be used AM or PM.</p> </div>";
                                    $ge = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP TWO</h4><h5 style='font-size: 16px; margin-bottom: 5px;'>COMPLEXION <i>RENEWAL PADS</i> Moistened pads help minimize surface oil + exfoliate dead skin cells </h5><h4 style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>  How to use </h4><p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'> Gently wipe one pad over the face after exfoliating, until pad is completely dry. Focus on areas with oiliness or where breakouts are common. Do not rinse. Can be used in both AM/PM routines.</p></div>";
                                break;
                                case 5 :
                                    $gm = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP THREE </h4><h5 style='font-size: 16px; margin-bottom: 5px;'>COMPLEXION <i>RENEWAL PADS</i> Moistened pads help minimize surface oil + exfoliate dead skin cells </h5><h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px;'><br/>**How to use**: </h4><p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'> Gently wipe one pad over the face after exfoliating, until pad is completely dry. Focus on areas with oiliness or where breakouts are common. Do not rinse. Can be used in both AM/PM routines. </p></div>";
                                    $ge = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP THREE </h4><h5 style='font-size: 16px; margin-bottom: 5px;'>DAILY POWER <i>DEFENSE</i>An advanced serum, containing Antioxidant's A & E, that help rebuild your skin's protective barrier while defending against environmental stressors and premature signs of aging. </h5><h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px'><br/>**How to use**:</h4><p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Apply 2 pumps into your hands and gently spread over your face and neck area with upward strokes, massage the serum into your skin, encouraging absorption and stimulating blood flow. </p> </div>";
                                break;
                                case 6 :
                                    $gm = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP FOUR </h4> <h5 style='font-size: 16px; margin-bottom: 5px;'>ILLUMINATING AOX <i>SERUM</i> A concentrated antioxidant serum containing Antioxidant's A + E & C, that provides protection against pollution + premature signs of aging while visibly brightening the skin with a subtly luminous, soft-focus finish. </h5> <h4 style='font-size: 14px; margin-bottom: 5px;  margin-top:5px;'><br/>**How to use**:</h4> <p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Apply 1-2 pumps every morning to face and neck after cleansing. This serum is designed for AM use due to its antioxidant properties, Vitamin C and should be applied before sunscreen. </p>
                                    </div>";
                                    $ge = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP FOUR </h4> <h5 style='font-size: 16px; margin-bottom: 5px;'>GROWTH FACTOR <i>SERUM</i> An advanced plant-based growth factor serum enhances collagen and hyaluronic acid production, restoring hydration while reinforcing your skin barrier. Reduces the appearance of fine lines, wrinkles, softening expression lines </h5> <h4 style='font-size: 14px; margin-bottom: 5px;  margin-top:5px;'><br/>**How to use**:</h4> <p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Apply 1 pump of the serum to your fingertip and with light, upward motions apply to your face and neck area. Focus on areas that may benefit most from the growth factors, such as fine lines, wrinkles, and areas lacking firmness. </p> </div>";
                                break;
                                case 7 :
                                    $gm = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP FIVE </h4> <h5 style='font-size: 16px; margin-bottom: 5px;  margin-top: 0;'>GROWTH FACTOR EYE <i>SERUM</i> Designed to improve the appearance of expression lines, creasing + hollowness while plumping + encouraging healthy skin for a visibly revived look </h5> <h4 style='font-size: 14px; margin-bottom: 5px;  margin-top: 5px;'><br/>**How to use**: </h4></p> <p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Apply one pump to apply to both eyes, applying product under the eye and around the orbital bone. It's designed to improve the appearance of fine lines, creasing, and hollowness. Use in the AM before sunscreen and in the PM after retinol as your last step. </p> </div>";
                                    $ge = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP FIVE </h4> <h5 style='font-size: 16px; margin-bottom: 5px;  margin-top: 0;'>GROWTH FACTOR EYE <i>SERUM</i> Designed to improve the appearance of expression lines, creasing + hollowness while plumping + encouraging healthy skin for a visibly revived look </h5> <h4 style='font-size: 14px; margin-bottom: 5px;  margin-top: 5px;'><br/>**How to use**: </h4> <p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Apply one pump to apply to both eyes, applying product under the eye and around the orbital bone. It's designed to improve the appearance of fine lines, creasing, and hollowness. Use in the AM before sunscreen and in the PM after retinol as your last step. </p> </div>";
                                break;
                                case 8 :
                                    $gm = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP SIX </h4> <h5 style='font-size: 16px; margin-bottom: 5px;'>SHEER FLUID <i>BROAD-SPECTRUM SUNSCREEN SPF 50</i> Mineral fluid sunscreen with a natural, bare-faced finish for all skin types provides antioxidant-rich environmental protection. </h5> <h4 style='font-size: 14px; margin-bottom: 5px;  margin-top: 5px;'><br/>**How to use**:</h4> <p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Product requires you to shake liberally for best consistency. Apply liberally to face and neck, 15 minutes before sun exposure. Reapply at least every 2 hours, using a water-resistant sunscreen if swimming or sweating. Provides broad-spectrum protection with a lightweight, natural finish suitable for all skin types. </p> </div>";
                                    $ge = "<div> <p style='font-size: 14px; margin-bottom: 5px;'>Remember to take a moment to upload a photo of your skin each week. It will be held confidential and more importantly, it will help us further evaluate your progress and provide additional feedback during your aesthetic care journey.</p> </div>";
                                break;
                                case 9 :
                                    $gm = "<div> <p style='font-size: 14px; margin-bottom: 5px;'>Remember to take a moment to upload a photo of your skin each week. It will be held confidential and more importantly, it will help us further evaluate your progress and provide addtional feedback during your aesthetic care journey.</p> </div>";
                                    $ge = "";
                                break;
                        }

                        if ( $createdAt->between( Carbon::parse('00:01:00'), Carbon::parse('11:59:00')) ) {
                            $rm = $gm;
                            if($this->schedule_no == 9) {
                                $responseBack['response_msg'] = $rm;
                                $responseBack['option'] = [
                                    [
                                        "title"=>"Watch Again", "action"=>"repeat"
                                    ],
                                    [
                                        "title"=>"Close Session", "action"=>"end_bundle"
                                    ]
                                ];
                            } else {
                                $responseBack['response_msg'] = $rm;
                                $responseBack['option'] = [
                                    [
                                        "title"=>"Click To Continue", "action"=>"bundle_info"
                                    ]
                                ];
                            }
                        } else {
                            $rm = $ge;
                            if($this->schedule_no == 8) {
                                $responseBack['response_msg'] = $rm;
                                $responseBack['option'] = [
                                    [
                                        "title"=>"Watch Again", "action"=>"repeat"
                                    ],
                                    [
                                        "title"=>"Close Session", "action"=>"end_bundle"
                                    ]
                                ];
                            } else {
                                $responseBack['response_msg'] = $rm;
                                $responseBack['option'] = [
                                    [
                                        "title"=>"Click To Continue", "action"=>"bundle_info"
                                    ]
                                ];
                            }
                        }

                    } elseif($aidaInfo_payload_bundle_sku == 1002) {
                        switch ($this->schedule_no) {
                            case 1 :
                                    $gm = "Hello " .ucfirst($user->fname). ", I'm here to assist you with your new morning skin care routine. We will go through a detailed guide on how to use each product. Are you ready?";
                                    $ge = "Hello " .ucfirst($user->fname). ", I'm here to assist you with your new evening skin care routine. We will go through a detailed guide on how to use each product. Are you ready?";
                                break;
                            case 2 :
                                $gm = "<div> <p style='font-size: 14px; margin-bottom: 5px;'>For your morning skin regimen, here's a detailed guide on how to use each product in the order you've listed. This routine incorporates steps preparing your skin to absorb active ingredients in products more effectively, followed by specific treatments to prevent and correct specific skin concerns, and concludes with protection against environmental factors.</p> </div>";
                                $ge = "<div> <p style='font-size: 14px; margin-bottom: 5px;'>For your evening skin regimen, here's a detailed guide on how to use each product in the order you've listed. This routine incorporates steps preparing your skin to absorb active ingredients in products more effectively, followed by specific treatments to prevent and correct specific skin concerns, and concludes with protection against environmental factors.</p> </div>";
                            break;
                            case 3 :
                                $gm = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP ONE </h4> <h5 style='font-size: 16px; margin-bottom: 5px;'>GENTLE CLEANSER <i>CLEANSERS</i></h5> <h5 style='font-size: 16px; margin-bottom: 5px;'>Gentle cleanser for all skin types </h5><h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px'><br/>**How to use**:</h4> <p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Apply a small amount to damp skin. Gently massage into the face and neck for about 60 seconds to remove oil & dead skin caused by natural exfoliation. Rinse thoroughly with lukewarm water. Can be used in both AM/PM. *If Balancing Cleansing Emulsion is used in PM regimen, for best result, Gentle Cleanser can be used as a second/double cleanse method cleanser.</p> </div>";
                                $ge = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP ONE </h4> <h5 style='font-size: 16px; margin-bottom: 5px;'>GENTLE CLEANSER <i>CLEANSERS</i></h5><h5 style='font-size: 16px; margin-bottom: 5px;'>Gentle cleanser for all skin types </h5> <h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px'><br/>**How to use**:</h4> <p style='font-size: 14px; margin-bottom: 5px; margin-top: 0;'>Apply a small amount to damp skin. Gently massage into the face and neck for about 60 seconds to remove oil & dead skin caused by natural exfoliation. Rinse thoroughly with lukewarm water. Can be used in both AM/PM. *If Balancing Cleansing Emulsion is used in PM regimen, for best result, Gentle Cleanser can be used as a second/double cleanse method cleanser. </p> </div>";
                            break;
                            case 4 :
                                $gm = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP TWO </h4> <h5 style='font-size: 16px; margin-bottom: 5px;'>EXFOLIATING POLISH <i>EXFOLIATORS</i></h5><h5 style='font-size: 16px; margin-bottom: 5px;'>Uniformed Magnesium crystals exfoliate dead skin cells </h5> <h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px;'><br/>**How to use**: </h4> <p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Start with 2-3 times a week, building tolerance for daily use as needed. After cleansing, with a dampen face, apply a small amount of polish (tip of index finger). Gently massage in circular motions, avoiding the eye area. Rinse thoroughly. May be used AM or PM. </p> </div>";
                                $ge = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP TWO </h4> <h5 style='font-size: 16px; margin-bottom: 5px;'>EXFOLIATING POLISH <i>EXFOLIATORS</i></h5> <h5 style='font-size: 16px; margin-bottom: 5px;'>Uniformed Magnesium crystals exfoliate dead skin cells </h5> <h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px;'><br/>**How to use**:  </h4> <p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Start with 2-3 times a week, building tolerance for daily use as needed. After cleansing, with a dampen face, apply a small amount of polish (tip of index finger). Gently massage in circular motions, avoiding the eye area. Rinse thoroughly. May be used AM or PM. </p> </div>";
                            break;
                            case 5 :
                                $gm = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP THREE </h4> <h5 style='font-size: 16px; margin-bottom: 5px;'>OIL CONTROL PADS <i>TONERS</i></h5><h5 style='font-size: 16px; margin-bottom: 5px;'>Moistened pads help minimize surface oil + exfoliate dead skin cells </h5> <h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px;'><br/>**How to use**: </h4> <p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Gently wipe one pad over the face after exfoliating, until pad is completely dry. Focus on areas with oiliness and acne or where breakouts are common. If you have acne-prone areas on your neck, chest, or back, you can use the pad to treat these areas.  Do not rinse. Can be used in both AM/PM routines.</p> </div>";
                                $ge = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP THREE </h4> <h5 style='font-size: 16px; margin-bottom: 5px;'>OIL CONTROL PADS <i>TONERS</i></h5><h5 style='font-size: 16px; margin-bottom: 5px;'>Moistened pads help minimize surface oil + exfoliate dead skin cells </h5> <h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px;'><br/>**How to use**: </h4> <p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Gently wipe one pad over the face after exfoliating, until pad is completely dry. Focus on areas with oiliness and acne or where breakouts are common. If you have acne-prone areas on your neck, chest, or back, you can use the pad to treat these areas.  Do not rinse. Can be used in both AM/PM routines.</p> </div>";
                            break;
                            case 6 :
                                $gm = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP FOUR </h4> <h5 style='font-size: 16px; margin-bottom: 5px;'>ILLUMINATING AOX SERUM <i>ANTI-AGING</i> </h5><h5 style='font-size: 16px; margin-bottom: 5px;'>A concentrated antioxidant serum containing Antioxidants A + E & C, that provides protection against pollution + premature signs of aging while visibly brightening the skin with a subtly luminous, soft-focus finish. </h5><h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px'><br/>**How to use**: </h4> <p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Apply 1-2 pumps every morning to face and neck after cleansing. This serum is designed for AM use due to its antioxidant properties, Vitamin C and should be applied before sunscreen.</p> </div>";
                                $ge = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP FOUR </h4> <h5 style='font-size: 16px; margin-bottom: 5px;'>GROWTH FACTOR SERUM <i>ANTI-AGING</i> </h5><h5 style='font-size: 16px; margin-bottom: 5px;'> An advanced plant-based growth factor serum enhances collagen and hyaluronic acid production, restoring hydration while reinforcing your skin barrier. Reduces the appearance of fine lines, wrinkles, softening expression lines </h5> <h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px'><br/>**How to use**: </h4> <p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Apply 2 pumps into your hands and gently spread over your face and neck area with upward strokes, massage the serum into your skin, encouraging absorption and stimulating blood flow. </p> </div>";
                            break;
                            case 7 :
                                $gm = "<div> <h4 style='font-size: 20px; margin-bottom: 5px;'>STEP FIVE </h4> <h5 style='font-size: 16px; margin-bottom: 5px;'>SHEER FLUID BROAD-SPECTRUM SUNSCREEN SPF 50 <i>SUNSCREEN</i> </h5><h5 style='font-size: 16px; margin-bottom: 5px;'>Mineral fluid sunscreen with a natural, bare-faced finish for all skin types provides antioxidant-rich environmental protection.</h5><h4 style='font-size: 14px; margin-bottom: 5px; margin-top:5px;'><br/>**How to use**: </h4> <p style='font-size: 14px; margin-bottom: 5px;  margin-top: 0;'>Product requires you to shake liberally for best consistency. Apply liberally to face and neck, 15 minutes before sun exposure. Reapply at least every 2 hours, using a water-resistant sunscreen if swimming or sweating. Provides broad-spectrum protection with a lightweight, natural finish suitable for all skin types. </p> </div>";
                                $ge = "";
                            break;
                        }
        
                        if ( $createdAt->between( Carbon::parse('00:01:00'), Carbon::parse('11:59:00')) ) {
                            $rm = $gm;
                            if($this->schedule_no == 7) {
                                $responseBack['response_msg'] = $rm;
                                $responseBack['option'] = [
                                    [
                                        "title"=>"Watch Again", "action"=>"repeat"
                                    ],
                                    [
                                        "title"=>"Close Session", "action"=>"end_bundle"
                                    ]
                                ];
                            } else {
                                $responseBack['response_msg'] = $rm;
                                $responseBack['option'] = [
                                    [
                                        "title"=>"Click To Continue", "action"=>"bundle_info"
                                    ]
                                ];
                            }
                        } else {
                            $rm = $ge;
                            if($this->schedule_no == 6) {
                                $responseBack['response_msg'] = $rm;
                                $responseBack['option'] = [
                                    [
                                        "title"=>"Watch Again", "action"=>"repeat"
                                    ],
                                    [
                                        "title"=>"Close Session", "action"=>"end_bundle"
                                    ]
                                ];
                            } else {
                                $responseBack['response_msg'] = $rm;
                                $responseBack['option'] = [
                                    [
                                        "title"=>"Click To Continue", "action"=>"bundle_info"
                                    ]
                                ];
                            }
                        }
                    }

                    break;
            case "bundle_info" :
                $aidaInfo = Notification::where('type', 'NEW_BUNDLE')
                ->whereJsonContains('payload->id', $this->gfe_aida_notification_id)
                ->first();

                $createdAt = Carbon::parse(Carbon::parse($aidaInfo->created_at)->format('H:i:s'));
                $aidaInfo_payload = json_decode($aidaInfo->payload);
                $aidaInfo_payload_sku = $aidaInfo_payload->bundle_sku;

                if(empty($aidaInfo_payload_sku) || $aidaInfo_payload_sku == null) {
                    $nt = new NotFound();
                    $nt->name = 'Received';
                    $this->conn->send($nt);
                    return;
                }

                if ($createdAt->between( Carbon::parse('00:01:00'), Carbon::parse('11:59:00'))) {
                    $direc = 'up';
                } else {
                    $direc = 'down';
                }

                $user = User::select('id', 'fname', 'mname', 'lname')->where('id', $this->conn->user->id)->first();
                $provider = User::where('id', $gfe->assigned_to_user_id)->first();

                if($this->flow_msg_id && $this->flow_msg_id != null) {
                    $rootMessages = FlowMessage::select(
                        'id',
                        'flow_id',
                        'message',
                        'direction',
                        'order',
                        'type',
                        'multi_option',
                        'button_title'
                    )->with('children.childMessage')
                    ->where('id', $this->flow_msg_id)
                    ->first();

                } else {
                    $rootMessages = FlowMessage::select(
                        'id',
                        'flow_id',
                        'message',
                        'direction',
                        'order',
                        'type',
                        'multi_option',
                        'button_title'
                    )->with('children', 'children.childMessage')
                    ->where(function($q) use($direc, $aidaInfo_payload_sku) {
                        $q->whereHas('product', function($q1) use($aidaInfo_payload_sku) {
                            return $q1->where('order', $aidaInfo_payload_sku);
                        })
                        ->where('direction', $direc);
                    })
                    ->doesntHave('parent')
                    ->first();
                }

                if(!$rootMessages) {
                    $nt = new NotFound();
                    $nt->name = 'Received';
                    $nt->error = 'FlowMessage object not found!';
                    $this->conn->send($nt);
                    return;
                }

                $find = ["<user_name>"];
                $replace = [(ucfirst($user->fname))];
                $modifiedString = str_replace($find, $replace, $rootMessages->message);

                if($rootMessages->button_title == 'Close') {
                    $data = json_encode(
                        [
                            [
                                "title" => "Watch Again",
                                "action" => "repeat"
                            ],
                            [
                                "title" => "Close Session",
                                "action" => "end_bundle"
                            ]
                        ]
                    );
                } else {
                    $nextMessageObject = $rootMessages->children[0]->childMessage;
                    $data = json_encode(
                        [
                            [
                                "title" => "Click To Continue",
                                "action" => "bundle_info",
                                "id" => isset($nextMessageObject)?$nextMessageObject->id:null
                            ]
                        ]
                    );
                }

                $responseBack['response_msg'] = isset($modifiedString)?$modifiedString:$rootMessages->message;
                $responseBack['option'] = json_decode($data, true);
                break;
            case "bundle_info_new" :
                $productDetails = Notification::where('module', 'PROTOCOL BUNDLE')
                ->whereJsonContains('payload->id', $this->gfe_aida_notification_id)
                ->first();

                $aidaInfo_payload = json_decode($productDetails->payload);
                $aidaInfo_payload_sku = $aidaInfo_payload->bundle_sku;

                if (isset($aidaInfo_payload_sku) && $aidaInfo_payload_sku == 'morning-skin-bundle') {
                    $schedule = 'am';
                }

                if (isset($aidaInfo_payload_sku) && $aidaInfo_payload_sku == 'evening-skin-bundle') {
                    $schedule = 'pm';
                }

                // $TrCardSkinProduct = TrCardSkinProduct::where(
                //     [
                //         'tr_card_id' => $this->tr_card_id,
                //         'schedule' =>  $schedule
                //     ]
                // )->pluck('id')
                // ->toArray();

                // $TrCardSkinProduct_first = TrCardSkinProduct::where(
                //     [
                //         'tr_card_id' => $this->tr_card_id,
                //         'schedule' =>  $schedule
                //     ]
                // )
                // ->first();

                // $current_id = isset($TrCardSkinProduct_first->id)?$TrCardSkinProduct_first->id:null;

                // if (empty($TrCardSkinProduct)) {
                //     $nt = new NotFound();
                //     $nt->name = 'Received';
                //     $nt->error = 'Messages not found!';
                //     $this->conn->send($nt);
                //     return;

                // } else {
                //     $firstElement = reset($TrCardSkinProduct); // Get the first element
                //     $lastElement = end($TrCardSkinProduct);    // Get the last element
            
                //     if (!$this->flow_msg_id && $this->flow_msg_id == null) {

                //         $resp['message'] = TrCardSkinProduct::where('id', $firstElement)
                //         ->select('id', 'description', 'how_to_use', 'notes')
                //         ->first();

                //         $responseBack['response_msg'] = json_encode($resp);

                //         if ($firstElement == $lastElement && $firstElement == $current_id || $lastElement == $current_id) {
                //             $data = json_encode(
                //                 [
                //                     [
                //                         "multi" => json_encode($TrCardSkinProduct)
                //                     ],
                //                     [
                //                         "title" => "Watch Again",
                //                         "action" => "repeat"
                //                     ],
                //                     [
                //                         "title" => "Close Session",
                //                         "action" => "bundle_info_new"
                //                     ]
                //                 ]
                //             );
                //         } elseif ($lastElement == $current_id) {
                //             $data = json_encode(
                //                 [
                //                     [
                //                         "multi" => json_encode($TrCardSkinProduct)
                //                     ],
                //                     [
                //                         "title" => "Click To Continue",
                //                         "action" => "bundle_info_new"
                //                     ]
                //                 ]
                //             );
                //         } elseif ($lastElement == $current_id) {
                //             $data = json_encode(
                //                 [
                //                     [
                //                         "multi" => json_encode($TrCardSkinProduct)
                //                     ],
                //                     [
                //                         "title" => "Watch Again",
                //                         "action" => "repeat"
                //                     ],
                //                     [
                //                         "title" => "Close Session",
                //                         "action" => "bundle_info_new"
                //                     ]
                //                 ]
                //             );
                //         }
                        
                //     } else {

                //         $resp['message'] = TrCardSkinProduct::where('id', $this->flow_msg_id)
                //         ->select('id', 'description', 'how_to_use', 'notes')
                //         ->first();

                //         if ($firstElement == $lastElement && $firstElement == $this->flow_msg_id || $lastElement == $this->flow_msg_id) {
                //             $data = json_encode(
                //                 [
                //                     [
                //                         "multi" => json_encode($TrCardSkinProduct)
                //                     ],
                //                     [
                //                         "title" => "Watch Again",
                //                         "action" => "repeat"
                //                     ],
                //                     [
                //                         "title" => "Close Session",
                //                         "action" => "bundle_info_new"
                //                     ]
                //                 ]
                //             );
                //         } elseif ($firstElement == $this->flow_msg_id) {
                //             $data = json_encode(
                //                 [
                //                     [
                //                         "multi" => json_encode($TrCardSkinProduct)
                //                     ],
                //                     [
                //                         "title" => "Click To Continue",
                //                         "action" => "bundle_info_new"
                //                     ]
                //                 ]
                //             );
                //         } elseif ($lastElement == $this->flow_msg_id) {
                //             $data = json_encode(
                //                 [
                //                     [
                //                         "multi" => json_encode($TrCardSkinProduct)
                //                     ],
                //                     [
                //                         "title" => "Watch Again",
                //                         "action" => "repeat"
                //                     ],
                //                     [
                //                         "title" => "Close Session",
                //                         "action" => "bundle_info_new"
                //                     ]
                //                 ]
                //             );
                //         } else {
                //             $nt = new NotFound();
                //             $nt->name = 'Received';
                //             $nt->error = 'Messages not found!';
                //             $this->conn->send($nt);
                //             return;
                //         }
                //     }
                // }

                $trCardTreatments = TrCardSkinProduct::where(
                    [
                        'tr_card_id' => $this->tr_card_id,
                        'schedule' => $schedule
                    ]
                )
                // ->whereIn('schedule', ['am', 'pm'])
                ->with('product:id,service_id,title,description,how_to_use')
                ->orderBy('position')
                ->get();

                $arr7[] =  [
                    'id' => 1,
                    'description' => "<div><h4>Hello, I'm AIDA...</h4><p>I am here to provide you follow-up support after your treatment.</p></div>"
                ];

                if (count($trCardTreatments)) {
                    $i = 2;
                    foreach($trCardTreatments as $trCardTreatment) {
                        $dataa['id'] = $i;
                        $dataa['tr_card_id'] = $trCardTreatment->tr_card_id;
                        $dataa['treatment_id'] = $trCardTreatment->treatment_id;
                        $dataa['service_id'] = $trCardTreatment->service_id;
                        $dataa['product_id'] = $trCardTreatment->product_id;
                        $dataa['description'] = $trCardTreatment->description;
                        $dataa['how_to_use'] = $trCardTreatment->how_to_use;
                        $dataa['notes'] = $trCardTreatment->notes;
                        $dataa['schedule'] =  $trCardTreatment->schedule;
                        $dataa['position'] =  $trCardTreatment->position;
                        $dataa['created_at'] = $trCardTreatment->created_at;
                        $dataa['product'] = $trCardTreatment->product;
                        $arr7[] = $dataa;
                        $i++;
                    }
                }

                $responseBack['response_msg'] = $arr7;
                $responseBack['option'] = [
                    [
                        "title"=>"Watch Again", "action"=>"repeat"
                    ],
                    [
                        "title"=>"Close Session", "action"=>"end_bundle"
                    ]
                ];
                break;
            case "end" :

                $GfeAidaNotification = GfeAidaNotification::where('id', $this->gfe_aida_notification_id)->first();
                $GfeAidaNotification->update(['status' => 'sent']);

                    $responseBack['response_msg'] = "Thank you so much for using our chat service. Have a good day.";
                    $responseBack['option'] = [["title"=>"I need something else","action"=>"treatment_issue_list"],["title"=>"Close session","action"=>"end"]];
                break;

            case "end_mid" :
                $GfeAidaNotification = GfeAidaNotification::where('id', $this->gfe_aida_notification_id)->first();
                $GfeAidaNotification->update(['status' => 'sent']);

                    $responseBack['response_msg'] = "";
                    $responseBack['option'] = [["title" => null, "action" => null]];
                break;

            case "new-flow" :
                    if($this->flow_msg_id && $this->flow_msg_id != null) {
                        $flow_msg_id = $this->flow_msg_id;
                    } else {
                        $rootMessages = FlowMessage::with('children', 'children.childMessage')->where(function($q) {
                            $q->whereHas('product', function($q1) {
                                return $q1->where('product_id', $this->product_id);
                            })->whereHas('product.flow', function($q3) {
                                return $q3->where('after', $this->schedule_type);
                            });
                        })->doesntHave('parent')
                        ->first();

                        if(!$rootMessages) {
                            $nt = new NotFound();
                            $nt->name = 'Received';
                            $nt->error = 'FlowMessage object not found! | schedule_type !';
                            $this->conn->send($nt);
                            return;
                        }

                        $flow_msg_id =  $rootMessages->id;
                    }

                    $rootMessages = FlowMessage::with('children.childMessage')
                    ->where('id', $flow_msg_id )
                    ->first();

                    if(!$rootMessages) {
                        $nt = new NotFound();
                        $nt->name = 'Received';
                        $nt->error = 'FlowMessage object not found!';
                        $this->conn->send($nt);
                        return;
                    }

                    $waitscreen = 0;
                    $user = User::select('id', 'fname', 'mname', 'lname', 'email')
                    ->where('id', $this->conn->user->id)
                    ->first();

                    $provider = User::where('id', $gfe->assigned_to_user_id)->first();
                    $aidaInfo = GfeAidaNotification::where('id', $this->gfe_aida_notification_id)->first();
                    $product_name = Product::where('id', $this->product_id)->value('title');
                    $product_info_link = TreatmentInformation::where('product_id', $this->product_id)->value('instruction_link');

                    if($product_info_link) {
                        $product_info_link = '<a href="'.$product_info_link.'">' .  $product_info_link . '</a>';
                    } else {
                        $product_info_link = '(Link not available)';
                    }

                    $find = ["<user_name>", "<product_name>", "<provider_name>", "<product_info_link>"];
                    $replace = [(ucfirst($user->fname)), $product_name, (ucfirst($provider->fname)), $product_info_link];
                    $modifiedString = str_replace($find, $replace, $rootMessages->message);

                    $rootMessagesChildOption = FlowMessage::with(
                        [
                            'childrenOption',
                            'childrenOption.childMessageOption'
                        ]
                    )->where(function($q1) use($flow_msg_id) {
                        return $q1->where('multi_option', 1)
                        ->where('id', $flow_msg_id);
                    })->first();

                    $multi =(!empty($rootMessagesChildOption->childrenOption))?$rootMessagesChildOption->childrenOption:null;

                    switch ($rootMessages->type) {
                        case 'ok':
                            if( $rootMessages && isset($rootMessages->children) ) {
                                $rootMessagess = collect($rootMessages['children']);

                                if(isset($rootMessages['children'])) {
                                    foreach($rootMessagess as $rM) {
                                        //if($rM->childMessage->order == 'ok') {
                                            $RM_ok_id = $rM->childMessage->id;
                                        //}
                                    }
                                    if($rootMessages->message == 'Upload Photo') {
                                        $data = json_encode([
                                            [
                                                'title' => 'Ok',
                                                'id' => isset($RM_ok_id) ? $RM_ok_id : null,
                                                'message' => [
                                                    [
                                                        'title' => 'Upload from gallery',
                                                        'id' => isset($RM_ok_id) ? $RM_ok_id : null
                                                    ],
                                                    [
                                                        'title' => 'Upload from camera',
                                                        'id' => isset($RM_ok_id) ? $RM_ok_id : null
                                                    ]
                                                ]
                                            ]
                                        ]);
                                    } else {
                                        $data = json_encode(
                                            [
                                                [
                                                    'title' => 'Ok',
                                                    'id' => isset($RM_ok_id) ? $RM_ok_id : null
                                                ]
                                            ]
                                        );
                                    }
                                }

                                if($rootMessages->multi_option) {
                                    $arr = []; $i = 0;
                                    foreach($rootMessagess as $rM) {
                                        if($rM->childMessage->order == 'ok') {
                                            $arr[$i]['id'] = $rM->childMessage->id;
                                            $waitscreen = 1;

                                            $find = ["<user_name>", "<product_name>", "<provider_name>", "<product_info_link>"];
                                            $product_info_link = TreatmentInformation::where('product_id', $this->product_id)->value('instruction_link');
                                            if($product_info_link) {
                                                $product_info_link = '<a href="'.$product_info_link.'">' .  $product_info_link . '</a>';
                                            } else {
                                                $product_info_link = '(Link not available)';
                                            }
                                            $replace = [(ucfirst($user->fname)), $product_name, (ucfirst($provider->fname)), $product_info_link];
                                            $newmodifiedString = str_replace($find, $replace, $rM->childMessage->message);
                                            $arr[$i]['title'] = $newmodifiedString; //$rM->childMessage->message;
                                        }
                                        $i++;
                                    }
                                    $data = json_encode($arr);
                                }

                                if($rootMessages->direction == 'up') {
                                    switch ($rootMessages->message) {
                                        case "Allergic reaction (e.g itching, hives)":
                                        case "Allergic reaction (e.g itching, hives, difficulty breathing or swallowing)":
                                            $msg = 'Facing allergic reaction';
                                            if( $provider && $provider->country_code && $provider->phone ) {
                                                $smsService = new SmsService();
                                                $sms = $smsService->send(
                                                    $provider->country_code.$provider->phone,
                                                    "Patient : " . ucfirst($user->fname) . "\n" . "Email : " . $user->email . "\n" . "Message" . "\n" . $msg
                                                );
                                            }

                                            $notification = Notification::create(
                                                [
                                                    'from_id' => $user->id,
                                                    'to_id' => $provider->id,
                                                    'module' => 'AIDA',
                                                    'module_id' => null,
                                                    'type' => 'AIDA_MESSAGE',
                                                    'title' => ucfirst($user->fname) . ' facing issue',
                                                    'body' => $msg
                                                ]
                                            );

                                            if($provider && $provider->device_token) {
                                                $push = Larafirebase::withTitle(ucfirst($user->fname) . ' facing issue' )
                                                ->withBody($msg)
                                                ->withSound('default')
                                                ->withPriority('high')
                                                ->withAdditionalData(
                                                    [
                                                        'patient_id' => $user->id,
                                                        'email' => $user->email,
                                                        'notification_id' => $notification->id,
                                                        'tr_crad_id' => $gfe->id
                                                    ]
                                                )
                                                ->sendNotification($provider->device_token);
                                            }

                                            $setMessage = "Emergency";
                                            $bgColor = 'danger';
                                            $provider_informed1 = 1;
                                            break;
                                        default:
                                            $setMessage = "Some Issues";
                                            $bgColor = 'warning';
                                            $provider_informed1 = 1;
                                            break;
                                    }

                                    $GfeAidaNotification = GfeAidaNotification::where('id', $this->gfe_aida_notification_id)
                                    ->first();

                                    if($GfeAidaNotification) {
                                        $GfeAidaNotification->update(
                                            [
                                                'provider_informed' => $provider_informed1,
                                                'followup_status' => $setMessage,
                                                'bg_color' => $bgColor
                                            ]
                                        );
                                    }

                                    $modifiedStringChk = null;
                                    foreach($rootMessagess as $rM) {

                                        $find = ["<user_name>", "<product_name>", "<provider_name>", "<product_info_link>"];
                                        $product_info_link = TreatmentInformation::where('product_id', $this->product_id)->value('instruction_link');
                                        if($product_info_link) {
                                            $product_info_link = '<a href="'.$product_info_link.'">' .  $product_info_link . '</a>';
                                        } else {
                                            $product_info_link = '(Link not available)';
                                        }
                                        $replace = [(ucfirst($user->fname)), $product_name, (ucfirst($provider->fname)), $product_info_link];
                                        $newmodifiedString_new = str_replace($find, $replace, $rM->childMessage->message);

                                        $modifiedStringChk = $newmodifiedString_new;

                                        $rootMessagesChildOptionChild = FlowMessage::with(['childrenOption', 'childrenOption.childMessageOption'])
                                        ->where(function($q1) use($rM) {
                                            return $q1->where('id', $rM->childMessage->id );
                                        })->first();

                                        $rootMessagesChildOptionChilds = collect($rootMessagesChildOptionChild['children']);
                                        $arr1 = []; $ii = 0;
                                        foreach($rootMessagesChildOptionChilds as $rMChild) {
                                            switch ($rMChild->childMessage->order) {
                                                case 'ok':
                                                    $atitle = 'Okay';
                                                    break;
                                                case 'yes':
                                                    $atitle =  'Yes';
                                                    break;
                                                case 'no':
                                                    $atitle =  'No';
                                                    break;
                                                case 'notthistime':
                                                    $atitle = 'Not At This Time';
                                                    break;
                                            }
                                            $arr1[$ii]['id'] = $rMChild->childMessage->id;
                                            $arr1[$ii]['title'] = isset($atitle)?$atitle:$rMChild->childMessage->order;
                                            $ii++;
                                        }
                                        $collection = collect($arr1);

                                        // Use the unique method to remove duplicates based on both 'id' and 'title'
                                        $uniqueData = $collection->unique(function ($item) {
                                            return $item['id'] . '|' . $item['title'];
                                        })->values()->all();

                                        $data = json_encode($uniqueData);
                                    }
                                }
                            }

                            if( $rootMessages &&
                                isset($rootMessages->children) &&
                                count($rootMessages->children) > 1
                            ){
                                $ar = []; $i2 = 0;
                                foreach($rootMessages->children as $child) {
                                    if($child->childMessage->status == true || $child->childMessage->status == 1) {
                                        $ar[$i2]['id'] = $child->childMessage->id;

                                        $find = ["<user_name>", "<product_name>", "<provider_name>", "<product_info_link>"];
                                        $replace = [(ucfirst($user->fname)), $product_name, (ucfirst($provider->fname)), $product_info_link];
                                        $newmodifiedString = str_replace($find, $replace, $child->childMessage->message);
    
                                        $ar[$i2]['title'] = $newmodifiedString; // $child->childMessage->message;
                                        $i2++;
                                    }
                                }
                                $data = json_encode($ar);
                            }

                            break;
                        case 'yes-no':
                            if( $rootMessages &&
                                isset($rootMessages->children)
                            ) {
                                $rootMessagess = collect($rootMessages['children']);

                                foreach($rootMessagess as $rM) {
                                    if($rM->childMessage->order == 'yes') {
                                        $RM_yes_id = $rM->childMessage->id;
                                    }
                                    if($rM->childMessage->order == 'no') {
                                        $RM_no_id = $rM->childMessage->id;
                                    }
                                }
                                $data = json_encode(
                                    [
                                        ['title' => 'Yes', 'id' => isset($RM_yes_id)?$RM_yes_id:null ],
                                        ['title' => 'No', 'id' => isset($RM_no_id)?$RM_no_id:null ]
                                    ]
                                );
                            }
                            break;
                        case 'ok-notThisTime':
                            if ($rootMessages->action == 'no-issue') {
                                $setMessage = "No Issues";
                                $bgColor = 'great';
                                $provider_informed1 = 0;

                                $GfeAidaNotification = GfeAidaNotification::where('id', $this->gfe_aida_notification_id)
                                ->first();
    
                                if($GfeAidaNotification) {
                                    $GfeAidaNotification->update(
                                        [
                                            'provider_informed' => $provider_informed1,
                                            'followup_status' => $setMessage,
                                            'bg_color' => $bgColor
                                        ]
                                    );
                                }
                            }

                            if( $rootMessages &&
                                isset($rootMessages->children)
                            ) {
                                $rootMessagess = collect($rootMessages['children']);

                                foreach($rootMessagess as $rM) {
                                    if($rM->childMessage->order == 'ok') {
                                        $RM_ok_id = $rM->childMessage->id;
                                    }
                                    if($rM->childMessage->order == 'notthistime') {
                                        $RM_nTT_id = $rM->childMessage->id;
                                    }
                                }
                                $data = json_encode(
                                    [
                                        ['title' => 'Okay', 'id' => ($RM_ok_id)? $RM_ok_id :null],
                                        ['title' => 'Not At This Time', 'id' => ($RM_nTT_id)?$RM_nTT_id:null]
                                    ]
                                );
                            }
                            break;
                        case 'low-mid-high':
                            $rootMessagess = collect($rootMessages['children']);
                            if($rootMessages->multi_option) {
                                $arr = []; $i = 0;
                                foreach($rootMessagess as $rM) {
                                    if($rM->childMessage->order == 'low') {
                                        $arr[$i]['id'] = $rM->childMessage->id;
                                        $arr[$i]['order'] = $rM->childMessage->order;
                                        $arr[$i]['title'] = $rM->childMessage->message;
                                    }
                                    if($rM->childMessage->order == 'mid') {
                                        $arr[$i]['id'] = $rM->childMessage->id;
                                        $arr[$i]['order'] = $rM->childMessage->order;
                                        $arr[$i]['title'] = $rM->childMessage->message;
                                    }
                                    if($rM->childMessage->order == 'high') {
                                        $arr[$i]['id'] = $rM->childMessage->id;
                                        $arr[$i]['order'] = $rM->childMessage->order;
                                        $arr[$i]['title'] = $rM->childMessage->message;
                                    }
                                    $i++;
                                }
                                $data = json_encode($arr);
                            }
                            break;
                    }

                    $responseBack['new_flow'] = 1;
                    $responseBack['wait'] = $waitscreen;
                    $responseBack['button_title'] = $rootMessages->button_title;
                    $responseBack['new_msg'] = $rootMessages;
                    $responseBack['response_msg'] = isset($modifiedStringChk)?$modifiedStringChk:$modifiedString;
                    $responseBack['option'] = json_decode($data, true);
                break;
            case "old-flow" :

                    if($this->flow_msg_id && $this->flow_msg_id != null) {
                        $flow_msg_id = $this->flow_msg_id;
                    } else {
                        $rootMessages = FlowMessage::select(
                            'id',
                            'flow_id',
                            'message',
                            'direction',
                            'order',
                            'type',
                            'multi_option',
                            'button_title'
                        )->with('children', 'children.childMessage')->where(function($q) {
                            $q->whereHas('product', function($q1) {
                                return $q1->where('product_id', 35);
                            })->whereHas('product.flow', function($q3) {
                                return $q3->where('after', 'Default');
                            });
                        })->doesntHave('parent')
                        ->first();

                        if(!$rootMessages) {
                            $nt = new NotFound();
                            $nt->name = 'Received';
                            $nt->error = 'FlowMessage object not found! | schedule_type !';
                            $this->conn->send($nt);
                            return;
                        }

                        $flow_msg_id =  $rootMessages->id;
                    }

                    $rootMessages = FlowMessage::with('children.childMessage')
                    ->where('id', $flow_msg_id)
                    ->first();

                    if(!$rootMessages) {
                        $nt = new NotFound();
                        $nt->name = 'Received';
                        $nt->error = 'FlowMessage object not found!';
                        $this->conn->send($nt);
                        return;
                    }

                    $user = User::select('id', 'fname', 'mname', 'lname', 'email')->where('id', $this->conn->user->id)->first();
                    $provider = User::where('id', $gfe->assigned_to_user_id)->first();
                    $aidaInfo = GfeAidaNotification::where('id', $this->gfe_aida_notification_id)->first();
                    $product_name = Product::where('id', $this->product_id)->value('title');
                    $product_info_link = TreatmentInformation::where('product_id', $this->product_id)->value('instruction_link');
                    if($product_info_link) {
                        $product_info_link = '<a href="'.$product_info_link.'">' .  $product_info_link . '</a>';
                    } else {
                        $product_info_link = '(Link not available)';
                    }

                    $find = ["<user_name>", "<product_name>", "<provider_name>", "<product_info_link>"];
                    $replace = [(ucfirst($user->fname)), $product_name, (ucfirst($provider->fname)), $product_info_link];
                    $modifiedString = str_replace($find, $replace, $rootMessages->message);

                    $rootMessagesChildOption = FlowMessage::select(
                        'id',
                        'flow_id',
                        'message',
                        'direction',
                        'order',
                        'type',
                        'multi_option',
                        'button_title'
                    )->with(['childrenOption', 'childrenOption.childMessageOption'])
                    ->where(function($q1) use($flow_msg_id) {
                        return $q1->where('multi_option', 1)
                        ->where('id', $flow_msg_id );
                    })->first();

                    $multi =(!empty($rootMessagesChildOption->childrenOption))?$rootMessagesChildOption->childrenOption:null;

                    switch ($rootMessages->type) {
                        case 'ok':
                            if( $rootMessages && isset($rootMessages->children) ) {
                                $rootMessagess = collect($rootMessages['children']);

                                if(isset($rootMessages['children'])) {
                                    foreach($rootMessagess as $rM) {
                                        $RM_ok_id = $rM->childMessage->id;
                                    }
                                    if($rootMessages->message == 'Upload Photo') {
                                        $data = json_encode([
                                            [
                                                'title' => 'Ok',
                                                'id' => isset($RM_ok_id) ? $RM_ok_id : null,
                                                'message' => [
                                                    [
                                                        'title' => 'Upload from gallery',
                                                        'id' => isset($RM_ok_id) ? $RM_ok_id : null
                                                    ],
                                                    [
                                                        'title' => 'Upload from camera',
                                                        'id' => isset($RM_ok_id) ? $RM_ok_id : null
                                                    ]
                                                ]
                                            ]
                                        ]);
                                    } else {
                                        $data = json_encode(
                                            [
                                                [
                                                    'title' => 'Ok',
                                                    'id' => isset($RM_ok_id) ? $RM_ok_id : null
                                                ]
                                            ]
                                        );
                                    }
                                }

                                if($rootMessages->multi_option) {
                                    $arr = []; $i = 0;
                                    foreach($rootMessagess as $rM) {
                                        if($rM->childMessage->order == 'ok') {
                                            $arr[$i]['id'] = $rM->childMessage->id;
                                            $waitscreen = 1;

                                            $find = ["<user_name>", "<product_name>", "<provider_name>", "<product_info_link>", "<calender_link>"];
                                            $calender_link = (UserDetail::where('user_id', $gfe->assigned_to_user_id)
                                            ->value('schedule_calendar')) ? "Thank you for scheduling a meeting with a provider. After you click on calendar link your AIDA session will get closed." . "<a href='".$calender_link."'>". $calender_link . "</a>" : "Provider doesn't have any calendar to schedule appointments. Please get in touch with your provider.";

                                            $product_info_link = (TreatmentInformation::where('product_id', $this->product_id)
                                            ->value('instruction_link')) ? '<a href="'.$product_info_link.'">' .  $product_info_link . '</a>' : '(Link not available)';

                                            $replace = [(ucfirst($user->fname)), $product_name, (ucfirst($provider->fname)), $product_info_link, $calender_link];
                                            $newmodifiedString = str_replace($find, $replace, $rM->childMessage->message);
                                            $arr[$i]['title'] = $newmodifiedString;
                                        }
                                        $i++;
                                    }
                                    $data = json_encode($arr);
                                }

                                if($rootMessages->direction == 'up') {
                                    $triggerPushMsg = false;
                                    switch ($rootMessages->message) {
                                        case "I'm experiencing some mild discomfort.":
                                            $triggerPushMsg = true;
                                            $setMessage = "Some Issues";
                                            $bgColor = 'warning';
                                            $provider_informed1 = 1;
                                        case "I have some moderate pain and tenderness at the treatment areas.":
                                            $triggerPushMsg = true;
                                            $setMessage = "Some Issues";
                                            $bgColor = 'warning';
                                            $provider_informed1 = 1;
                                        case "I'm experiencing significant discomfort and pain.":
                                            $triggerPushMsg = true;
                                            $setMessage = "Emergency";
                                            $bgColor = 'danger';
                                            $provider_informed1 = 1;
                                            break;
                                        default:
                                            $setMessage = "Some Issues";
                                            $bgColor = 'warning';
                                            $provider_informed1 = 0;
                                            break;
                                    }

                                    if($triggerPushMsg) {
                                        $msg = $rootMessages->message;
                                        if( $provider && $provider->country_code && $provider->phone ) {
                                            $smsService = new SmsService();
                                            $sms = $smsService->send(
                                                $provider->country_code.$provider->phone,
                                                "Patient : " . ucfirst($user->fname) . "\n" . "Email : " . $user->email . "\n" . "Message" . "\n" . $msg
                                            );
                                        }

                                        $notification = Notification::create(
                                            [
                                                'from_id' => $user->id,
                                                'to_id' => $provider->id,
                                                'module' => 'AIDA',
                                                'module_id' => null,
                                                'type' => 'AIDA_MESSAGE',
                                                'title' => ucfirst($user->fname) . ' facing issue',
                                                'body' => $msg
                                            ]
                                        );

                                        if($provider && $provider->device_token) {
                                            $push = Larafirebase::withTitle(ucfirst($user->fname) . ' facing issue')
                                            ->withBody($msg)
                                            ->withSound('default')
                                            ->withPriority('high')
                                            ->withAdditionalData([
                                                'patient_id' => $user->id,
                                                'email' => $user->email,
                                                'notification_id' => $notification->id,
                                                'tr_crad_id' => $gfe->id
                                            ])
                                            ->sendNotification($provider->device_token);
                                        }
                                    }

                                    if ($rootMessages->action == 'no-issue') {
                                        $setMessage = "No Issues";
                                        $bgColor = 'great';
                                        $provider_informed1 = 0;
                                    }

                                    $GfeAidaNotification = GfeAidaNotification::where('id', $this->gfe_aida_notification_id)
                                    ->first();

                                    if($GfeAidaNotification) {
                                        $GfeAidaNotification->update(
                                            [
                                                'provider_informed' => $provider_informed1,
                                                'followup_status' => $setMessage,
                                                'bg_color' => $bgColor
                                            ]
                                        );
                                    }

                                    $modifiedStringChk = null;
                                    foreach($rootMessagess as $rM) {

                                        $find = ["<user_name>", "<product_name>", "<provider_name>", "<product_info_link>, <calender_link>"];
                                        $product_info_link = TreatmentInformation::where('product_id', $this->product_id)->value('instruction_link');
                                        $calender_link = (UserDetail::where('user_id', $gfe->assigned_to_user_id)
                                        ->value('schedule_calendar')) ? "Thank you for scheduling a meeting with a provider. After you click on calendar link your AIDA session will get closed." . "<a href='".$calender_link."'>". $calender_link . "</a>" : "Provider doesn't have any calendar to schedule appointments. Please get in touch with your provider.";

                                        $product_info_link = (TreatmentInformation::where('product_id', $this->product_id)
                                        ->value('instruction_link')) ? '<a href="'.$product_info_link.'">' .  $product_info_link . '</a>' : '(Link not available)';

                                        $replace = [(ucfirst($user->fname)), $product_name, (ucfirst($provider->fname)), $product_info_link, $calender_link];
                                        $newmodifiedString_new = str_replace($find, $replace, $rM->childMessage->message);

                                        $modifiedStringChk = $newmodifiedString_new;

                                        $rootMessagesChildOptionChild = FlowMessage::with(['childrenOption', 'childrenOption.childMessageOption'])
                                        ->where(function($q1) use($rM) {
                                            return $q1->where('id', $rM->childMessage->id );
                                        })->first();

                                        $rootMessagesChildOptionChilds = collect($rootMessagesChildOptionChild['children']);
                                        $arr1 = []; $ii = 0;
                                        foreach($rootMessagesChildOptionChilds as $rMChild) {
                                            switch ($rMChild->childMessage->order) {
                                                case 'ok':
                                                    $atitle = 'Okay';
                                                    break;
                                                case 'yes':
                                                    $atitle =  'Yes';
                                                    break;
                                                case 'no':
                                                    $atitle =  'No';
                                                    break;
                                            }
                                            $arr1[$ii]['id'] = $rMChild->childMessage->id;
                                            $arr1[$ii]['title'] = isset($atitle)?$atitle:$rMChild->childMessage->order;
                                            $ii++;
                                        }
                                        $collection = collect($arr1);

                                        // Use the unique method to remove duplicates based on both 'id' and 'title'
                                        $uniqueData = $collection->unique(function ($item) {
                                            return $item['id'] . '|' . $item['title'];
                                        })->values()->all();

                                        $data = json_encode($uniqueData);
                                    }
                                }
                            }

                            if( $rootMessages &&
                                isset($rootMessages->children) &&
                                count($rootMessages->children) > 1
                            ) {
                                $ar = []; $i2 = 0;
                                foreach($rootMessages->children as $child) {
                                    if($child->childMessage->status == true || $child->childMessage->status == 1) {
                                        $ar[$i2]['id'] = $child->childMessage->id;

                                        $find = ["<user_name>", "<product_name>", "<provider_name>", "<product_info_link>, <calender_link>"];
                                        $calender_link = (UserDetail::where('user_id', $gfe->assigned_to_user_id)
                                        ->value('schedule_calendar')) ? "Thank you for scheduling a meeting with a provider. After you click on calendar link your AIDA session will get closed." . "<a href='".$calender_link."'>". $calender_link . "</a>" : "Provider doesn't have any calendar to schedule appointments. Please get in touch with your provider.";

                                        $product_info_link = (TreatmentInformation::where('product_id', $this->product_id)
                                        ->value('instruction_link')) ? '<a href="'.$product_info_link.'">' .  $product_info_link . '</a>' : '(Link not available)';
                                        $replace = [(ucfirst($user->fname)), $product_name, (ucfirst($provider->fname)), $product_info_link, $calender_link];
                                        $newmodifiedString = str_replace($find, $replace, $child->childMessage->message);
    
                                        $ar[$i2]['title'] = $newmodifiedString;
                                        $i2++;
                                    }
                                }
                                $data = json_encode($ar);
                                break;
                            }

                            if($rootMessages->button_title == 'Proceed') { //"Close"
                                $data = json_encode(
                                    [
                                        [
                                            'id' => 1075, // Multi options list id
                                            'title' => 'I need something else'
                                        ],
                                        [
                                            'id' => null,
                                            'title' => 'Close Session'
                                        ]
                                    ]
                                );

                                if($rootMessages->message == "Tell me something else in your own words") {
                                    $provider = User::where('id', $gfe->assigned_to_user_id)
                                    ->first();

                                    $user = User::select('id', 'fname', 'mname', 'lname', 'email')
                                    ->where('id', $this->conn->user->id)
                                    ->first();

                                    if( $provider && $provider->country_code && $provider->phone ) {
                                        $smsService = new SmsService();
                                        $sms = $smsService->send(
                                            $provider->country_code.$provider->phone,
                                            "Patient : " . ucfirst($user->fname) . "\n" . "Email : " . $user->email . "\n" . "Message" . "\n" . $this->message
                                        );
                                    }

                                    $notification = Notification::create(
                                        [
                                            'from_id' => $user->id,
                                            'to_id' => $provider->id,
                                            'module' => 'AIDA',
                                            'module_id' => null,
                                            'type' => 'AIDA_MESSAGE',
                                            'title' => ucfirst($user->fname) . ' facing issue',
                                            'body' => $this->message
                                        ]
                                    );

                                    $device_token = $provider->device_token;
                                    if($device_token) {
                                        $push = Larafirebase::withTitle(ucfirst($user->fname) . ' facing issue')
                                        ->withBody($this->message)
                                        ->withSound('default')
                                        ->withPriority('high')
                                        ->withAdditionalData(
                                            [
                                                'patient_id' => $user->id,
                                                'email' => $user->email,
                                                'notification_id' => $notification->id,
                                                'tr_crad_id' => $gfe->id
                                            ]
                                        )
                                        ->sendNotification($device_token);
                                    }

                                    $GfeAidaNotification = GfeAidaNotification::with('aidaSchedule')
                                    ->where('id', $this->gfe_aida_notification_id)
                                    ->first();

                                    if($GfeAidaNotification) {
                                        $GfeAidaNotification->update(
                                            [
                                                'provider_informed' => 1,
                                                'followup_status' => "Some Issues",
                                                'bg_color' => 'warning'
                                            ]
                                        );
                                    }
                                }
                                break;
                            } elseif( $rootMessages->message == "<user_treatment_info>") {
                                $difference = $aidaInfo->created_at->diffInDays(now(), false);

                                if($difference > 0 && $difference <= 1) {
                                    $modifiedString = "Hi " . ucfirst($user->fname) . ", we wanted to check in with you after your " . Product::where('id', $this->product_id)->value('title') . " treatment " . "yesterday?";
                                } elseif( $difference > 1 && $difference < 8 ){
                                    $modifiedString =  "Hi " . ucfirst($user->fname) . ", it's been a week since your " . Product::where('id', $this->product_id)->value('title') . " treatment. I wanted to see how things are progressing";
                                } elseif($difference > 7 && $difference < 15){
                                    $todate = "2 weeks";
                                    $modifiedString =  "Hi " . ucfirst($user->fname) . ", it's been about " . $todate. " since your " . Product::where('id', $this->product_id)->value('title') . " treatment. I wanted to see how things are progressing";
                                } elseif($difference > 14 && $difference < 22){
                                    $todate = "3 weeks";
                                    $modifiedString = "Hi " . ucfirst($user->fname) . ", it's been about " . $todate. " since your " . Product::where('id', $this->product_id)->value('title') . " treatment. I wanted to see how things are progressing";
                                } elseif($difference > 21 && $difference < 31){
                                    $todate = "4 weeks";
                                    $modifiedString = "Hi " . ucfirst($user->fname) . ", it's been about " . $todate. " since your " . Product::where('id', $this->product_id)->value('title') . " treatment. I wanted to see how things are progressing";
                                } else {
                                    $todate = "more than 4 weeks";
                                    $modifiedString = "Hi " . ucfirst($user->fname) . ", it's been about " . $todate. " since your " . Product::where('id', $this->product_id)->value('title') . " treatment. I wanted to see how things are progressing";
                                }
                                break;
                            } elseif($rootMessages->message == "Which option best describes your symptoms?") {
                                $symtom_opt = ServiceSymptom::where('service_product_id', $this->product_id)
                                ->select('id', 'service_product_id', 'symptoms_id')
                                ->with('symptom:id,title')
                                ->get();

                                $data = json_decode($data, true);

                                $arr = []; $i = 0;
                                foreach($symtom_opt as $so) {
                                    $arr[$i]['symtom_id'] = $so->symptom[0]->id;
                                    $arr[$i]['id'] = $data[0]['id'];
                                    $arr[$i]['title'] = $so->symptom[0]->title;
                                    $i++;
                                }

                                if(count($arr) > 0) {
                                } else {
                                    $nt = new NotFound();
                                    $nt->name = 'Received';
                                    $this->conn->send($nt);
                                    return;
                                }

                                $data = null;
                                $data = $arr;
                                $data = json_encode($data, true);
                                break;
                            } elseif ($rootMessages->message == "What is the degree of <symptom> on a scale of 1 to 10?") {
                                $newmessage = "What is the degree of " . $this->symptoms_title . " on a scale of 1 to 10?";
                                $modifiedString = null;
                                $modifiedString = $newmessage;
                            } elseif ($rootMessages->message == "Perfect! If it's convenient, please take a moment to upload a photo of the treatment area. It will be held confidential and more importantly, it will help me further evaluate your progress and provide any additional feedback in case I notice something that you may have not.") {
                                $sym_title = $this->symptoms_title;

                                $symtom_opt = ServiceSymptom::where(['service_product_id' => $this->product_id])
                                ->whereHas('symptom', function($e) use($sym_title) {
                                    return $e->where('title', 'like', '%'.$sym_title.'%');
                                })->first();

                                if(!$symtom_opt) {
                                    $nt = new NotFound();
                                    $nt->name = 'Received';
                                    $this->conn->send($nt);
                                    return;
                                }

                                SeverityRanges::create(
                                    [
                                        'user_id' => $this->conn->user->id,
                                        'service_symptom_id' => $symtom_opt->id,
                                        'range' => $this->message
                                    ]
                                );
                                break;
                            }
                            break;
                        case 'yes-no':
                            if( $rootMessages &&
                                isset($rootMessages->children)
                            ) {
                                $rootMessagess = collect($rootMessages['children']);

                                foreach($rootMessagess as $rM) {
                                    if($rM->childMessage->order == 'yes') {
                                        $RM_yes_id = $rM->childMessage->id;
                                    }
                                    if($rM->childMessage->order == 'no') {
                                        $RM_no_id = $rM->childMessage->id;
                                    }
                                }
                                $data = json_encode(
                                    [
                                        ['title' => 'Yes', 'id' => isset($RM_yes_id)?$RM_yes_id:null ],
                                        ['title' => 'No', 'id' => isset($RM_no_id)?$RM_no_id:null ]
                                    ]
                                );
                            }
                            break;
                        case 'yes-notThisTime':
                            if( $rootMessages &&
                                isset($rootMessages->children)
                            ) {
                                $rootMessagess = collect($rootMessages['children']);

                                foreach($rootMessagess as $rM) {
                                    if($rM->childMessage->order == 'yes') {
                                        $RM_yes_id = $rM->childMessage->id;
                                    }
                                    if($rM->childMessage->order == 'notThisTime') {
                                        $subchild = FlowMessage::select(
                                            'id',
                                            'flow_id',
                                            'message',
                                            'direction',
                                            'order',
                                            'type',
                                            'multi_option',
                                            'button_title'
                                        )->with(['children', 'children.childMessage'])
                                        ->where(function($q1) use($rM) {
                                            return $q1->where('id', $rM->childMessage->id);
                                        })->first();

                                        $RM_no_id = isset($subchild->children[0]->message_id)?$subchild->children[0]->message_id:null;
                                    }
                                }
                                $data = json_encode(
                                    [
                                        ['title' => 'Upload from camera', 'id' => isset($RM_yes_id)?$RM_yes_id:null ],
                                        ['title' => 'Not Right Now', 'id' => isset($RM_no_id)?$RM_no_id:null ]
                                    ]
                                );
                            }
                            break;
                        case 'escalate':
                            $data = json_encode(
                                [
                                    [
                                        'id' => 1104, //Escalate Further Options List IDs
                                        'title' => "I need something else"
                                    ],
                                    [
                                        'id' => null,
                                        'title' => "Close Session"
                                    ]
                                ]
                            );
                            break;
                    }

                    // Change response titile if any left
                    // $find = ["<user_name>", "<product_name>", "<provider_name>", "<product_info_link>", "<calender_link>"];
                    // $calender_link = (UserDetail::where('user_id', $gfe->assigned_to_user_id)
                    // ->value('schedule_calendar')) ? "Thank you for scheduling a meeting with a provider. After you click on calendar link your AIDA session will get closed." . "<a href='".$calender_link."'>". $calender_link . "</a>" : "Provider doesn't have any calendar to schedule appointments. Please get in touch with your provider.";

                    // $product_info_link = (TreatmentInformation::where('product_id', $this->product_id)
                    // ->value('instruction_link')) ? '<a href="'.$product_info_link.'">' .  $product_info_link . '</a>' : '(Link not available)';

                    // $replace = [(ucfirst($user->fname)), $product_name, (ucfirst($provider->fname)), $product_info_link, $calender_link];
                    // $modifiedString = str_replace($find, $replace, $rM->childMessage->message);

                    $responseBack['old_flow'] = 1;
                    $responseBack['button_title'] = $rootMessages->button_title;
                    $responseBack['new_msg'] = $rootMessages;
                    $responseBack['response_msg'] = isset($modifiedStringChk)?$modifiedStringChk:$modifiedString;
                    $responseBack['option'] = json_decode((isset($data)?$data:null), true);
                break;
        }

        if($this->type == 'photo' && $this->message) {
            TrCardReceipt::create(
                [
                    'type' => 'aida',
                    'receipt' => $this->message,
                    'tr_card_id' => $this->tr_card_id
                ]
            );
        }

        //Notify Other User
        $responseBack['created_at'] = now();
        $responseBack['message_id'] = $this->message_id;
        $e = new self($responseBack);
        $e->name = 'Received';
        $this->controller->sendToUser($this->conn->user->id, $e);

        if($sender_participations) {
            ChatMessages::create(
                [
                    'chat_conversation_id' => $conversation->id,
                    'chat_participation_id' => $sender_participations->id,
                    'body' => $es, //$this->message,
                    'type' => $this->type,
                    'thumbnail' => $this->thumbnail,
                    'size' => $this->size,
                    'length' => $this->length
                ]
            );
        }

        if($receiver_participations) {
            sleep(1);
            ChatMessages::create(
                [
                    'chat_conversation_id' => $conversation->id,
                    'chat_participation_id' => $receiver_participations->id,
                    'body' => $e
                ]
            );
        }
    }
}