<?php
  /**
   * Lets the user import a GEDCOM file to turn into wiki pages
   *
   */

if ( !defined( 'MEDIAWIKI' ) ) die();

class GEDCOM2Wiki extends SpecialPage {

  var $mPages = array();

  /**
   * Constructor
   */
  public function GEDCOM2Wiki() {
    parent::__construct( 'ImportGEDCOM' );
  }

  function execute( $query ) {
    global $wgUser, $wgOut, $wgRequest;
    $this->setHeaders();

    if ( ! $wgUser->isAllowed( 'gedcomimport' ) ) {
      global $wgOut;
      $wgOut->addHTML( wfMsgWikiHtml( 'importgedcom-full-description' ) );
      // The following mostly copied from 'loginToUse' from OutputPage.php:
      if( $wgUser->isLoggedIn() ) {
	$wgOut->permissionRequired( 'gedcomimport' );
	return;
      }
      $skin = $wgUser->getSkin();
      $wgOut->setPageTitle( wfMsg( 'loginreqtitle' ) );
      $wgOut->setHtmlTitle( wfMsg( 'errorpagetitle' ) );
      $wgOut->setRobotPolicy( 'noindex,nofollow' );
      $wgOut->setArticleFlag( false );
      $loginTitle = SpecialPage::getTitleFor( 'Userlogin' );
      $loginLink = $skin->link(
		$loginTitle,
		wfMsgHtml( 'loginreqlink' ),
		array(),
		array( 'returnto' => $wgOut->getTitle()->getPrefixedText() ),
		array( 'known', 'noclasses' )
		);
      $wgOut->addHTML( wfMsgWikiHtml( 'importgedcom-loginreqpagetext', $loginLink ) );
      return;
    }

    if ( $wgRequest->getCheck( 'import_file' ) ) {
      $text = "<p>" . wfMsg( 'importgedcom_importing' ) . "</p>\n";
      $uploadResult = ImportStreamSource::newFromUpload( "file_name" );
      // handling changed in MW 1.17
      $uploadError = null;
      if ( $uploadResult instanceof Status ) {
	if ( $uploadResult->isOK() ) {
	  $source = $uploadResult->value;
	} else {
	  $uploadError = $wgOut->parse( $uploadResult->getWikiText() );
	}
      } elseif ( $uploadResult instanceof WikiErrorMsg ) {
	$uploadError = $uploadResult->getMessage();
      } else {
	$source = $uploadResult;
      }

      if ( !is_null( $uploadError ) ) {
	$text .= $uploadError;
	$wgOut->addHTML( $text );
	return;
      }

      $file_name = wfBaseName($_FILES['file_name']['name']);
      if (!$file_name) { $file_name = date('c'); }

      $error_msg = self::getGEDCOMData( $source->mHandle, $file_name );
      if ( ! is_null( $error_msg ) ) {
	$text .= $error_msg;
	$wgOut->addHTML( $text );
	return;
      }
    }  elseif ( $wgRequest->getVal( 'action' ) == 'listfiles' ) {
	$text .= self::listRDFFiles();
    }  elseif ( $wgRequest->getVal( 'action' ) == 'importrdffiles' ) {
	$text .= self::importRDFFiles();
    } else {
      $wgOut->addHTML( wfMsgWikiHtml( 'importgedcom-full-description' ) );
      $select_file_label = wfMsg( 'importgedcom_selectfile', 'GEDCOM' );
      $import_button = wfMsg( 'import-interwiki-submit' );
      $text = <<<END
	<p>$select_file_label</p>
	<form enctype="multipart/form-data" action="" method="post">
	<p><input type="file" name="file_name" size="25" /></p>
	<p><input type="Submit" name="import_file" value="$import_button"></p>
	</form>

END;

      $skin = $wgUser->getSkin();
      $text .= $skin->linkKnown(
				SpecialPage::getTitleFor( 'ImportGEDCOM' ),
				wfMsgHtml( 'importgedcom-listfiles' ),
				array(),
				array( 'action' => 'listfiles' )
				);
    }

    $wgOut->addHTML( $text );
  }

  function getGEDCOMData( $ged_file, $ged_filename ) {
    if ( is_null( $ged_file ) )
      return wfMsg( 'emptyfile' );

    $ged_text = '';
    while ( !feof( $ged_file ) ) {
      $ged_text .= fgets( $ged_file, 65535 );
    }

    $dir_name = tempnam(sys_get_temp_dir(), "GEDCOMImport");
    unlink($dir_name);
    mkdir($dir_name);

    $ged_fn = $dir_name . '/' . $ged_filename;
    if ($ged_fh = fopen($ged_fn, "w")) {
      fwrite($ged_fh, $ged_text);
      fclose($ged_fh);
    }
    unset($ged_text);

    $ged_basename = basename(basename($ged_filename, '.GED'), '.ged');
    $rdf_filename = $ged_basename . '.rdf';
    $rdf_fn = $dir_name . '/' . $rdf_filename;
    $error_fn = $dir_name . '/errors';
    $output_fn = $dir_name . '/output';

    $cmd = wfEscapeShellArg('/usr/bin/perl') .
      ' ' . wfEscapeShellArg('extensions/ImportGEDCOM/gedcom2wiki.pl') .
      ' ' . wfEscapeShellArg($ged_fn) .
      ' ' . wfEscapeShellArg($rdf_fn) .
      ' > ' . wfEscapeShellArg($output_fn) .
      ' 2> ' . wfEscapeShellArg($error_fn);
    $retval = '';
    $output = wfShellExec( $cmd, $retval );

    $error_str = file_get_contents( $error_fn );
    // unlink($error_fn);

    if ( $retval !== 0 ) {
      return sprintf( "<pre>Import failed: error %d\n%s\n%s</pre>\n",
		      $retval, trim( $error_str ) , $output );
    }

    $edit_summary = wfMsgForContent( 'importgedcom_editsummary', 'GEDCOM' );
    $return_str = '';

    global $wgLegalTitleChars;
    global $wgUser;
    $skin = $wgUser->getSkin();

    $jobs = array();
    $job_params = array();
    $job_params['user_id'] = $wgUser->getId();
    $job_params['edit_summary'] = $edit_summary;

    error_log ('ini.memory_limit='.ini_get('memory_limit'));
    error_log ('0. memory_get_usage='.memory_get_usage().' memory_get_peak_usage='.memory_get_peak_usage());

    $output_fh = fopen($output_fn, "r");
    if ($output_fh) {
      while (!feof($output_fh)) {
	$page_name = stream_get_line($output_fh, 1000000, "\n");
	$text = stream_get_line($output_fh, 1000000, "\f");

	$page_name = preg_replace('/[^'.$wgLegalTitleChars.']/', '', $page_name);
	$title = Title::newFromText( $page_name );
	if (!is_object($title)) continue;

	$job_params['text'] = $text . "[[Category:GEDCOM_$ged_basename]]\n";
	$jobs[] = new ImportGEDCOMJob( $title, $job_params );
	// Do a small transaction for accumulated 300 jobs and free memory.
	if ( count( $jobs ) >= 300 ) {
	  Job::batchInsert( $jobs );
	  $jobs = array();
	  error_log ('1. memory_get_usage='.memory_get_usage().' memory_get_peak_usage='.memory_get_peak_usage());
	}
      }
      if ($jobs) { // last chunk
	Job::batchInsert( $jobs );
	$jobs = array();
      }
      error_log ('2. memory_get_usage='.memory_get_usage().' memory_get_peak_usage='.memory_get_peak_usage());
      fclose($output_fh);
    }
    // unlink($output_fn);

    $GED_title = Title::makeTitleSafe( NS_FILE, $ged_filename );
    if ( is_object( $GED_title ) ) {
      $image = wfLocalFile( $GED_title );
      $archive = $image->publish( $ged_fn );
      if ( $archive->isGood() ) {
	$image->recordUpload( $archive->value, $edit_summary, '' );
      }
    }

    error_log ('3. memory_get_usage='.memory_get_usage().' memory_get_peak_usage='.memory_get_peak_usage());

    $RDF_title = Title::makeTitleSafe( NS_FILE, $rdf_filename );
    if ( is_object( $RDF_title ) ) {
      $image = wfLocalFile( $RDF_title );
      $archive = $image->publish( $rdf_fn );
      if ( $archive->isGood() ) {
	$image->recordUpload( $archive->value, $edit_summary, '' );
      }
    }

    error_log ('4. memory_get_usage='.memory_get_usage().' memory_get_peak_usage='.memory_get_peak_usage());

    $GED_title = preg_replace('/^File:/', '', $GED_title);
    $RDF_title = preg_replace('/^File:/', '', $RDF_title);

    $GEDCOM_text = <<<END
{{GEDCOM
|Name=$ged_basename
|GEDCOM file=$GED_title
|RDF file=$RDF_title
}}
END;

    error_log ('5. memory_get_usage='.memory_get_usage().' memory_get_peak_usage='.memory_get_peak_usage());

    $GEDCOM_title = Title::newFromText( "Category:GEDCOM_$ged_basename" );
    if ( is_object( $GEDCOM_title ) ) {
      $GEDCOM_article = new Article( $GEDCOM_title );
      if ( !$GEDCOM_article ) {
	return 'gedcomImport: Article not found "' . $GEDCOM_title . '"';
      }
      $GEDCOM_article->doEdit( $GEDCOM_text, $edit_summary );
    } else {
	return 'gedcomImport: Invalid page title "' . $ged_basename . '"';
    }

    error_log ('6. memory_get_usage='.memory_get_usage().' memory_get_peak_usage='.memory_get_peak_usage());

    // unlink($ged_fn . '.index');
    // unlink($ged_fn);
    // unlink($rdf_fn);
    // rmdir($dir_name);

    $return_str =
      "Imported GEDCOM file to the following page: <br />\n" .
      $skin->link( $GEDCOM_title, $GEDCOM_title->getPrefixedText(), array(), array() ) .
      "<br />\n<br />\n";

    return $return_str;
  }

  function getRDFFiles( ) {
    global $wgUser;
    $skin = $wgUser->getSkin();
    $return_array = array();
    $batchSize = 1000;
    $start = '';
    $dbr = wfGetDB( DB_SLAVE );

    // logic from maintenance/checkImages.php
    do {
      $res = $dbr->select( 'image', '*', array( 'img_name ' . $dbr->buildLike( $dbr->anyString(), ".rdf" ),
						'img_name > ' . $dbr->addQuotes( $start ) ),
			   __METHOD__, array( 'LIMIT' => $batchSize ) );
      foreach ( $res as $row ) {
	$start = $row->img_name;
	$file = RepoGroup::singleton()->getLocalRepo()->newFileFromRow( $row );
	$path = $file->getPath();
	if ( !$path ) continue;
	$stat = @stat( $file->getPath() );
	if ( !$stat ) continue;
	if ( $stat['mode'] & 040000 ) continue;
	if ( $stat['size'] == 0 && $row->img_size != 0 ) continue;
	if ( $stat['size'] != $row->img_size ) continue;

	$return_array[] = $file;
      }
    } while ( $res->numRows() );

    return $return_array;
  }

  function listRDFFiles( ) {
    $return_str = '';
    foreach (self::getRDFFiles() as $file) {
      $return_str .= '<a href="'.htmlspecialchars($file->getFullURL()).'">'.htmlspecialchars(basename($file->getPath())).'</a><br />'."\n";
    }
    return $return_str;
  }

  function importRDFFiles( ) {
    $return_str = '';
    foreach (self::getRDFFiles() as $file) {
      $return_str .= self::importRDFFile($file->getPath())."<br />\n";
    }
    return $return_str;
  }

  function importRDFFile($filename) {
    error_log ('1. importRDFFile.filename='.$filename);
    $basename = basename(basename($filename, '.RDF'), '.rdf');
    $graph = 'http://futurewavehosting.com:3030/my-family-lineage/graph/'.$basename;
    $dataset = 'http://futurewavehosting.com:3030/my-family-lineage/data';
    $url = $dataset."?graph=".urlencode($graph);
    $fp = fopen($filename, 'r');
    $filesize = filesize($filename);
    $headers = array(
      'Content-Type: application/rdf+xml;charset=utf-8',
      'Content-Length: ' . $filesize,
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_PUT, 1);
    curl_setopt($ch, CURLOPT_INFILE, $fp);
    curl_setopt($ch, CURLOPT_INFILESIZE, $filesize);
    curl_setopt($ch, CURLOPT_VERBOSE, 0);

    $return_str = curl_exec($ch);
    if(!curl_errno($ch)){
      $info = curl_getinfo($ch);
      $return_str .= 'Took ' . $info['total_time'] . ' seconds to send the RDF file '. $filename .' to ' . $info['url'] . "<br />\n";
    } else {
      $return_str .= 'Curl error: ' . curl_error($ch) . "<br />\n";
    }
    curl_close($ch);
    fclose($fp);
    return $return_str;
  }

}
