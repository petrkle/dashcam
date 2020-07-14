<?php

function get_config()
{
    return parse_ini_file('dashcam.ini');
}

function get_parts()
{
    $config = get_config();
    $chunks = glob($config['in']."/*.".$config['suffix']);
    asort($chunks);

    $split = $config['split'];
    $splittolerance = $config['splittolerance'];
    $parts = array();
    $part = 0;
    $prevtime = 0;

    foreach ($chunks as $filename) {
        $file = basename($filename);
        $time = filename2time($file);

        if ($prevtime > 0) {
            $diff = $time - $prevtime;
            if ($diff > ($split-$splittolerance) and $diff < ($split+$splittolerance)) {
                if (!is_array($parts[$part]['files'])) {
                    $parts[$part]['files'] = array();
                }
                array_push($parts[$part]['files'], $filename);
            } else {
                $part++;
                $parts[$part]['files'] = array();
                array_push($parts[$part]['files'], $filename);
            }
        } else {
            $parts[$part]['files'] = array();
            array_push($parts[$part]['files'], $filename);
        }
        $prevtime = $time;
    }

    return $parts;
}

function signalHandler($signal)
{
    global $pidFile;
    ftruncate($pidFile, 0);
    if (is_file(WORK)) {
        unlink(file_get_contents(WORK));
    }
    exit;
}

function filename2time($file)
{
    $year = '20'.substr($file, 4, 2);
    $month = substr($file, 6, 2);
    $day = substr($file, 8, 2);
    $hour = substr($file, 11, 2);
    $min = substr($file, 13, 2);
    $sec = substr($file, 15, 2);
    return strtotime("$year-$month-$day $hour:$min:$sec");
}

function human_filesize($bytes, $decimals = 1)
{
    $sz = 'BKMGTP';
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
}

function nearhome($file)
{
    $result = false;
    $config = get_config();
    $skip = array();
    foreach ($config['skip'] as $foo) {
        $skip[] = preg_split('/:/', $foo);
    }

    $nmeafile = preg_replace('/\.MP4$/', '.NMEA', $file);
    $file = file($nmeafile, FILE_IGNORE_NEW_LINES);
    
    foreach ($skip as $coord) {
        foreach ($file as $line) {
            if (preg_match('/^\$GPRMC/', $line)) {
                $line = preg_split('/,/', $line);
                $status = $line[2];
                if ($status == 'A') {
                    $lat = $line[3];
                    $lon = $line[5];
                    $lat_deg = substr($lat, 0, 2);
                    $lat_min = substr($lat, 2, 2);
                    $lat_min2 = substr($lat, 5, 5);
                    $lat_dec = DMStoDD($lat_deg, "$lat_min.$lat_min2", 0);

                    $lon_deg = substr($lon, 1, 2);
                    $lon_min = substr($lon, 3, 2);
                    $lon_min2 = substr($lon, 6, 5);
                    $lon_dec = DMStoDD($lon_deg, "$lon_min.$lon_min2", 0);

                    $distance = distance($lat_dec, $lon_dec, $coord[1], $coord[2]);
                    if ($distance < $coord[3]) {
                        $result = true;
                    }
                }
            }
        }
    }

    return $result;
}

function DMStoDD($deg, $min, $sec)
{
    return $deg+((($min*60)+($sec))/3600);
}

function distance($lat1, $lon1, $lat2, $lon2, $unit = 'K')
{
    #print "\n\n$lat1, $lon1, $lat2, $lon2\n\n";
    if (($lat1 == $lat2) && ($lon1 == $lon2)) {
        return 0;
    } else {
        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        $unit = strtoupper($unit);

        if ($unit == "K") {
            return ($miles * 1.609344);
        } elseif ($unit == "N") {
            return ($miles * 0.8684);
        } else {
            return $miles;
        }
    }
}

function geodata($file)
{
    $result = array();

    $config = get_config();
    $nmeafile = preg_replace('/\.'.$config['suffix'].'$/', '.'.$config['gpssuffix'], $file);
    $file = file($nmeafile, FILE_IGNORE_NEW_LINES);
    
    $zaznam = 0;
    foreach ($file as $line) {
        if (preg_match('/^\$GPRMC/', $line)) {
            $line = preg_split('/,/', $line);
            $status = $line[2];
            if ($status == 'A') {
                $lat = $line[3];
                $lon = $line[5];
                $lat_deg = substr($lat, 0, 2);
                $lat_min = substr($lat, 2, 2);
                $lat_min2 = substr($lat, 5, 5);
                $lat_dec = DMStoDD($lat_deg, "$lat_min.$lat_min2", 0);

                $lon_deg = substr($lon, 1, 2);
                $lon_min = substr($lon, 3, 2);
                $lon_min2 = substr($lon, 6, 5);
                $lon_dec = DMStoDD($lon_deg, "$lon_min.$lon_min2", 0);

                if ($zaznam == 0) {
                    $result['start']['lat'] = $lat_dec;
                    $result['start']['lon'] = $lon_dec;
                }
                $result['end']['lat'] = $lat_dec;
                $result['end']['lon'] = $lon_dec;
                $zaznam++;
            }
        }
    }

    $result['start']['info'] = json_decode(file_get_contents($config['geocodingapi']."/reverse?format=json&lat=".$result['start']['lat']."&lon=".$result['start']['lon']));
    $result['end']['info'] = json_decode(file_get_contents($config['geocodingapi']."/reverse?format=json&lat=".$result['end']['lat']."&lon=".$result['end']['lon']));
    return $result;
}
