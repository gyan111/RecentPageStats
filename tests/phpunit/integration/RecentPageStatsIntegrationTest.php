<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\RecentPageStats\Tests\Integration;

use MediaWiki\Extension\RecentPageStats\Pager\RecentPageStatsPager;
use MediaWikiIntegrationTestCase;
use RequestContext;

/**
 * Integration tests for RecentPageStats extension
 *
 * @group RecentPageStats
 * @group Database
 * @covers \MediaWiki\Extension\RecentPageStats\Pager\RecentPageStatsPager
 * @covers \MediaWiki\Extension\RecentPageStats\Special\SpecialRecentPageStats
 * @covers \MediaWiki\Extension\RecentPageStats\Special\SpecialRecentChangesStats
 */
class RecentPageStatsIntegrationTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->tablesUsed[] = 'page';
		$this->tablesUsed[] = 'recentchanges';
	}

	/**
	 * Test that the pager can be instantiated with default parameters
	 */
	public function testPagerInstantiation() {
		$context = new RequestContext();
		$context->setUser( $this->getTestUser()->getUser() );

		$pager = new RecentPageStatsPager(
			$context,
			30,
			null,
			'time',
			true,
			false
		);

		$this->assertInstanceOf( RecentPageStatsPager::class, $pager );
	}

	/**
	 * Test that statistics return null when no data exists
	 */
	public function testStatisticsWithNoData() {
		$context = new RequestContext();
		$context->setUser( $this->getTestUser()->getUser() );

		$pager = new RecentPageStatsPager(
			$context, 30, null, 'time', true, false
		);

		$stats = $pager->getStatistics();
		$this->assertNull( $stats,
			'Statistics should be null when no recent changes exist' );
	}

	/**
	 * Test that cache is used on second call (same result)
	 */
	public function testCachingConsistency() {
		$context = new RequestContext();
		$context->setUser( $this->getTestUser()->getUser() );

		$pager1 = new RecentPageStatsPager(
			$context, 30, null, 'time', true, false
		);
		$stats1 = $pager1->getStatistics();

		$pager2 = new RecentPageStatsPager(
			$context, 30, null, 'time', true, false
		);
		$stats2 = $pager2->getStatistics();

		$this->assertEquals( $stats1, $stats2,
			'Cached statistics should match' );
	}

	/**
	 * Test cache bypass returns same data but queries DB
	 */
	public function testCacheBypass() {
		$context = new RequestContext();
		$context->setUser( $this->getTestUser()->getUser() );

		$pager1 = new RecentPageStatsPager(
			$context, 30, null, 'time', true, false
		);
		$stats1 = $pager1->getStatistics();

		$pager2 = new RecentPageStatsPager(
			$context, 30, null, 'time', true, true
		);
		$stats2 = $pager2->getStatistics();

		$this->assertEquals( $stats1, $stats2,
			'Bypassed cache should still return correct data' );
	}

	/**
	 * Test different sort orders instantiate correctly
	 */
	public function testSortOrders() {
		$context = new RequestContext();
		$context->setUser( $this->getTestUser()->getUser() );

		$pagerTime = new RecentPageStatsPager(
			$context, 30, null, 'time', true, false
		);
		$pagerCount = new RecentPageStatsPager(
			$context, 30, null, 'count', true, false
		);

		$this->assertInstanceOf( RecentPageStatsPager::class, $pagerTime );
		$this->assertInstanceOf( RecentPageStatsPager::class, $pagerCount );
	}

	/**
	 * Test namespace filtering
	 */
	public function testNamespaceFilter() {
		$context = new RequestContext();
		$context->setUser( $this->getTestUser()->getUser() );

		$pager = new RecentPageStatsPager(
			$context, 30, NS_MAIN, 'time', true, false
		);

		$this->assertInstanceOf( RecentPageStatsPager::class, $pager );
	}

	/**
	 * Test minor edits filter options
	 */
	public function testMinorEditsFilter() {
		$context = new RequestContext();
		$context->setUser( $this->getTestUser()->getUser() );

		$withMinor = new RecentPageStatsPager(
			$context, 30, null, 'time', true, false
		);
		$noMinor = new RecentPageStatsPager(
			$context, 30, null, 'time', false, false
		);

		$this->assertInstanceOf( RecentPageStatsPager::class, $withMinor );
		$this->assertInstanceOf( RecentPageStatsPager::class, $noMinor );
	}

	/**
	 * Test that SpecialRecentPageStats can be loaded
	 */
	public function testSpecialPageLoads() {
		$page = \MediaWiki\MediaWikiServices::getInstance()
			->getSpecialPageFactory()
			->getPage( 'RecentPageStats' );

		$this->assertNotNull( $page, 'Special:RecentPageStats should exist' );
	}

	/**
	 * Test that SpecialRecentChangesStats can be loaded
	 */
	public function testDashboardPageLoads() {
		$page = \MediaWiki\MediaWikiServices::getInstance()
			->getSpecialPageFactory()
			->getPage( 'RecentChangesStats' );

		$this->assertNotNull( $page, 'Special:RecentChangesStats should exist' );
	}
}
