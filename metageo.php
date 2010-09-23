#!/usr/bin/php
<?php

define('GEOCODE_URL', 'http://maps.google.com/maps/api/geocode/json?sensor=false&address=');
ini_set('memory_limit', -1);

if (!extension_loaded('mongo')) exit("requires mongo extension.\n");

$mongo = new Mongo();
if (!$mongo) exit("couldn't connect to mongo!\n");

$prog = basename(array_shift($argv), '.php');
$db = $mongo->$prog;

if ($argc < 2) {
  exit("usage: $prog command [options]\n");
}

$command = array_shift($argv);
$args = metageo_parse_args($argv);

switch ($command) {
  case 'insert': metageo_insert($args); break;
  case 'find': metageo_find($args); break;
  case 'remove': metageo_remove($args); break;
}

function metageo_insert($args) {
  global $prog, $db;
  if (empty($args['file'])) exit("must specify --file=inputfile\n");
  if (empty($args['name'])) exit("must specify --name=metadataname\n");
  if (!file_exists($args['file'])) exit("--file does not exist.\n");
  if (!empty($args['convert'])) {
    exec('whereis ogr2ogr', $output, $ret);
    if ($ret || empty($output) || !preg_match('~ogr2ogr: [^\w]~', $output[0])) exit("ogr2ogr command not found.\n");
    $tmp = sys_get_temp_dir() .'/'. $prog .'_'. md5(time() . mt_rand());
    exec(sprintf('ogr2ogr %s -f "GeoJSON" %s', escapeshellarg($tmp), escapeshellarg($args['file'])), $output, $ret);
    if ($ret) exit("unable to convert input file.\n");
    $args['file'] = $tmp;
  }
  $raw = file_get_contents($args['file']);
  $input = json_decode($raw, TRUE);
  if (empty($input)) {
    $raw = utf8_encode($raw);
    $input = json_decode($raw, TRUE);
  }
  if (empty($input) || empty($input['features'])) exit("unable to parse input.\n");
 
  $features_stats = metageo_features_stats($input['features']);

  foreach ($input['features'] as $feature) {
    $db->properties->insert($feature['properties']);
    $db->geometries->insert($feature['geometry']);
    $record = array(
      'name' => $args['name'],
      'type' => $feature['geometry']['type'],
      'center' => $feature['center'],
      'radius' => $feature['radius'],
      'bounds' => $feature['bounds'],
      'properties' => $feature['properties']['_id'],
      'geometry' => $feature['geometry']['_id'],
    );
    $db->features->insert($record);
  }
  
  $meta = $db->meta->findOne(array('name' => $args['name']));
  if (!$meta) {
    $meta = array('name' => $args['name'], 'type' => $features_stats['type'], 'feature_count' => $meta['feature_count'] + $features_stats['feature_count']);
  }
  else {
    $meta['feature_count'] = $features_stats['feature_count'];
  }
  $db->meta->save($meta);

  $db->features->ensureIndex(array('center' => '2d', 'name' => 1));
  $db->features->ensureIndex(array('name' => 1));

  $db->meta->ensureIndex(array('name' => 1));

  if (!empty($args['convert'])) @unlink($tmp);
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
  $radius = max($bounds['max_x'] - $bounds['min_x'], $bounds['max_y'] - $bounds['min_y']) / 2;
  return compact('bounds', 'center', 'radius');
}

function metageo_expand_bounds($point, &$bounds) {
  $bounds += array('min_x' => 180, 'min_y' => 90, 'max_x' => -180, 'max_y' => -90);
  $bounds['min_x'] = min($point[0], $bounds['min_x']);
  $bounds['min_y'] = min($point[1], $bounds['min_y']);
  $bounds['max_x'] = max($point[0], $bounds['max_x']);
  $bounds['max_y'] = max($point[1], $bounds['max_y']);
}

function metageo_features_stats(&$features) {
  $stats = array(
    'type' => NULL,
    'feature_count' => 0,
  );
  foreach ($features as $i => $feature) {
    if ($feature['type'] != 'Feature') {
      continue;
    }
    $stats['feature_count']++;
    $features[$i] += metageo_geometry_stats($feature['geometry']);
    if (!$stats['type']) {
      switch ($feature['geometry']['type']) {
        case 'Polygon': case 'MultiPolygon': $stats['type'] = 'Polygon'; break;
        case 'Point': case 'MultiPoint': $stats['type'] = 'Point'; break;
        case 'LineString': case 'MultiLineString': $stats['type'] = 'Line'; break;
        default: $stats['type'] = 'Mixed'; break;
      }
    }
  }
  return $stats;
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
        exit("unable to geocode location.\n");
      }
    }
    $conditions += array('center' => array('$near' => $args['location']));
  }
  if (!empty($args['limit'])) {
    $args['limit'] = (int) $args['limit'];
  }
  else {
    $args['limit'] = 100;
  }
  if (!empty($args['name'])) {
    $meta = $db->meta->findOne(array('name' => $args['name']));
    if (!$meta) {
      exit("--name not in database.\n");
    }
    $conditions += array('name' => $args['name']);
  }

  $ret = array();

  if (!empty($args['within'])) {
    $ret['within'] = metageo_find_within($conditions, $args['limit']);
  }

  if ((empty($args['within']) && empty($args['nearby'])) || !empty($args['nearby'])) {
    $result = $db->features->find($conditions)->limit($args['limit']);
    foreach ($result as $feature) {
      $feature['properties'] = $db->properties->findOne(array('_id' => $feature['properties']));
      if (!empty($args['geometry'])) {
        $feature['geometry'] = $db->geometries->findOne(array('_id' => $feature['geometry']));
      }
      else {
        unset($feature['geometry']);
      }
      $ret['nearby'][] = $feature;
    }
  }
  var_dump($ret);
}

function metageo_find_within($conditions, $master_limit = 100) {
  global $db, $args;

  $reader = metageo_wkt_reader();
  $ret = array();
  $skip = 0;
  $limit = 100;
  $out_of_bounds = 0;
  $point = $conditions['center']['$near'];
  $g1 = $reader->read(sprintf('POINT(%f %f)', $point[0], $point[1]));
  do {
    $result = $db->features->find($conditions + array('type' => array('$in' => array('Polygon', 'MultiPolygon'))))->limit($limit)->skip($skip);
    foreach ($result as $feature) {
      if ($point[0] < $feature['bounds']['min_x'] || $point[0] > $feature['bounds']['max_x'] || $point[1] < $feature['bounds']['min_y'] || $point[1] > $feature['bounds']['max_y']) {
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
        $feature['geometry'] = $db->geometries->findOne(array('_id' => $feature['geometry']));
        $wkt = metageo_geometry_to_wkt($feature['geometry']);
        $g2 = $reader->read($wkt);
        if ($g1->within($g2)) {
          $feature['properties'] = $db->properties->findOne(array('_id' => $feature['properties']));
          if (empty($args['geometry'])) {
            unset($feature['geometry']);
          }
          $ret[] = $feature;
          if (count($ret) == $master_limit) {
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
  if (!extension_loaded('geos')) exit("requires geos extension.\n");
  if (!$reader = new GEOSWKTReader()) {
    exit("unable to initialize WKT reader.\n");
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
  if (empty($args['name'])) exit("--name required.\n");
  $result = $db->features->find(array('name' => $args['name']));
  foreach ($result as $feature) {
    $db->properties->remove(array('_id' => $feature['properties']));
    $db->geometries->remove(array('_id' => $feature['geometry']));
    $db->features->remove(array('_id' => $feature['_id']));
  }
}

function metageo_geocode($location) {
  if ($geocode = json_decode(file_get_contents(GEOCODE_URL . urlencode($location)))) {
    if ($geocode->status == 'OK') {
      foreach ($geocode->results as $result) {
        return array($result->geometry->location->lng, $result->geometry->location->lat);
      }
    }
  }
  else {
    return FALSE;
  }
}

function metageo_parse_args($args) {
  $retval = array();
  foreach ($args as $arg) {
    $arg = trim($arg, '-');
    if (strpos($arg, '=') !== FALSE) {
      list($key, $value) = explode('=', $arg);
      $retval[$key] = $value;
    }
    else {
      $retval[$arg] = TRUE;
    }
  }
  return $retval;
}
