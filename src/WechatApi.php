<?php
namespace Hulucat\WechatMch;

use GuzzleHttp\Client as HttpClient;
use Cache;
use Log;

class WechatApi{
	protected $http;
    private $appId = null;
    private $secret = null;
	private $token = null;
	public function __construct(HttpClient $hc){
		$this->http = $hc;
        $this->token = config('wechat_mch.token');
        $this->appId = config('wechat_mch.app_id');
        $this->secret = config('wechat_mch.secret');
	}

    public function getAccessToken(){
        $cacheKey = 'WECHAT_MCH_ACCESS_TOKEN';
        $at = Cache::get($cacheKey);
        if($at){
            return $at;
        }else{
            $body = $this->httpGet('https://api.weixin.qq.com/cgi-bin/token', [
                'grant_type' => 'client_credential',
                'appid' => $this->appId,
                'secret'    => $this->secret,
            ]);
            $rt = json_decode($body);
            if(property_exists($rt, 'access_token')){
                $at = $rt->access_token;
                Cache::put($cacheKey, $at, 100);
                return $at;
            }else{
                return null;
            }
        }
    }

    public function parseMsg($postStr){
        Log::debug("WechatMch parse row string: $postStr");
        if(!$postStr){
            return null;
        }
        libxml_disable_entity_loader(true);
        $postObj = simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);
        $from = $postObj->FromUserName;
        $to = $postObj->ToUserName;
        $content = trim($postObj->Content);
        $type = $postObj->MsgType;
        $rt = ['content'=>$content, 'type'=>$type, 'from'=>$from, 'to'=>$to];
        return $rt;
    }

    public function replyTextMsg($from, $to, $content){
        $textTpl = "<xml>
                        <FromUserName><![CDATA[%s]]></FromUserName>
                        <ToUserName><![CDATA[%s]]></ToUserName>
                        <CreateTime>%s</CreateTime>
                        <MsgType><![CDATA[%s]]></MsgType>
                        <Content><![CDATA[%s]]></Content>
                        <FuncFlag>0</FuncFlag>
                    </xml>";
        return sprintf($textTpl, $from, $to, time(), 'text', $content);
    }

    public function checkSignature($signature, $timestamp, $nonce){
        $tmpArr = array($this->token, $timestamp, $nonce);
        sort($tmpArr, SORT_STRING);
        $tmpStr = implode( $tmpArr );
        $tmpStr = sha1( $tmpStr );
        if( $tmpStr == $signature ){
            return true;
        }else{
            return false;
        }
    }

	public function oauth2($backUrl){
		$redirectUri = array_key_exists('HTTPS', $_SERVER)?'https://':'http://';
		$redirectUri = urlencode($redirectUri.config('wechat_corp.app_host')."/corp/oauth2?back=$backUrl");
		$url='https://open.weixin.qq.com/connect/oauth2/authorize?appid=';
		$url .= config('wechat_corp.id');
		$url .= '&redirect_uri=';
		$url .= $redirectUri;
		$url .= '&response_type=code&scope=snsapi_base#wechat_redirect';
		header("Location: $url", true, 302);
	}

    /** 根据oauth2的code换取用户id
     * @param $code
     * @return null
     */
    public function getUserId($code){
		$body = $this->httpGet('https://qyapi.weixin.qq.com/cgi-bin/user/getuserinfo', [
			'access_token' => $this->getAccessToken(),
			'code' => $code,
		]);
		$rt = json_decode($body);
		if(property_exists($rt, 'UserId')){
			return $rt->UserId;
		}else{
			return null;
		}
	}

    /**根据userId获取用户信息
     * @param $userId
     */
    public function getUser($userId, $refresh=false){
        $user = null;
        $cacheKey = "wechat_corp_user_$userId";
        if($refresh){
            $user = $this->realGetUser($userId);
        }else{
            $user = Cache::get($cacheKey);
            if(!$user){
                $user = $this->realGetUser($userId);
            }else{
                Log::debug("Get corp user from cache", [
                    'userId'    => $userId,
                    'user'      => $user,
                ]);
            }
        }
        return $user;
    }

    private function realGetUser($userId){
        $body = $this->httpGet('https://qyapi.weixin.qq.com/cgi-bin/user/get', [
            'access_token' => $this->getAccessToken(),
            'userid' => $userId,
        ]);
        $user = json_decode($body);
        if($user->errcode==0){
            $cacheKey = "wechat_corp_user_$userId";
            Cache::put($cacheKey, $user, 60);
        }
        Log::debug("Real get user: ", [
            'userId'=>$userId,
            "user"=>$user,
        ]);
        return $user;
    }

	protected function httpGet($url, Array $query){
		\Log::debug("WechatMch get: ", [
			'Request: ' => $url,
			'Params: ' => $query,
		]);
		$response = $this->http->request('GET', $url, ['query' => $query]);
		\Log::debug('WechatMch:', [
				'Status' => $response->getStatusCode(),
				'Reason' => $response->getReasonPhrase(),
				'Headers' => $response->getHeaders(),
				'Body' => strval($response->getBody()),
		]);
		return $response->getBody();
	}

    protected function httpPost($url, $body){
        Log::debug("WechatMch post: ", [
            'Request: ' => $url,
            'body: ' => $body,
        ]);
        $response = $this->http->request('POST', $url, [
            'body'  => $body
        ]);
        Log::debug('WechatMch:', [
            'Status' => $response->getStatusCode(),
            'Reason' => $response->getReasonPhrase(),
            'Headers' => $response->getHeaders(),
            'Body' => strval($response->getBody()),
        ]);
    }
}
