#!/bin/sh
# Full separation + transcription battery inside the webserver container (as www-data). Results in /bench/battery/.
cd /var/www/html
O=/bench/battery; mkdir -p $O
cfg() { php admin/cli/cfg.php --component=mod_videoai --name=$1 --set=$2; }
echo "== 1 formats";      php /bench/bench.php 2>/dev/null | grep -v '^$\|Deprecated\|No such' > $O/1-formats.txt
echo "== 2 separation";   cfg transcribe 0; php /bench/e2e.php > $O/2-separation-e2e.txt 2>&1
echo "== 3 samples";      php -r 'define("CLI_SCRIPT",1); require "config.php"; $c=$DB->get_record("course",["shortname"=>"muestras"]); foreach($DB->get_records("videoai",["course"=>$c->id]) as $v) \mod_videoai\local\processor::queue($v->id,true);'
                          php /bench/samples_e2e.php > $O/3-samples.txt 2>&1
echo "== 4 transcription ffmpeg"; cfg transcribe 1; ENGINE=ffmpeg php /bench/transcribe_e2e.php > $O/4-transcribe-ffmpeg.txt 2>&1
echo "== 5 auto courses"; cfg engine ffmpeg; php /bench/autotranscribe_e2e.php > $O/5-autotranscribe.txt 2>&1
echo "== 6 phpunit";      vendor/bin/phpunit --filter "mod_videoai|local_videotranscriber" > $O/6-phpunit.txt 2>&1
cfg engine ffmpeg
echo "BATTERY DONE"
