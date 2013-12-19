<?php
	//database class
	namespace db;
	
	class db {
		private $host = 'localhost';
		
		//replace with your database name
		private $dbName = 'roulette';
	
		//replace with your username and password
		private $dbUser = 'root';
		private $dbPass = '';
		
		public $DBH;
		
		public function __construct() {
		
			if (!defined('PDO::ATTR_DRIVER_NAME')) {
				die('PDO unavailable');
			}
		
			$this->DBH = new \PDO("mysql:host=$this->host;dbname=$this->dbName", $this->dbUser, $this->dbPass);
		}
	}
