<?php
/**
 * Canonical migration value encoder tests.
 *
 * @package Presenter
 */

use Presenter\Migration_Value_Encoder;

require_once dirname( __DIR__, 2 ) . '/includes/class-migration-value-encoder.php';

/**
 * Verify deterministic, type-explicit migration value encoding.
 */
final class Presenter_Migration_Value_Encoder_Test extends Presenter_Test_Case {
	/** Encoding is stable JSON and deterministic for the same PHP value. */
	public function test_encode_returns_stable_json(): void {
		$value   = array(
			'string' => '1',
			'number' => 1,
			'nested' => (object) array(
				'enabled' => true,
			),
		);
		$encoder = new Migration_Value_Encoder();

		$first  = $encoder->encode( $value );
		$second = $encoder->encode( $value );

		$this->assertSame( $first, $second );
		$this->assertIsArray( json_decode( $first, true, 512, JSON_THROW_ON_ERROR ) );
	}

	/** Scalar and compound PHP types have distinct encodings. */
	public function test_encode_distinguishes_php_types(): void {
		$encoder = new Migration_Value_Encoder();
		$values  = array(
			null,
			false,
			true,
			0,
			0.0,
			'0',
			array(),
			(object) array(),
		);
		$encoded = array_map( array( $encoder, 'encode' ), $values );

		$this->assertCount( count( $values ), array_unique( $encoded ) );
	}

	/** Array key types and insertion order remain part of the encoded value. */
	public function test_encode_preserves_key_types_and_order(): void {
		$encoder = new Migration_Value_Encoder();

		$this->assertNotSame(
			$encoder->encode( array( 1 => 'value' ) ),
			$encoder->encode( array( '01' => 'value' ) )
		);
		$this->assertNotSame(
			$encoder->encode(
				array(
					'first'  => 1,
					'second' => 2,
				)
			),
			$encoder->encode(
				array(
					'second' => 2,
					'first'  => 1,
				)
			)
		);
	}

	/** The JSON representation explicitly tags containers, keys, and objects. */
	public function test_encode_uses_explicit_type_key_and_object_tags(): void {
		$encoded = Migration_Value_Encoder::encode(
			array(
				'01' => (object) array( 'enabled' => true ),
			)
		);
		$node    = json_decode( $encoded, true, 512, JSON_THROW_ON_ERROR );

		$this->assertSame( 'array', $node['type'] );
		$this->assertSame( 'string', $node['entries'][0]['keyType'] );
		$this->assertSame( 'MDE=', $node['entries'][0]['key'] );
		$this->assertSame( 'object', $node['entries'][0]['value']['type'] );
		$this->assertSame( 'c3RkQ2xhc3M=', $node['entries'][0]['value']['class'] );
		$this->assertSame( 'ZW5hYmxlZA==', $node['entries'][0]['value']['properties'][0]['name'] );
		$this->assertSame( 'bool', $node['entries'][0]['value']['properties'][0]['value']['type'] );
	}

	/** Object class, property types, and property order are explicit inputs. */
	public function test_encode_distinguishes_objects_and_property_order(): void {
		$encoder       = new Migration_Value_Encoder();
		$first         = new stdClass();
		$first->one    = 1;
		$first->two    = '2';
		$second        = new stdClass();
		$second->two   = '2';
		$second->one   = 1;
		$array_version = array(
			'one' => 1,
			'two' => '2',
		);

		$this->assertNotSame( $encoder->encode( $first ), $encoder->encode( $second ) );
		$this->assertNotSame( $encoder->encode( $first ), $encoder->encode( $array_version ) );
	}
}
