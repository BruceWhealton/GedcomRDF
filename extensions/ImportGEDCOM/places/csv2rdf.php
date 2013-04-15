#!/usr/bin/env php
<?php
# Usage: cat places.csv | php csv2rdf.php > places.rdf
print <<<EOH
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF
    xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
    xmlns:rdfs="http://www.w3.org/2000/01/rdf-schema#"
    xmlns:foaf="http://xmlns.com/foaf/0.1/"
    xmlns:geo="http://www.w3.org/2003/01/geo/wgs84_pos#"
    xmlns:owl="http://www.w3.org/2002/07/owl#"
    xmlns:schema="http://schema.org/">

EOH;

function output_place($place) {
  print " <schema:Place rdf:about=\"http://my-family-lineage.com/wiki/"
    .str_replace(' ', '_', $place['Title'])
    ."\">\n";
  if ($place['Place[Label]']) {
    print "  <rdfs:label>".$place['Place[Label]']."</rdfs:label>\n";
  }
  if ($place['Place[Coordinates]']) {
    $coords = preg_split("/[\s_,]+/", $place['Place[Coordinates]'], -1, PREG_SPLIT_NO_EMPTY);
    print "  <geo:geometry>POINT(".$coords[1]." ".$coords[0].")</geo:geometry>\n";
    print "  <geo:lat>".$coords[0]."</geo:lat>\n";
    print "  <geo:long>".$coords[1]."</geo:long>\n";
  }
  if ($place['Place[Wikipedia Page]']) {
    print "  <foaf:page>".$place['Place[Wikipedia Page]']."</foaf:page>\n";
  }
  if ($place['Place[DBpedia Page]']) {
    print "  <owl:sameAs>".$place['Place[DBpedia Page]']."</owl:sameAs>\n";
  }
  if ($place['Place[Freebase Link]']) {
    print "  <owl:sameAs>".$place['Place[Freebase Link]']."</owl:sameAs>\n";
  }
  if ($place['Place[Yago Link]']) {
    print "  <owl:sameAs>".$place['Place[Yago Link]']."</owl:sameAs>\n";
  }
  if ($place['Place[Geonames Link]']) {
    print "  <owl:sameAs>".$place['Place[Geonames Link]']."</owl:sameAs>\n";
  }
  if ($place['Place[Linkedgeodata Link]']) {
    print "  <owl:sameAs>".$place['Place[Linkedgeodata Link]']."</owl:sameAs>\n";
  }
  if ($place['Place[Rdfabout Link]']) {
    print "  <owl:sameAs>".$place['Place[Rdfabout Link]']."</owl:sameAs>\n";
  }

  print " </schema:Place>\n";
}

function output_place2($place) {
  fputcsv(STDOUT, $place);
}

if (($data = fgetcsv(STDIN, 1000, ",")) !== FALSE) {
  for ($i = 0; $i < count($data); $i++) {
    $header[$i] = $data[$i];
  }
  while (($data = fgetcsv(STDIN, 1000, ",")) !== FALSE) {
    if ($data[0]) {
      $place = array();
      for ($i = 0; $i < count($data); $i++) {
	$place[$header[$i]] = $data[$i];
      }
      if ($place['Title']) { output_place($place); }
    }
  }
}

print "</rdf:RDF>\n";
