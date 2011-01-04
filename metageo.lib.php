<?php

function metageo_do_insert($args) {
  global $prog, $db;
  if (empty($args['file']) && empty($args['input'])) return metageo_error("must specify file=inputfile or input={geojson}");
  if (empty($args['name'])) return metageo_error("must specify name=metadataname");
  if (!empty($args['file'])) {
    if (!metageo_is_cli()) {
      return metageo_error("file can only be used on cli.");
    }
    if (!file_exists($args['file'])) return metageo_error("file does not exist.");
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

function metageo_do_find($args) {
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
  if (!empty($args['skip'])) {
    $args['skip'] = (int) $args['skip'];
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
      return metageo_error("unable to parse conditions.");
    }
    $conditions += $extra_cond;
  }

  $ret = array(
    'type' => 'FeatureCollection',
    'features' => array(),
  );

  if (!empty($args['within'])) {
    if (empty($args['location'])) {
      return metageo_error("within must also specify location.");
    }
    $ret['features'] = metageo_find_within($conditions, $args);
  }
  else {
    $result = $db->features->find($conditions);
    if (!empty($args['limit'])) {
      $result->limit($args['limit']);
    }
    if (!empty($args['skip'])) {
      $result->skip($args['skip']);
    }
    foreach ($result as $feature) {
      if (!empty($args['location'])) {
        $feature['distance'] = distance($args['location'], $feature['center']);
        if (!empty($args['distance']) && $feature['distance'] > $args['distance']) {
          break;
        }
      }
      if (!empty($args['no-geometry'])) {
        unset($feature['geometry']);
      }
      $feature['_id'] .= '';
      $ret['features'][] = $feature;
    }
  }

  return $ret;
}

function metageo_find_within($conditions, $args) {
  global $db;

  if (!$reader = metageo_wkt_reader()) {
    return FALSE;
  }
  $ret = array();
  $misses = 0;
  if (!isset($args['max-misses'])) {
    $args['max-misses'] = 100;
  }

  $names = (isset($args['name'])) ? array_flip(explode(',', $args['name'])) : FALSE;
  if ($names) {
    $args['limit'] = count($names);
    // Keep track of misses per name.
    $misses = array();
    foreach ($names as $name => $val) {
      $misses[$name] = 0;
    }
  }

  $point = $conditions['center']['$near'];
  $g1 = $reader->read(sprintf('POINT(%f %f)', $point[0], $point[1]));

  // Geo queries default to having limit=100. We set the limit to include *all* documents in
  // the collection, so we can iterate the results freely and break as soon as we have our match.
  $result = $db->features->find($conditions + array('geometry.type' => array('$in' => array('Polygon', 'MultiPolygon'))))->limit($db->features->count());
  foreach ($result as $feature) {
    if ($names && !isset($names[$feature['name']])) {
      // This name has already been found, skip.
      continue;
    }
    if ($point[0] >= $feature['bbox'][0] && $point[1] >= $feature['bbox'][1] && $point[0] <= $feature['bbox'][2] && $point[1] <= $feature['bbox'][3]) {
      // Test that the point is within the polygon.
      $wkt = metageo_geometry_to_wkt($feature['geometry']);
      $g2 = $reader->read($wkt);
      if ($g1->within($g2)) {
        if (!empty($args['no-geometry'])) {
          unset($feature['geometry']);
        }
        // Convert Mongo ID to string.
        $feature['_id'] .= '';

        $ret[] = $feature;
        if (!empty($conditions['name'])) {
          // If this is a name-based query, stop looking for this name.
          unset($names[$feature['name']]);
        }
        if (isset($args['limit']) && count($ret) == $args['limit']) {
          // Limit has been reached, return now.
          break;
        }
        // A hit, continue searching.
        continue;
      }
    }
    // A miss.
    if ($names) {
      $misses[$feature['name']]++;
      $miss_count = $misses[$feature['name']];
    }
    else {
      $misses++;
      $miss_count = $misses;
    }
    if ($miss_count > $args['max-misses']) {
      break;
    }
  }

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

function metageo_do_remove($args) {
  global $db;
  if (empty($args['conditions'])) metageo_exit("conditions required.");
  if (!$args['conditions'] = json_decode($args['conditions'], TRUE)) metageo_exit("unable to decode conditions.");
  $db->features->remove($args['conditions']);
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
  global $args, $prog;
  if (metageo_is_cli()) {
    if (!empty($args['vardump'])) {
      ob_start();
      var_dump($resp);
      return ob_get_clean();
    }
    else {
      return json_encode($resp) ."\n";
    }
  }
  else {
    header('Cache-Control: public, max-age=86400');
    header('Vary: Accept-Encoding');
    $gzip = (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== FALSE) ? TRUE : FALSE;
    $output = '';
    if (!isset($args['format']) || $args['format'] == 'json' || $args['format'] == 'jsonp') {
      header('Content-Type: text/plain; charset=utf-8');
      if (isset($args['callback'])) {
        $output .= $args['callback'] ."(\n\t";
      }
      $output .= json_encode($resp);
      if (isset($args['callback'])) {
        $output .= "\n);";
      }
    }
    elseif (isset($args['format']) && $args['format'] == 'kml') {
      exec('whereis ogr2ogr', $cmd_output, $ret);
      if ($ret || empty($cmd_output) || !preg_match('~\s(/[^\s]*bin/[^\s]*)~', $cmd_output[0], $matches)) metageo_exit("ogr2ogr command not found.");
      $ogr2ogr = $matches[1];
      $tmp_geojson = sys_get_temp_dir() .'/'. $prog .'_'. md5(time() . mt_rand()) .'.json';
      $tmp_kml = sys_get_temp_dir() .'/'. $prog .'_'. md5(time() . mt_rand()) .'.kml';
      file_put_contents($tmp_geojson, json_encode($resp));
      $cmd = sprintf('%s %s -f "KML" %s', $ogr2ogr, escapeshellarg($tmp_kml), escapeshellarg($tmp_geojson));
      $cmd_output = array();
      $ret = 0;
      exec($cmd, $cmd_output, $ret);
      if ($ret) metageo_exit("unable to convert to KML.");
      $output = file_get_contents($tmp_kml);

      @unlink($tmp_geojson);
      @unlink($tmp_kml);
      header('Content-Type: application/xml; charset=utf-8');
    }
    if ($gzip) {
      header('Content-Encoding: gzip');
      $output = gzencode($output);
    }
    header('Content-Length: '. strlen($output));
    return $output;
  }
}

function metageo_exit($message, $ok = FALSE) {
  if (metageo_is_cli()) {
    print $message ."\n";
    exit($ok ? 0 : 1);
  }
  else {
    header('Content-Type: text/plain; charset=utf-8');
    $resp = array('ok' => (bool) $ok, 'message' => $message);
    print json_encode($resp);
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
