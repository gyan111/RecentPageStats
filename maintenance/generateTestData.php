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
		$this->addOption( 'edit-existing', 'Number of existing pages to edit (default: 0)', false, true );
		$this->requireExtension( 'RecentPageStats' );
	}

	public function execute() {
		$numPages = (int)$this->getOption( 'pages', 50 );
		$maxEdits = (int)$this->getOption( 'edits', 10 );
		$daysSpread = (int)$this->getOption( 'days', 30 );
		$editExisting = (int)$this->getOption( 'edit-existing', 0 );
		
		$this->output( "Generating test data for RecentPageStats extension...\n" );
		$this->output( "Pages: $numPages, Max edits per page: $maxEdits, Days spread: $daysSpread" );
		if ( $editExisting > 0 ) {
			$this->output( ", Edit existing: $editExisting" );
		}
		$this->output( "\n\n" );
		
		$services = MediaWikiServices::getInstance();
		$wikiPageFactory = $services->getWikiPageFactory();
		$userFactory = $services->getUserFactory();
		
		$usernames = [ 'Arun', 'Priya', 'Rajesh', 'Sneha', 'Vikram', 'Anjali', 'Karthik', 'Deepa', 'Suresh', 'Meera' ];
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
		
		// Random article topics
		$topics = [
			'Indian Classical Music', 'Artificial Intelligence', 'Cricket World Cup',
			'Yoga and Meditation', 'Blockchain Technology', 'Bollywood Cinema',
			'Indian Cuisine', 'Machine Learning', 'Space Exploration',
			'Ancient Indian History', 'Renewable Energy', 'Digital Marketing',
			'Software Development', 'Ayurvedic Medicine', 'Indian Festivals',
			'Quantum Computing', 'Wildlife Conservation', 'Climate Change',
			'Indian Architecture', 'Cryptocurrency', 'Social Media Marketing',
			'Indian Literature', 'Cloud Computing', 'Organic Farming',
			'Data Science', 'Traditional Dance Forms', 'Mobile Apps',
			'Indian Mythology', 'Cybersecurity', 'Sustainable Development'
		];
		
		$createdPages = 0;
		$totalEdits = 0;
		
		for ( $i = 1; $i <= $numPages; $i++ ) {
			// Randomly select namespace
			$ns = array_rand( $namespaces );
			$nsName = $namespaces[$ns];
			
			// Create random article title
			$titleText = $topics[ array_rand( $topics ) ] . ' ' . rand( 2020, 2026 );
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
				
				// Generate varied content
				$sampleTexts = [
					"This article explores the rich cultural heritage and modern developments in this field. ",
					"Recent research has shown significant progress and innovation in this area. ",
					"Experts from around the world have contributed to advancing our understanding. ",
					"The historical context provides valuable insights into current practices. ",
					"Modern technology has transformed how we approach this subject. ",
					"Traditional knowledge combined with contemporary methods creates new opportunities. ",
					"This field continues to evolve with changing global trends and demands. ",
					"Practitioners emphasize the importance of sustainable and ethical approaches. "
				];
				
				for ( $p = 0; $p < $paragraphs; $p++ ) {
					$sentences = rand( 3, 6 );
					for ( $s = 0; $s < $sentences; $s++ ) {
						$content .= $sampleTexts[ array_rand( $sampleTexts ) ];
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
		
		// Edit existing pages if requested
		if ( $editExisting > 0 ) {
			$this->output( "\nEditing existing pages...\n" );
			$editedCount = $this->editExistingPages( $editExisting, $users, $maxEdits, $daysSpread );
			$this->output( "Edited $editedCount existing pages\n" );
			$totalEdits += $editedCount * 2; // Approximate
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
	 * Edit existing pages with new content from random users
	 *
	 * @param int $numPages
	 * @param array $users
	 * @param int $maxEdits
	 * @param int $daysSpread
	 * @return int Number of pages edited
	 */
	private function editExistingPages( int $numPages, array $users, int $maxEdits, int $daysSpread ): int {
		$services = MediaWikiServices::getInstance();
		$wikiPageFactory = $services->getWikiPageFactory();
		$dbr = $this->getDB( DB_REPLICA );
		
		// Get random existing pages
		$result = $dbr->select(
			'page',
			[ 'page_namespace', 'page_title' ],
			[],
			__METHOD__,
			[
				'ORDER BY' => 'RAND()',
				'LIMIT' => $numPages
			]
		);
		
		$editedCount = 0;
		$sampleTexts = [
			"This article has been updated with the latest information and research. ",
			"Recent developments have brought new insights to this topic. ",
			"Experts continue to refine our understanding of this subject. ",
			"New data and analysis have enhanced this article. ",
			"This content has been revised to reflect current knowledge. ",
			"Additional research has expanded our perspective on this topic. "
		];
		
		foreach ( $result as $row ) {
			$title = Title::makeTitle( $row->page_namespace, $row->page_title );
			if ( !$title || !$title->exists() ) {
				continue;
			}
			
			$page = $wikiPageFactory->newFromTitle( $title );
			$numEdits = rand( 1, min( $maxEdits, 5 ) );
			
			for ( $j = 0; $j < $numEdits; $j++ ) {
				$user = $users[ array_rand( $users ) ];
				
				// Get current content and append to it
				$content = $page->getContent();
				if ( !$content ) {
					continue;
				}
				
				$text = $content->getText();
				$text .= "\n\n== Update by {$user->getName()} ==\n\n";
				
				// Add a few sentences
				for ( $s = 0; $s < rand( 2, 4 ); $s++ ) {
					$text .= $sampleTexts[ array_rand( $sampleTexts ) ];
				}
				
				$wikiContent = new WikitextContent( $text );
				
				try {
					$updater = $page->newPageUpdater( $user );
					$updater->setContent( SlotRecord::MAIN, $wikiContent );
					
					// Randomly mark as minor edit
					if ( rand( 1, 100 ) <= 30 ) {
						$updater->setFlags( EDIT_MINOR );
					}
					
					$summary = CommentStoreComment::newUnsavedComment( 
						"Updated by {$user->getName()}"
					);
					
					$updater->saveRevision( $summary );
					
					// Update timestamp to spread over the days
					$this->updateRecentChangeTimestamp( $title, $daysSpread );
					
				} catch ( Exception $e ) {
					$this->output( "  Error editing {$title->getPrefixedText()}: {$e->getMessage()}\n" );
					continue 2;
				}
			}
			
			$editedCount++;
			if ( $editedCount % 5 == 0 ) {
				$this->output( "  Progress: $editedCount pages edited\n" );
			}
		}
		
		return $editedCount;
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
