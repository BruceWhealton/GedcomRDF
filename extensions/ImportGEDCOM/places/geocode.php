#!/usr/bin/env php
<?php
// Usage: cat allplaces | php geocode.php > places.csv &
// Test: echo "Gratiot, Licking, Ohio, USA" | php geocode.php
function geocodeQuery($query) {
  $baseURL='http://maps.googleapis.com/maps/api/geocode/json';
  $params=array(
		"address" =>  $query,
		"sensor"  =>  'false',
		);
  $querypart="?";
  foreach($params as $name => $value) {
    $querypart=$querypart . $name . '=' . urlencode($value) . "&";
  }
  $geocodeURL=$baseURL . $querypart;
  return json_decode(file_get_contents($geocodeURL));
};

function geocodePlace($place) {
  $data=geocodeQuery($place['Title']);
  if ($data->status == "OVER_QUERY_LIMIT" ||
      $data->status == "REQUEST_DENIED" ||
      $data->status == "INVALID_REQUEST")
  {
    fwrite(STDERR, '# '.$data->status.': '.print_r($place,1)."\n");
    exit(1);
  } elseif ($data->status == 'OK') {
    $result=$data->results[0];
    $place['Place[Label]']=$result->formatted_address;
    $place['Place[Coordinates]']=$result->geometry->location->lat.', '.
                           $result->geometry->location->lng;
    foreach ($result->address_components as $part) {
      if (in_array('locality', $part->types)) {
	$place['Place[City]']=$part->long_name;
      } elseif (in_array('administrative_area_level_2', $part->types)) {
	$place['Place[County]']=$part->long_name;
      } elseif (in_array('administrative_area_level_1', $part->types)) {
	$place['Place[State]']=$part->long_name;
      } elseif (in_array('country', $part->types)) {
	$place['Place[Country]']=$part->long_name;
      }
    }
  } else {
    $place['Free Text']=$data->status;
  }
  return $place;
}

function sparqlQuery($query, $baseURL, $format="application/json") {
  $params=array(
		"default-graph" =>  "",
		"should-sponge" =>  "soft",
		"query" =>  $query,
		"debug" =>  "on",
		"timeout" =>  "",
		"format" =>  $format,
		"save" =>  "display",
		"fname" =>  ""
		);
  $querypart="?";
  foreach($params as $name => $value) {
    $querypart=$querypart . $name . '=' . urlencode($value) . "&";
  }
  $sparqlURL=$baseURL . $querypart;
  return json_decode(file_get_contents($sparqlURL));
};

function dbpediaPlace($place) {
  // Pages to try.
  $pages=array();
  if ($place['Place[County]']) {
    $pages[]='http://dbpedia.org/resource/'
             .str_replace(' ', '_', $place['Place[City]']
                             .',_'.$place['Place[County]']);
  }
  if ($place['Place[State]']) {
    $pages[]='http://dbpedia.org/resource/'
            .str_replace(' ', '_', $place['Place[City]']
                             .',_'.$place['Place[State]']);
  }
  if ($place['Place[Country]']) {
    $pages[]='http://dbpedia.org/resource/'
            .str_replace(' ', '_', $place['Place[City]']
                             .',_'.$place['Place[Country]']);
  }
  $pages[]='http://dbpedia.org/resource/'
          .str_replace(' ', '_', $place['Place[City]']);
  foreach($pages as $page) {
    $query=<<<EOQ
SELECT ?link WHERE { {
  <$page> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://schema.org/Place> .
  <$page> <http://xmlns.com/foaf/0.1/page> ?link .
} UNION {
  <$page> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://schema.org/Place> .
  <$page> <http://www.w3.org/2002/07/owl#sameAs> ?link .
} UNION {
  <$page> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://schema.org/Place> .
  ?link <http://www.w3.org/2002/07/owl#sameAs> <$page> .
} } LIMIT 10
EOQ;
    #print "query: ".print_r($query,1)."\n";
    $result=sparqlQuery($query, 'http://dbpedia.org/sparql');
    #print "result: ".print_r($result,1)."\n";
    $results=$result->results->bindings;
    if (count($results) > 0) {
      $place['Place[DBpedia Page]']=$page;
      foreach($results as $res) {
	$link=$res->link->value;
	if (strpos($link, 'wikipedia.org')) {
	  $place['Place[Wikipedia Page]']=$link;
	} elseif (strpos($link, 'freebase.com')) {
	  $place['Place[Freebase Link]']=$link;
	} elseif (strpos($link, 'yago')) {
	  $place['Place[Yago Link]']=$link;
	} elseif (strpos($link, 'geonames.org')) {
	  $place['Place[Geonames Link]']=$link;
	} elseif (strpos($link, 'linkedgeodata.org')) {
	  $place['Place[Linkedgeodata Link]']=$link;
	} elseif (strpos($link, 'rdfabout.com')) {
	  $place['Place[Rdfabout Link]']=$link;
	}
      }
      return $place;
    }
  }
  return $place;
}

$header=array('Title'=>'','Place[Label]'=>'','Place[Coordinates]'=>'',
	      'Place[City]'=>'','Place[County]'=>'','Place[State]'=>'','Place[Country]'=>'',
	      'Place[DBpedia Page]'=>'','Place[Wikipedia Page]'=>'','Place[Freebase Link]'=>'',
	      'Place[Yago Link]'=>'','Place[Geonames Link]'=>'','Place[Linkedgeodata Link]'=>'',
	      'Place[Rdfabout Link]'=>'','Free Text'=>'');
fputcsv(STDOUT, array_keys($header));
while(!feof(STDIN)) {
  $line = fgets(STDIN);
  if($line === FALSE) {
    if(feof(STDIN)) {
      break;
    }
    continue;
  }
  $place=$header;
  $place['Title']=trim($line);
  if ($place['Title']) { $place=geocodePlace($place); }
  if ($place['Place[Country]']) { $place=dbpediaPlace($place); }
  fputcsv(STDOUT, $place);
  sleep(1);
}
