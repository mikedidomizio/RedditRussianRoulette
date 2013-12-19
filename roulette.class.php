<?php
	namespace roulette;
	//Reddit Russian Roulette roulette file
	date_default_timezone_set('UTC');

	class roulette extends \reddit\reddit {
		
		//we make it public in case we need to change it on the fly.  This is in Reddit markup
		public	$footer = "\n\n---\n\n *[RussianRouletteBot](http://www.reddit.com/r/RussianRouletteBot) simulates the game of Russian Roulette*";
		private $db;
		
		//message to show when game is created
		private $gameCreatedMessages = array(
			'Alright boys let\'s do this!',
			'Game created!',
			'Y\'all ready for this?'
		);
		
		private $minUsers = 2;
		private $maxUsers = 10;
		private $numberOfChambers = 6;
		
		/**
		*	Make the database connection and then call the parent construct and make a reddit connection
		*/
		public function __construct($user,$pass) {
			
			if(class_exists('db\db')) {
				$this->db = new \db\db();
			};
			parent::__construct($user,$pass);
		}
		
		/*
		*	Returns a search of posts/comments that mention russian roulette
		*
		*	@param	string	$subreddit	what subreddit
		*	@param	bool	$restrict	restrict it to that subreddit
		*/
		public function checkForMentions($subreddit = 'RussianRouletteBot', $restrict = false) {
		
			return $this->search($subreddit,array(
				'q' => 'russian roulette',
				'restrict_sr' => $restrict,
				'sort' => 'new',
				't' => 'hour'
			));
		}
		
		/**
		*	Checks for new posts against the database, sort by new
		*/
		public function checkForNewThreads() {
			$results = $this->page('RussianRouletteBot/new',25);
			
			
			$STH = $this->db->DBH->prepare("SELECT `thing_id`, `created` FROM `games` ORDER BY created DESC LIMIT 1");
			$STH->execute();
			$row = $STH->fetch(\PDO::FETCH_ASSOC);
			
			//if we have data, we convert the UTC timestamp to UNIX, else we take it all
			$row['created'] = ($row['created']) ? strtotime($row['created']) : 0;
						
			foreach($results->data->children as $key=>$var) {
				$data = $var->data;
				//no link threads, just self threads
				if($data->is_self && $data->created_utc > $row['created']) {
					//echo $data->id;
					//echo $data->selftext;
					//get the usernames for the game
					preg_match('/^\@([\w\d\s\_\-\,]+)(\#\d)?/',$data->selftext,$matches);
					if(sizeof($matches) >= 2) {
						
						$users = explode(',',$matches[1]);
						//we have to include the author incase they missed it
						$users[] = $data->author;
						
						$users = array_unique($users);
						//ensure we don't add blank entries
						$playerList = '';
						
						foreach($users as $key => $var) {
							if(empty($var)) {
								unset($users[$key]);
							} else {
								$playerList .= "\n\n- $var";
							}
						}
						//re-index array
						$users = array_values($users);
						$numberOfUsers = sizeof($users);
						if($numberOfUsers >= $this->minUsers && $numberOfUsers <= $this->maxUsers) {
							
							//returns the key of the player to start the game
							$playerTurn = $this->createGame($data->name,$users);
							
							if($playerTurn >= 0) {
								//game is created
								
								//we need to choose a random game generated message
								$num = rand(0,sizeof($this->gameCreatedMessages) - 1);
								
								$this->comment($data->name,$this->gameCreatedMessages[$num]."\n\nThe first person to go is.......... $users[$playerTurn]! $playerList");
							} else {
								//error when creating game
								$this->comment($data->name,"There was an error creating the game or the bot tried to create the game twice, please PM /u/mikedidomizio with this thread");
							}
						} else {
							//not enough users or too many
							$this->comment($data->name,"Invalid number of users or incorrect format");
						}
					
					}

				}
			}
		}
		
		/**
		*	creates a game by thing_id
		*
		*	@param	string	$thing_id	The Reddit thing Id
		*	@param	array	$users		An array of usernames that will be inserted into the game
		*/
		private function createGame($thing_id,$users) {
			if(!empty($thing_id) && is_array($users)) {
			
				$now = date("Y-m-d H:i:s");

				//lets make sure we haven't created the game already
				$STH = $this->db->DBH->prepare("SELECT thing_id FROM games WHERE thing_id = ? LIMIT 1");
				$STH->execute(array($thing_id));
				$row = $STH->fetch();
				
				if(empty($row)) {
					//we have to decide who starts first
					$firstPlayer = rand(0,sizeof($users) - 1);
					
					//The number of pulls before it goes off
					$chambersAway = rand(0,$this->numberOfChambers);
					
					//creates the game
					$STH = $this->db->DBH->prepare("INSERT INTO `games` (thing_id,created,lastActivity,playerTurn,chambersAway) VALUES (?,?,?,?,?)");
					$STH->execute(array($thing_id,$now,$now,$firstPlayer,$chambersAway));
				
					//adds the users to the game
					$STH = $this->db->DBH->prepare("INSERT INTO `usersInGame` (thing_id,username,playerNumber) VALUES (:thing_id,:username,:playerNumber)");
					foreach($users as $key => $var) {
						$STH->bindParam(':username', $var, \PDO::PARAM_STR, 64);
						$STH->bindParam(':thing_id', $thing_id, \PDO::PARAM_STR, 12);
						$STH->bindParam(':playerNumber', $key, \PDO::PARAM_STR, 2);
						$STH->execute();
					}
					return $firstPlayer;
				} else {
					return -1;
				}
			}
			return -1;
		}
		
		/**
		*	Used to comment on a "thing", calls comment method in reddit.class.php
		*	
		*	@param	string	$thing		The thing id
		*	@param	string	$comment	The comment to be made
		*	@param	bool	$add_footer	Adds the comment footer by default
		*
		*	@return	object
		*/
		public function comment($thing,$comment,$add_footer = true) {
			
			if($add_footer) {
				$comment = $this->comment_footer($comment);
			}
			
			return parent::comment($thing,$comment);
		}
		
		/*
		*	Converts a unix date to datetime
		*/
		public function convertDate($date) {
			return date("Y-m-d H:i:s", $date);
		}
		
		/**
		*	Edit text of a "thing"
		*
		*	@param string	$thing		The thing id
		*	@param string	$comment	The updated comment
		*
		*	@return object
		*/
		public function edit($thing,$comment,$add_footer = true) {
		
			if($add_footer) {
				$comment = $this->comment_footer($comment);
			}
		
			return parent::edit($thing,$comment);
		}
		
		/*
		*	Adds a footer note to the comment, good to direct people
		*
		*	@param	string	comment		the original comment to add the footer to
		*
		*/
		public function comment_footer($comment) {
			return $comment . $this->footer;
		}
		
		/**
		*	Inserts a user into the database
		*
		*
		*/
		public function insertUser($id,$username) {
			$STH = $this->db->DBH->prepare("INSERT INTO `users` (user_id,username) VALUES (?,?)");
			$STH->execute(array($id,$username));
		}
	}
	