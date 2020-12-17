<?php
//学校的体温填报是真的傻逼，盯着查的导员也是
//如果点进来的是学校相关领导，还是赶紧滚蛋吧！
const SCKEY				=	"";					/// 微信推送, 去 http://sc.ftqq.com/ 登录申请一下就行

$users = [
	""     =>  "",	// 一行一个, 前账号后密码(身份证后十位)
];

echo("<pre>");
foreach($users as $user => $pass) {
	(new SurveyPost($user, $pass, SCKEY))->start();
}
echo("</pre>");


class SurveyPost {
	
	private $config = [
			'login_url'		=> 'http://xscfw.hebust.edu.cn/survey/login/ajaxLogin',
			'post_url'		=> 'http://xscfw.hebust.edu.cn/survey/surveySave?timestamp=',
			
			'main_url'		=> 'http://xscfw.hebust.edu.cn/survey/index',
			'survey_url'	=> 'http://xscfw.hebust.edu.cn/survey/surveyEdit?id='
	];
	
	public $cookie = '';
	
	public function __construct($username, $passwd, $sckey = '') {
		$this->config['user']       = $username;
		$this->config['passwd']     = $passwd;
		$this->config['sckey']      = $sckey;
		
		$this->config['timestamp']  = time()."000";
		$this->config['post_url']  .= $this->config['timestamp'];
	}
	
	public function login() {
		$re = $this->post_curl($this->config['login_url'], [
					'stuNum' => $this->config['user'],
					'pwd' => $this->config['passwd']
		], true);
		
		if(!$re) {
			throw new \Exception("站挂了, 等下再提交吧...");
		}
		
		$data = null;
		if(preg_match('/\{(.*)\}/i', $re, $matchs) >= 1){
		    $data = $matchs[0];
		}
		if(json_decode($data, true)['data'] !== true) {
			throw new \Exception("登录错误, 可能是密码错了: ".$data);
		}
		
		preg_match('/Set-Cookie:(.*);/iU', $re, $str); 
		$this->cookie = $str[1];
	}
	
	private function input_value($content, $key) {
		$ret = preg_match('/name=\"'.$key.'\" value=\"(.*)\"/i', $content, $matchs);
		if($ret >= 1){
			return $matchs[1];
		}
		return null;
	}
	
	public function execute($sid) {
		$re = $this->post_curl($this->config['survey_url'] . $sid);
		
	    $data = [
	        'timestamp'	=> $this->config['timestamp'],
			'id'		=> $sid,
			'stuId'		=> $this->input_value($re, "stuId"),
			'qid'		=> ''
		]; // 这些内容都一样, 不会变
	    
		$ret = preg_match('/var formJson = \[(.*)\];/i', $re, $matchs);
		if($ret >= 1){
		    $jsonData = json_decode('['.$matchs[1].']', true);

            foreach ($jsonData as $obj) {
                $value = null;
                if(strcmp($obj['type'], "单选框") == 0 && !empty($obj['options'])) {
                    foreach ($obj['options'] as $option) {
                        if(strcmp($option['checked'], "true") == 0) {
                            $value = $option['value'];
                            break;
                        }
                    }
                } elseif(strpos($obj['title'], "正常")) {
                    $value = '36.'.rand(1,9);
                }
    
                if(!empty($value)) {
                    $data[$obj['name']] = $value;
                }
            }
        }else {
            throw new \Exception("无法获取到配置信息");
        }

		$this->post_curl($this->config['post_url'], $data);
	}
	
	public function send_to_wechat($content) {
		if(empty($this->config['sckey'])){
			return;
		}
		
		$this->post_curl('https://sc.ftqq.com/'.$this->config['sckey'].'.send',[
			'text' 		=> $content
		]);
	}
	
	public function query_sids() {
		$re = $this->post_curl($this->config['main_url']);
		
		$ret = preg_match_all('/sid=\"(.*)\"(.*)right\">未完成<\/span>/iUs', $re, $sids);
		if($ret >= 1){
			return $sids[1];
		}
		return null;
	}
	
	function post_curl($url, $params = [], $isShowHeader = false) {
		$ch = curl_init();
		
		curl_setopt($ch, CURLOPT_HEADER, $isShowHeader);
		curl_setopt($ch, CURLOPT_HTTP_VERSION , CURL_HTTP_VERSION_1_1);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 ReactOS like (Windows NT 5.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) WebBrowser/0.12 Safari/537.36');
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
		curl_setopt($ch, CURLOPT_URL, $url);
		
		$response = curl_exec($ch);
		if (!$response || curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
			return false;
		}
		
		curl_close($ch);
		return $response;
	}
	
	public function start() {
		try {
			$this->login();
			if(!$this->cookie) {
				throw new \Exception("登录失败");
			}
			
			$sids = $this->query_sids();
			if(empty($sids)) {
				throw new \Exception("找不到未提交的体温统计表, 可能是还没发布?");
			}
			// print_r($sids);
			
			foreach ($sids as $sid) {
				$this->execute($sid);
			}
			
			$sids = $this->query_sids();
	    	if(empty($sids)) {
	    	    throw new \Exception("今日体温已填报");
	    	}
		}catch(\Exception $e) {
		    $this->send_to_wechat($this->config['user'].$e->getMessage());
			print($e->getMessage());
		}
	}
}