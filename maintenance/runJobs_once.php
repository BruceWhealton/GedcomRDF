<?php
/*
 * This file contains a copy of runJobs.php but it protects this script
 * from parallel execution of more than one instance at the same time.
 * Usage: use the following cron command to run every 5 minutes:
 * /usr/local/bin/php /home/myfamily/public_html/w/maintenance/runJobs_once.php --maxjobs 600 2>&1 >> /home/myfamily/public_html/w/runJobs.log
 */

$max_exec_time = 1800; // sec
$prog_name = 'runJobs';
$lock_file = '/tmp/'.$prog_name.'.lock';

$run = TRUE;

if(file_exists($lock_file)) {
  list($pid, $time) = explode(PHP_EOL, file_get_contents($lock_file));
  if (is_numeric($pid) && is_numeric($time)) {
    $pids = explode(PHP_EOL, shell_exec("ps x|grep ".escapeshellarg($prog_name).
                                        "|grep -v grep|awk '{print $1}'"));
    if (in_array($pid, $pids)) {
      if (time() - $time > $max_exec_time) {
        shell_exec('kill '.escapeshellarg($pid));
      } else {
        $run = FALSE;
      }
    }
  }
}

if (!$run) { exit(0); }

if(file_exists($lock_file)) {
  unlink($lock_file);
}
file_put_contents($lock_file, getmypid().PHP_EOL.time());

/* We can't include runJobs.php because Maintenance::shouldExecute
   prevents its execution, so copy its content here below
   (with Tweet modifications).  */

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class RunJobs extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Run pending jobs";
		$this->addOption( 'maxjobs', 'Maximum number of jobs to run', false, true );
		$this->addOption( 'type', 'Type of job to run', false, true );
		$this->addOption( 'procs', 'Number of processes to use', false, true );
	}

	public function memoryLimit() {
		// Don't eat all memory on the machine if we get a bad job.
		return "150M";
	}

	public function execute() {
		// Don't post Tweets from the import script.
		global $wgHooks;
		unset($wgHooks['ArticleInsertComplete']['TweetANew::TweetANewNewArticle']);
		unset($wgHooks['ArticleSaveComplete']['TweetANew::TweetANewEditMade']);
		unset($wgHooks['EditPageBeforeEditChecks']['efTweetANewEditCheckBox']);

		global $wgTitle;
		if ( $this->hasOption( 'procs' ) ) {
			$procs = intval( $this->getOption( 'procs' ) );
			if ( $procs < 1 || $procs > 1000 ) {
				$this->error( "Invalid argument to --procs", true );
			}
			$fc = new ForkController( $procs );
			if ( $fc->start( $procs ) != 'child' ) {
				exit( 0 );
			}
		}
		$maxJobs = $this->getOption( 'maxjobs', 10000 );
		$type = $this->getOption( 'type', false );
		$wgTitle = Title::newFromText( 'RunJobs.php' );
		$dbw = wfGetDB( DB_MASTER );
		$n = 0;
		$conds = '';
		if ( $type !== false )
			$conds = "job_cmd = " . $dbw->addQuotes( $type );

		while ( $dbw->selectField( 'job', 'job_id', $conds, 'runJobs.php' ) ) {
			$offset = 0;
			for ( ; ; ) {
				$job = !$type ? Job::pop( $offset ) : Job::pop_type( $type );

				if ( !$job )
					break;

				wfWaitForSlaves( 5 );
				$t = microtime( true );
				$offset = $job->id;
				$status = $job->run();
				$t = microtime( true ) - $t;
				$timeMs = intval( $t * 1000 );
				if ( !$status ) {
					$this->runJobsLog( $job->toString() . " t=$timeMs error={$job->error}" );
				} else {
					$this->runJobsLog( $job->toString() . " t=$timeMs good" );
				}
				if ( $maxJobs && ++$n > $maxJobs ) {
					break 2;
				}
			}
		}
	}

	/**
	 * Log the job message
	 * @param $msg String The message to log
	 */
	private function runJobsLog( $msg ) {
		$this->output( wfTimestamp( TS_DB ) . " $msg\n" );
		wfDebugLog( 'runJobs', $msg );
	}
}

$maintClass = "RunJobs";
require_once( RUN_MAINTENANCE_IF_MAIN );

if(file_exists($lock_file)) {
  unlink($lock_file);
}
