GedcomRDF
=========

Gedcom and Genealogy information in Semantic Web format, using RDF serialization format(s).  The GEDCOM format is a
proprietary and open defacto standard for exchanging genealogical data between different computer programs.  Using the 
Perl GEDCOM.pm module, several genealogies were parsed, converted to and saved as RDF files.  This information was 
aligned with popular and existing vocabularies for describing people, their relationships and biographical history.  
The initial, and seemingly obvious, vocabularies used were FOAF (which is described here: http://xmlns.com/foaf/spec/), 
BIO: A vocabulary for biographical information (described here: http://vocab.org/bio/0.1/.html) and REL:
RELATIONSHIP: A vocabulary for describing relationships between people (which is specified here:
http://vocab.org/relationship/.html).

Persons are invited to make use of this data.   

It may be valuable to develop a unique ontology for Genealogy data on the web.  This would relate each and every term 
from the GEDCOM specification to Semantic Web Ontology Classes with specific properties that relate the classes (terms)
to one another.  The GEDCOM X vocabulary was examined for this project.  This project can be found on github
here: https://github.com/FamilySearch/gedcomx/wiki/Community
While the GEDCOM X specification is very comprehensive and aligns well with the GEDCOM terms, it can be improved by 
defining the ontology more formally.  A formal ontology or vocabulary would specify certain terms as Classes which have
specific properties that are defined for describing and relating the terms.  

Another related project is the historical-data schemas which are a set of enhancements to existing schema.org 'types'
and new 'types', each associated with a set of properties. The types are arranged in a hierarchy, which inherits and 
shares more general types and properties with schemas listed at schema.org.  This was developed by and for the major
search engines.  The historical-data schemas project was not consulted for this project, one reason being the newness 
of the historical-data schemas.  It is still uncertain as to whether or not schema.org schemas will be used in the 
development of software applications for the Semantic Web.  

The value of this effort is best realized through the widespread adoption of this ontology/vocabulary by the wider 
community of genealogists and family historians - the community of persons who enjoy tracing their roots.  Having 
genealogical data in various RDF/Semantic Web serialization formats has value that extends beyond its use by search 
engines.  Hopefully new software applications will use this data.  End users will be interested in the new
applications and features that the Semantic Web offers.  We will need individuals to submit their GEDCOM data for
conversion to RDF format.
