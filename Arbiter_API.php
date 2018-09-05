<?php

    /**
    * 
    */

	class Arbiter_API {
		const TIMEZONE = 'HST';
		
		public $username;
		public $password;
		public $games = array();
		
		const URL_LOGIN 				= 'https://www1.arbitersports.com/Shared/SignIn/Signin.aspx';
		const URL_LOGIN_POST 			= 'https://www1.arbitersports.com/Shared/SignIn/Signin.aspx';
		const URL_LOGIN_ACCOUNT_POST	= 'https://www1.arbitersports.com/Generic/Default.aspx';
		const URL_GAME_SCHEDULE			= 'https://www1.arbitersports.com/Official/GameScheduleEdit.aspx';
		
		private $_session;
		
		function __construct() {
			require_once 'URLScrape.php';
		}
		
		public function get_games($username = null, $password = null) {
			if (!empty($username)) $this->username = $username;
			if (!empty($password)) $this->password = $password;
			
			$this->_session = new URLScrape();
			$this->_session->persist();
	   
			$this->_session->URLFetch(self::URL_LOGIN);
			$_headers = array(
				'Origin:https://www1.arbitersports.com',
				'Referer:https://www1.arbitersports.com/Shared/SignIn/Signin.aspx',
				'Accept-Language: en-US,en;q=0.8',
				'Upgrade-Insecure-Requests:1',
				'Cache-Control: max-age=0',
				'Connection: keep-alive',
				'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
			);
			$_post = array(
				'ctl00$ContentHolder$pgeSignIn$conSignIn$btnSignIn'	=> '',
				'__EVENTTARGET'										=> '',
				'__EVENTARGUMENT'									=> '',
				'__VIEWSTATE'										=> $this->_session->GetElementValueByID('__VIEWSTATE'),
				'__VIEWSTATEGENERATOR'								=> $this->_session->GetElementValueByID('__VIEWSTATEGENERATOR'),
				'ctl00$ContentHolder$pgeSignIn$conSignIn$txtEmail'	=> $this->username,
				'txtPassword'										=> $this->password,
				// 'ctl00$EmailTextbox'  								=> 'Email',
				// 'ctl00$PasswordTextbox'  							=> '',
				// 'ctl00$PasswordWatermarkTextbox'  					=> 'Password',
			);
			$this->_session->URLFetch(self::URL_LOGIN_POST, $_post, $_headers);

			// multiple accounts, so just pick one
			if (strpos($this->_session->response,'Since your account is registered in more than one group')) {
				$_account = $this->_session->GetDOMXPathItemAttribute("//tr[@class='miniAccountsGridRow'][2]/td[3]/a",'id');
				$_account = str_replace('_','$',$_account);
				$_account = str_replace('MiniAccounts1','ContentHolder$pgeDefault$conDefault', $_account);
				$_post = array(
					'__EVENTTARGET'										=> $_account,
					'__EVENTARGUMENT'									=> '',
					'__VIEWSTATE'										=> $this->_session->GetElementValueByID('__VIEWSTATE'),
					'__VIEWSTATEGENERATOR'								=> $this->_session->GetElementValueByID('__VIEWSTATEGENERATOR'),
				);
				$this->_session->URLFetch(self::URL_LOGIN_ACCOUNT_POST, $_post);
			}
			
			$this->_session->URLFetch(self::URL_GAME_SCHEDULE);
			$xpath = $this->_session->GetDOMXPath();
			$games = $xpath->query('//table[@class="dataGrids"]')->item(0);
			foreach ($games->childNodes as $idx => $_tr) {
				$tds = $xpath->query('//table[@class="dataGrids"]/tr[' . ($idx+1) . ']/td');
				$game_info = array(
					'game_id' 	=> trim($tds->item(0)->nodeValue),
					'group' 	=> trim($tds->item(2)->nodeValue),
					'position' 	=> trim($tds->item(3)->nodeValue),
					'datetime' 	=> trim($tds->item(4)->nodeValue),
					'sport' 	=> trim($tds->item(5)->nodeValue),
					'site' 		=> trim($tds->item(6)->nodeValue),
					'home' 		=> trim($tds->item(7)->nodeValue),
					'away' 		=> trim($tds->item(8)->nodeValue),
					'status' 	=> trim($tds->item(10)->nodeValue),
					'username'	=> 'game@arbiter.com', // $this->username,
				);
				$this->games[] = $game_info;
			}
			return $this->games;
		}
		
		public static function MapGameToGoogleEventArray($game) {
			$status = (strpos($game['status'],'Accepted on') !== FALSE) ? '' : 'NOT YET ACCEPTED ';
			
			// what a shitty date format they use; it can't be interpreted by strtotime
			$time = date_create_from_format('m/d/Y?????h:i A T',$game['datetime'] . ' ' . self::TIMEZONE);
			$start = $time->format('Y-m-d\TH:i:sP');
			$time->modify('+2 hour');
			$end = $time->format('Y-m-d\TH:i:sP');
			$_data = array(
				'summary' => $status . $game['position'] . ' ' . $game['sport'] . ' ' . $game['home'] . ' vs. ' . $game['away'], 
				'description' => 'Generated from swc-google-calendar-sync from ' . PHP_EOL .
								 'Position: ' . $game['position'] . PHP_EOL . 
								 'Home: ' . $game['home'] . PHP_EOL . 
								 'Away: ' . $game['away'] . PHP_EOL . 
								 'Site: ' . $game['site'] . PHP_EOL . 
								 'sport: ' . $game['sport'] . PHP_EOL . 
								 'group: ' . $game['group'],
				'start' => array(
					'dateTime' => $start,
					// 'timeZone' => $exch_mtg->TimeZone,
				),
				'end' => array(
					'dateTime' => $end,
					// 'timeZone' => $exch_mtg->TimeZone,
				),
				'reminders' => array(
					'useDefault' => TRUE,
				),
				'location' => $game['site'],
				'attendees' => array(
					array(
						'email' => $game['username'],
						// 'displayName' => $this->username,
					),
				),
			);
			
			return $_data;

		}
	}

	