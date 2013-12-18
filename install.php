<?php

	//Reddit Russian Roulette Install
	
	if (!defined('PDO::ATTR_DRIVER_NAME')) {
		die('PDO unavailable');
	}
	
	$host = 'localhost';
	//replace with your database name
	$dbName = 'roulette';
	
	//the user for this needs needs the CREATE permission
	
	//replace with your username and password
	$dbUser = 'root';
	$dbPass = '';

	if(!empty($host) && !empty($dbName) && !empty($dbUser)) {
	
		try {
			//make the PDO connection
			$DBH = new PDO("mysql:host=$host;dbname=$dbName", $dbUser, $dbPass);
		} catch (Exception $e) {
			die('There was an error connecting to the database');
		}
		
		/* Grab a list of tables that already exist */
		$STH = $DBH->prepare("SHOW TABLES");
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
			$STH = $DBH->prepare("CREATE TABLE `users` (`user_id` VARCHAR(6) NOT NULL, `username` VARCHAR(32) NOT NULL UNIQUE, PRIMARY KEY(user_id) );");
	        if(!$STH->execute()) {
		        die('Failed to create users table');
	        }
		}
        
        /**
        *	Create the table that holds the games
        */
		if(!in_array('games',$tablesList)) {
	        $STH = $DBH->prepare("CREATE TABLE `games` (`id` INT(10) NOT NULL AUTO_INCREMENT, `lastActivity` TIMESTAMP NOT NULL, PRIMARY KEY(id) );");
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
	        $STH = $DBH->prepare("CREATE TABLE `usersInGame` (`id` INT(10) NOT NULL, `user_id` VARCHAR(6) NOT NULL );");
	        if(!$STH->execute()) {
		        die('Failed to create usersInGame table');
	        }
		}
		
		echo 'Success!';
    } else {
	    die('One of the required fields is empty, please check the install file');
    }
    