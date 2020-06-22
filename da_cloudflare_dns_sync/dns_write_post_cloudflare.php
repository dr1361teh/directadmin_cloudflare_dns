<?php

/**
 * DirectAdmin DNS to Cloudflare sync
 */

require_once('vendor/autoload.php');
$key = new \Cloudflare\API\Auth\APIKey($cloudflare_email, $cloudflare_api_key);
$adapter = new Cloudflare\API\Adapter\Guzzle($key);
$zones = new \Cloudflare\API\Endpoints\Zones($adapter);

logMessage("------------------ dns_write_post.sh ------------------");

$domain = getenv('DOMAIN');

$errorList = array();
$hasErrors = false;

// Retrieve DNS Records from Environment Variables
$a = getenv('A');
$cname = getenv('CNAME');
$mx = getenv('MX');
$mx_full = getenv('MX_FULL');
$ns = getenv('NS');
$ptr = getenv('PTR');
$txt = getenv('TXT');
$spf = getenv('SPF');
$aaaa = getenv('AAAA');
$srv = getenv('SRV');
$aTTL = getenv('A_TIME');
$txtTTL = getenv('TXT_TIME');
$mxTTL = getenv('MX_TIME');
$cnameTTL = getenv('CNAME_TIME');
$ptrTTL = getenv('PTR_TIME');
$nsTTL = getenv('NS_TIME');
$aaaaTTL = getenv('AAAA_TIME');
$srvTTL = getenv('SRV_TIME');

// $serial = getenv('SERIAL');
// $srv_email = getenv('EMAIL');
// $domainIP = getenv('DOMAIN_IP');
// $serverIP = getenv('SERVER_IP');
// $ds = getenv('DS');
//$dsTTL = getenv('DS_TIME');
//$spfTTL = getenv('SPF_TIME');

// Retrieve zoneID for domain from Cloudflare - if zone doesn't exist add it

$zoneID = null;
try {
    $zoneID = $zones->getZoneID($domain);
} catch (\Exception $e) {
    if ($e->getMessage() == 'Could not find zones with specified name.') {
        // Create zone
        try {
            $result = $zones->addZone($domain);
            if ($result->name == $domain) {
                $zoneID = $result->id;
            } else {
                throw new Exception("Error Adding Domain", 1);
            }
        } catch (\Exception $e) {
            logMessage('Exception Caught: ' . $e->getMessage());
            exit;
        }
    } else {
        exit;
    }
}

logMessage('Zone ID for ' . $domain . ' - ' . $zoneID);

/**
 * Load existing DNS records for the domain
 */
$dns = new \Cloudflare\API\Endpoints\DNS($adapter);
$existingRecords = $dns->listRecords($zoneID)->result;

/**
 * Array of dns records to add to Cloudflare
 */
$recordsToAdd = array();

/**
 * Array of dns records that already exist on Cloudflare
 */
$recordsThatExist = array();

// NOTE don't parse NS records

// parse A records
$output = parseInput($a);
if (count($output) > 0) {
    foreach ($output as $value) {
        $record = (object) array(
            'type' => 'A',
            'name' => qualifyRecordName($value->key),
            'content' => $value->value,
            'ttl'   => $aTTL,
        );
        if (doesRecordExist($existingRecords, $record) === false) {
            $recordsToAdd[] = $record;
        } else {
            $recordsThatExist[] = $record;
        }
    }
}

// parse TXT records
$output = parseInput($txt);
if (count($output) > 0) {
    foreach ($output as $value) {
        $record = (object) array(
            'type' => 'TXT',
            'name' => qualifyRecordName($value->key),
            'content' => trim(preg_replace(array('/"\s+"/', '/"/'), array('', ''), trim($value->value, '()'))),
            'ttl'   => $txtTTL,
        );
        if (doesRecordExist($existingRecords, $record) === false) {
            $recordsToAdd[] = $record;
        } else {
            $recordsThatExist[] = $record;
        }
    }
}

// parse MX records
$output = parseInput($mx_full);
if (count($output) > 0) {
    foreach ($output as $value) {
        preg_match('/(\d+) (.*)/', $value->value, $parsedValue);
        $record = (object) array(
            'type' => 'MX',
            'name' => qualifyRecordName($value->key),
            'priority' => $parsedValue[1],
            'content' => qualifyRecordName($parsedValue[2]),
            'ttl'   => $mxTTL,
        );
        if (doesRecordExist($existingRecords, $record) === false) {
            $recordsToAdd[] = $record;
        } else {
            $recordsThatExist[] = $record;
        }
    }
}

// parse CNAME records
$output = parseInput($cname);
if (count($output) > 0) {
    foreach ($output as $value) {
        $record = (object) array(
            'type' => 'CNAME',
            'name' => qualifyRecordName($value->key),
            'content' => qualifyRecordName($value->value),
            'ttl'   => $cnameTTL,
        );
        if (doesRecordExist($existingRecords, $record) === false) {
            $recordsToAdd[] = $record;
        } else {
            $recordsThatExist[] = $record;
        }
    }
}

// parse PTR records
$output = parseInput($ptr);
if (count($output) > 0) {
    foreach ($output as $value) {
        $record = (object) array(
            'type' => 'PTR',
            'name' => $value->key,
            'content' => qualifyRecordName($value->value),
            'ttl' => $ptrTTL,
        );
        if (doesRecordExist($existingRecords, $record) === false) {
            $recordsToAdd[] = $record;
        } else {
            $recordsThatExist[] = $record;
        }
    }
}

// parse AAAA records
$output = parseInput($aaaa);
if (count($output) > 0) {
    foreach ($output as $value) {
        $record = (object) array(
            'type' => 'AAAA',
            'name' => qualifyRecordName($value->key),
            'content' => $value->value,
            'ttl' => $aaaaTTL,
        );
        if (doesRecordExist($existingRecords, $record) === false) {
            $recordsToAdd[] = $record;
        } else {
            $recordsThatExist[] = $record;
        }
    }
}

// parse SRV records
$output = parseInput($srv);
if (count($output) > 0) {

    foreach ($output as $value) {
        preg_match('/(\d+) (\d+) (\d+) (.*)/', $value->value, $parsedValue);

        $fullSRVname = qualifyRecordName($value->key);
        preg_match('/^(.*)\._(tcp|udp|tls)\.(.*)$/', $fullSRVname, $srv_match);

        $record = (object) array(
            'type' => 'SRV',
            'name' => $fullSRVname,
            'priority' => $parsedValue[1],
            'content' => qualifyRecordName($parsedValue[4]),
            'data' => array(
                'name'  => $srv_match[3],
                'weight' => (int) $parsedValue[2],
                'port' => (int) $parsedValue[3],
                'target' => qualifyRecordName($parsedValue[4]),
                'proto' => '_' . $srv_match[2],
                'service' => $srv_match[1],
                'priority' => (int) $parsedValue[1],
            ),
            'ttl' => $srvTTL,
        );
        if (doesRecordExist($existingRecords, $record) === false) {
            $recordsToAdd[] = $record;
        } else {
            $recordsThatExist[] = $record;
        }
    }
}

/**
 * Delete records from Cloudflare that don't exist in DirectAdmin
 * 
 * While ignoring nameserver records, go through all existingRecords and see if there is a match in recordsToAdd or recordsThatExist - if not, delete the record.
 */
$keysToDelete = array();
foreach ($existingRecords as $record) {
    if ($record->type != 'NS') {
        if (!isRecordCurrent($recordsToAdd, $recordsThatExist, $record)) {
            array_push($keysToDelete, $record->id);
            logMessage('Queue Record to Delete: ' . $record->id . ' - ' . $record->name . "\t" . $record->type . "\t" . $record->content);
        }
    }
}
if (count($keysToDelete) > 0) {
    foreach ($keysToDelete as $key) {
        $success = $dns->deleteRecord($zoneID, $key);
        logMessage('Delete Record ID ' . $key  . "\t" . ($success == true ? 'SUCCESSFUL' : 'FAILED'));
    }
}

/**
 * Add new records to Cloudflare
 */
foreach ($recordsToAdd as $record) {
    $priority = isset($record->priority) ? $record->priority : '';
    $data = isset($record->data) && count($record->data) > 0 ? $record->data : [];
    $proxied = false;
    $ttl = $record->ttl > 0 ? $record->ttl : 0; // use default TTL
    try {
        $success = $dns->addRecord($zoneID, $record->type, $record->name, $record->content, $ttl, $proxied, $priority, $data);
        logMessage('Add Record: ' . $record->name . "\t" . $record->type . "\t" . $record->content . "\t" . ($success == true ? 'SUCCESSFUL' : 'FAILED'), !$success);
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        logMessage('Add Record: ' . $record->name . "\t" . $record->type . "\t" . $record->content . "\t" . 'FAILED', true);
        logMessage($e->getResponse()->getBody()->getContents(), true);
    }
}

logMessage('Result: ' . get_records_modified_text($keysToDelete) . ' deleted, ' . get_records_modified_text($recordsToAdd) . ' added.');

// Output any errors to be displayed in the DirectAdmin console
if ($hasErrors) {
    echo join("\n\n", $errorList);
    exit(1);
} else {
    exit(0);
}

function get_records_modified_text($mod_array)
{
    if (count($mod_array) == 1) {
        return '1 record';
    }
    return count($mod_array) . ' records';
}

/**
 * Log a message to the php error log
 */
function logMessage($message, $error = false)
{
    global $log_messages, $log_to_file, $log_filename, $errorList, $hasErrors;
    if ($error) {
        $hasErrors = true;
        $errorList[] = $message;
    }
    if (!$log_messages) {
        return;
    }
    $timestamp = date('Y-m-d H:i:s');
    if ($log_to_file) {
        error_log($timestamp . " - " . $message . "\n", 3, $log_filename);
    } else {
        error_log($timestamp . " - " . $message . "\n");
    }
}

/**
 * Search through array of records for a particular record
 */
function doesRecordExist($records, $record)
{
    if (count($records) == 0) {
        return false;
    }
    foreach ($records as $compare) {
        if ($record->type == 'SRV') {
            if (!isset($compare->data)) {
                continue;
            }
            $compare_data = (object) $compare->data;
            $record_data = (object) $record->data;
            if (
                compare_records($compare->type, $record->type) &&
                compare_records($compare->name, $record->name) &&
                compare_records($compare->ttl, $record->ttl) &&
                compare_records($compare_data->weight, $record_data->weight) &&
                compare_records($compare_data->target, $record_data->target) &&
                compare_records($compare_data->proto, $record_data->proto) &&
                compare_records($compare_data->service, $record_data->service) &&
                compare_records($compare_data->priority, $record_data->priority) &&
                compare_records($compare_data->port, $record_data->port)
            ) {
                return true;
            }
        } else {
            if (
                compare_records($compare->type, $record->type) &&
                compare_records($compare->name, $record->name) &&
                compare_records($compare->content, $record->content) &&
                compare_records($compare->ttl, $record->ttl)
            ) {
                if ($record->type == 'MX' && !compare_records($compare->priority, $record->priority)) {
                    continue;
                }
                return true;
            }
        }
    }
    return false;
}

/**
 * Compare two records to see if they match
 * @param string $r1 Existing Record on Cloudflare
 * @param string $r2 Record on server
 */
function compare_records($r1, $r2)
{
    return $r1 == $r2;
}

/**
 * Search through recordsToAdd and recordsThatExist to see if the existing record should be kept or deleted
 */
function isRecordCurrent($recordsToAdd, $recordsThatExist, $record)
{
    if (count($recordsToAdd) > 0) {
        if (doesRecordExist($recordsToAdd, $record)) {
            return true;
        }
    }
    if (count($recordsThatExist) > 0) {
        if (doesRecordExist($recordsThatExist, $record)) {
            return true;
        }
    }
    return false;
}

/**
 * Parse Environment variables from DirectAdmin and split into key and value pairs
 */
function parseInput($input)
{
    $pairs = explode('&', $input);
    $results = array();
    foreach ($pairs as $pair) {
        if ($pair == "") continue;
        list($key, $value) = explode('=', $pair, 2);
        if ($key == "" || $value == "") continue;
        $object = new StdClass();
        $object->key = $key;
        $object->value = $value;
        $results[] = $object;
    }
    return $results;
}

/**
 * Fully qualify the record name
 */
function qualifyRecordName($name)
{
    global $domain;
    return trim($name . (substr($name, -1) !== '.' ? '.' . $domain : ''), '.');
}
