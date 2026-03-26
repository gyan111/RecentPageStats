<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\RecentPageStats\Special;

use Html;
use HTMLForm;
use MediaWiki\MediaWikiServices;
use SpecialPage;
use WANObjectCache;

/**
 * Special page showing overall recent changes statistics and dashboard
 *
 * Provides aggregate summary numbers, namespace breakdown, top editors,
 * and activity trends for recent changes.
 *
 * @ingroup SpecialPage
 */
class SpecialRecentChangesStats extends SpecialPage {

	private int $days;
	private bool $includeMinor;
	private bool $refresh;

	private WANObjectCache $cache;
	private int $cacheTTL;

	public function __construct() {
		parent::__construct( 'RecentChangesStats' );
	}

	/** @inheritDoc */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();
		$this->addHelpLink( 'Extension:RecentPageStats' );

		$out = $this->getOutput();
		$request = $this->getRequest();

		$out->addModuleStyles( 'ext.recentPageStats.styles' );

		$services = MediaWikiServices::getInstance();
		$this->cache = $services->getMainWANObjectCache();

		$config = $this->getConfig();
		$defaultDays = $config->get( 'RecentPageStatsDefaultDays' );
		$this->cacheTTL = $config->get( 'RecentPageStatsCacheDuration' );

		$this->days = $request->getInt( 'days', $defaultDays );
		$this->includeMinor = $request->getBool( 'includeminor', true );
		$this->refresh = $request->getBool( 'refresh', false );

		if ( $this->days < 1 || $this->days > 365 ) {
			$this->days = $defaultDays;
		}

		// Link to the companion per-page table
		$pageStatsTitle = SpecialPage::getTitleFor( 'RecentPageStats' );
		$out->addHTML(
			'<div class="recentpagestats-nav">' .
			$this->msg( 'recentchangesstats-see-pagestats' )
				->rawParams( $this->getLinkRenderer()->makeKnownLink(
					$pageStatsTitle,
					$this->msg( 'recentpagestats' )->text()
				) )->escaped() .
			'</div>'
		);

		$this->displayForm();

		$stats = $this->fetchDashboardData();
		if ( $stats === null ) {
			$out->addWikiMsg( 'recentchangesstats-no-data' );
			return;
		}

		$this->displaySummaryCards( $stats );
		$this->displayNamespaceBreakdown( $stats );
		$this->displayTopEditors( $stats );
		$this->displayDailyActivity( $stats );
	}

	private function displayForm(): void {
		$formDescriptor = [
			'days' => [
				'type' => 'int',
				'name' => 'days',
				'label-message' => 'recentpagestats-days-label',
				'default' => $this->days,
				'min' => 1,
				'max' => 365,
			],
			'includeminor' => [
				'type' => 'check',
				'name' => 'includeminor',
				'label-message' => 'recentpagestats-includeminor-label',
				'default' => $this->includeMinor,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'recentchangesstats-legend' )
			->setSubmitTextMsg( 'recentpagestats-submit' )
			->addButton( [
				'name' => 'refresh',
				'value' => '1',
				'label-message' => 'recentpagestats-refresh',
				'flags' => [ 'progressive' ],
			] )
			->prepareForm()
			->displayForm( false );
	}

	/**
	 * Fetch all dashboard data, using cache when available
	 */
	private function fetchDashboardData(): ?array {
		$cacheKey = $this->cache->makeKey(
			'recentchangesstats',
			'dashboard',
			(string)$this->days,
			$this->includeMinor ? 'withminor' : 'nominor'
		);

		if ( !$this->refresh ) {
			$cached = $this->cache->get( $cacheKey );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$cutoff = $dbr->timestamp( time() - ( $this->days * 86400 ) );

		$conds = [
			'rc_timestamp >= ' . $dbr->addQuotes( $cutoff ),
			'rc_type' => [ RC_EDIT, RC_NEW ],
		];

		if ( !$this->includeMinor ) {
			$conds['rc_minor'] = 0;
		}

		// 1. Summary numbers
		$row = $dbr->selectRow(
			'recentchanges',
			[
				'total_edits' => 'COUNT(*)',
				'total_pages' => 'COUNT(DISTINCT rc_cur_id)',
				'unique_editors' => 'COUNT(DISTINCT rc_user_text)',
				'new_pages' => 'SUM(CASE WHEN rc_type = ' . RC_NEW . ' THEN 1 ELSE 0 END)',
				'minor_edits' => 'SUM(CASE WHEN rc_minor = 1 THEN 1 ELSE 0 END)',
				'avg_edits_per_page' => 'COUNT(*) / NULLIF(COUNT(DISTINCT rc_cur_id), 0)',
			],
			$conds,
			__METHOD__
		);

		if ( !$row || (int)$row->total_edits === 0 ) {
			return null;
		}

		$summary = [
			'total_edits' => (int)$row->total_edits,
			'total_pages' => (int)$row->total_pages,
			'unique_editors' => (int)$row->unique_editors,
			'new_pages' => (int)$row->new_pages,
			'minor_edits' => (int)$row->minor_edits,
			'avg_edits_per_page' => round( (float)$row->avg_edits_per_page, 1 ),
		];

		// 2. Namespace breakdown
		$nsRows = $dbr->select(
			'recentchanges',
			[
				'rc_namespace',
				'ns_edits' => 'COUNT(*)',
				'ns_pages' => 'COUNT(DISTINCT rc_cur_id)',
				'ns_editors' => 'COUNT(DISTINCT rc_user_text)',
			],
			$conds,
			__METHOD__,
			[
				'GROUP BY' => 'rc_namespace',
				'ORDER BY' => 'ns_edits DESC',
				'LIMIT' => 15,
			]
		);

		$namespaces = [];
		foreach ( $nsRows as $nsRow ) {
			$namespaces[] = [
				'namespace' => (int)$nsRow->rc_namespace,
				'edits' => (int)$nsRow->ns_edits,
				'pages' => (int)$nsRow->ns_pages,
				'editors' => (int)$nsRow->ns_editors,
			];
		}

		// 3. Top editors
		$editorRows = $dbr->select(
			'recentchanges',
			[
				'rc_user_text',
				'editor_edits' => 'COUNT(*)',
				'editor_pages' => 'COUNT(DISTINCT rc_cur_id)',
			],
			$conds,
			__METHOD__,
			[
				'GROUP BY' => 'rc_user_text',
				'ORDER BY' => 'editor_edits DESC',
				'LIMIT' => 10,
			]
		);

		$topEditors = [];
		foreach ( $editorRows as $editorRow ) {
			$topEditors[] = [
				'name' => $editorRow->rc_user_text,
				'edits' => (int)$editorRow->editor_edits,
				'pages' => (int)$editorRow->editor_pages,
			];
		}

		// 4. Daily activity (last N days, grouped by day)
		$dailyRows = $dbr->select(
			'recentchanges',
			[
				'edit_day' => 'DATE(rc_timestamp)',
				'day_edits' => 'COUNT(*)',
				'day_pages' => 'COUNT(DISTINCT rc_cur_id)',
				'day_editors' => 'COUNT(DISTINCT rc_user_text)',
			],
			$conds,
			__METHOD__,
			[
				'GROUP BY' => 'edit_day',
				'ORDER BY' => 'edit_day DESC',
				'LIMIT' => min( $this->days, 30 ),
			]
		);

		$dailyActivity = [];
		foreach ( $dailyRows as $dayRow ) {
			$dailyActivity[] = [
				'date' => $dayRow->edit_day,
				'edits' => (int)$dayRow->day_edits,
				'pages' => (int)$dayRow->day_pages,
				'editors' => (int)$dayRow->day_editors,
			];
		}

		$data = [
			'summary' => $summary,
			'namespaces' => $namespaces,
			'top_editors' => $topEditors,
			'daily_activity' => $dailyActivity,
		];

		$this->cache->set( $cacheKey, $data, $this->cacheTTL );

		return $data;
	}

	/**
	 * Display the summary cards at the top
	 */
	private function displaySummaryCards( array $data ): void {
		$out = $this->getOutput();
		$lang = $this->getLanguage();
		$summary = $data['summary'];

		$cards = [
			[
				'value' => $lang->formatNum( $summary['total_edits'] ),
				'label' => $this->msg( 'recentchangesstats-total-edits' )->text(),
			],
			[
				'value' => $lang->formatNum( $summary['total_pages'] ),
				'label' => $this->msg( 'recentchangesstats-total-pages' )->text(),
			],
			[
				'value' => $lang->formatNum( $summary['unique_editors'] ),
				'label' => $this->msg( 'recentchangesstats-unique-editors' )->text(),
			],
			[
				'value' => $lang->formatNum( $summary['new_pages'] ),
				'label' => $this->msg( 'recentchangesstats-new-pages' )->text(),
			],
			[
				'value' => $lang->formatNum( $summary['minor_edits'] ),
				'label' => $this->msg( 'recentchangesstats-minor-edits' )->text(),
			],
			[
				'value' => $lang->formatNum( $summary['avg_edits_per_page'] ),
				'label' => $this->msg( 'recentchangesstats-avg-edits' )->text(),
			],
		];

		$html = Html::openElement( 'div', [ 'class' => 'rcstats-dashboard' ] );
		$html .= Html::element( 'h3', [],
			$this->msg( 'recentchangesstats-summary-title' )->text() );
		$html .= Html::openElement( 'div', [ 'class' => 'rcstats-cards-grid' ] );

		foreach ( $cards as $card ) {
			$html .= Html::openElement( 'div', [ 'class' => 'rcstats-card' ] );
			$html .= Html::element( 'div', [ 'class' => 'rcstats-card-value' ],
				$card['value'] );
			$html .= Html::element( 'div', [ 'class' => 'rcstats-card-label' ],
				$card['label'] );
			$html .= Html::closeElement( 'div' );
		}

		$html .= Html::closeElement( 'div' );
		$html .= Html::closeElement( 'div' );

		$out->addHTML( $html );
	}

	/**
	 * Display namespace breakdown table
	 */
	private function displayNamespaceBreakdown( array $data ): void {
		if ( empty( $data['namespaces'] ) ) {
			return;
		}

		$out = $this->getOutput();
		$lang = $this->getLanguage();
		$nsInfo = MediaWikiServices::getInstance()->getNamespaceInfo();

		$html = Html::openElement( 'div', [ 'class' => 'rcstats-section' ] );
		$html .= Html::element( 'h3', [],
			$this->msg( 'recentchangesstats-namespace-title' )->text() );

		$html .= Html::openElement( 'table', [
			'class' => 'wikitable sortable rcstats-table'
		] );

		$html .= Html::openElement( 'thead' );
		$html .= Html::openElement( 'tr' );
		$html .= Html::element( 'th', [],
			$this->msg( 'recentchangesstats-ns-name' )->text() );
		$html .= Html::element( 'th', [],
			$this->msg( 'recentchangesstats-ns-edits' )->text() );
		$html .= Html::element( 'th', [],
			$this->msg( 'recentchangesstats-ns-pages' )->text() );
		$html .= Html::element( 'th', [],
			$this->msg( 'recentchangesstats-ns-editors' )->text() );
		$html .= Html::closeElement( 'tr' );
		$html .= Html::closeElement( 'thead' );

		$html .= Html::openElement( 'tbody' );
		foreach ( $data['namespaces'] as $ns ) {
			$nsName = $ns['namespace'] === NS_MAIN
				? $this->msg( 'recentpagestats-namespace-main' )->text()
				: ( $nsInfo->getCanonicalName( $ns['namespace'] ) ?: '(Unknown)' );

			$html .= Html::openElement( 'tr' );
			$html .= Html::element( 'td', [], $nsName );
			$html .= Html::element( 'td', [ 'class' => 'rcstats-num' ],
				$lang->formatNum( $ns['edits'] ) );
			$html .= Html::element( 'td', [ 'class' => 'rcstats-num' ],
				$lang->formatNum( $ns['pages'] ) );
			$html .= Html::element( 'td', [ 'class' => 'rcstats-num' ],
				$lang->formatNum( $ns['editors'] ) );
			$html .= Html::closeElement( 'tr' );
		}
		$html .= Html::closeElement( 'tbody' );
		$html .= Html::closeElement( 'table' );
		$html .= Html::closeElement( 'div' );

		$out->addHTML( $html );
	}

	/**
	 * Display top editors table
	 */
	private function displayTopEditors( array $data ): void {
		if ( empty( $data['top_editors'] ) ) {
			return;
		}

		$out = $this->getOutput();
		$lang = $this->getLanguage();
		$linkRenderer = $this->getLinkRenderer();
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();

		$html = Html::openElement( 'div', [ 'class' => 'rcstats-section' ] );
		$html .= Html::element( 'h3', [],
			$this->msg( 'recentchangesstats-topeditors-title' )->text() );

		$html .= Html::openElement( 'table', [
			'class' => 'wikitable sortable rcstats-table'
		] );

		$html .= Html::openElement( 'thead' );
		$html .= Html::openElement( 'tr' );
		$html .= Html::element( 'th', [], '#' );
		$html .= Html::element( 'th', [],
			$this->msg( 'recentchangesstats-editor-name' )->text() );
		$html .= Html::element( 'th', [],
			$this->msg( 'recentchangesstats-editor-edits' )->text() );
		$html .= Html::element( 'th', [],
			$this->msg( 'recentchangesstats-editor-pages' )->text() );
		$html .= Html::closeElement( 'tr' );
		$html .= Html::closeElement( 'thead' );

		$html .= Html::openElement( 'tbody' );
		$rank = 1;
		foreach ( $data['top_editors'] as $editor ) {
			$userText = $editor['name'];
			$displayName = $userText;

			$user = $userFactory->newFromName( $userText );
			if ( $user && $user->isRegistered() ) {
				$realName = $user->getRealName();
				if ( $realName ) {
					$displayName = $realName;
				}
			}

			$contribsTitle = SpecialPage::getTitleFor( 'Contributions', $userText );
			$userLink = $linkRenderer->makeLink( $contribsTitle, $displayName );

			$html .= Html::openElement( 'tr' );
			$html .= Html::element( 'td', [ 'class' => 'rcstats-num' ],
				(string)$rank );
			$html .= Html::rawElement( 'td', [], $userLink );
			$html .= Html::element( 'td', [ 'class' => 'rcstats-num' ],
				$lang->formatNum( $editor['edits'] ) );
			$html .= Html::element( 'td', [ 'class' => 'rcstats-num' ],
				$lang->formatNum( $editor['pages'] ) );
			$html .= Html::closeElement( 'tr' );

			$rank++;
		}
		$html .= Html::closeElement( 'tbody' );
		$html .= Html::closeElement( 'table' );
		$html .= Html::closeElement( 'div' );

		$out->addHTML( $html );
	}

	/**
	 * Display daily activity table
	 */
	private function displayDailyActivity( array $data ): void {
		if ( empty( $data['daily_activity'] ) ) {
			return;
		}

		$out = $this->getOutput();
		$lang = $this->getLanguage();

		$html = Html::openElement( 'div', [ 'class' => 'rcstats-section' ] );
		$html .= Html::element( 'h3', [],
			$this->msg( 'recentchangesstats-daily-title' )->text() );

		$html .= Html::openElement( 'table', [
			'class' => 'wikitable sortable rcstats-table'
		] );

		$html .= Html::openElement( 'thead' );
		$html .= Html::openElement( 'tr' );
		$html .= Html::element( 'th', [],
			$this->msg( 'recentchangesstats-daily-date' )->text() );
		$html .= Html::element( 'th', [],
			$this->msg( 'recentchangesstats-daily-edits' )->text() );
		$html .= Html::element( 'th', [],
			$this->msg( 'recentchangesstats-daily-pages' )->text() );
		$html .= Html::element( 'th', [],
			$this->msg( 'recentchangesstats-daily-editors' )->text() );

		// Visual bar column
		$html .= Html::element( 'th', [],
			$this->msg( 'recentchangesstats-daily-activity' )->text() );
		$html .= Html::closeElement( 'tr' );
		$html .= Html::closeElement( 'thead' );

		// Find max edits for bar scaling
		$maxEdits = 1;
		foreach ( $data['daily_activity'] as $day ) {
			if ( $day['edits'] > $maxEdits ) {
				$maxEdits = $day['edits'];
			}
		}

		$html .= Html::openElement( 'tbody' );
		foreach ( $data['daily_activity'] as $day ) {
			$barWidth = round( ( $day['edits'] / $maxEdits ) * 100 );

			$html .= Html::openElement( 'tr' );
			$html .= Html::element( 'td', [], $day['date'] );
			$html .= Html::element( 'td', [ 'class' => 'rcstats-num' ],
				$lang->formatNum( $day['edits'] ) );
			$html .= Html::element( 'td', [ 'class' => 'rcstats-num' ],
				$lang->formatNum( $day['pages'] ) );
			$html .= Html::element( 'td', [ 'class' => 'rcstats-num' ],
				$lang->formatNum( $day['editors'] ) );

			// Activity bar
			$html .= Html::rawElement( 'td', [ 'class' => 'rcstats-bar-cell' ],
				Html::element( 'div', [
					'class' => 'rcstats-bar',
					'style' => "width: {$barWidth}%;",
				], '' )
			);
			$html .= Html::closeElement( 'tr' );
		}
		$html .= Html::closeElement( 'tbody' );
		$html .= Html::closeElement( 'table' );
		$html .= Html::closeElement( 'div' );

		$out->addHTML( $html );
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'changes';
	}
}
