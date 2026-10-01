<?php  // Moodle configuration file for the mod_videoai spike (Docker only).
unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'db';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'moodle';
$CFG->dbpass    = 'moodle';
$CFG->prefix    = 'm_';
$CFG->dboptions = ['dbpersist' => 0, 'dbport' => '', 'dbsocket' => ''];

$CFG->wwwroot   = 'http://localhost:8000';
$CFG->dataroot  = '/var/www/moodledata';
$CFG->admin     = 'admin';
$CFG->directorypermissions = 0777;

$CFG->phpunit_prefix = 'phpu_';
$CFG->phpunit_dataroot = '/var/www/phpunitdata';
define('MOD_VIDEOAI_TEST_FFMPEG', '/usr/bin/ffmpeg');
define('MOD_VIDEOAI_TEST_FFPROBE', '/usr/bin/ffprobe');

$CFG->debug = E_ALL;
$CFG->debugdisplay = 1;

require_once(__DIR__ . '/lib/setup.php');
