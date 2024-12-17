<?php

namespace App\Http\Websocket\Events;

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
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AidaAuthorize extends Event
{

    public $event = "connected";

    private function isAccessTokenRevoked($tokenId)
    {
        return DB::table('oauth_access_tokens')->where('id', $tokenId)->where('revoked', 1)->exists();
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

    protected function handle() {

        echo "_Aida Auth_" . PHP_EOL;
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

        $user['response_msg'] = ("<div><h5>Hello, I'm AIDA...</h5><p>I am here to provide you follow-up support after your treatment.</p></div>");
        $user['option'] = ([["title" => "Okay, Proceed", "action" => "start"]]);

        $this->controller->mapConnection($user->id, $this->conn->resourceId);
        $this->conn->authorized = true;
        $this->conn->user = $user;
        $this->conn->send(new Authorized($user->toArray()));
    }
}
