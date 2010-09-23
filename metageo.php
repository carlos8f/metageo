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
  case 'insert': $ret = metageo_insert($args); break;
  case 'find': $ret = metageo_find($args); break;
  case 'remove':
    if (!metageo_is_cli()) {
      $ret = metageo_error("remove command only available from cli.");
    }
    else {
      $ret = metageo_remove($args);
    }
    break;
  default: $ret = metageo_error("invalid command.");
}

if (metageo_error()) {
  metageo_exit(metageo_error());
}
elseif (is_string($ret)) {
  metageo_exit($ret, TRUE);
}
else {
  metageo_response($ret);
}
