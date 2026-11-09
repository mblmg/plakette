<?php
/**
 * Test fixture (mu-plugin): a tiny stand-in for Breakdance's dynamic data API, so the chapter navigation and
 * the author bio fields can be tested without Breakdance. Only active when the option "plk_fake_breakdance"
 * is set (not by the shared seed – other e2e runs stay without Breakdance).
 *
 * Fields are collected in $GLOBALS['plk_bd_fields'] (slug => field object).
 */

namespace Breakdance\DynamicData;

if ( ! defined( 'ABSPATH' ) || ! get_option( 'plk_fake_breakdance' ) || defined( '__BREAKDANCE_VERSION' ) ) {
	return;
}

define( '__BREAKDANCE_VERSION', '2.0.0-fake' );

class StringData {
	public $value = '';

	public static function fromString( $s ) {
		$d        = new self();
		$d->value = (string) $s;
		return $d;
	}

	public static function emptyString() {
		return new self();
	}
}

abstract class StringField {
	abstract public function label();
	abstract public function category();
	abstract public function slug();
	public function returnTypes() {
		return array( 'string' );
	}
	abstract public function handler( $attributes ): StringData;
}

function registerField( $field ) {
	if ( isset( $GLOBALS['plk_bd_fields'][ $field->slug() ] ) ) {
		trigger_error( 'Breakdance field registered twice: ' . $field->slug(), E_USER_WARNING ); // phpcs:ignore
	}
	$GLOBALS['plk_bd_fields'][ $field->slug() ] = $field;
}
