<?php
    use Google\Auth\ApplicationDefaultCredentials;
    use GuzzleHttp\Client;
    use GuzzleHttp\HandlerStack;
    // REQUIRES Guzzle 6
    // REQUIRES PHP 5.5+
    // REQUIRES php_soap    
	set_time_limit (300); // 5min


    /**
    * #TODO Recurring meetings
	*	Master recurring patterns will need more support
    * 	Exceptions / changed occurrences will need more support (look at FindAppointments instead of FindItems if we need to)
    * 
	* #TODO attachment support
    *
    * #TODO remove original occurrence when an occurrence is updated
    * #TODO delete modified occurrences when recurring master is deleted
    * 
    * v0.1
    * 	Two week radius create-if-doesn't-exist for any occurrences
    * 	Basic auth for google
    *   Normal auth for exchange
    *   Linear coding
	*   Support only for fields
	*		Subject
	*		Location
	*		Organizer
	*		Start/End time
    * 
    * v0.2
    * 	Exchange sync - changes since last sync hash
    *   Override to sync all
    *   Support for recurring masters and occurrences
    *   Modularlized code
    *   Multiple account syncing
    *   Setting persistence with sync time
    * 	Full support for fields, including body, required/optional attendees
    *   Support for single- and multiple-update responses from EWS sync
    *   Support for updating occurrences of a series
    *
    *
    *
    * 
    * 
    * 
    * 
    */



    define('THRESHOLD_MAX_DATE','2015-07-01');

	// Library autoloader
    spl_autoload_register(function ($class_name) {
        $base_path = "../_library/";
        $include_file = $base_path . '/' . $class_name . '.php';
        return (file_exists($include_file) ? require_once $include_file : false);
    });
	
    // Google API autoloader
    require_once 'vendor/autoload.php';
    
    // EWS autoloader (manual)
    spl_autoload_register(function ($class_name) {
        $base_path = "php-ews-master/";
        $include_file = $base_path . '/' . $class_name . '.php';
		if (file_exists($include_file)) {
			require_once $include_file;
			return true;
		}
        $include_file = $base_path . '/' . str_replace('_', '/', $class_name) . '.php';
		return (file_exists($include_file) ? require_once $include_file : false);
    });
    
	function get_ews_calendar_event($ews,$id) {
		// Form the GetItem request
		$request = new EWSType_GetItemType();

		// Define which item properties are returned in the response
		$request->ItemShape = new EWSType_ItemResponseShapeType();
		$request->ItemShape->BaseShape = EWSType_DefaultShapeNamesType::ALL_PROPERTIES; // ALL_PROPERTIES is important!

		// Set the itemID of the desired item to retrieve
		if (!is_object($request->ItemIds)) $request->ItemIds = new stdClass;
		$request->ItemIds->ItemId = new EWSType_ItemIdType();
		$request->ItemIds->ItemId->Id = $id;

		//  Send the listing (find) request and get the response
		if (isset($ews->GetItem($request)->ResponseMessages->GetItemResponseMessage->Items->CalendarItem))
            return $ews->GetItem($request)->ResponseMessages->GetItemResponseMessage->Items->CalendarItem;
        return null;
	}
	
    function get_ews_calendar_events($ews,$start,$end) {
        $request = new EWSType_FindItemType();
        $request->Traversal = EWSType_ItemQueryTraversalType::SHALLOW;

        $request->ItemShape = new EWSType_ItemResponseShapeType();
        $request->ItemShape->BaseShape = EWSType_DefaultShapeNamesType::DEFAULT_PROPERTIES;

        $request->CalendarView = new EWSType_CalendarViewType();
        $request->CalendarView->StartDate = date('c', $start);
        $request->CalendarView->EndDate = date('c', $end);

        $request->ParentFolderIds = new EWSType_NonEmptyArrayOfBaseFolderIdsType();
        $request->ParentFolderIds->DistinguishedFolderId = new EWSType_DistinguishedFolderIdType();
        $request->ParentFolderIds->DistinguishedFolderId->Id = EWSType_DistinguishedFolderIdNameType::CALENDAR;

        // Send request
        $response = $ews->FindItem($request);

        if ($response->ResponseMessages->FindItemResponseMessage->RootFolder->TotalItemsInView > 0){
            return $response->ResponseMessages->FindItemResponseMessage->RootFolder->Items->CalendarItem;
        }
        return array();
    }
    
    function get_ews_calendar_sync($ews, $sync_state) {
        $request = new EWSType_SyncFolderItemsType;
        $request->SyncState = empty($sync_state) ? null : $sync_state;
        $request->MaxChangesReturned = 512;
        $request->ItemShape = new EWSType_ItemResponseShapeType;
        $request->ItemShape->BaseShape = EWSType_DefaultShapeNamesType::DEFAULT_PROPERTIES;

        $request->SyncFolderId = new EWSType_NonEmptyArrayOfBaseFolderIdsType;
        $request->SyncFolderId->DistinguishedFolderId = new EWSType_DistinguishedFolderIdType;
        $request->SyncFolderId->DistinguishedFolderId->Id = EWSType_DistinguishedFolderIdNameType::CALENDAR;

        $response = $ews->SyncFolderItems($request);
        
        $return = array();
        
        // save this string somewhere
        $sync_state = $response->ResponseMessages->SyncFolderItemsResponseMessage->SyncState;
        $return['sync'] = $sync_state;

        $changes = $response->ResponseMessages->SyncFolderItemsResponseMessage->Changes;
		
		$return['all'] = array();
		
        // created events
        if(property_exists($changes, 'Create')) {
            $return['create'] = $changes->Create;
			foreach ($changes->Create as $_mtg) {
				$return['all'][] = array(
					'action' => 'create',
					'mtg_obj' => $_mtg,
				);
			}
        }

        // updated events
        if(property_exists($changes, 'Update')) {
            $return['update'] = $changes->Update;
			foreach ($changes->Update as $_mtg) {
				$return['all'][] = array(
					'action' => 'update',
					'mtg_obj' => $_mtg,
				);
                
                if (isset($_mtg->CalendarItemType) && $_mtg->CalendarItemType == 'RecurringMaster') {
                    $_mtg_obj = get_ews_calendar_event($ews,$_mtg->ItemId->Id);
                    if (isset($_mtg_obj->ModifiedOccurrences->Occurrence)) {
                        foreach ($_mtg_obj->ModifiedOccurrences->Occurrence as $_mod_mtg) {
                            $return['all'][] = array(
                                'action' => 'update',
                                'mtg_obj' => $_mod_mtg,
                            );
                        }
                    }
                }

			}
        }

        // deleted events
        if(property_exists($changes, 'Delete')) {
            $return['delete'] = $changes->Delete;
			foreach ($changes->Delete as $_mtg) {
				$return['all'][] = array(
					'action' => 'delete',
					'mtg_obj' => $_mtg,
				);
			}
        }
        
        return $return;
    }
    
    // Calendar API at https://developers.google.com/google-apps/calendar/v3/reference/events/get
    // composer require google/auth
    //      https://github.com/google/google-auth-library-php
    // composer require guzzlehttp/client
    function get_google_calendar($gle_application_name) {
        // echo realpath('google-service-account.json');
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . realpath('google-service-account.json'));
        $scopes = ['https://www.googleapis.com/auth/calendar'];

        // create middleware
        $middleware = ApplicationDefaultCredentials::getMiddleware($scopes);
        $stack = HandlerStack::create();
        $stack->push($middleware);

        // create the HTTP client
        $client = new Client([
          'handler' => $stack,
          'base_url' => 'https://www.googleapis.com',
          'auth' => 'google_auth'  // authorize all requests
        ]);

        $client = new Google_Client();
        $client->setApplicationName($gle_application_name);
        $client->setScopes(Google_Service_Calendar::CALENDAR);
        $client->setAuthConfigFile('google-client.json');
        $client->setAccessType('offline');

        // re-authenticate and write access token with specific code
        // get this URL and put the auth code from the redirect into google-auth-code
        // to get a refresh token, this needs to be NEW access (so revoke it first) OR
        // change approval_type=force instead of auto on the redir
        // echo $client->createAuthUrl(); die();

        if (file_exists('google-auth-code')) {
            $auth_code = file_get_contents('google-auth-code');
            $auth_token = $client->authenticate($auth_code);
            $refreshToken = $client->getRefreshToken();
            $auth_token['refresh_token'] = $refreshToken;
            file_put_contents('google-auth.json', json_encode($auth_token)); // need to json encode it now 11/11/16
            unlink('google-auth-code');
        }
        
        if (!file_exists('google-auth.json')) {
            echo 'Go get a code at this URL and put it in google-auth-code; make sure this is a NEW access request (revoke first) or use approval_type=force to ensure you get a refresh token';
            echo $client->createAuthUrl();
        }

        // Load previously authorized credentials from a file.
        $client->setAccessToken(file_get_contents('google-auth.json'));
        
        // Refresh the token if it's expired.
        try {
            if ($client->isAccessTokenExpired()) {
                $refreshToken = $client->getRefreshToken();
                $client->refreshToken($refreshToken);
                $newAccessToken = $client->getAccessToken();
                $newAccessToken['refresh_token'] = $refreshToken;
                file_put_contents('google-auth.json', json_encode($newAccessToken));
            }
        }
        catch (Google_Auth_Exception $e) {
            echo 'Go get a code at this URL and put it in google-auth-code; make sure this is a NEW access request (revoke first) or use approval_type=force to ensure you get a refresh token';
            echo $client->createAuthUrl();
        }
        
        // Load previously authorized credentials from a file.
        $client->setAccessToken(file_get_contents('google-auth.json'));
        
        $service = new Google_Service_Calendar($client);
        return $service;
    }
    
    function create_google_event($gle, $gle_calendar_id, $event) {
        return $gle->events->insert($gle_calendar_id, $event);
    }
    
	// event needs to include the id
    function update_google_event($gle, $gle_calendar_id, $event_id, $event) {
        return $gle->events->update($gle_calendar_id, $event_id, $event);
    }
	
    function delete_google_event($gle, $gle_calendar_id, $event_id) {
        return $gle->events->delete($gle_calendar_id, $event_id);
    }
	
    function create_google_event_from_exchange_meeting($gle, $gle_calendar_id, $exch_mtg) {
		$gle_event = map_exchange_meeting_to_google_event($exch_mtg);
        return create_google_event($gle, $gle_calendar_id, $gle_event);
    }
	
    function update_google_event_from_exchange_meeting($gle, $gle_calendar_id, $event_id, $exch_mtg) {
		$gle_event = map_exchange_meeting_to_google_event($exch_mtg);
        // print_r($gle_event);
        return update_google_event($gle, $gle_calendar_id, $event_id, $gle_event);
    }
	
	function get_google_event_from_exchange_meeting($gle, $gle_calendar_id, $exch_mtg) {
        if (isset($exch_mtg->ItemId->Id)) {
            $exch_mtg_id = $exch_mtg->ItemId->Id;
            $_data = $gle->events->listEvents($gle_calendar_id,array('privateExtendedProperty' => 'ExchCalID=' . str_replace('=','',$exch_mtg_id)))->getItems();
            return end($_data);
        }
        else {
            print_r($exch_mtg);
            throw new Exception('ERROR NO ITEMID->ID!!!');
        }
	}
	
	function sync_google_event_from_exchange_meeting($gle, $gle_calendar_id, $exch_mtg) {
		$gle_event = get_google_event_from_exchange_meeting($gle, $gle_calendar_id, $exch_mtg);
		if (!empty($gle_event)) {
			update_google_event_from_exchange_meeting($gle, $gle_calendar_id, $gle_event->id, $exch_mtg);
		}
		else {
			create_google_event_from_exchange_meeting($gle, $gle_calendar_id, $exch_mtg);
		}
	}
	
	function delete_google_event_from_exchange_meeting($gle, $gle_calendar_id, $exch_mtg) {
		$gle_event = get_google_event_from_exchange_meeting($gle, $gle_calendar_id, $exch_mtg);
		if (!empty($gle_event)) {
			delete_google_event($gle, $gle_calendar_id, $gle_event->id);
		}
	}
	
	function create_google_event_from_arbiter_game($gle, $gle_calendar_id, $arbiter_game) {
		$gle_event = map_arbiter_game_to_google_event($arbiter_game);
        return create_google_event($gle, $gle_calendar_id, $gle_event);
    }
	
    function update_google_event_from_arbiter_game($gle, $gle_calendar_id, $event_id, $arbiter_game) {
		$gle_event = map_arbiter_game_to_google_event($arbiter_game);
        return update_google_event($gle, $gle_calendar_id, $event_id, $gle_event);
    }
	
	function get_google_event_from_arbiter_game($gle, $gle_calendar_id, $arbiter_game) {
		$ar = array('privateExtendedProperty' => 'ArbiterGameID=' . str_replace('=','',$arbiter_game['game_id']));
		$re = $gle->events->listEvents($gle_calendar_id, $ar)->getItems();
		return end($re);
	}
	
	function sync_google_event_from_arbiter_game($gle, $gle_calendar_id, $arbiter_game) {
		$gle_event = get_google_event_from_arbiter_game($gle, $gle_calendar_id, $arbiter_game);
		if (!empty($gle_event)) {
			update_google_event_from_arbiter_game($gle, $gle_calendar_id, $gle_event->id, $arbiter_game);
		}
		else {
			create_google_event_from_arbiter_game($gle, $gle_calendar_id, $arbiter_game);
		}
	}
	
	function map_arbiter_game_to_google_event($arbiter_game) {
        $_data = Arbiter_API::MapGameToGoogleEventArray($arbiter_game);

		$gle_event = new Google_Service_Calendar_Event($_data);

		// can't have ='s in the extended property value because we match it later with a key=value query
		$extendedProperties = New Google_Service_Calendar_EventExtendedProperties();
		$extendedProperties->setPrivate(array('ArbiterGameID' => str_replace('=','',$arbiter_game['game_id'])));
		$gle_event->setExtendedProperties($extendedProperties); 
		return $gle_event;
	}
	
	// exchange data is NOT the standard ews class because we want extended info
	function map_exchange_meeting_to_google_event($exch_mtg) {
        $subject = $exch_mtg->Subject;
		$timezone = $exch_mtg->TimeZone;
		switch ($timezone) {
            case 'GMT-1000':
            case '(UTC-10:00) Hawaii':
                $timezone = 'Pacific/Honolulu'; 
                break;
            case '(UTC-05:00) Eastern Time (US & Canada)':
                $timezone = 'America/New_York';
                break;
            case '(UTC-07:00) Mountain Time (US & Canada)':
                $timezone = 'America/Denver';
                break;
            case '(UTC) Dublin, Edinburgh, Lisbon, London':
                $timezone = 'Europe/Zurich';
                break;
            default: // better than not having something mapped and throwing an error?
                $timezone = 'Europe/Zurich';
                $subject = '** TIMEZONE MAY NOT BE CORRECT** ' . $subject;
                break;
        }

        // #TODO add other TZ mappings to IANA Time Zone Database name per https://developers.google.com/google-apps/calendar/v3/reference/events#resource-representations
        
        $_data = array(
            'summary' => $subject,
            'description' => 'Syncd by swc-google-exchange-calendar-sync from ' . $exch_mtg->addl_attributes->ews_calendar . PHP_EOL . $exch_mtg->Body->_,
            'start' => array(
                'dateTime' => $exch_mtg->Start,
                'timeZone' => $timezone,
            ),
            'end' => array(
                'dateTime' => $exch_mtg->End,
                'timeZone' => $timezone,
            ),
            'reminders' => array(
                'useDefault' => TRUE,
            ),
        );
		
		$_data['location'] = empty($exch_mtg->Location) ? 'TBD' : $exch_mtg->Location;

		
        $_data['attendees'] = array();
		
		// attendees will NOT be an array if there are only one
        // there may be no required attendees if it is a personal appointment
        if (isset($exch_mtg->OptionalAttendees)) {
            if (!is_array($exch_mtg->RequiredAttendees->Attendee)) $exch_mtg->RequiredAttendees->Attendee = array($exch_mtg->RequiredAttendees->Attendee);
            foreach ($exch_mtg->RequiredAttendees->Attendee as $attendee) {
                $email = strpos($attendee->Mailbox->EmailAddress, '@') !== FALSE ? $attendee->Mailbox->EmailAddress : 'unknown@example.com';
                $_data['attendees'][] = array(
                    'email' => 'noreplyemail.' . $email, // don't want to risk sending email notifications
                    'displayName' => $attendee->Mailbox->Name
                );
            }
        }
        else {
            $email = strpos($exch_mtg->Organizer->Mailbox->EmailAddress, '@') !== FALSE ? $exch_mtg->Organizer->Mailbox->EmailAddress : 'unknown@example.com';
            $_data['attendees'][] = array(
                'email' => 'noreplyemail.' . $email, // don't want to risk sending email notifications
                'displayName' => $exch_mtg->Organizer->Mailbox->Name
            );
        }
		if (isset($exch_mtg->OptionalAttendees)) {
			if (!is_array($exch_mtg->OptionalAttendees->Attendee)) $exch_mtg->OptionalAttendees->Attendee = array($exch_mtg->OptionalAttendees->Attendee);
			foreach ($exch_mtg->OptionalAttendees->Attendee as $attendee) {
				$_data['attendees'][] = array(
					'email' => 'noreplyemail.' . $attendee->Mailbox->EmailAddress, // don't want to risk sending email notifications
					'displayName' => $attendee->Mailbox->Name,
					'optional' => true
				);
			}
		}
		if ($exch_mtg->CalendarItemType == 'RecurringMaster') {
			$rec_type = null;
			$rec_int = null;
		
			if (isset($exch_mtg->Recurrence->DailyRecurrence)) { 
				$rec_type = 'DAILY'; 
				$rec_int = $exch_mtg->Recurrence->DailyRecurrence->Interval;
			}
			if (isset($exch_mtg->Recurrence->WeeklyRecurrence)) {
				$rec_type = 'WEEKLY';
				$rec_int = $exch_mtg->Recurrence->WeeklyRecurrence->Interval;
				if (isset($exch_mtg->Recurrence->WeeklyRecurrence->DaysOfWeek)) {
					$rec_int .= ';BYDAY=' .  strtoupper(substr($exch_mtg->Recurrence->WeeklyRecurrence->DaysOfWeek,0,2));
				}
			}
			if (isset($exch_mtg->Recurrence->MonthlyRecurrence)) {
				$rec_type = 'MONTHLY';
				$rec_int = $exch_mtg->Recurrence->MonthlyRecurrence->Interval;
				if (isset($exch_mtg->Recurrence->MonthlyRecurrence->DaysOfMonth)) {
					$rec_int .= ';BYDAY=' . strtoupper(substr($exch_mtg->Recurrence->MonthlyRecurrence->DaysOfMonth,0,2));
				}
			}
			if (isset($exch_mtg->Recurrence->EndDateRecurrence->EndDate)) {
                $rec_end = $exch_mtg->Recurrence->EndDateRecurrence->EndDate;
                $rec_end = date('Ymd\This\Z',strtotime($rec_end));
                // needs to be format of 2015-06-24T22:00:00Z and it comes in as 2015-09-09-10:00
            }			
			
            $_rec_rule = 'RRULE:FREQ=' . $rec_type . ';INTERVAL=' . $rec_int;
            if (isset($rec_end)) $_rec_rule .= ';UNTIL=' . $rec_end;
            $_data['recurrence'] = array($_rec_rule);
	    }

        $gle_event = new Google_Service_Calendar_Event($_data);
        
        // can't have ='s in the extended property value because we match it later with a key=value query
        $extendedProperties = New Google_Service_Calendar_EventExtendedProperties();
        $extendedProperties->setPrivate(array('ExchCalID' => str_replace('=','',$exch_mtg->ItemId->Id)));
        $gle_event->setExtendedProperties($extendedProperties); 
		return $gle_event;
	}
    
	function ews_sync($gle, $gle_config) {
		$reset = isset($_GET['reset']);
		$config = json_decode(file_get_contents('ews.json'));
		foreach ($config->accounts as $idx => $account) {
			if ($reset) $account->sync = 0;
			echo '[' . $account->server . '] Syncing changes since ' . (empty($account->sync) ? 'the beginning of time ' : $account->sync_time) . PHP_EOL;
			$ews = new ExchangeWebServices($account->server,$account->username,$account->password);
			// $ews_mtgs = get_ews_calendar_events($ews,strtotime('today -1 week'),strtotime('today +1 week'));
			try {
                try {
                    $ews_mtgs = get_ews_calendar_sync($ews, $account->sync);
                }
                catch (Exception $e) {
                    echo '[' . $account->server . '] Error retrieving calendar ' . $e->getMessage() . PHP_EOL;
                    continue;
                }
            
                // print_r($ews_mtgs);

                $_mtg_obj = null;

                foreach ($ews_mtgs['all'] as $_mtg) {
                    // print_r($_mtg);
                    
                    if (isset($_mtg['mtg_obj']->CalendarItem->ItemId)) $_mtg_obj = $_mtg['mtg_obj']->CalendarItem;
                    if (isset($_mtg['mtg_obj']->ItemId)) $_mtg_obj = $_mtg['mtg_obj'];

    				if ((isset($_mtg_obj->Start) && strtotime($_mtg_obj->Start) < strtotime(THRESHOLD_MAX_DATE)) && !(isset($_mtg_obj->CalendarItemType) && $_mtg_obj->CalendarItemType == 'RecurringMaster')) continue;
    				$_full_mtg_obj = get_ews_calendar_event($ews,$_mtg_obj->ItemId->Id);
    				$_mtg_obj = (array)(empty($_full_mtg_obj) ? $_mtg_obj : $_full_mtg_obj); 

    				// print_r($_mtg_obj);

                    $_mtg_obj['addl_attributes'] = (object)array(
                        'ews_calendar' => $account->server,
                    );
                    $_mtg_obj = (object)$_mtg_obj;

    				// print_r($_mtg_obj);
    				switch ($_mtg['action']) {
    					case 'update':
    					case 'create':
                            try {
        						sync_google_event_from_exchange_meeting($gle, $gle_config['calendars']['work'], $_mtg_obj);
                                echo '[' . $account->server . '] Created/Updated ' . $_mtg_obj->Subject . ' on ' . date('Y-m-d',strtotime($_mtg_obj->Start)) . PHP_EOL;
                            }
                            catch (Exception $e) {
                                echo '[' . $account->server . '] Error syncing changes ' . $e->getMessage() . PHP_EOL;
                                print_r($_mtg_obj);
                            }
    						break;
    					case 'delete':
                            try {
                                delete_google_event_from_exchange_meeting($gle, $gle_config['calendars']['work'], $_mtg_obj);
                                echo '[' . $account->server . '] Deleted event' . PHP_EOL;
                            }
                            catch (Exception $e) {
                                echo '[' . $account->server . '] Error removing meeting ' . $e->getMessage() . PHP_EOL;
                                print_r($_mtg_obj);
                            }
    						break;
    				}
    				ob_flush();
    				flush();
    			}
    			$config->accounts[$idx]->sync = $ews_mtgs['sync']; 
    			$config->accounts[$idx]->sync_time = date('Y-m-d h:i');
            }
            catch (Exception $e) {
                echo '[' . $account->server . '] Unknown error with sync ' . $e->getMessage() . PHP_EOL;
            }
		}
		file_put_contents('ews.json',json_encode($config));
	}
	
	function arbiter_sync($gle, $gle_config) {
		$config = json_decode(file_get_contents('arbiter.json'));
		foreach ($config->accounts as $idx => $account) {
			$arb = new Arbiter_API();
			$games = $arb->get_games($account->username, $account->password);
			foreach ($games as $game) {
				if ($game['game_id'] == 'Game') continue;
				sync_google_event_from_arbiter_game($gle, $gle_config['calendars']['soccer'], $game);
				echo '[ArbiterSports ' . $account->username . '] Created/updated ' . $game['home'] . ' vs. ' . $game['away'] . ' at ' . $game['site'] . ' on ' . $game['datetime'] . PHP_EOL;
			}
		}
	}
	
	$do_ews = file_exists('ews.json');
	$do_arbiter = file_exists('arbiter.json');
	
    $gle_config = json_decode(file_get_contents('googlecalendar.json'), TRUE);
	$gle = get_google_calendar($gle_config['application_name']);
?>
<pre>
<?php if ($do_ews): ?>
[swc-google-calendar-sync] Beginning EWS sync
<?php ews_sync($gle, $gle_config); ?>
[swc-google-calendar-sync] Completed EWS sync
<?php endif; ?>
<?php if ($do_arbiter): ?>
[swc-google-calendar-sync] Beginning ArbiterSports sync
<?php arbiter_sync($gle, $gle_config); ?>
[swc-google-calendar-sync] Completed ArbiterSports sync
<?php endif; ?>
</pre>