<?php

namespace App\Http\Websocket\Events;

use DB;
// use Lcobucci\JWT\Parser;
// use Lcobucci\JWT\Signer\Rsa\Sha256;
// use Lcobucci\JWT\ValidationData;
use DateTimeImmutable;
use Lcobucci\JWT\Encoding\CannotDecodeContent;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\InvalidTokenStructure;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\Token\UnsupportedHeaderFound;
use Lcobucci\JWT\UnencryptedToken;
use Laravel\Passport\Passport;
use App\Models\ExaminerLicenseState;
use App\Models\Gfe;
use App\Models\User;

class Authorize extends Event
{

    public $event = "connected";

    private function isAccessTokenRevoked($tokenId)
    {
        return DB::table('oauth_access_tokens')
                    ->where('id', $tokenId)->where('revoked', 1)->exists();
    }

    private function validateToken($jwt)
    {
        $parser = new Parser(new JoseEncoder());

        try {
            $token = $parser->parse($jwt);

            if ($this->isAccessTokenRevoked($token->claims()->get('jti'))) {
                // throw OAuthServerException::accessDenied('Access token has been revoked');
                return false;
            }

            assert($token instanceof UnencryptedToken);

            $now = strtotime(gmdate('Y-m-d H:i:s')); //current timestamp
            $tokenExpDate = strtotime(
                $token->claims()->get(RegisteredClaims::EXPIRATION_TIME)->format(DateTimeImmutable::RFC3339)
            );

            if ($now >= $tokenExpDate) {
                // Expired;
                return false;
            }
        } catch (CannotDecodeContent | InvalidTokenStructure | UnsupportedHeaderFound $e) {
            echo 'Oh no, an error: ' . $e->getMessage();

            return false;
        }

        return $token->claims()->get('sub');
    }

    protected function handle()
    {
        if ($this->token) {
            $uid = $this->validateToken($this->token);
        } else {
            $this->conn->send(new UnAuthorize());
            return;
        }
        $user = User::find($uid);
        if (!$user) {
            $this->conn->send(new UnAuthorize());
            return;
        }

        $this->controller->mapConnection($user->id, $this->conn->resourceId);
        $this->conn->authorized = true;
        $this->conn->user = $user;
        $this->conn->send(new Authorized($user->toArray()));

        if ($user->role_id == 8) {
            $gfesStaff = Gfe::select('gfes.id', 'assigned_to_user_id')->leftJoin(
                'states',
                'states.name',
                '=',
                'gfes.state'
            )->where('approval_status', 'pending')->where('channel_id', 1)->groupBy('gfes.id', 'assigned_to_user_id')->whereIn(
                'states.id',
                ExaminerLicenseState::where('examiner_user_id', $user->id)->pluck('state_id')->toArray()
            )->get();

            if (isset($gfesStaff) && count($gfesStaff)) {

                foreach ($gfesStaff as $key => $value) {
                    $eea = new ExaminerAvailable(['id' => $value->id]);
                    $eea->name = 'Received';
                    $this->controller->sendToUser($value->assigned_to_user_id, $eea);
                }
            }

            $gfesPatient = Gfe::select('gfes.id', 'patient_user_id')->leftJoin(
                'states',
                'states.name',
                '=',
                'gfes.state'
            )->where('approval_status', 'pending')->whereIn('channel_id', [2, 3, 5])->groupBy('gfes.id', 'patient_user_id')->whereIn(
                'states.id',
                ExaminerLicenseState::where('examiner_user_id', $user->id)->pluck('state_id')->toArray()
            )->get();

            if (isset($gfesPatient) && count($gfesPatient)) {

                foreach ($gfesPatient as $key => $value) {
                    $eea = new ExaminerAvailable(['id' => $value->id]);
                    $eea->name = 'Received';
                    $this->controller->sendToUser($value->patient_user_id, $eea);
                }
            }
        }
    }
}
