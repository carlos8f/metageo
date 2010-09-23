<?php

function metageo_insert($args) {
  global $prog, $db;
  if (empty($args['file']) && empty($args['input'])) return metageo_error("must specify --file=inputfile or --input={geojson}");
  if (empty($args['name'])) return metageo_error("must specify --name=metadataname");
  if (!empty($args['file'])) {
    if (!metageo_is_cli()) {
      return metageo_error("--file can only be used on cli.");
    }
    if (!file_exists($args['file'])) return metageo_error("--file does not exist.");
    if (!empty($args['convert'])) {
      exec('whereis ogr2ogr', $output, $ret);
      if ($ret || empty($output) || !preg_match('~ogr2ogr: [^\w]~', $output[0])) return metageo_error("ogr2ogr command not found.");
      $tmp = sys_get_temp_dir() .'/'. $prog .'_'. md5(time() . mt_rand());
      exec(sprintf('ogr2ogr %s -f "GeoJSON" %s', escapeshellarg($tmp), escapeshellarg($args['file'])), $output, $ret);
      if ($ret) return metageo_error("unable to convert input file.");
      $args['file'] = $tmp;
    }
    $raw = file_get_contents($args['file']);
    $input = json_decode($raw, TRUE);
    if (empty($input)) {
      $raw = utf8_encode($raw);
      $input = json_decode($raw, TRUE);
    }
  }
  elseif (!empty($args['input'])) {
    $input = json_decode($args['input'], TRUE);
  }
  if (empty($input) || empty($input['features'])) return metageo_error("unable to parse input.");
 
  foreach ($input['features'] as $feature) {
    if ($feature['type'] == 'FeatureCollection') {
      foreach ($feature['features'] as $sub_feature) {
        metageo_save_feature($sub_feature, $args['name']);
      }
    }
    else {
      metageo_save_feature($feature, $args['name']);
    }
  }

  $db->features->ensureIndex(array('center' => '2d', 'name' => 1));
  $db->features->ensureIndex(array('name' => 1));

  if (!empty($args['convert'])) @unlink($tmp);
  return 'insert ok.';
}

function metageo_save_feature($feature, $name) {
  global $db;
  $feature += metageo_geometry_stats($feature['geometry']);
  $feature['name'] = $name;
  if (isset($feature['_id'])) {
    $feature['_id'] = new MongoId($feature['_id']);
  }
  $db->features->save($feature);
}

function metageo_geometry_stats($geometry) {
  $bounds = array();
  $radius = 0;
  switch ($geometry['type']) {
    case 'Point': metageo_expand_bounds($geometry['coordinates'], $bounds); break;
    case 'MultiPoint': case 'LineString': foreach ($geometry['coordinates'] as $point) metageo_expand_bounds($point, $bounds); break;
    case 'MultiLineString': case 'Polygon': foreach ($geometry['coordinates'] as $group) foreach ($group as $point) metageo_expand_bounds($point, $bounds); break;
    case 'MultiPolygon': foreach ($geometry['coordinates'] as $metagroup) foreach ($metagroup as $group) foreach ($group as $point) metageo_expand_bounds($point, $bounds); break;
  }
  $center = array(($bounds['min_x'] + $bounds['max_x']) / 2, ($bounds['min_y'] + $bounds['max_y']) / 2);
  $bbox = array($bounds['min_x'], $bounds['min_y'], $bounds['max_x'], $bounds['max_y']);
  return compact('bbox', 'center');
}

function metageo_expand_bounds($point, &$bounds) {
  $bounds += array('min_x' => 180, 'min_y' => 90, 'max_x' => -180, 'max_y' => -90);
  $bounds['min_x'] = min($point[0], $bounds['min_x']);
  $bounds['min_y'] = min($point[1], $bounds['min_y']);
  $bounds['max_x'] = max($point[0], $bounds['max_x']);
  $bounds['max_y'] = max($point[1], $bounds['max_y']);
}

function metageo_find($args) {
  global $prog, $db;
  $conditions = array();
  if (!empty($args['location'])) {
    $args['location'] = explode(',', $args['location']);
    if (count($args['location']) == 2 && is_numeric($args['location'][0]) && is_numeric($args['location'][1])) {
      // we have a long/lat, most likely.
      $args['location'][0] = (float) $args['location'][0];
      $args['location'][1] = (float) $args['location'][1];
    }
    else {
      // Geocode the location string.
      if ($geocode = metageo_geocode(implode(',', $args['location']))) {
        $args['location'] = $geocode;
      }
      else {
        return metageo_error("unable to geocode location.");
      }
    }
    $conditions += array('center' => array('$near' => $args['location']));
  }
  if (!empty($args['limit'])) {
    $args['limit'] = (int) $args['limit'];
  }
  if (!empty($args['name'])) {
    if (strpos($args['name'], ',') !== FALSE) {
      $conditions += array('name' => array('$in' => explode(',', $args['name'])));
    }
    else {
      $conditions += array('name' => $args['name']);
    }
  }
  if (!empty($args['conditions'])) {
    if (!$extra_cond = json_decode($args['conditions'], TRUE)) {
      return metageo_error("unable to parse --conditions.");
    }
    $conditions += $extra_cond;
  }

  $ret = array();

  if (!empty($args['within'])) {
    $ret = metageo_find_within($conditions);
  }
  else {
    $result = $db->features->find($conditions);
    if (!empty($args['limit'])) {
      $result->limit($args['limit']);
    }
    foreach ($result as $feature) {
      if (!empty($args['location'])) {
        $feature['distance'] = distance($args['location'], $feature['center']);
        if (!empty($args['distance']) && $feature['distance'] > $args['distance']) {
          break;
        }
      }
      if (empty($args['geometry'])) {
        unset($feature['geometry']);
      }
      $feature['_id'] .= '';
      $ret[] = $feature;
    }
  }
  return $ret;
}

function metageo_find_within($conditions) {
  global $db, $args;

  if (!$reader = metageo_wkt_reader()) {
    return FALSE;
  }
  $ret = array();
  $skip = 0;
  $limit = 100;
  $out_of_bounds = 0;
  $point = $conditions['center']['$near'];
  $g1 = $reader->read(sprintf('POINT(%f %f)', $point[0], $point[1]));

  $names = (isset($args['name'])) ? array_flip(explode(',', $args['name'])) : FALSE;

  do {
    $result = $db->features->find($conditions + array('geometry.type' => array('$in' => array('Polygon', 'MultiPolygon'))))->limit($limit)->skip($skip);
    foreach ($result as $feature) {
      if ($point[0] < $feature['bbox'][0] || $point[0] > $feature['bbox'][2] || $point[1] < $feature['bbox'][1] || $point[1] > $feature['bbox'][3]) {
        // Point is out of bounds of this feature.
        $out_of_bounds++;
        if ($out_of_bounds - count($ret) == $limit) {
          // After $limit out-of-bound's, give up.
          return $ret;
        }
        continue;
      }
      else {
        // Test that the point is within the polygon.
        $wkt = metageo_geometry_to_wkt($feature['geometry']);
        $g2 = $reader->read($wkt);
        if ($g1->within($g2)) {
          if (empty($args['geometry'])) {
            unset($feature['geometry']);
          }
          $feature['_id'] .= '';
          $ret[] = $feature;
          if ($names) {
            unset($names[$feature['name']]);
          }
          // If all names have been found, return result.
          if ($names === array()) {
            return $ret;
          }
          elseif (!empty($args['limit']) && count($ret) == $args['limit']) {
            return $ret;
          }
        }
      }
    }
    $skip += $limit;
  } while ($result);
  return $ret;
}

function metageo_wkt_reader() {
  static $reader;
  if (isset($reader)) return $reader;
  if (!extension_loaded('geos')) return metageo_error("geos extension required.");
  if (!$reader = new GEOSWKTReader()) {
    return metageo_error("unable to initialize WKT reader.");
  }
  return $reader;
}

function metageo_geometry_to_wkt($geometry) {
  $type = strtoupper($geometry['type']);
  switch ($geometry['type']) {
    case 'Point': return sprintf('%s(%s %s)', $type, $geometry['coordinates'][0], $geometry['coordinates'][1]);
    case 'MultiPoint': case 'LineString':
      $points = array();
      foreach ($geometry['coordinates'] as $point) {
        $points[] = $point[0] .' '. $point[1];
      }
      return sprintf('%s(%s)', $type, implode(',', $points));
    case 'MultiLineString': case 'Polygon':
      $groups = array();
      foreach ($geometry['coordinates'] as $i => $group) {
        foreach ($group as $point) {
          $groups[$i][] = $point[0] .' '. $point[1];
        }
        $groups[$i] = '('. implode(',', $groups[$i]) .')';
      }
      return sprintf('%s(%s)', $type, implode(',', $groups));
    case 'MultiPolygon':
      $groups = array();
      foreach ($geometry['coordinates'] as $i => $metagroup) {
        foreach ($metagroup as $j => $group) {
          foreach ($group as $point) {
            $groups[$i][$j][] = $point[0] .' '. $point[1];
          }
          $groups[$i][$j] = '('. implode(',', $groups[$i][$j]) .')';
        }
        $groups[$i] = '('. implode(',', $groups[$i]) .')';
      }
      return sprintf('%s(%s)', $type, implode(',', $groups));
    default:
      return FALSE;
  }
}

function metageo_remove($args) {
  global $db;
  if (empty($args['name'])) metageo_exit("--name required.");
  $db->features->remove(array('name' => $args['name']));
  return 'remove OK.';
}

function metageo_geocode($location) {
  global $prog;
  // Try Google first...
  if ($geocode = json_decode(file_get_contents('http://maps.google.com/maps/api/geocode/json?sensor=false&address=' . urlencode($location)), TRUE)) {
    if ($geocode['status'] == 'OK') {
      foreach ($geocode['results'] as $result) {
        return array($result['geometry']['location']['lng'], $result['geometry']['location']['lat']);
      }
    }
  }
  // Try geonames...
  if ($geocode = json_decode(file_get_contents('http://ws.geonames.org/searchJSON?username='. $prog .'&q='. urlencode($location)), TRUE)) {
    if (!empty($geocode['geonames'])) {
      foreach ($geocode['geonames'] as $result) {
        return array($result['lng'], $result['lat']);
      }
    }
  }
  return FALSE;
}

function metageo_parse_args() {
  global $command, $args;
  $args = array();
  if (metageo_is_cli()) {
    global $argv;
    $command = array_shift($argv);
    foreach ($argv as $arg) {
      $arg = trim($arg, '-');
      if (strpos($arg, '=') !== FALSE) {
        list($key, $value) = explode('=', $arg);
        $args[$key] = $value;
      }
      else {
        $args[$arg] = TRUE;
      }
    }
  }
  else {
    if (function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc()) {
      $quotes_sybase = strtolower(ini_get('magic_quotes_sybase'));
      $unescape_function = (empty($quotes_sybase) || $quotes_sybase === 'off') ? 'stripslashes($value)' : 'str_replace("\'\'","\'",$value)';
      $stripslashes_deep = create_function('&$value, $fn', '
          if (is_string($value)) {
              $value = ' . $unescape_function . ';
          } else if (is_array($value)) {
              foreach ($value as &$v) $fn($v, $fn);
          }
      ');

      // Unescape data
      $stripslashes_deep($_POST, $stripslashes_deep);
      $stripslashes_deep($_GET, $stripslashes_deep);
      $stripslashes_deep($_COOKIE, $stripslashes_deep);
      $stripslashes_deep($_REQUEST, $stripslashes_deep);
    }
    $args = $_GET;
    $command = isset($_GET['command']) ? $_GET['command'] : FALSE;
  }
}

/**
 * hocus pocus!
 */
function distance($point1, $point2) {
  $theta = $point1[0] - $point2[0];
  $dist = sin(deg2rad($point1[1])) * sin(deg2rad($point2[1])) +  cos(deg2rad($point1[1])) * cos(deg2rad($point2[1])) * cos(deg2rad($theta));
  $dist = acos($dist);
  $dist = rad2deg($dist);
  $miles = $dist * 60 * 1.1515;
  return $miles;
}

function metageo_response($resp) {
  global $args;
  if (metageo_is_cli()) {
    var_dump($resp);
  }
  else {
    header('Content-Type: text/plain; charset=utf-8');
    if (isset($args['callback'])) {
      print $args['callback'] ."(\n\t";
    }
    print json_encode($resp);
    if (isset($args['callback'])) {
      print "\n);";
    }
  }
}

function metageo_exit($message, $ok = FALSE) {
  if (metageo_is_cli()) {
    print $message ."\n";
    exit($status);
  }
  else {
    metageo_response(array('ok' => (bool) $ok, 'message' => $message));
    exit();
  }
}

function metageo_is_cli() {
  return php_sapi_name() === 'cli';
}

function metageo_error($message = NULL) {
  static $_message = NULL;
  if ($message) {
    $_message = $message;
    return FALSE;
  }
  return $_message;
}
