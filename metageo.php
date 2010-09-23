<?php

require 'metageo.lib.php';

ini_set('memory_limit', -1);

if (!extension_loaded('mongo')) metageo_exit("requires mongo extension.");

$mongo = new Mongo();
if (!$mongo) metageo_exit("couldn't connect to mongo!");

if (isset($argv[0])) {
  $prog = basename(array_shift($argv), '.php');
}
else {
  $prog = basename(__FILE__, '.php');
}
$db = $mongo->$prog;

metageo_parse_args();

if (empty($command)) {
  metageo_exit("usage: $prog command [options]");
}

switch ($command) {
  case 'insert': metageo_insert($args); break;
  case 'find': metageo_find($args); break;
  case 'remove':
    if (!metageo_is_cli()) {
      metageo_exit("remove command only available from cli.\n");
    }
    metageo_remove($args);
    break;
  default: metageo_exit("invalid command.");
}
