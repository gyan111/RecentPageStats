<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\RecentPageStats\Special;

use HTMLForm;
use MediaWiki\Extension\RecentPageStats\Pager\RecentPageStatsPager;
use MediaWiki\MediaWikiServices;
use SpecialPage;

/**
 * Special page showing per-page recent edit statistics
 *
 * Displays a filterable, sortable table of pages that have been edited
 * recently, along with edit counts, last editor, page size, etc.
 *
 * @ingroup SpecialPage
 */
class SpecialRecentPageStats extends SpecialPage {

	private int $days;
	private ?int $namespace;
	private string $sortBy;
	private bool $includeMinor;
	private bool $refresh;

	public function __construct() {
		parent::__construct( 'RecentPageStats' );
	}

	/** @inheritDoc */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();
		$this->addHelpLink( 'Extension:RecentPageStats' );

		$out = $this->getOutput();
		$request = $this->getRequest();

		$out->addModuleStyles( 'ext.recentPageStats.styles' );

		$config = $this->getConfig();
		$defaultDays = $config->get( 'RecentPageStatsDefaultDays' );

		$this->days = $request->getInt( 'days', $defaultDays );
		$this->namespace = $request->getVal( 'namespace', '' ) === ''
			? null : $request->getInt( 'namespace' );
		$this->sortBy = $request->getVal( 'sort', 'time' );
		$this->includeMinor = $request->getBool( 'includeminor', true );
		$this->refresh = $request->getBool( 'refresh', false );

		if ( $this->days < 1 || $this->days > 365 ) {
			$this->days = $defaultDays;
		}

		// Link to the companion dashboard page
		$dashboardTitle = SpecialPage::getTitleFor( 'RecentChangesStats' );
		$out->addHTML(
			'<div class="recentpagestats-nav">' .
			$this->msg( 'recentpagestats-see-dashboard' )
				->rawParams( $this->getLinkRenderer()->makeKnownLink(
					$dashboardTitle,
					$this->msg( 'recentchangesstats' )->text()
				) )->escaped() .
			'</div>'
		);

		$this->displayForm();
		$this->displayResults();
	}

	private function displayForm(): void {
		$namespaceOptions = [
			$this->msg( 'recentpagestats-namespace-all' )->text() => ''
		];
		$namespaces = MediaWikiServices::getInstance()
			->getNamespaceInfo()->getCanonicalNamespaces();

		foreach ( $namespaces as $nsId => $nsName ) {
			if ( $nsId < 0 ) {
				continue;
			}
			$displayName = $nsId === NS_MAIN
				? $this->msg( 'recentpagestats-namespace-main' )->text()
				: $nsName;
			$namespaceOptions[$displayName] = (string)$nsId;
		}

		$sortOptions = [
			$this->msg( 'recentpagestats-sort-time' )->text() => 'time',
			$this->msg( 'recentpagestats-sort-count' )->text() => 'count',
		];

		$formDescriptor = [
			'days' => [
				'type' => 'int',
				'name' => 'days',
				'label-message' => 'recentpagestats-days-label',
				'default' => $this->days,
				'min' => 1,
				'max' => 365,
			],
			'namespace' => [
				'type' => 'select',
				'name' => 'namespace',
				'label-message' => 'recentpagestats-namespace-label',
				'options' => $namespaceOptions,
				'default' => $this->namespace !== null
					? (string)$this->namespace : '',
			],
			'sort' => [
				'type' => 'select',
				'name' => 'sort',
				'label-message' => 'recentpagestats-sort-label',
				'options' => $sortOptions,
				'default' => $this->sortBy,
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
			->setWrapperLegendMsg( 'recentpagestats-legend' )
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

	private function displayResults(): void {
		$out = $this->getOutput();

		$pager = new RecentPageStatsPager(
			$this->getContext(),
			$this->days,
			$this->namespace,
			$this->sortBy,
			$this->includeMinor,
			$this->refresh
		);

		if ( $pager->getNumRows() > 0 ) {
			$out->addHTML(
				$pager->getNavigationBar() .
				$pager->getBody() .
				$pager->getNavigationBar()
			);
		} else {
			$out->addWikiMsg( 'recentpagestats-no-results' );
		}
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'changes';
	}
}
