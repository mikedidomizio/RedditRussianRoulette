<?php

	//Reddit Russian Roulette main file
	//thread to test comments t3_1t5733
	
	require_once 'reddit.class.php';
	require_once 'db.class.php';
	require_once 'roulette.class.php';
	
	$username = 'RussianRouletteBot';
	$pass = '';
	
	
	$reddit = new roulette\roulette($username,$pass);

	$reddit->checkForNewThreads();

