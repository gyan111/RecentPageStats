<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\RecentPageStats\Pager;

use Html;
use IContextSource;
use IndexPager;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserFactory;
use MWTimestamp;
use SpecialPage;
use TablePager;
use WANObjectCache;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * Pager for displaying per-page recent edit statistics
 *
 * @ingroup Pager
 */
class RecentPageStatsPager extends TablePager {

	private int $days;
	private ?int $namespace;
	private string $sortBy;
	private bool $includeMinor;
	private bool $bypassCache;

	private LinkRenderer $linkRenderer;
	private LinkBatchFactory $linkBatchFactory;
	private UserFactory $userFactory;
	private WANObjectCache $cache;
	private int $cacheTTL;

	private ?array $statistics = null;

	public function __construct(
		IContextSource $context,
		int $days,
		?int $namespace,
		string $sortBy = 'time',
		bool $includeMinor = true,
		bool $bypassCache = false
	) {
		$this->days = $days;
		$this->namespace = $namespace;
		$this->sortBy = $sortBy;
		$this->includeMinor = $includeMinor;
		$this->bypassCache = $bypassCache;

		parent::__construct( $context );

		$services = MediaWikiServices::getInstance();
		$this->linkRenderer = $services->getLinkRenderer();
		$this->linkBatchFactory = $services->getLinkBatchFactory();
		$this->userFactory = $services->getUserFactory();
		$this->cache = $services->getMainWANObjectCache();

		$config = $context->getConfig();
		$this->mLimit = $config->get( 'RecentPageStatsPerPage' );
		$this->cacheTTL = $config->get( 'RecentPageStatsCacheDuration' );
	}

	/** @inheritDoc */
	public function getQueryInfo(): array {
		$dbr = $this->getDatabase();
		$cutoffTimestamp = $dbr->timestamp( time() - ( $this->days * 86400 ) );

		$conds = [
			'rc_timestamp >= ' . $dbr->addQuotes( $cutoffTimestamp ),
			'rc_type' => [ RC_EDIT, RC_NEW ],
		];

		if ( $this->namespace !== null ) {
			$conds['rc_namespace'] = $this->namespace;
		}

		if ( !$this->includeMinor ) {
			$conds['rc_minor'] = 0;
		}

		return [
			'tables' => [ 'recentchanges', 'page' ],
			'fields' => [
				'page_id',
				'page_namespace',
				'page_title',
				'page_len',
				'last_timestamp' => 'MAX(rc_timestamp)',
				'last_user_text' => 'SUBSTRING_INDEX(GROUP_CONCAT(rc_user_text ORDER BY rc_timestamp DESC SEPARATOR \'|\'), \'|\', 1)',
				'edit_count' => 'COUNT(*)',
			],
			'conds' => $conds,
			'options' => [
				'GROUP BY' => [ 'page_id', 'page_namespace', 'page_title', 'page_len' ],
			],
			'join_conds' => [
				'page' => [
					'INNER JOIN',
					[
						'rc_namespace = page_namespace',
						'rc_title = page_title',
					],
				],
			],
		];
	}

	/** @inheritDoc */
	public function getFieldNames(): array {
		return [
			'page_title' => $this->msg( 'recentpagestats-header-page' )->text(),
			'last_user_text' => $this->msg( 'recentpagestats-header-editor' )->text(),
			'last_timestamp' => $this->msg( 'recentpagestats-header-lasttime' )->text(),
			'edit_count' => $this->msg( 'recentpagestats-header-editcount' )->text(),
			'page_len' => $this->msg( 'recentpagestats-header-pagesize' )->text(),
		];
	}

	/** @inheritDoc */
	public function formatValue( $name, $value ): string {
		$row = $this->mCurrentRow;
		$language = $this->getLanguage();

		switch ( $name ) {
			case 'page_title':
				$title = Title::makeTitle( (int)$row->page_namespace, $row->page_title );
				return $this->linkRenderer->makeLink( $title );

			case 'last_user_text':
				if ( !$value ) {
					return $this->msg( 'recentpagestats-unknown-user' )->escaped();
				}

				$userText = $value;
				$displayName = $userText;

				$user = $this->userFactory->newFromName( $userText );
				if ( $user && $user->isRegistered() ) {
					$realName = $user->getRealName();
					if ( $realName ) {
						$displayName = $realName;
					}
				}

				$contribsTitle = SpecialPage::getTitleFor( 'Contributions', $userText );
				return $this->linkRenderer->makeLink( $contribsTitle, $displayName );

			case 'last_timestamp':
				$timestamp = wfTimestamp( TS_MW, $value );
				$formatted = $language->userTimeAndDate( $timestamp, $this->getUser() );
				$relative = $language->getHumanTimestamp(
					new MWTimestamp( $timestamp ),
					null,
					$this->getUser()
				);
				return Html::element( 'span', [ 'title' => $relative ], $formatted );

			case 'edit_count':
				return $language->formatNum( (int)$value );

			case 'page_len':
				$bytes = (int)$value;
				if ( $bytes < 1024 ) {
					return $language->formatNum( $bytes ) . ' ' .
						$this->msg( 'recentpagestats-bytes' )->text();
				}
				$kb = round( $bytes / 1024, 1 );
				return $language->formatNum( $kb ) . ' ' .
					$this->msg( 'recentpagestats-kb' )->text();

			default:
				return htmlspecialchars( (string)$value );
		}
	}

	/** @inheritDoc */
	public function getDefaultSort(): string {
		return $this->sortBy === 'count' ? 'edit_count' : 'last_timestamp';
	}

	/** @inheritDoc */
	public function isFieldSortable( $field ): bool {
		return in_array( $field, [ 'last_timestamp', 'edit_count' ] );
	}

	/** @inheritDoc */
	public function getIndexField() {
		return 'page_id';
	}

	/** @inheritDoc */
	public function getTableClass(): string {
		return parent::getTableClass() . ' recentpagestats-table';
	}

	/** @inheritDoc */
	public function getDefaultDirections() {
		return [
			'last_timestamp' => IndexPager::DIR_DESCENDING,
			'edit_count' => IndexPager::DIR_DESCENDING,
		];
	}

	/** @inheritDoc */
	protected function preprocessResults( $result ) {
		if ( !$result->numRows() ) {
			return;
		}

		$batch = $this->linkBatchFactory->newLinkBatch();
		foreach ( $result as $row ) {
			$batch->add( (int)$row->page_namespace, $row->page_title );
		}
		$batch->execute();
		$result->seek( 0 );
	}

	/**
	 * Get summary statistics for the dashboard
	 *
	 * @return array|null Array with statistics or null if no data
	 */
	public function getStatistics(): ?array {
		if ( $this->statistics !== null ) {
			return $this->statistics;
		}

		$cacheKey = $this->cache->makeKey(
			'recentpagestats',
			'statistics',
			(string)$this->days,
			(string)( $this->namespace ?? 'all' ),
			$this->includeMinor ? 'withminor' : 'nominor'
		);

		if ( !$this->bypassCache ) {
			$cached = $this->cache->get( $cacheKey );
			if ( $cached !== false ) {
				$this->statistics = $cached;
				return $this->statistics;
			}
		}

		$dbr = $this->getDatabase();
		$cutoffTimestamp = $dbr->timestamp( time() - ( $this->days * 86400 ) );

		$conds = [
			'rc_timestamp >= ' . $dbr->addQuotes( $cutoffTimestamp ),
			'rc_type' => [ RC_EDIT, RC_NEW ],
		];

		if ( $this->namespace !== null ) {
			$conds['rc_namespace'] = $this->namespace;
		}

		if ( !$this->includeMinor ) {
			$conds['rc_minor'] = 0;
		}

		$result = $dbr->select(
			[ 'recentchanges', 'page' ],
			[
				'total_pages' => 'COUNT(DISTINCT page_id)',
				'total_edits' => 'COUNT(*)',
				'unique_editors' => 'COUNT(DISTINCT rc_user_text)',
			],
			$conds,
			__METHOD__,
			[],
			[
				'page' => [
					'INNER JOIN',
					[ 'rc_namespace = page_namespace', 'rc_title = page_title' ],
				],
			]
		);

		$row = $result->fetchObject();
		if ( !$row || (int)$row->total_pages === 0 ) {
			$this->statistics = null;
			return null;
		}

		$stats = [
			'total_pages' => (int)$row->total_pages,
			'total_edits' => (int)$row->total_edits,
			'unique_editors' => (int)$row->unique_editors,
			'most_active_namespace' => null,
		];

		if ( $this->namespace === null ) {
			$nsResult = $dbr->select(
				[ 'recentchanges', 'page' ],
				[
					'page_namespace',
					'ns_count' => 'COUNT(DISTINCT page_id)',
				],
				$conds,
				__METHOD__,
				[
					'GROUP BY' => 'page_namespace',
					'ORDER BY' => 'ns_count DESC',
					'LIMIT' => 1,
				],
				[
					'page' => [
						'INNER JOIN',
						[ 'rc_namespace = page_namespace', 'rc_title = page_title' ],
					],
				]
			);

			$nsRow = $nsResult->fetchObject();
			if ( $nsRow ) {
				$stats['most_active_namespace'] = (int)$nsRow->page_namespace;
			}
		}

		$this->statistics = $stats;
		$this->cache->set( $cacheKey, $stats, $this->cacheTTL );

		return $this->statistics;
	}
}
