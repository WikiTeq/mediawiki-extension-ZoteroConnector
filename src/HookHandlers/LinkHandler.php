<?php

namespace MediaWiki\Extension\ZoteroConnector\HookHandlers;

use HtmlArmor;
use MediaWiki\Linker\Hook\HtmlPageLinkRendererBeginHook;
use PageProps;
use TitleFactory;
use TitleFormatter;

class LinkHandler implements HtmlPageLinkRendererBeginHook {

	private PageProps $pageProps;
	private TitleFactory $titleFactory;
	private TitleFormatter $titleFormatter;

	public function __construct(
		PageProps $pageProps,
		TitleFactory $titleFactory,
		TitleFormatter $titleFormatter
	) {
		$this->pageProps = $pageProps;
		$this->titleFactory = $titleFactory;
		$this->titleFormatter = $titleFormatter;
	}

	/**
	 * This hook is called when a link begins formatting by the Linker. See
	 * documentation in core for details.
	 *
	 * @inheritDoc
	 */
	public function onHtmlPageLinkRendererBegin(
		$linkRenderer,
		$target,
		&$text,
		&$customAttribs,
		&$query,
		&$ret
	) {
		if ( $target->getNamespace() !== NS_ZOTERO_REF ) {
			return true;
		}
		// Check if there is already text that isn't just the default or a
		// normal link
		if ( $text !== null ) {
			$default = $this->titleFormatter->getPrefixedText( $target );
			// If the difference between the default and the current text is
			// just a matter of underscores, we still override
			$cleanCurrent = strtr( HtmlArmor::getHtml( $text ), '_', ' ' );
			if ( $cleanCurrent !== strtr( $default, '_', ' ' ) ) {
				// Already has been overridden, e.g. with a pipe, skip
				return true;
			}
		}
		$asTitle = $this->titleFactory->newFromLinkTarget( $target );
		$displayProps = $this->pageProps->getProperties( $asTitle, 'displaytitle' );
		if ( $displayProps === [] ) {
			// No display title set?
			return true;
		}
		$pageId = $asTitle->getId();
		if ( !isset( $displayProps[ $pageId ] ) ) {
			return true;
		}
		$text = $displayProps[ $pageId ];
		// Stop processing
		return false;
	}
}
