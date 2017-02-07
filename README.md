# google-exchange-calendar-sync
#
# Syncs exchange calendars to google calendars
# Syncs arbiter sports games to google calendars
#
# Dependencies:
#   PHP 5.5+
#	php_soap    	
#   Composer:
#   	"google/apiclient": "2.0",
#    	"google/auth": "0.8",
#       "guzzlehttp/guzzle": "~6.0"
#
# Setup:
#	1) Pull repo and install dependencies
#	2) Configure accounts in ews.json and/or arbiter.json
#	3) Configure See example configurations in googlecalendar.json, ews.json and/or arbiter.json for accounts
#	4) Get all the Google securities...
#		a) Put Google Service Account JSON in google-service-account.json
#			If you don't have one, you'll need a service account to auth the Google Calendar API
#			https://console.developers.google.com/permissions/serviceaccounts - use the JSON provided when creating a new service account
#			https://developers.google.com/google-apps/calendar/v3/reference/events/get
#		b) Put Google Client ID JSON in google-client.json
#			OAuth 2.0 client ID from https://console.developers.google.com/apis/credentials
# 	5) Run index.php to sync