<?php

/**
 * Background job to import a page into the wiki, for use by ImportGEDCOM
 *
 */
class ImportGEDCOMJob extends Job {

	function __construct( $title, $params = '', $id = 0 ) {
		parent::__construct( 'gedcomImport', $title, $params, $id );
	}

	/**
	 * Run a gedcomImport job
	 * @return boolean success
	 */
	function run() {
		wfProfileIn( __METHOD__ );

		if ( is_null( $this->title ) ) {
			$this->error = "gedcomImport: Invalid title";
			wfProfileOut( __METHOD__ );
			return false;
		}

		$article = new Article( $this->title );
		if ( !$article ) {
			$this->error = 'gedcomImport: Article not found "' . $this->title->getPrefixedDBkey() . '"';
			wfProfileOut( __METHOD__ );
			return false;
		}

		// change global $wgUser variable to the one specified by
		// the job only for the extent of this import
		global $wgUser;
		$actual_user = $wgUser;
		$wgUser = User::newFromId( $this->params['user_id'] );
		$text = $this->params['text'];
		$edit_summary = $this->params['edit_summary'];
		$article->doEdit( $text, $edit_summary );
		$wgUser = $actual_user;
		wfProfileOut( __METHOD__ );
		return true;
	}

	function toString() {
		if ( is_object( $this->title ) ) {
			return "{$this->command} " . $this->title->getPrefixedDBkey();
		} else {
			return "{$this->command}";
		}
	}
}

