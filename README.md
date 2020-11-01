# TPLinkKasa - Stand-alone php Class providing access to TPLink KASA HS100-Family Cloud-Controlled WiFi-Switches

Author: rh1492raw@gmail.com
License: none
[Relates to / Inspired by: https://github.com/TheHackLife/TPLink-hs100-PHP-REST-API/blob/master/tplink.class.php](https://github.com/TheHackLife/TPLink-hs100-PHP-REST-API/blob/master/tplink.class.php)
My thanks to to [Joan "TheHackLife" Manual (https://github.com/TheHackLife)] for providing the base logic (API reverse engineering) of this package


## Pre-requisites
It just needs a basic php7.3++ Environment.

## Outline
 This Class can be used to switch TP Link KASA HS100-Family WiFi-Switches (HS-100/HS110)
 The builds a basis for extending it to other devices
 API calls are provided as granular functions
 constructor is built with file "caching" to avoid redundant repeated calls to the KASA API but depends on whether the PHP-Code can write in the local folder (location of the library)

## Some details
 A capability toggle (*PHP_CAN_WRITE_TO_FILES_IN_PACKAGE_FOLDER*) can be used to turn the file "caching" off.
 Rewritten php TP Link Kasa Library https://github.com/TheHackLife/TPLink-hs100-PHP-REST-API/blob/master/tplink.class.php
 - added local file caching
 - added method phpDocs and comments
 - separted queries and extracted some reused constants
 - divided it into granular, resuable methods

 ## Class TPLinkKasa
     constructor: establishes the connection and authentication, has switches to force a re-authentication or a refetch of the device list
     sendQuery:     runs a sendQuery
     getDeviceList: get list of devices (from cached file devices.json or the internet)
     togglePlugbyId(): toggle the relais switch of Plug x by ID
     togglePlugByName(): toggle the relaisswitch of Plug x by togglePlugByName
     ...

 ## Possible Improvements:
 1) App throws lots of exceptions in many situations - maybe build a centralized error handler
 2) Caching abstraction - Relying on writing local files might be more than standard server environments offer (see *PHP_CAN_WRITE_TO_FILES_IN_PACKAGE_FOLDER* switch)

# Basic Usage

## Initialization

```php
     include 'TPLinkKasa.php';
     // Try using "cached" logins, if not available, login and fetch device list, store ClientID, Authentication Token and Device list in "cache" files for reuse
     $myTPLink = new TPLinkKasa(<username>, <password>, false , false);
```

## Toggle a plug (relay) by alias (name)
```php
    $myTPLink->togglePlugByName('Heizungslicht');
```

## Set a plug state 
```php
    $deviceId = $myTPLink->getDeviceIDbyName(<PlugAliasName>);
    $simplifiedDeviceList= $myTPLink->getDevicesById();
    $appServerUrl = $simplifiedDeviceList['deviceURL'];
    $myTPLink->setPlugState(true,$deviceId,$appServerUrl); // Turn device on
    $myTPLink->setPlugState(false,$deviceId,$appServerUrl); // Turn device off
```

## Get a plug state
(with deviceId and appServerUrl as above)
```php
    $myTPLink->getPlugStateBool($deviceId, $appServerUrl);
```

# More Features?
The package is self-explanatory using phpDoc and lots of comments. Therefore, it can be extended easily.
I am happy to add contributors...