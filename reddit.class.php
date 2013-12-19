<?php
	namespace reddit;
	//Reddit API

	class reddit {
	
		private $reddit = 'http://www.reddit.com/';
		private $api = 'http://www.reddit.com/api/';
		private $uh, $user, $pass, $cookie;
		private $cacheCookie = true;
		
		/**
		*	@param string	$user	username associated with Reddit account
		*	@param string	$pass	password associated with Reddit account
		*/
		function __construct($user = NULL,$pass = NULL) {
			
			if($user != NULL && $pass !== NULL) {
				$this->user = $user;
				$this->pass = $pass;
				
				$data = $this->curl($this->api."login/".$user,"user=".urlencode($user)."&passwd=".$pass."&api_type=json");
	
				if(is_array($data->json->errors) && !empty($data->json->errors)){
					echo $data->json->errors[0][0].' '.$data->json->errors[0][1].'<br/>';
				} else {
					//Success, we keep the session data for further use
					$this->uh = $data->json->data->modhash;
					$this->cookie = $data->json->data->cookie;
					
					if($this->cacheCookie) {
						//if true, we cache the cookie
						setcookie("Reddit-".$this->user, $this->cookie, time()+300);
					}
				}
			} else {
				echo 'Username or Password left empty';
			}
		}
		
		/*
		*	Changes an array into GET parameters
		*
		*	@param	array	$arr	An array that gets returned in GET parameter format
		*
		*	@return	object
		*/
		private function buildGETQuery($arr) {
			
			$string = "";
			$i = 0;
					
			foreach($arr as $key => $var) {
				$string .= (($i > 0) ? "&" : "")."$key=".urlencode($var);
				$i++;
			}
			return $string;
		}
		
		/**
		*	Used to comment on a "thing"
		*	
		*	@param	string	$thing		The thing id
		*	@param	string	$comment	The comment to be made
		*
		*	@return	object
		*/
		public function comment($thing,$comment) {
			return $this->curl($this->api."comment","thing_id=$thing&uh=$this->uh&text=".urlencode($comment),$this->cookie);
		}
		
		/**
		*	Dumps the class (used mostly for testing)
		*/
		public function dump() {
			print_r($this);
		}
		
		/**
		*	Edit text of a "thing"
		*
		*	@param string	$thing		The thing id
		*	@param string	$comment	The updated comment
		*
		*	@return object
		*/
		public function edit($thing,$comment) {
			return $this->curl($this->api."editusertext","thing_id=$thing&uh=$this->uh&text=".urlencode($comment),$this->cookie);
		}
		
		public function messages($type = "unread",$params = array()) {
			return $this->curl($this->reddit."message/$type.json?".$this->buildGETQuery($params),array(),$this->cookie);
		}
		
		public function messagesRead($thing_id) {
			return $this->curl($this->api."read_message",array('id'=>$thing_id,'uh'=>$this->uh),$this->cookie,'POST');
		}
		
		/**
		*	Get a subreddit page
		*
		*	@param	string	$page	The subreddit page /r/games = games
		*	@param	int		$limit	The limit on the number of results to return
		*
		*	@return	object
		*/
		public function page($page = '',$limit = 100) {
			if($page != ''){$page = '/r/'.$page;};
			return $this->curl($this->reddit.$page.".json?limit=".$limit,array(),$this->cookie);
		}
		
		/*
		*	Search SubReddits
		*
		*	@param	string	$subreddit	The subreddit to search
		*	@param	array	$params		GET parameters to use in the search
		*
		*	@return	object
		*/
		public function search($subreddit = 'RussianRouletteBot',$params = array()) {
			return $this->curl($this->reddit."r/".$subreddit."/search.json?".$this->buildGETQuery($params),array());
		}
		
		/**
		*	Submit a post to Reddit
		*
		*	@param	array	$postParams		Expects parameters documented at http://www.reddit.com/dev/api#POST_api_submit
		*/
		public function submit($postParams) {
			
			if(!array_key_exists('title',$postParams) || empty($postParams['title']) || !array_key_exists('text',$postParams) || empty($postParams['text'])) {
				return false;
			}
			
			//we add the modhash
			$postParams['uh'] = $this->uh;
			
			//For safety
			if(!array_key_exists('sr',$postParams) || empty($postParams['sr'])) {
				$postParams['sr'] = 'RussianRouletteBot';
			}
			
			//if kind is not set or empty, we make it a self post
			if(!array_key_exists('kind',$postParams) || empty($postParams['kind'])) {
				$postParams['kind'] = 'self';
			}

			return $this->curl($this->api."submit",$postParams,$this->cookie,"POST");
		}
		
		public function submitted($username) {
			return $this->curl($this->reddit.'user/'.$username."/submitted.json".$query,array(),$this->cookie);
		}
		
		/**
		*	Get information about a Reddit users posting/commenting history
		*
		*	@param	string	$username	Username
		*
		*	@return	object
		*/
		public function user($username) {
			return $this->curl($this->reddit.'user/'.$username.".json",array(),$this->cookie);
		}
		
		/**
		*	Get an overview for a user
		*
		*	@param	string	$username	Username
		*	@param	array	$params		Additional GET parameters to set
		*
		*	@return	object
		*/
		public function user_overview($username,$params = array()) {
			return $this->curl($this->reddit.'user/'.$username."/overview.json?".$this->buildGETQuery($params),array(),$this->cookie);
		}
		
		/**
		*	Vote on a Reddit "thing"
		*
		*	@param string	$id		The id of the what thing you are voting on
		*	@param int		$dir	Direction to vote (-1,0,1)
		*
		*	@return	object
		*/
		public function vote($id,$dir) {
			return $this->curl($this->api."vote","id=$id&dir=$dir&uh=$this->uh",$this->cookie);
		}
		
		/**
		*	Makes a call to Reddit via CURL
		*
		*	@param	string			$url		The Reddit API url to be called
		*	@param	string|array	$params		Additional GET parameters to set
		*	@param	string			$cookie		Needed to communicate with the Reddit API
		*	@param	string			$type		Type of HTTP post to make, default is GET.  POST parameters must be an array
		*
		*	@return
		*/
		private function curl($url,$params = '',$cookie = NULL,$type = "GET") {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_HEADER, false);
			
			if($cookie != null) {
				curl_setopt ($ch, CURLOPT_COOKIE, "reddit_session=".$cookie);
			}
			
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			
			if($type == "POST") {
				
				$params_string = "";
			
				foreach($params as $key=>$value) { $params_string .= $key.'='.$value.'&'; }
				rtrim($params_string, '&');
				
				curl_setopt($ch,CURLOPT_POST, count($params));
				curl_setopt($ch,CURLOPT_POSTFIELDS, $params_string);

			} elseif ($type == "GET") {
				if(!empty($params)) {
					curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
				}
			}
			
			$d = json_decode(curl_exec($ch));
			curl_close($ch);
			return $d;
		}
	};
	