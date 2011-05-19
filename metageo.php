<?php

require 'metageo.lib.php';
require 'config.php';

ini_set('memory_limit', -1);

if (!extension_loaded('mongo')) metageo_exit("requires mongo extension.");

$connection_string = defined('METAGEO_CONNECTION_STRING') ? METAGEO_CONNECTION_STRING : 'mongodb://localhost';

if (isset($argv[0])) {
  $prog = basename(array_shift($argv), '.php');
}
else {
  $prog = basename(__FILE__, '.php');
}
try {
  $mongo = new Mongo($connection_string, array('persist' => $prog));
}
catch (Exception $e) {
  metageo_exit("couldn't connect to mongo!");
}
$db = $mongo->$prog;

metageo_parse_args();

if (empty($command)) {
  metageo_exit("usage: $prog command [options]");
}

if ($command != 'find' && !metageo_is_cli() && METAGEO_SECRET_KEY && (empty($_REQUEST['key']) || $_REQUEST['key'] != METAGEO_SECRET_KEY)) {
  metageo_exit("invalid key.");
}

switch ($command) {
  case 'insert': $ret = metageo_do_insert($args); break;
  case 'update': $ret = metageo_do_update($args); break;
  case 'find': $ret = metageo_do_find($args); break;
  case 'remove': $ret = metageo_do_remove($args); break;
  default: $ret = metageo_error("invalid command.");
}

if (metageo_error()) {
  metageo_exit(metageo_error());
}
elseif (is_string($ret)) {
  metageo_exit($ret, TRUE);
}
else {
  print metageo_response($ret);
}
