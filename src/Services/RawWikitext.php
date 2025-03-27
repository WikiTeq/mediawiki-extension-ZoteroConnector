<?php

namespace MediaWiki\Extension\ZoteroConnector\Services;

/**
 * Class to represent wikitext that should NOT be escaped when used as a
 * parameter in a template, e.g. a nested template
 */
class RawWikitext {

	private string $wikitext;

	public function __construct( string $wikitext ) {
		$this->wikitext = $wikitext;
	}

	public function getWikitext(): string {
		return $this->wikitext;
	}

}
