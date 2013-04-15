#!/usr/bin/perl
#
# Wikify GEDCOM data.
#
# This script converts GEDCOM files to Wiki and RDF format.
#
# Uses the CPAN module Gedcom.pm by Paul Johnson, see
# http://search.cpan.org/dist/Gedcom/ for docs.
#
# Author: Juri Linkov
#
# Usage: perl gedcom2wiki.pl --basename=example example.ged example.rdf

use strict;
require 5.005;
use diagnostics;
use utf8;
$| = 1;

my $wiki_base_url = "http://my-family-lineage.com/wiki/";

use Data::Dumper;
$Data::Dumper::Indent = 1;

eval "use Date::Manip";
Date_Init("DateFormat=US") if $INC{"Date/Manip.pm"};

$SIG{__WARN__} = sub { print STDERR "\n@_" };

my $rdf_fh;

use Gedcom;
my $ged;                        # Global Gedcom object

sub read_gedcom_file
{
  my $gedcom_file = shift;
  # print "reading $gedcom_file...";

  $ged = Gedcom->new
  (
   gedcom_file     => $gedcom_file,
   read_only       => 1,
  );

  # print "\nvalidating...";
  # $ged->validate; # || print "validation failed.";

  # print "\nnormalising dates...";
  $ged->normalise_dates("%d %b %Y");
}

sub year
{
  my $date = shift;
  my $year;
  if ($date && $INC{"Date/Manip.pm"}) {
      $year = UnixDate(ParseDate($date), "%Y");
  }
  return $year if ((defined $year) && $year);
  return $1 if ((defined $date) && ($date =~ /(\d{4})/));
  return '?';
}

sub person_label
{
  my $i = shift;
  my $given_names = $i->given_names;
  my $surname = $i->surname;
  $given_names = 'First Name Unknown' if $given_names eq '?';
  # Remove nicknames.
  $given_names =~ s/ *[({"[].*?[]"})]//;

  my $name = join(' ', $given_names, $surname);
  $name =~ s/ Jr (.*)$/ $1 Jr/;

  my $birth_year = year($i->birth && $i->get_value("birth date"));
  my $death_year = year($i->death && $i->get_value("death date"));
  my $ret = "$name($birth_year-$death_year)";

  # Remove illegal characters from Wiki Titles.
  # Use $wgLegalTitleChars from w/includes/DefaultSettings.php, i.e.
  # $wgLegalTitleChars = " %!\"$&'()*,\\-.\\/0-9:;=?@A-Z\\\\^_`a-z~\\x80-\\xFF+";
  $ret =~ s'[^ %!"$&\'()*,\-./0-9:;=?@A-Z\\^_`a-z~\x80-\xFF+]''g; #'

  # A shorter list of forbidden characters is from:
  # http://en.wikipedia.org/wiki/Wikipedia:Naming_conventions_(technical_restrictions)#Forbidden_characters
  # But don't use it now, the above is more correct, so commented out:
  # $ret =~ tr/#<>[]|{}"//d;

  return $ret;
}

sub wiki_urlencode {
    my $s = shift;

    # Create URL from Wiki Title.
    # Convert spaces to underscores.
    # Also convert all characters except ones not converted
    # by PHP's 'urlencode', i.e. [^-.0-9A-Z_a-z]
    # And don't convert characters mentioned in 'wfUrlencode'
    # in includes/GlobalFunctions.php, i.e. [^;@$!*(),/:]
    $s =~ s' '_'g; #'
    $s =~ s'([^-.0-9A-Z_a-z;@$!*(),/:])'sprintf("%%%02X", ord($1))'seg; #'

    return $s;
}

sub wiki_url {
    my $s = shift;
    $s = wiki_urlencode($s);
    if (defined $wiki_base_url) {
      $s = $wiki_base_url . $s;
    }
    return $s;
}

sub list_of_labels { join(', ', map { person_label($_) } @_) }

sub get_persons
{
  foreach my $i ($ged->individuals) {
    my @parents = $i->parents;
    my $given_names = $i->given_names;
    my $surname = $i->surname;
    my $nickname = $1 if ($given_names =~ m/[({"[](.*?)[]"})]/);
    # Remove nicknames.
    $given_names =~ s/ *[({"[].*?[]"})]//;
    my $fullname = join ' ', $given_names, $surname;
    my $person =
    {
      page_name  => person_label($i),
      full_name  => $fullname,
      given_names => $given_names,
      surname    => $surname,
      gender     => (defined $i->get_value("sex") ?
                    ($i->sex eq 'M' ? 'Male' : $i->sex eq 'F' ? 'Female' : 'Unknown') : 'Unknown'),
      spouse     => list_of_labels($i->spouse),
      father     => list_of_labels(grep { $_->sex eq 'M' } @parents),
      mother     => list_of_labels(grep { $_->sex eq 'F' } @parents),
      ancestors  => list_of_labels(@parents),
      siblings   => list_of_labels($i->siblings),
      children   => list_of_labels($i->children),
      # grandparents => list_of_labels(map { $_->parents } $i->parents),
      occupation => $i->get_value("occupation place") || $i->get_value("occupation") || '',
      education  => $i->get_value("education") || '',
      ssn        => $i->get_value("soc_sec_number") || '',
      idno       => $i->get_value("ident_number") || '',
      nationality => $i->get_value("nationality") || '',
      caste      => $i->get_value("caste") || '',
      religion   => $i->get_value("religion") || '',
    };
    $person->{nickname} = $nickname if $nickname;

    my $biography = '';
    for my $src ($i->get_record("name source")) {
        my $data = $src->get_value("data text");
        $biography .= $data . "\n" if defined $data;
    }
    chomp($biography);
    # $person->{biography} = $biography;
    $person->{biography} = '';
    $person->{free_text} = $biography;

    for my $event_type qw( birth death graduation emigration
			   adoption baptism bar_mitzvah bas_mitzvah
			   blessing burial census christening adult_christening
			   confirmation cremation first_communion immigration
			   individual naturalization ordination probate
			   residence retirement will) {
      my @event_pages;
      for my $event_record ($i->get_record($event_type)) {
        my $date  = $event_record->get_value("date");
        my $place = $event_record->get_value("place");
        my $value = $event_record->{value}
              ? ref $event_record->{value}
                  ? $event_record->{value}{xref}
                  : $event_record->full_value
                  : undef;
        my $event;
	my $event_name = ucfirst($event_type);
	$event_name = 'AdultChristening' if ($event_type eq 'adult_christening');
	$event_name = 'BarMitzvah' if ($event_type eq 'bar_mitzvah');
	$event_name = 'BasMitzvah' if ($event_type eq 'bas_mitzvah');
	$event_name = 'FirstCommunion' if ($event_type eq 'first_communion');
        $event->{page_name} = $event_name." of ".$person->{page_name};
        $event->{date} = (defined $date) ? $date : '';
        if (defined $place) {
          $event->{place} = $place;
          my ($place_label) = $place =~ /^([^,]*)/;
          if ($event_type eq 'graduation') {
            $event->{page_name} .= " from ".$place_label;
          }
          if ($event_type eq 'emigration') {
            $event->{page_name} .= " to ".$place_label;
          }
        } else {
            $event->{place} = '';
        }
        $event->{free_text} = (defined $value) ? $value : '';
        push @{$person->{events}}, $event;
        push @event_pages, $event->{page_name};
      }
      $person->{event_pages}->{$event_type} =
        @event_pages ? join(', ', @event_pages) : '';
    }

    write_person_to_wiki($person);
    write_person_to_rdf($person) if (defined($rdf_fh) && $rdf_fh);
  }
}

sub write_person_to_wiki
{
  my $person = shift;
  my $page_name = $person->{page_name};
  $person->{nickname} = '' if !defined $person->{nickname};
  my $text = <<"EOTP";
{{Person
|Full Name=$person->{full_name}
|Surname=$person->{surname}
|Nickname=$person->{nickname}
|Gender=$person->{gender}
|Spouse=$person->{spouse}
|Father=$person->{father}
|Mother=$person->{mother}
|Ancestors=$person->{ancestors}
|Siblings=$person->{siblings}
|Children=$person->{children}
|Birth=$person->{event_pages}->{birth}
|Graduation=$person->{event_pages}->{graduation}
|Emigration=$person->{event_pages}->{emigration}
|Death=$person->{event_pages}->{death}
|Occupation=$person->{occupation}
|Education=$person->{education}
|SSN=$person->{ssn}
|Religious Affiliation=$person->{religion}
|National Origin=$person->{nationality}
|National Id=$person->{idno}
|Caste Name=$person->{caste}
|Biography=$person->{biography}
}}
<pre>$person->{free_text}</pre>
__SHOWFACTBOX__
EOTP
  print "$page_name\n$text\f";

  foreach my $event (@{$person->{events}}) {
    # print Dumper($event);
    my $page_name = $event->{page_name};
    my ($event_type) = $page_name =~ /^(\w+)/;
    my $location_label = 'Location';
    $location_label = 'Organization' if ($event_type eq 'Graduation');
    $location_label = 'State Emigrating To' if ($event_type eq 'Emigration');
    $text = <<"EOTPE1";
{{$event_type
|Date=$event->{date}
|Name=$person->{page_name}
EOTPE1
    if ($event_type eq 'Birth' || $event_type eq 'Adoption') {
      $text .= <<"EOTPE2";
|Parent 1=$person->{mother}
|Parent 2=$person->{father}
EOTPE2
    }
    $text .= <<"EOTPE3";
|$location_label=$event->{place}
|$event_type Source Information=
}}
<pre>$event->{free_text}</pre>
__SHOWFACTBOX__
EOTPE3
    print "$page_name\n$text\f";
  }
}

sub get_families
{
  foreach my $f ($ged->families) {
    my $spouse1 = $f->wife    ? person_label($f->wife)    : '';
    my $spouse2 = $f->husband ? person_label($f->husband) : '';
    my $family =
    {
      page_name => "Family of $spouse1 and $spouse2",
      spouse1   => $spouse1,
      spouse2   => $spouse2,
      children  => list_of_labels($f->children),
    };

    my $text = '';
    for my $src ($f->get_record("name source")) {
        my $data = $src->get_value("data text");
        $text .= $data . "\n" if defined $data;
    }
    chomp($text);
    $family->{free_text} = $text;

    for my $event_type qw( marriage divorce divorce_filed annulment engagement
			   marriage_bann marr_contract marr_license marr_settlement ) {
      my @event_pages;
      for my $event_record ($f->get_record($event_type)) {
        my $date  = $event_record->get_value("date");
        my $place = $event_record->get_value("place");
        my $value = $event_record->{value}
              ? ref $event_record->{value}
                  ? $event_record->{value}{xref}
                  : $event_record->full_value
                  : undef;
        my $event;
	my $event_name = ucfirst($event_type);
	$event_name = 'DivorceFiling' if ($event_type eq 'divorce_filed');
	$event_name = 'MarriageBanns' if ($event_type eq 'marriage_bann');
	$event_name = 'MarriageContract' if ($event_type eq 'marr_contract');
	$event_name = 'MarriageLicense' if ($event_type eq 'marr_license');
	$event_name = 'MarriageSettlement' if ($event_type eq 'marr_settlement');
        $event->{page_name} = $event_name." of $spouse1 and $spouse2";
        $event->{date} = (defined $date) ? $date : '';
        $event->{place} = (defined $place) ? $place : '';
        $event->{free_text} = (defined $value) ? $value : '';
        push @{$family->{events}}, $event;
        push @event_pages, $event->{page_name};
      }
      $family->{event_pages}->{$event_type} =
        @event_pages ? join(', ', @event_pages) : '';
    }
    write_family_to_wiki($family);
    write_family_to_rdf($family) if (defined($rdf_fh) && $rdf_fh);
  }
}

sub write_family_to_wiki
{
  my $family = shift;
  my $page_name = $family->{page_name};
  my $event_pages = {'marriage'=>'','divorce'=>'','divorcefiling'=>'',
		     'annulment'=>'','engagement'=>'',
		     'marriagebanns'=>'','marriagecontract'=>'',
		     'marriagelicense'=>'','marriagesettlement'=>'',};
  foreach my $event (@{$family->{events}}) {
    my $page_name = $event->{page_name};
    my ($event_type) = $page_name =~ /^(\w+)/;
    $event_pages->{lc $event_type}=$page_name;
  }
  my $text = <<"EOTPF";
{{Family
|Husband=$family->{spouse2}
|Wife=$family->{spouse1}
|Children=$family->{children}
|Marriage=$family->{event_pages}->{marriage}
|Divorce=$family->{event_pages}->{divorce}
}}
<pre>$family->{free_text}</pre>
__SHOWFACTBOX__
EOTPF
  print "$page_name\n$text\f";

  foreach my $event (@{$family->{events}}) {
    my $page_name = $event->{page_name};
    my ($event_type) = $page_name =~ /^(\w+)/;
    my $text = <<"EOTPFE1";
{{$event_type
|Date=$event->{date}
EOTPFE1
    if ($event_type eq 'Engagement') {
      $text .= <<"EOTPE2";
|Partner 1=$family->{spouse1}
|Partner 2=$family->{spouse2}
EOTPE2
} else {
      $text .= <<"EOTPE2";
|Spouse 1=$family->{spouse1}
|Spouse 2=$family->{spouse2}
EOTPE2
}
    $text .= <<"EOTPFE3";
|Location=$event->{place}
|$event_type Source Information=
}}
<pre>$event->{free_text}</pre>
__SHOWFACTBOX__
EOTPFE3
    print "$page_name\n$text\f";
  }
}

sub write_person_to_rdf
{
  my $person = shift;
  my $page_name = $person->{page_name};
  my $uri = wiki_url($page_name);
  my $text = <<"EOTR";
  <foaf:Person rdf:about="$uri">
    <rdf:type rdf:resource="http://gedcomx.org/Person"/>
    <rdfs:label>$page_name</rdfs:label>
    <foaf:name>$person->{full_name}</foaf:name>
    <foaf:givenName>$person->{given_names}</foaf:name>
    <foaf:surname>$person->{surname}</foaf:surname>
    <foaf:gender>$person->{gender}</foaf:gender>
    <gx:Given>$person->{given_names}</gx:Given>
    <gx:Surname>$person->{surname}</gx:Surname>
    <gx:Gender rdf:resource="http://gedcomx.org/$person->{gender}"/>
EOTR
  if ($person->{nickname}) {
    $text .= <<"EOTR";
    <foaf:nick>$person->{nickname}</foaf:nick>
EOTR
  }
  if ($person->{spouse}) {
    for my $p (split /, /, $person->{spouse}) {
      my $uri = wiki_url($p);
      $text .= <<"EOTR";
    <rel:spouseOf rdf:resource="$uri"/>
EOTR
    }
  }
  if ($person->{mother}) {
    my $uri = wiki_url($person->{mother});
    $text .= <<"EOTR";
    <rel:childOf rdf:resource="$uri"/>
EOTR
  }
  if ($person->{father}) {
    my $uri = wiki_url($person->{father});
    $text .= <<"EOTR";
    <rel:childOf rdf:resource="$uri"/>
EOTR
  }
  if ($person->{siblings}) {
    for my $p (split /, /, $person->{siblings}) {
      my $uri = wiki_url($p);
      $text .= <<"EOTR";
    <rel:siblingOf rdf:resource="$uri"/>
EOTR
    }
  }
  if ($person->{occupation}) {
    $text .= <<"EOTR";
    <bio:occupation>$person->{occupation}</bio:occupation>
EOTR
  }

  foreach my $event (@{$person->{events}}) {
    my $page_name = $event->{page_name};
    my ($event_type) = $page_name =~ /^(\w+)/;
    my $page_uri = wiki_url($page_name);
    my $place_uri = wiki_url($event->{place});
    my $location_label = 'place';
    $location_label = 'organization' if ($event_type eq 'Graduation');
    $location_label = 'state' if ($event_type eq 'Emigration');
    $text .= <<"EOTRE";
    <bio:event>
      <bio:$event_type rdf:about="$page_uri">
        <rdfs:label>$page_name</rdfs:label>
        <rdf:type rdf:resource="http://gedcomx.org/Event"/>
        <gx:Fact rdf:resource="http://gedcomx.org/$event_type"/>
        <bio:date>$event->{date}</bio:date>
        <bio:$location_label rdf:resource="$place_uri"/>
      </bio:$event_type>
    </bio:event>
EOTRE
  }
  $text .= <<"EOTR";
  </foaf:Person>
EOTR

  if (defined($rdf_fh) && $rdf_fh) {
    $text =~ s/&/&amp;/;
    $rdf_fh->print($text);
  }
}

sub write_family_to_rdf
{
  my $family = shift;
  my $page_name = $family->{page_name};
  my $uri = wiki_url($page_name);
  my $text = <<"EOTRFE";
  <foaf:Group rdf:about="$uri">
    <rdf:type rdf:resource="http://xmlns.com/wordnet/1.6/Family"/>
    <rdf:type rdf:resource="http://gedcomx.org/Relationship"/>
    <rdfs:label>$page_name</rdfs:label>
EOTRFE
  foreach my $event (@{$family->{events}}) {
    my $page_name = $event->{page_name};
    my ($event_type) = $page_name =~ /^(\w+)/;
    my $page_uri = wiki_url($page_name);
    my $place_uri = wiki_url($event->{place});
    $text .= <<"EOTRFE";
    <bio:event>
      <bio:$event_type rdf:about="$page_uri">
        <rdfs:label>$page_name</rdfs:label>
        <rdf:type rdf:resource="http://gedcomx.org/Event"/>
        <gx:Fact rdf:resource="http://gedcomx.org/$event_type"/>
        <bio:date>$event->{date}</bio:date>
        <bio:place rdf:resource="$place_uri"/>
      </bio:$event_type>
    </bio:event>
EOTRFE
  }
  my $uri1 = wiki_url($family->{spouse1});
  my $uri2 = wiki_url($family->{spouse2});
  $text .= <<"EOTRFE";
    <foaf:member rdf:resource="$uri1"/>
    <foaf:member rdf:resource="$uri2"/>
  </foaf:Group>
EOTRFE

  if (defined($rdf_fh) && $rdf_fh) {
    $text =~ s/&/&amp;/;
    $rdf_fh->print($text);
  }
}

my $gedcom_file = shift @ARGV || die "No GEDCOM file specified.";

read_gedcom_file($gedcom_file);

my $rdf_file = shift @ARGV;
if ($rdf_file) {
  $rdf_fh = FileHandle->new($rdf_file, "w");
}

if (defined($rdf_fh) && $rdf_fh) {
  my $text = <<"EOTR";
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF
    xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
    xmlns:rdfs="http://www.w3.org/2000/01/rdf-schema#"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:rel="http://purl.org/vocab/relationship/"
    xmlns:foaf="http://xmlns.com/foaf/0.1/"
    xmlns:bio="http://purl.org/vocab/bio/0.1/"
    xmlns:gx="http://gedcomx.org/">
EOTR
  $rdf_fh->print($text);
}

get_persons();
get_families();

if (defined($rdf_fh) && $rdf_fh) {
  my $text = <<"EOTR";
</rdf:RDF>
EOTR
  $rdf_fh->print($text);
  $rdf_fh->close;
}
