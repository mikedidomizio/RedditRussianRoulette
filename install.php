<?php

	//Reddit Russian Roulette Install
	
	require_once 'db.class.php';
	
	if(class_exists('db\db')) {
		$db = new \db\db();
	};
	
	/* Grab a list of tables that already exist */
	$STH = $db->DBH->prepare("SHOW TABLES");
	$STH->execute();
	$results = $STH->fetchAll(PDO::FETCH_NUM);
	
	//put the results in a single dimensional array
	$tablesList = Array();
	foreach($results as $var) {
		$tablesList[] = $var[0];
	}
	
	/**
	*	Create the users table
	*
	*	I believe Reddit user ids are just 5 chars in length, we'll make it 6 just in case
	*/
	if(!in_array('users',$tablesList)) {
		$STH = $db->DBH->prepare("CREATE TABLE `users` (`username` VARCHAR(32) NOT NULL UNIQUE, PRIMARY KEY(username) );");
        if(!$STH->execute()) {
	        die('Failed to create users table');
        }
	}
    
    /**
    *	Create the table that holds the games
    *	
    *	Able to have length 2 of chambers, sure!
    */
	if(!in_array('games',$tablesList)) {
        $STH = $db->DBH->prepare("CREATE TABLE `games` (`thing_id` VARCHAR(10) NOT NULL, `playerTurn` TINYINT(2) UNSIGNED NOT NULL default 0, `chambersAway` SMALLINT(2) UNSIGNED NOT NULL default 0, `lastComment` VARCHAR(10) NOT NULL, `created` TIMESTAMP NOT NULL default '0000-00-00 00:00:00', `lastActivity` TIMESTAMP NOT NULL default '0000-00-00 00:00:00', PRIMARY KEY(thing_id) );");
        if(!$STH->execute()) {
	        die('Failed to create games table');
        }
	}
	
	/**
    *	Create the table that holds the games
    *
    *	Users can be in multiple games
    */
	if(!in_array('usersInGame',$tablesList)) {
        $STH = $db->DBH->prepare("CREATE TABLE `usersInGame` (`thing_id` VARCHAR(10) NOT NULL, `username` VARCHAR(64) NOT NULL, `playerNumber` TINYINT(2) UNSIGNED NOT NULL);");
        if(!$STH->execute()) {
	        die('Failed to create usersInGame table');
        }
	}
	
	if(!in_array('statistics',$tablesList)) {
        $STH = $db->DBH->prepare("CREATE TABLE `statistics` (`gamesCreated` SMALLINT(5) UNSIGNED NOT NULL default 0, `gamesFinished` SMALLINT(5) UNSIGNED NOT NULL default 0,  `lastCreatedGame` TIMESTAMP NOT NULL default '0000-00-00 00:00:00');");
        if(!$STH->execute()) {
	        die('Failed to create statistics table');
        }
	}
	
	echo 'Success!';
