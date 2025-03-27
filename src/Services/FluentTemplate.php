<?php

namespace MediaWiki\Extension\ZoteroConnector\Services;

use InvalidArgumentException;
use UnexpectedValueException;
use Wikimedia\Assert\Assert;

/**
 * Utility to simplify setting up templates with a fluent interface
 */
class FluentTemplate {

	private string $templateName;

	/**
	 * @var array<string,string|RawWikitext>
	 * Map of parameter name to UNESCAPED value, or a RawWikitext object if
	 * it shouldn't be escaped
	 */
	private array $templateParams;

	public function __construct( string $templateName ) {
		$this->templateName = $templateName;
		$this->templateParams = [];
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

	/**
	 * @param string $paramName
	 * @param string|RawWikitext $value
	 */
	public function setParam( string $paramName, $value ): void {
		Assert::parameterType(
			[ 'string', RawWikitext::class ],
			$value,
			'$value'
		);
		$this->templateParams[ $paramName ] = $value;
	}

	/**
	 * @param string $paramName
	 * @param string|RawWikitext $value
	 */
	public function maybeAddParam( string $paramName, $value ): bool {
		Assert::parameterType(
			[ 'string', RawWikitext::class ],
			$value,
			'$value'
		);
		if ( isset( $this->templateParams[ $paramName ] ) ) {
			return false;
		}
		$this->templateParams[ $paramName ] = $value;
		return true;
	}

	/**
	 * @param string $paramName
	 * @return string|RawWikitext
	 */
	public function getParam( string $paramName ) {
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
			if ( $v instanceof RawWikitext ) {
				$escaped = $v->getWikitext();
			} elseif ( is_string( $v ) ) {
				$escaped = self::escape( $v );
			} else {
				throw new UnexpectedValueException(
					"Parameter type should have been checked when it was added"
				);
			}
			$template .= "\n|$p = " . $escaped;
		}
		$template .= "\n}}";
		return $template;
	}

}
