<?php
/**
 * Initialization file for the ImportGEDCOM extension
 *
 */

if ( !defined( 'MEDIAWIKI' ) ) die();

define( 'IMPORT_GEDCOM_VERSION', '1.0' );

$wgExtensionCredits['specialpage'][] = array(
	'path'           => __FILE__,
	'name'           => 'Import GEDCOM',
	'version'        => IMPORT_GEDCOM_VERSION,
	'author'         => 'Juri Linkov',
	'url'            => 'http://my-family-lineage.com/wiki/Special:Version',
	'descriptionmsg' => 'importgedcom-desc',
);

$wgSpecialPages['ImportGEDCOM'] = 'GEDCOM2Wiki';
$wgAutoloadClasses['GEDCOM2Wiki'] = dirname( __FILE__ ) . '/GEDCOM2Wiki.php';

$wgJobClasses['gedcomImport'] = 'ImportGEDCOMJob';
$wgAutoloadClasses['ImportGEDCOMJob'] = dirname(__FILE__) . '/ImportGEDCOMJob.php';

$wgGroupPermissions['sysop']['gedcomimport'] = true;
$wgGroupPermissions['user']['gedcomimport'] = true;
$wgAvailableRights[] = 'gedcomimport';

$wgExtensionMessagesFiles['ImportGEDCOM'] = dirname(__FILE__) . '/ImportGEDCOM.i18n.php';
