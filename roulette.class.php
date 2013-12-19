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
			'Y\'all ready for this?',
			'Ante Up!',
			'Lets rock!'
		);
			
		private $minUsers = 2;
		private $maxUsers = 10;
		private $numberOfChambers = 6;
		private $startComments = array(
			'is not afraid',
			'is feeling lucky',
			'isn\'t scared'
		);
		
		/**
		*	Make the database connection and then call the parent construct and make a reddit connection
		*/
		public function __construct($user,$pass) {
			
			if(class_exists('db\db')) {
				$this->db = new \db\db();
			};
			parent::__construct($user,$pass);
		}
		
		/**
		*	The current user has made an action, we do the work and decide if they keep going on
		*
		*	@param	string	$action			fire|spin
		*	@param	string	$thing_id		Reddit thing_id
		*	@param	string	$commentId		$comment ID to respond to
		*	@params	int		$chambersAway	The number of chambers until it fires
		*/
		private function action($action = NULL,$thing_id,$commentId,$username,$chambersAway = 6) {

			switch($action) {
				case "fire" : $this->fire($thing_id,$commentId,$username,$chambersAway);
					break;
				case "spin" : 
						//we spin the barrel and then fire twice
						$this->spin($thing_id);
						
						if($this->fire($thing_id,$commentId,$username,$chambersAway)) {
							//the gun didn't go off, we do it again
							$this->fire($thing_id,$commentId,$username,$chambersAway);
						}
					break;
				default : return false;
			}
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
								
								try {
									$returned = $this->comment($data->name,$this->gameCreatedMessages[$num]."\n\nThe first person to go is.......... $users[$playerTurn]! $playerList");
									
									//reddit callback is weird...
									$commentId = $returned->jquery[18][3][0][0]->data->id;
									
									if(!empty($commentId)) {
									//we update the table with the last comment made, which at this point is the bots
										$this->updateLastCommentThing($data->name,$commentId);
									}

								} catch (Exception $e) {
									die('Error with getting the comment id');
								}
								//print_r($returned);
								
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
		*	Checks for updates in games that are going
		*/
		public function checkForUpdates() {
		
			//get active games and get the player that is up next
			$STH = $this->db->DBH->prepare("SELECT games.thing_id, playerTurn, username, chambersAway, lastComment 
					FROM games
					INNER JOIN usersInGame ON games.playerTurn = playerNumber AND games.thing_id = usersInGame.thing_id
					ORDER BY lastActivity DESC LIMIT 30");
			$STH->execute();
			$results = $STH->fetchAll(\PDO::FETCH_ASSOC);
			
			
			$lastCommentsArr = array();
			
			foreach($results as $var) {
				$lastCommentsArr[] = $var['lastComment'];
			}
			
			$unreadMessages = $this->messages("unread");
			if(!empty($unreadMessages)) {
				foreach($unreadMessages->data->children as $key => $var) {

					$data = $var->data;
					
					if($data->was_comment && $data->body) {
						$author = $data->author;
						$parent = $data->parent_id;
						
						//determine if it's an active game
						if(in_array($parent,$lastCommentsArr)) {
							
							//determine if the person that is commenting is our next victi...person
							$key = array_search($parent, $lastCommentsArr);

							if($key >= 0 && $results[$key]['username'] === $author) {
								$comment = strtolower($data->body);
								//either we fire or spin!
								preg_match('/(fire|spin)/',$comment,$matches);
								
								if(sizeof($matches) == 2 && ($matches[1] === 'fire' || $matches[1] === 'spin')) {
									$this->action($matches[1],$results[$key]['thing_id'],$data->name,$author,$results[$key]['chambersAway']);
								}
							}
							
						}
					}

					echo '<br/><br/><br/><br/>';
				}
			}
			//$this->messagesRead();
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
		
		/**
		*	Adds a footer note to the comment, good to direct people
		*
		*	@param	string	comment		the original comment to add the footer to
		*
		*/
		public function comment_footer($comment) {
			return $comment . $this->footer;
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
		*	Edit text of a "thing"
		*
		*	@param string	$thing		The thing id
		*	@param string	$comment	The updated comment
		*
		*	@return object
		*/
		public function edit($thing,$comment,$addFooter = true) {
		
			if($add_footer) {
				$comment = $this->comment_footer($comment);
			}
		
			return parent::edit($thing,$comment);
		}
		
		/**
		*	Fires the gun
		*
		*	@param	string	$thing_id		The original thing_id from Reddit
		*	@param	string	$commentId		The comment we will be replying to
		*	@param	int		$chambersAway	The number of chambers until the gun goes off, if 0 it fires
		*
		*/
		private function fire($thing_id,$commentId,$username,$chambersAway) {
			
			if($chambersAway <= 0) {
				// :(
				return false;
			}else {
				//we lower the chamber number by 1 and comment on that, setting up the next person
				$STH = $this->db->DBH->prepare("UPDATE `game` SET chambersAway = chambersAway - 1 WHERE thing_id = ?");
				$STH->execute(array($thing_id));
				
				$this->comment($commentId,$this->getStartCommentAction($username).' *click*');
				return true;
			}
		}
		
		/**
		*	Builds the start of the comment when firing
		*
		*	@param	string	$username	The username associated with the action
		*
		*	@return	string	returns the string that will be inserted into the comment
		*/
		private function getStartCommentAction($username) {
			return $username.' '.$this->startComments[rand(0,sizeof($this->startComments) -1)].'.........';
		}
		
		/**
		*	Spins the barrel
		*
		*	@param	string	$thing_id	Reddit thing_id
		*/
		private function spin($thing_id) {
			$STH = $this->db->DBH->prepare("UPDATE `games` SET chambersAway = ? WHERE thing_id = ?");
			//we pick a random number to set how many chambers it is away
			$spin = rand(0,$this->numberOfChambers);
			$STH->execute(array($spin,$thing_id));
		}
		
		/**
		*	Update the thread with the last comment id made
		*
		*	@param	string	$postId		the thread id
		*	@param	string	$commentId	the id of the bots last comment
		*/
		private function updateLastCommentThing($postId,$commentId) {
			$STH = $this->db->DBH->prepare("UPDATE `games` SET lastComment = :lastComment WHERE thing_id = :thing_id");
			$STH->bindParam(':thing_id', $postId, \PDO::PARAM_STR, 12);
			$STH->bindParam(':lastComment', $commentId, \PDO::PARAM_STR, 12);
			$STH->execute();
		}
	}
	