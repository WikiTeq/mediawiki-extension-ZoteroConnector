<?php

namespace MediaWiki\Extension\ZoteroConnector\Services;

use InvalidArgumentException;

/**
 * Utility to simplify setting up templates with a fluent interface
 */
class FluentTemplate {

	private string $templateName;

	/** @var array<string,string> Map of parameter name to UNESCAPED value */
	private array $templateParams;

	public function __construct( string $templateName ) {
		$this->templateName = $templateName;
	}

	public static function escape( string $rawArg ): string {
		$escaped = strtr(
			$rawArg,
			[
				'{' => '&#123;',
				'}' => '&#125;',
				'[' => '&#91;',
				']' => '&#93;',
			]
		);
		$escaped = str_replace( '|', '{{!}}', $escaped );
		return $escaped;
	}

	public function hasParam( string $paramName ): bool {
		return isset( $this->templateParams[ $paramName ] );
	}

	public function setParam( string $paramName, string $value ): void {
		$this->templateParams[ $paramName ] = $value;
	}

	public function maybeAddParam( string $paramName, string $value ): bool {
		if ( isset( $this->templateParams[ $paramName ] ) ) {
			return false;
		}
		$this->templateParams[ $paramName ] = $value;
		return true;
	}

	public function getParam( string $paramName ): string {
		if ( !isset( $this->templateParams[ $paramName ] ) ) {
			throw new InvalidArgumentException(
				"Cannot get missing param $paramName"
			);
		}
		return $this->templateParams[ $paramName ];
	}

	public function getWikitext(): string {
		$template = '{{' . $this->templateName;
		foreach ( $this->templateParams as $p => $v ) {
			$template .= "\n|$p = " . self::escape( $v );
		}
		$template .= "\n}}";
		return $template;
	}

}
