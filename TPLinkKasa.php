<?php
/**
  * Stand-alone php Class providing access to TPLink KASA HS100-Family Cloud-Controlled WiFi-Switches
  * It just needs a basic php7.3++ Environment.
  *
  * This Class can be used to switch TP Link KASA HS100-Family WiFi-Switches (HS-100/HS110)
  *
  * The builds a basis for extending it to other devices
  * API calls are provided as granular functions
  * constructor is built with file "caching" to avoid redundant repeated calls to the KASA API but depends on whether the PHP-Code can write in the local folder (location of the library)
  * A capability toggle (PHP_CAN_WRITE_TO_FILES_IN_PACKAGE_FOLDER) can be used to turn the file "caching" off.
  *
  * Rewritten php TP Link Kasa Library https://github.com/TheHackLife/TPLink-hs100-PHP-REST-API/blob/master/tplink.class.php
  *
  * added local file caching
  * added method phpDocs
  * separted queries and extracted some reused constants
  * divided it into granular, resuable methods
  *
  *
  * Class TPLinkKasa
  *     __constructor: establishes the connection and authentication
  *     sendQuery:     runs a sendQuery
  *     getDeviceList: get list of devices (from cached file devices.json or the internet)
  *     togglePlugbyId(): toggle the relais switch of Plug x by ID
  *     togglePlugByName(): toggle the relaisswitch of Plug x by togglePlugByName
  *     ...
  *
  * Possible Improvements:
  * 1) App throws lots of exceptions in many situations - maybe build a centralized error handler
  * 2) Caching abstraction
  *
 **/
  class TPLinkKasa
  {
      // ClientUUID (generated identifier for this client), device summary data and the authentication token are stored in simple local text files where possible
      const UUIDFILE = 'TMP_UUID.txt';
      const DEVICEOBJFILE = 'TMP_DEVICES.json';
      const TOKENFILE = 'TMP_TOKEN.txt';

      // Capability switch - don't write to local files if it is not supported by the PHP settings
      const PHP_CAN_WRITE_TO_FILES_IN_PACKAGE_FOLDER = true;

      // Kasa API Endpoint
      const KASA_API_BASE_URL = 'https://wap.tplinkcloud.com';

      // these parameters don't seem to have an impact on the API, I would guess they are from intercepted traffic package data
      // can be left empty
      const KASA_GET_PARAMS = ''; /*'&appName=Kasa_iOS'.
                            '&ospf=iOS%2010.2.1'.
                            '&appVer=1.4.3.390'.
                            '&netType=wifi'.
                            '&locale=en_GB'; */

      /**
       * @var string Client ID for reuse (UUID format)
       */
      private $clientID;

      /**
       * @var string - authentication token from authentication (is created in the constructor via apiLogon)
       */
      private $token;

      /**
       * @var array - shared array of the devices
       */
      private $devices;

      /**
       * Indexed device objects.
       *
       *  @var array - devicesByID[] - array indexed by device ID
       */
      private $devicesByID = [];
      /**
       * for the high level functions:.
       *
       * @var array devicesByName[] array indexed by device label
       */
      private $devicesByName = [];

      /**
       * Constructor - creates a ClientUUID, logs in and fetches the device list
       *    (added simple file caching to not reload redundant info again and again, relogin, refetch can be forced via optional constructor parameters).
       *
       * @param string, - user name to login to KASA API
       * @param string, - pw of KASA API
       * @param bool $forceReAuthentication    - this triggers a forced new creation of a ClientUUID and a relogin (new token) - optional (defaults to don't reauthenticate (false))
       * @param bool $forceRefreshOfDeviceFile - flag to forces a reset: created new clientUUID, authenticate and store token, then reload the device file - optional (defaults to don't refresh the device file (false))
       */
      public function __construct(
        string $kasaUserName,
        string $kasaPassword,
        bool $forceReAuthentication = false,
        bool $forceRefreshDeviceFile = false
        ) {
          // Assure our client has a client ID to use (with primitive file cache)
          if (file_exists(self::UUIDFILE) && !$forceReAuthentication) {
              $this->clientID = file_get_contents(self::UUIDFILE);
          } else {
              $this->clientID = $this->createClientUUID();
              if (self::PHP_CAN_WRITE_TO_FILES_IN_PACKAGE_FOLDER) {
                  file_put_contents(self::UUIDFILE, $this->clientID);
              }
          }

          // Assure we have a valid login session
          if (file_exists(self::TOKENFILE) && !$forceReAuthentication) {
              $this->token = file_get_contents(self::TOKENFILE);
          } else {
              $this->token = $this->apiLogon($kasaUserName, $kasaPassword);
              if (self::PHP_CAN_WRITE_TO_FILES_IN_PACKAGE_FOLDER) {
                  file_put_contents(self::TOKENFILE, $this->token);
              }
          }

          // Assure, we have a device list
          if (file_exists(self::DEVICEOBJFILE) && !$forceRefreshDeviceFile) {
              $this->devices = (array) json_decode(file_get_contents(self::DEVICEOBJFILE), true);
          } else {
              $this->devices = $this->getKasaDeviceList();
              if (self::PHP_CAN_WRITE_TO_FILES_IN_PACKAGE_FOLDER) {
                  file_put_contents(self::DEVICEOBJFILE, json_encode($this->devices));
              }
          }

          $this->createDeviceArrays($this->devices);

          return $this;
      }

      /**
       * Creates a KASA API compatible ClientID used for this library at one point in time.
       * Called from the constructor if not cached.
       *
       * @return string UUID of the format "B762D589-2CF4-4AF8-97F3-3912444626E6" (1 double-quadruplet, 3 single quadruplets, 1 triple-quadruplet)
       */
      public function createClientUUID()
      {
          $data = openssl_random_pseudo_bytes(16);
          assert(16 == strlen($data));

          $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        $deviceId = strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)));

          return $deviceId;
      }

      /**
       * @param bool $isJSON - defines whether the request is for a JSON request or applicatio/x-www...
       *
       * @return string HeaderString for stream_context_create
       */
      public function createHTTPHeaderForStream(bool $isJSON)
      {
          if ($isJSON) {
              return "Content-Type: application/json\r\n".
            "Accept: */*; \r\n";
          } else {
              return "Content-type: application/x-www-form-urlencoded\r\n";
          }
      }

      /*
       * @param string $method - HTTP Method ('GET', 'POST', 'PUT', ...)
       * @param bool $isJSON - defines whether the format to send is JSON (defines the header from createHTTPHeaderForStream)
       * @param string $url - TPLINK-Kasa-API-URL
       * @param array $data - Data to send either JSON or plain (tbd)
       *
       * @return array $result - return value of the API request
       */
      public function sendQuery(string $method,
                                bool $isJSON,
                                string $url,
                                array $data
                                ) {
          $streamOptions = [
                            'http' => [
                                        'header' => $this->createHTTPHeaderForStream($isJSON),
                                        'method' => $method,
                                        'content' => ($isJSON) ? json_encode($data) : http_build_query($data),
                            ],
                        ];
          $context = stream_context_create($streamOptions);

          // ready for the call
          try {
              $response = file_get_contents($url, false, $context);
          } catch (Exception $e) {
              throw $e;
          }

          // simple error detection
          if (false === $response) {
              echo 'Exception found : dumping the resonse object:';
              var_dump($response);
              throw new Exception('issue with connecting, authenticating or fetching from the API');
          }

          // regular request resposne

          // extract cookies from the response header (magic variable)
          $cookies = [];
          foreach ($http_response_header as $hdr) {
              if (preg_match('/^Set-Cookie:\s*([^;]+)/', $hdr, $matches)) {
                  parse_str($matches[1], $tmp);
                  $cookies += $tmp;
              }
          }

          // response payload
          $responsePayload = json_decode($response, JSON_PRETTY_PRINT);

          // regular case (JSON Encoding worked without error )
          if (0 === json_last_error()) {
              // we have a response
              return [$responsePayload, $cookies];
          }

          // JSON decoding didn't work out - return raw info
          return [$response, $cookies];
      }

      /**
       * Method to login and create an access Token
       * The input parameters are the KASA logins created when initiating the TPLink KASA App Setup (e.g. on your mobile).
       *
       * @param string $userName - KASA user name
       * @param string $password - KASA password
       *
       * @return string $token - reusable authentication token - validity seems long (possibly permanent)
       *
       * @throws Exception - if network errors appear or an unexpected response is encountered
       */
      public function apiLogon(string $userName, string $password)
      {
          $url = self::KASA_API_BASE_URL;
          // login array as JSON Object
          $body = [
            'method' => 'login',
            'params' => [
                'cloudUserName' => $userName,
                'appType' => 'Kasa_iOS',
                'terminalUUID' => $this->clientID,
                'cloudPassword' => $password,
            ],
        ];

          // Send the request
          try {
              $response = $this->sendQuery('POST', $isJSON = true, $url, $body);
              if (2 != count($response)) {
                  throw new Exception('API LOgin - Reponse unexpected, double-check the request code');
              }
              $responseData = $response[0];
              $responseCookie = $response[1];
          } catch (Exception $e) {
              throw new Exception('error when trying to login to API - exception message'.$e->getMessage());
          }
          if (array_key_exists('error_code', $responseData) && (0 === $responseData['error_code'])) {
              return $responseData['result']['token'];
          }

          throw new Exception('Validation of the credentials with kasa API failed - please check your credentials');
      }

      /**
       * This function calls the KASA getDeviceList Inventory and returns the device object (which is cached in the constructor.
       *
       * @return array $KasaDeviceListObject - raw Kasa device inventory - the function additionally creates cleaned up devicesById and devicesByName indexed arrays, that allow quick access to id, name=alias, mac, apiURL, status
       */
      public function getKasaDeviceList()
      {
          $requestURL = self::KASA_API_BASE_URL.
                '/?token='.$this->token.
                '&termID='.$this->clientID.
                self::KASA_GET_PARAMS;
          $queryData = [
              'method' => 'getDeviceList',
          ];

          // Send the request
          try {
              $response = $this->sendQuery('POST',
                                         $isJSON = true,
                                         $requestURL,
                                         $queryData);
              if (2 != count($response)) {
                  throw new Exception('getKasaDeviceList - Reponse unexpected, double-check the request code');
              }
              $responseData = $response[0];
              $responseCookie = $response[1];
          } catch (Exception $e) {
              throw $e;
          }

          // Missing - Evaluate the response []
          // collect the plugs and request each plugs state
          $plugs = [];
          $devices = $responseData['result']['deviceList'];

          // Index the data in the two arrays devicesByID and devicesByName
          $this->createDeviceArrays($devices);

          return $devices;
      }

      /**
       * This function populates the indexed arrays by ID and by Name
       *    writes to the class variables -> $this->deviceByName and $thisDeviceByID
       *    NOTE: This is called in the constructor, therefore is usually not called again.
       *
       * @param array $deviceInfoRawData - data from the getDeviceInfo call
       *
       * @return bool true - always true
       */
      public function createDeviceArrays(array $deviceInfoRawData)
      {
          foreach ($deviceInfoRawData as $device) {
              $deviceID = $device['deviceId'];
              $name = $device['alias'];
              $mac = $device['deviceMac'];
              $useURL = $device['appServerUrl'];
              $working = $device['status'];

              $this->devicesByID[$deviceID] = [
                'name' => $name, // duplicate but possible easier to interpret
                'alias' => $name,
                'mac' => $mac,
                'deviceURL' => $useURL,
                'status' => $working,
                'id' => $deviceID,
            ];
              $this->devicesByName[$name] = $this->devicesByID[$deviceID]; // byName is the same value, just indexed differently
          }

          return true;
      }

      /**
       * Encapsulated logic to calculate the relay state of a Kasa Plug.
       *
       * @param array $plugState - input the state received from getPlugState
       *
       * @return bool $state - true if relay is on, false, if relay is off
       */
      public function getRelayStateFromPlugState(array $plugState)
      {
          // TODO: add some checks for existence here (if wrong input, this will throw index not found exceptions)

          return (0 != $plugState['system']['get_sysinfo']['err_code']) ? false : ((0 == $plugState['system']['get_sysinfo']['relay_state']) ? false : true);
      }

      /**
       * Call the KASA API to retrieve the Plug state
       * (note that there are no checks on whether a respective device supports this feature)
       * This has been tested for HS110.
       * Please note: that little of the return values (geo location, schedule, ...) is used, basically the "dynamic plug state" only.
       *
       * @param string $deviceID     - id of the device to request the info for (KASA Device ID)
       * @param string $appServerUrl - Url to responsible app server for device from device list
       *
       * @return array $data - plug state data rawData especially
       */
      public function getPlugState(string $deviceID, string $appServerUrl)
      {
          $requestURL = $appServerUrl.
                '/?token='.$this->token.
                '&termID='.$this->clientID.
                self::KASA_GET_PARAMS;
          $plugJson = ['method' => 'passthrough',
                     'params' => [
                            'deviceId' => $deviceID,
                            'requestData' => '{"schedule":{"get_next_action":{}},"system":{"get_sysinfo":{}}}',
                            ],
                    ];

          // Send the request
          try {
              $response = $this->sendQuery('POST',
                                       $isJSON = true,
                                       $requestURL,
                                       $plugJson);

              if (2 != count($response)) {
                  throw new Exception('API LOgin - Reponse unexpected, double-check the request code');
              }
              $responseData = $response[0];
              $responseCookie = $response[1];
          } catch (Exception $e) {
              throw $e;
          }

          if (array_key_exists('result', $responseData) && array_key_exists('responseData', $responseData['result'])) {
              $plugState = json_decode($responseData['result']['responseData'], JSON_PRETTY_PRINT);

              return $plugState;
          }

          return null;
      }

      /**
       * Call the KASA API to set a plug state
       * (note that there are no checks on whether a respective device supports this feature)
       * This has been tested for HS110.
       *
       * @param bool   $state         - target state of plug (true: relays is in "on" state, false: relay is off)
       * @param string $deviceID      - ID of the device to switch (KASA Device ID)
       * @param string $appServierUrl - Device Specific app server URL (from Device info)
       *
       * @return bool $alwaysTrue - 'true'
       *
       * @throws Excpetion - in case the HTTP request fails or the resonse is not of the expected format
       */
      public function setPlugState(bool $state, string $deviceID, string $appServerUrl)
      {
          $requestURL = self::KASA_API_BASE_URL.
                     '/?token='.$this->token.
                     '&termID='.$this->clientID.
                     self::KASA_GET_PARAMS;

          $plugStateChangeQueryJSON = [
                'method' => 'passthrough',
                'params' => ['deviceId' => ''.$deviceID.'',
                'requestData' => '{"system":{"set_relay_state":{"state":'.(($state) ? 1 : 0).'}}}', ], ];

          try {
              $response = $this->sendQuery('POST',
                                     $isJSON = true,
                                     $requestURL,
                                     $plugStateChangeQueryJSON);

              if (2 != count($response)) {
                  throw new Exception('API LOgin - Reponse unexpected, double-check the request code');
              }
              $responseData = $response[0];
              $responseCookie = $response[1];
          } catch (Exception $e) {
              throw $e;
          }

          return true;
      }

      /**
       * Toggle the relay of a plug, the plug is addressed by Device ID
       * // Lower level function: toggle by Device ID.
       *
       * @param string $deviceID - ID of the device to toggle (KASA Device ID)
       *
       * @return bool $alwaysTrue - 'true'
       */
      public function togglePlugState(string $deviceID)
      {
          echo "Entering Toggle State<br>\n";

          $appServerUrl = $this->devicesByID[$deviceID]['deviceURL'];

          // For the relay Status, another request needs to be sent
          $plugState = $this->getPlugStateBool($deviceID, $appServerUrl);

          echo 'Found state: '.($plugState ? 'on' : 'off')."<br>\n";

          return $this->setPlugState(!$plugState, $deviceID, $appServerUrl);
      }

      /**
       * Toggle the relay of a plug, the plug is addressed by Device Name (alias given in Kasa)
       * // Higher level function: toggle by Device Name.
       *
       * @param string $alias - Device Name (Alias / Label) of the device to toggle
       *
       * @return bool $alwaysTrue - 'true'
       */
      public function togglePlugByName(string $alias)
      {
          return $this->togglePlugState($this->getDeviceIDbyName($alias));
      }

      /**
       * @param string $deviceID     - ID of the device to toggle (KASA Device ID)
       * @param string $appServerUrl - URL to the Device Specific API Endpoint (from device inventory, usually the same endpoint as the global API Endpoint)
       *
       * @return bool $relayState - true if on, false if off
       */
      public function getPlugStateBool(string $deviceID, string $appServerUrl)
      {
          // For the relay Status, another request needs to be sent
          $plugState = $this->getPlugState($deviceID, $appServerUrl);
          if (!$plugState) {
              throw new Exception('Could not get the plug state for devide '.$deviceID);
          }

          $relayState = $this->getRelayStateFromPlugState($plugState); // this has 'on' or 'off' as value

          return $relayState;
      }

      /**
       * Simple lookup of the DeviceID by the alias / name of the device
       * Allows comfortable operations
       * (is public!).
       *
       * @param string $deviceName - name of the device (as given during the KASA Device setup)
       *
       * @return string $deviceID - KASA Device ID
       */
      public function getDeviceIDbyName(string $alias)
      {
          return $this->devicesByName[$alias]['id'];
      }

      /**
       * ClientUUID getter.
       *
       * @return string ClientUUID.
       */
      public function getClientUUID()
      {
          return $this->ClientID;
      }

      /** Devices raw array getter */
      public function getDevices()
      {
          return $this->devices;
      }

      /** Devices By ID array Getter */
      public function getDevicesById()
      {
          return $this->devicesByID;
      }
  }
