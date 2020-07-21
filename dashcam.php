#!/usr/bin/php
<?php

require('func.php');

define("WORK", '/tmp/'.basename(__FILE__).'.processing');

declare(ticks = 1);

pcntl_signal(SIGTERM, 'signalHandler');
pcntl_signal(SIGHUP, 'signalHandler');
pcntl_signal(SIGINT, 'signalHandler');

$pidFileName = basename(__FILE__) . '.pid';
$pidFile = @fopen($pidFileName, 'c');
if (!$pidFile) {
    die("Could not open $pidFileName\n");
}
if (!@flock($pidFile, LOCK_EX | LOCK_NB)) {
    die("Already running?\n");
}
ftruncate($pidFile, 0);
fwrite($pidFile, getmypid());

$config = get_config();

$parts = get_parts();

$opt = getopt("", ["delete"]);

foreach ($parts as $part) {
    if (count($part['files']) > 1) {
        $date = date('Y-m-d-Hi', filename2time(basename($part['files'][0])));

        $tmpfname = tempnam("/tmp", __FILE__);
        $fp = fopen($tmpfname, 'w');
        $videopart = 0;
        foreach ($part['files'] as $file) {
            if (!nearhome($file)) {
                fwrite($fp, "file '$file'\n");
                if ($videopart == 0) {
                    $first = $file;
                } else {
                    $last = $file;
                }
                $videopart++;
                print "$file\n";
            }
        }
        fclose($fp);

        if ($videopart > 1) {
            $fromplace = geodata($first);
            $toplace = geodata($last);
            $video = $config['out']."/$date-".preg_replace('/ /', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $fromplace['start']['info']->address->suburb)))."-".preg_replace('/ /', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $toplace['end']['info']->address->suburb))).".mkv";

            file_put_contents(WORK, $video);

            if (!is_file($video)) {
                $cmd = "ffmpeg -y -loglevel error -safe 0 -f concat -i $tmpfname -vcodec copy -an $video";
                print "$cmd\n";
                system($cmd);
            }

            if (is_file($video)) {
                print "$video\n";
                print human_filesize(filesize($video))."\n";
            }

            unlink(WORK);
            unlink($tmpfname);
            foreach ($part['files'] as $srcfile) {
                if (isset($opt['delete'])) {
                    unlink($srcfile);
                    unlink(preg_replace('/\.'.$config['suffix'].'$/', '.'.$config['gpssuffix'], $srcfile));
                }
            }
        }
    }
}

unlink($pidFileName);
