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
			'Ante Up!',
			'Game created!',
			'Lets rock!',
			'Y\'all ready for this?'
		);
			
		private $minUsers = 2;
		private $maxUsers = 20;
		private $numberOfChambers = 6;
		private $startComments = array(
			'is feeling lucky',
			'is not afraid',
			'isn\'t scared'
		);
		private $statisticsFile = 'statistics.xml';
		
		private $spinBarrelText = 'spins the revolver!';
		
		private $bulletMessage = array(
			array('The bullet leaves the barrel and hits '),
			array(
				' in the ',
				' right through the '
			),
			array(
				' blood erupts all over the place',
				' exploding',
				' shattering it into a million pieces'
			)
		);
		
		private $parts = array(
			array('brain','chest','face','head'),
			array('arm','eye','leg')
		);
		
		/**
		*	Make the database connection and then call the parent construct and make a reddit connection
		*/
		public function __construct($user,$pass) {
			
			if(class_exists('db\db')) {
				$this->db = new \db\db();
			};
			
			//we need the statistics file			
			if(!file_exists($this->statisticsFile)) {
				die($this->statisticsFile.' is missing');
			}
			
			parent::__construct($user,$pass);
		}
		
		/**
		*	The current user has made an action, we do the work and decide if they keep going on
		*
		*	@param	string	$action			fire|spin
		*	@param	string	$thing_id		Reddit thing_id
		*	@param	string	$commentId		comment id to respond to
		*	@param	string	$username		current players username
		*	@params	int		$chambersAway	The number of chambers until it fires
		*/
		private function action($action = NULL,$thing_id,$commentId,$username,$chambersAway = 6) {

			$chamberPos = $chambersAway;
			
			$commentText = "";
			
			switch($action) {
				case "fire" : 
					$isSafe = $this->fire($chambersAway);
					
					$nextPlayer = $this->nextPlayer($thing_id,$username);
					$commentText .= $this->getStartCommentAction($username);
					
					
					if(!$isSafe) {
						//gun has fired
						$this->removePlayer($thing_id,$username);
						$commentText .= ' *bang*';
						$nextPlayer[3]--;
					} else {
						//we lower the chamber number by 1 and comment on that, setting up the next person
						$commentText .= ' *click*';
						$chamberPos--;
					}
					
					break;
				case "spin" : 
						//we spin the barrel and then fire twice
						$chamberPos = $this->spin($thing_id);
						$commentText .= $this->spinBarrelText;
						
						$isSafe = $this->fire($chamberPos);
						$nextPlayer = $this->nextPlayer($thing_id,$username);
						
						if($isSafe) {
							//gun hasn't fired
							$commentText .= "\n\n".$this->getStartCommentAction($username)." *click*";
							$chamberPos--;
							
							$result = $this->fire($chamberPos);
							
							if($isSafe) {
								//gun didn't fire a second time
								$commentText .= "\n\n".$this->getStartCommentAction($username)." *click*";
								$chamberPos--;
								
							} else {
								//gun fired
								$this->removePlayer($thing_id,$username);
								$commentText .= "\n\n".$this->getStartCommentAction($username)." *bang*";
								$nextPlayer[3]--;
							}
							
						} else {
							//gun fired
							$this->removePlayer($thing_id,$username);
							$commentText .= "\n\n".$this->getStartCommentAction($username)." *bang*";
							$nextPlayer[3]--;
						}
						
					break;
				default : $nextPlayer = $this->nextPlayer($thing_id,$username);
			}
			
			
			if($nextPlayer[3] === 1) {
				//only one player remains, they win!	
				$this->comment($commentId,$commentText . "\n\n**The winner is ".$nextPlayer[1]."**");
			
				//remove the game
				$STH = $this->db->DBH->prepare("DELETE FROM `games` WHERE thing_id = ?");
				$STH->execute(array($thing_id));
				
			} else {
				//update game
				$commentText .= "\n\n The next player is **".$nextPlayer[1]."**";
				
				//we need the returned comment thing_id so we can update the db
				$returned = $this->comment($commentId,$commentText);
				$commentId = $returned->jquery[30][3][0][0]->data->id;
				
				//print_r(array($chamberPos,$nextPlayer[2],$commentId,$thing_id));
				
				$now = date("Y-m-d H:i:s");
				
				$STH = $this->db->DBH->prepare("UPDATE `games` SET chambersAway = ?, playerTurn = ?, lastComment = ?, lastActivity = ? WHERE thing_id = ?");
				$STH->execute(array($chamberPos,$nextPlayer[2],$commentId,$thing_id,$now));
				
				
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
			$results = $this->page('RussianRouletteBot/new',array(
				'limit' => 25,
				'sort' => 'new',
				't' => 'hour'
			));
			
			$STH = $this->db->DBH->prepare("SELECT `thing_id`, `created` FROM `games` ORDER BY created DESC LIMIT 1");
			$STH->execute();
			$row = $STH->fetch(\PDO::FETCH_ASSOC);
			
			//we find any games that are newer than our last created game
			$stats = $this->readStats();

			//if we have data, we convert the UTC timestamp to UNIX, else we take it all
			$stats['lastCreatedGame'] = (is_array($stats) && isset($stats['lastCreatedGame']) && $stats['lastCreatedGame'] != '0000-00-00 00:00:00')
				? strtotime($stats['lastCreatedGame']) : 0;
				
			//This is used later to update the statistics table
			$numberOfNewThreads = 0;
			
			foreach($results->data->children as $key=>$var) {
				$data = $var->data;
				//no link threads, just self threads
				if($data->is_self && $data->created_utc > $stats['lastCreatedGame']) {
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
									
									$numberOfNewThreads++;
									
								} catch (Exception $e) {
									die('Error with getting the comment id');
								}
								
							} else {
								//error when creating game
								$this->comment($data->name,"There was an error creating the game or the bot tried to create the game twice, please PM /u/mikedidomizio with this thread");
							}
						} else {
							//not enough users or too many
							$this->comment($data->name,"Invalid number of users or incorrect format");
						}
					
					}

				} else if($data->created_utc <= $stats['lastCreatedGame']) {
					//no new threads than what we have, therefore we stop checking
					
					//before we stop, should we update the statistics table
					if($numberOfNewThreads > 0) {
						$now = date("Y-m-d H:i:s");
						$this->updateStats(0,0,$now);
					}
					
					break;
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
								preg_match('/(fire|spin)/i',$comment,$matches);
								
								if(sizeof($matches) == 2 && ($matches[1] === 'fire' || $matches[1] === 'spin')) {
									$returnedResult = $this->action($matches[1],$results[$key]['thing_id'],$data->name,$author,$results[$key]['chambersAway']);
								}
							}
							
						}
					}
					//we mark it as read
					$this->messagesRead('t1_'.$data->id);
				}
			}
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
		*	@param	int		$chambersAway	The number of chambers until the gun goes off, if 0 it fires
		*
		*/
		private function fire($chambersAway) {
			return ($chambersAway <= 0) ? false : true;
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
		*	Update game to the next player
		*
		*	@param	string	$thing_id			The reddit thing_id
		*	@param	string	$currentUsername	The current users username
		*
		*	@return	array						
		*/
		private function nextPlayer($thing_id,$currentUsername) {
			$STH = $this->db->DBH->prepare("SELECT username, playerNumber FROM `usersInGame` WHERE thing_id = ? ORDER BY playerNumber ASC");
			$STH->execute(array($thing_id));
			$results = $STH->fetchALL(\PDO::FETCH_ASSOC);
			
			$numberOfRemainingPlayers = sizeof($results);
			
			if($numberOfRemainingPlayers > 1) {
			
				foreach($results as $key => $var) {

					if($var['username'] == $currentUsername) {
						//we found the current user
						
						if(isset($results[$key+1])) {
							//start with next player
							$returnArr = array($thing_id,$results[$key+1]['username'],$results[$key+1]['playerNumber'],$numberOfRemainingPlayers);
						} else {
							//start back at the top
							$returnArr = array($thing_id,$results[0]['username'],$results[0]['playerNumber'],$numberOfRemainingPlayers);
						}
						
						return $returnArr;
					}
					
				}
			} else {
				//this is the last person, they have won
				return array($thing_id,$currentUsername,$results[0]['playerNumber'],1);
			}
		}
		
		/**
		*	Get statistics from db
		*/
		private function readStats() {
			$STH = $this->db->DBH->prepare("SELECT * FROM statistics ORDER BY lastCreatedGame ASC LIMIT 1");
			$STH->execute();
			return $STH->fetch(\PDO::FETCH_ASSOC);
		}
		
		/**
		*	Removes the player
		*
		*	@param	string	$thing_id		The thing_id to remove the player from
		*	@param	string	$username		The user to remove
		*/
		private function removePlayer($thing_id,$username) {
			$STH = $this->db->DBH->prepare("DELETE FROM `usersInGame` WHERE thing_id = ? && username = ?");
			return $STH->execute(array($thing_id,$username));
		}
		
		/**
		*	Spins the barrel
		*
		*	@param	string	$thing_id	Reddit thing_id
		*
		*	@return	int		$spin		Returns the number of chambers away
		*/
		private function spin($thing_id) {
			$STH = $this->db->DBH->prepare("UPDATE `games` SET chambersAway = ? WHERE thing_id = ?");
			//we pick a random number to set how many chambers it is away
			$spin = rand(0,$this->numberOfChambers);
			$STH->execute(array($spin,$thing_id));
			return $spin;
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
		
		/**
		*	Updates statistics
		*
		*	Statistics will have to change in the future to something else, this just doesn't feel right
		*/
		public function updateStats($gamesCreated,$gamesFinished,$lastCreatedGame = NULL) {
			
			$stats = $this->readStats();
			
			if(!is_array($stats)) {
				$STH = $this->db->DBH->prepare("INSERT INTO `statistics` (gamesCreated) VALUES (0)");
				$STH->execute();
			}

			$sql = 'UPDATE `statistics` SET ';
			
			//if lastCreatedGame is not null, it means we update it and also add to the gamesCreated column
			//else a game has finished
			$sql .= (!is_null($lastCreatedGame)) ? 'gamesCreated = gamesCreated + :gamesCreated, lastCreatedGame = :lastCreatedGame' 
				: 'gamesFinished = gamesFinished + :gamesFinished';
			
			
			$STH = $this->db->DBH->prepare($sql);

			if(!is_null($lastCreatedGame)) {
				$STH->bindParam(':gamesCreated', $gamesCreated, \PDO::PARAM_INT, 5);
				$STH->bindParam(':lastCreatedGame', $lastCreatedGame, \PDO::PARAM_STR, 19);
			} else{
				$STH->bindParam(':gamesFinished', $gamesFinished, \PDO::PARAM_INT, 5);
			}
			
			return $STH->execute();
		}
	}
	