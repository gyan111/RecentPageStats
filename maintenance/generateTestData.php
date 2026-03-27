<?php
/**
 * Generate test data for RecentPageStats extension
 *
 * This maintenance script creates test pages with realistic edit patterns
 * to test the RecentPageStats extension.
 *
 * Usage:
 *   php extensions/RecentPageStats/maintenance/generateTestData.php --pages=100 --edits=10
 *
 * @file
 * @ingroup Maintenance
 */

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

class GenerateRecentPageStatsTestData extends Maintenance {
	
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Generate test data for RecentPageStats extension' );
		$this->addOption( 'pages', 'Number of pages to create (default: 50)', false, true );
		$this->addOption( 'edits', 'Maximum edits per page (default: 10)', false, true );
		$this->addOption( 'days', 'Spread edits over this many days (default: 30)', false, true );
		$this->requireExtension( 'RecentPageStats' );
	}

	public function execute() {
		$numPages = (int)$this->getOption( 'pages', 50 );
		$maxEdits = (int)$this->getOption( 'edits', 10 );
		$daysSpread = (int)$this->getOption( 'days', 30 );
		
		$this->output( "Generating test data for RecentPageStats extension...\n" );
		$this->output( "Pages: $numPages, Max edits per page: $maxEdits, Days spread: $daysSpread\n\n" );
		
		$services = MediaWikiServices::getInstance();
		$wikiPageFactory = $services->getWikiPageFactory();
		$userFactory = $services->getUserFactory();
		
		// Create test users
		$usernames = [ 'Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace' ];
		$users = [];
		
		$this->output( "Creating test users...\n" );
		foreach ( $usernames as $username ) {
			$user = $userFactory->newFromName( $username );
			if ( !$user ) {
				continue;
			}
			
			if ( !$user->getId() ) {
				$user->addToDatabase();
				
				// Set real name for some users (to test real name display)
				if ( rand( 0, 1 ) ) {
					$user->setRealName( ucfirst( strtolower( $username ) ) . ' Test' );
				}
				
				$user->saveSettings();
				$this->output( "  Created user: $username\n" );
			} else {
				$this->output( "  User already exists: $username\n" );
			}
			
			$users[] = $user;
		}
		
		if ( empty( $users ) ) {
			$this->fatalError( "No users available for testing!\n" );
		}
		
		$this->output( "\nCreating pages with edits...\n" );
		
		// Namespaces to test
		$namespaces = [
			NS_MAIN => 'Main',
			NS_USER => 'User',
			NS_HELP => 'Help',
			NS_PROJECT => 'Project',
		];
		
		$createdPages = 0;
		$totalEdits = 0;
		
		for ( $i = 1; $i <= $numPages; $i++ ) {
			// Randomly select namespace
			$ns = array_rand( $namespaces );
			$nsName = $namespaces[$ns];
			
			// Create title
			$titleText = "TestPage_" . str_pad( $i, 4, '0', STR_PAD_LEFT );
			$title = Title::makeTitle( $ns, $titleText );
			
			if ( !$title || !$title->canExist() ) {
				$this->output( "  Skipping invalid title: $titleText\n" );
				continue;
			}
			
			// Skip if page already exists
			if ( $title->exists() ) {
				$this->output( "  Page already exists: {$title->getPrefixedText()}\n" );
				continue;
			}
			
			$page = $wikiPageFactory->newFromTitle( $title );
			
			// Random number of edits for this page
			$numEdits = rand( 1, $maxEdits );
			
			for ( $j = 0; $j < $numEdits; $j++ ) {
				$user = $users[ array_rand( $users ) ];
				
				// Generate content with varying sizes
				$paragraphs = rand( 2, 10 );
				$content = "= {$title->getText()} =\n\n";
				$content .= "This is a test page created for RecentPageStats extension testing.\n\n";
				
				for ( $p = 0; $p < $paragraphs; $p++ ) {
					$sentences = rand( 3, 8 );
					for ( $s = 0; $s < $sentences; $s++ ) {
						$content .= "Lorem ipsum dolor sit amet, consectetur adipiscing elit. ";
					}
					$content .= "\n\n";
				}
				
				$content .= "== Section $j ==\n\n";
				$content .= "Edit number $j by {$user->getName()}.\n";
				
				$wikiContent = new WikitextContent( $content );
				
				try {
					$updater = $page->newPageUpdater( $user );
					$updater->setContent( SlotRecord::MAIN, $wikiContent );
					
					// Randomly mark as minor edit (30% chance)
					if ( rand( 1, 100 ) <= 30 ) {
						$updater->setFlags( EDIT_MINOR );
					}
					
					$summary = CommentStoreComment::newUnsavedComment( 
						"Test edit " . ( $j + 1 ) . " of $numEdits" 
					);
					
					$updater->saveRevision( $summary, EDIT_NEW );
					
					// Update timestamp to spread over the days
					$this->updateRecentChangeTimestamp( $title, $daysSpread );
					
					$totalEdits++;
					
				} catch ( Exception $e ) {
					$this->output( "  Error creating page {$title->getPrefixedText()}: {$e->getMessage()}\n" );
					continue 2;
				}
			}
			
			$createdPages++;
			
			if ( $i % 10 == 0 ) {
				$this->output( "  Progress: $createdPages pages created, $totalEdits total edits\n" );
			}
		}
		
		// Rebuild recent changes to ensure everything is indexed
		$this->output( "\nRebuilding recent changes table...\n" );
		$this->rebuildRecentChanges();
		
		$this->output( "\n=== Summary ===\n" );
		$this->output( "Pages created: $createdPages\n" );
		$this->output( "Total edits: $totalEdits\n" );
		$this->output( "Test users: " . count( $users ) . "\n" );
		$this->output( "\nDone! Visit Special:RecentPageStats to see the results.\n" );
		$this->output( "URL: " . SpecialPage::getTitleFor( 'RecentPageStats' )->getFullURL() . "\n" );
	}
	
	/**
	 * Update the timestamp of the most recent change for a page
	 * to simulate edits spread over time
	 *
	 * @param Title $title
	 * @param int $daysSpread
	 */
	private function updateRecentChangeTimestamp( Title $title, int $daysSpread ): void {
		$dbw = $this->getDB( DB_PRIMARY );
		
		// Random timestamp within the spread period
		$daysAgo = rand( 0, $daysSpread );
		$hoursAgo = rand( 0, 23 );
		$minutesAgo = rand( 0, 59 );
		
		$secondsAgo = ( $daysAgo * 86400 ) + ( $hoursAgo * 3600 ) + ( $minutesAgo * 60 );
		$timestamp = $dbw->timestamp( time() - $secondsAgo );
		
		$dbw->update(
			'recentchanges',
			[ 'rc_timestamp' => $timestamp ],
			[
				'rc_namespace' => $title->getNamespace(),
				'rc_title' => $title->getDBkey()
			],
			__METHOD__,
			[ 'ORDER BY' => 'rc_id DESC', 'LIMIT' => 1 ]
		);
	}
	
	/**
	 * Rebuild recent changes table
	 */
	private function rebuildRecentChanges(): void {
		$dbw = $this->getDB( DB_PRIMARY );
		
		// Just ensure the table is properly indexed
		// The actual rebuild is handled by MediaWiki's maintenance script
		$this->output( "  Optimizing recentchanges table...\n" );
		
		try {
			$dbw->query( 'OPTIMIZE TABLE ' . $dbw->tableName( 'recentchanges' ), __METHOD__ );
		} catch ( Exception $e ) {
			// Ignore errors - not all DB engines support OPTIMIZE
		}
	}
}

$maintClass = GenerateRecentPageStatsTestData::class;
require_once RUN_MAINTENANCE_IF_MAIN;
