<?php
/**
 * Gutenberg-specific helpers for Yjs updateV2 payloads.
 *
 * The low-level lib0/Yjs primitives are provided by yjs/yjs-php, loaded
 * through Composer.
 *
 * @package Gutenberg_Yjs_Update_V2
 */

use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoding;
use Yjs\Lib0\IntDiffOptRleDecoder;
use Yjs\Lib0\IntDiffOptRleEncoder;
use Yjs\Lib0\RleDecoder;
use Yjs\Lib0\RleEncoder;
use Yjs\Lib0\UintOptRleDecoder;
use Yjs\Lib0\UintOptRleEncoder;
use Yjs\Structs\AbstractContent;
use Yjs\Structs\ContentString;
use Yjs\Structs\ContentType;

/**
 * lib0-compatible string stream wrapper.
 *
 * yjs-php currently length-prefixes the string-length RLE payload. Yjs JS
 * expects those bytes directly after the concatenated string, so Shouter keeps
 * this tiny adapter until yjs-php exposes a compatible StringEncoder.
 */
class Gutenberg_Yjs_String_Encoder {
	private UintOptRleEncoder $length_encoder;
	private string $buffer = '';

	public function __construct() {
		$this->length_encoder = new UintOptRleEncoder();
	}

	public function write( string $value ): void {
		$this->buffer .= $value;
		$this->length_encoder->write( gutenberg_yjs_utf16_clock_len( $value ) );
	}

	public function to_string(): string {
		$encoder = new Encoding();
		$encoder->writeVarString( $this->buffer );
		$encoder->writeUint8Array( $this->length_encoder->toUint8Array() );
		return $encoder->toUint8Array();
	}
}

/**
 * lib0-compatible string stream reader.
 */
class Gutenberg_Yjs_String_Decoder {
	private UintOptRleDecoder $length_decoder;
	private string $buffer;
	private int $offset = 0;

	public function __construct( Decoding $decoder ) {
		$this->buffer         = $decoder->readVarString();
		$this->length_decoder = new UintOptRleDecoder( $decoder );
	}

	public function read(): string {
		$length = $this->length_decoder->read();

		if ( function_exists( 'mb_substr' ) && strlen( $this->buffer ) !== mb_strlen( $this->buffer, 'UTF-8' ) ) {
			$value = mb_substr( $this->buffer, $this->offset, $length, 'UTF-8' );
			$this->offset += $length;
			return false === $value ? '' : $value;
		}

		$value = substr( $this->buffer, $this->offset, $length );
		$this->offset += $length;
		return false === $value ? '' : $value;
	}
}

/**
 * Plain-array decoder for the updateV2 subset Shouter inspects.
 */
class Gutenberg_Yjs_Update_V2_Array_Decoder {
	private Decoding $rest_decoder;
	private IntDiffOptRleDecoder $key_clock_decoder;
	private UintOptRleDecoder $client_decoder;
	private IntDiffOptRleDecoder $left_clock_decoder;
	private IntDiffOptRleDecoder $right_clock_decoder;
	private RleDecoder $info_decoder;
	private Gutenberg_Yjs_String_Decoder $string_decoder;
	private RleDecoder $parent_info_decoder;
	private UintOptRleDecoder $type_ref_decoder;
	private UintOptRleDecoder $len_decoder;

	/**
	 * @var array<int, string>
	 */
	private array $keys = array();

	public function __construct( string $update ) {
		$decoder = new Decoding( $update );
		$decoder->readVarUint(); // feature flag.

		$this->key_clock_decoder   = new IntDiffOptRleDecoder( new Decoding( $decoder->readVarUint8Array() ) );
		$this->client_decoder      = new UintOptRleDecoder( new Decoding( $decoder->readVarUint8Array() ) );
		$this->left_clock_decoder  = new IntDiffOptRleDecoder( new Decoding( $decoder->readVarUint8Array() ) );
		$this->right_clock_decoder = new IntDiffOptRleDecoder( new Decoding( $decoder->readVarUint8Array() ) );
		$this->info_decoder        = new RleDecoder( new Decoding( $decoder->readVarUint8Array() ), static fn( Decoding $d ): int => $d->readUint8() );
		$this->string_decoder      = new Gutenberg_Yjs_String_Decoder( new Decoding( $decoder->readVarUint8Array() ) );
		$this->parent_info_decoder = new RleDecoder( new Decoding( $decoder->readVarUint8Array() ), static fn( Decoding $d ): int => $d->readVarUint() );
		$this->type_ref_decoder    = new UintOptRleDecoder( new Decoding( $decoder->readVarUint8Array() ) );
		$this->len_decoder         = new UintOptRleDecoder( new Decoding( $decoder->readVarUint8Array() ) );
		$this->rest_decoder        = new Decoding( $decoder->getRemainingData() );
	}

	public function read_num_clients(): int {
		return $this->rest_decoder->readVarUint();
	}

	public function read_num_structs(): int {
		return $this->rest_decoder->readVarUint();
	}

	public function read_client(): int {
		return $this->client_decoder->read();
	}

	public function read_info(): int {
		return $this->info_decoder->read();
	}

	public function read_string(): string {
		return $this->string_decoder->read();
	}

	public function read_parent_info(): int {
		return $this->parent_info_decoder->read();
	}

	public function read_type_ref(): int {
		return $this->type_ref_decoder->read();
	}

	public function read_len(): int {
		return $this->len_decoder->read();
	}

	public function read_left_clock(): int {
		return $this->left_clock_decoder->read();
	}

	public function read_right_clock(): int {
		return $this->right_clock_decoder->read();
	}

	public function read_key(): string {
		$key_clock = $this->key_clock_decoder->read();
		if ( ! array_key_exists( $key_clock, $this->keys ) ) {
			$this->keys[ $key_clock ] = $this->read_string();
		}

		return $this->keys[ $key_clock ];
	}

	public function read_rest_var_uint(): int {
		return $this->rest_decoder->readVarUint();
	}

	/**
	 * @return mixed
	 */
	public function read_any() {
		return $this->rest_decoder->readAny();
	}

	public function rest_has_content(): bool {
		return $this->rest_decoder->hasContent();
	}
}

/**
 * Direct updateV2 writer for Gutenberg's paragraph update subset.
 */
class Gutenberg_Yjs_Update_V2_Encoder {
	private Encoding $rest_encoder;
	private IntDiffOptRleEncoder $key_clock_encoder;
	private UintOptRleEncoder $client_encoder;
	private IntDiffOptRleEncoder $left_clock_encoder;
	private IntDiffOptRleEncoder $right_clock_encoder;
	private RleEncoder $info_encoder;
	private Gutenberg_Yjs_String_Encoder $string_encoder;
	private RleEncoder $parent_info_encoder;
	private UintOptRleEncoder $type_ref_encoder;
	private UintOptRleEncoder $len_encoder;

	/**
	 * @var array<int, string>
	 */
	private array $keys = array();

	public function __construct() {
		$this->rest_encoder        = new Encoding();
		$this->key_clock_encoder   = new IntDiffOptRleEncoder();
		$this->client_encoder      = new UintOptRleEncoder();
		$this->left_clock_encoder  = new IntDiffOptRleEncoder();
		$this->right_clock_encoder = new IntDiffOptRleEncoder();
		$this->info_encoder        = new RleEncoder( static fn( Encoding $e, int $v ): Encoding => $e->writeUint8( $v ) );
		$this->string_encoder      = new Gutenberg_Yjs_String_Encoder();
		$this->parent_info_encoder = new RleEncoder( static fn( Encoding $e, int $v ): Encoding => $e->writeVarUint( $v ) );
		$this->type_ref_encoder    = new UintOptRleEncoder();
		$this->len_encoder         = new UintOptRleEncoder();
	}

	public function write_rest_var_uint( int $value ): void {
		$this->rest_encoder->writeVarUint( $value );
	}

	public function write_info( int $value ): void {
		$this->info_encoder->write( $value );
	}

	public function write_client( int $client_id ): void {
		$this->client_encoder->write( $client_id );
	}

	public function write_left_id( int $client_id, int $clock ): void {
		$this->client_encoder->write( $client_id );
		$this->left_clock_encoder->write( $clock );
	}

	public function write_right_id( int $client_id, int $clock ): void {
		$this->client_encoder->write( $client_id );
		$this->right_clock_encoder->write( $clock );
	}

	public function write_string( string $value ): void {
		$this->string_encoder->write( $value );
	}

	public function write_key( string $value ): void {
		$index = array_search( $value, $this->keys, true );
		if ( false !== $index ) {
			$this->key_clock_encoder->write( $index );
			return;
		}

		$this->key_clock_encoder->write( count( $this->keys ) );
		$this->keys[] = $value;
		$this->write_string( $value );
	}

	public function write_parent_info( bool $is_y_key ): void {
		$this->parent_info_encoder->write( $is_y_key ? 1 : 0 );
	}

	public function write_type_ref( int $value ): void {
		$this->type_ref_encoder->write( $value );
	}

	public function write_len( int $value ): void {
		$this->len_encoder->write( $value );
	}

	/**
	 * @param mixed $value Value.
	 */
	public function write_any( $value ): void {
		$this->rest_encoder->writeAny( $value );
	}

	public function to_string(): string {
		$encoder = new Encoding();
		$encoder->writeVarUint( 0 );
		$encoder->writeVarUint8Array( $this->key_clock_encoder->toUint8Array() );
		$encoder->writeVarUint8Array( $this->client_encoder->toUint8Array() );
		$encoder->writeVarUint8Array( $this->left_clock_encoder->toUint8Array() );
		$encoder->writeVarUint8Array( $this->right_clock_encoder->toUint8Array() );
		$encoder->writeVarUint8Array( $this->info_encoder->toUint8Array() );
		$encoder->writeVarUint8Array( $this->string_encoder->to_string() );
		$encoder->writeVarUint8Array( $this->parent_info_encoder->toUint8Array() );
		$encoder->writeVarUint8Array( $this->type_ref_encoder->toUint8Array() );
		$encoder->writeVarUint8Array( $this->len_encoder->toUint8Array() );
		$encoder->writeUint8Array( $this->rest_encoder->toUint8Array() );
		return $encoder->toUint8Array();
	}
}

/**
 * Decodes Yjs updateV2 structs into plain arrays.
 *
 * @return array<string, mixed>
 */
function gutenberg_yjs_decode_update_v2( string $update ): array {
	if ( ! class_exists( Decoding::class ) ) {
		throw new RuntimeException( 'yjs/yjs-php is not loaded. Run composer install for Shouter.' );
	}

	$decoder       = new Gutenberg_Yjs_Update_V2_Array_Decoder( $update );
	$state_count   = $decoder->read_num_clients();
	$client_blocks = array();
	$structs       = array();

	for ( $state_index = 0; $state_index < $state_count; $state_index++ ) {
		$struct_count = $decoder->read_num_structs();
		$client       = $decoder->read_client();
		$clock        = $decoder->read_rest_var_uint();
		$client_block = array(
			'client'       => $client,
			'start_clock'  => $clock,
			'struct_count' => $struct_count,
			'structs'      => array(),
		);

		for ( $i = 0; $i < $struct_count; $i++ ) {
			$info        = $decoder->read_info();
			$content_ref = $info & 0x1f;

			if ( 0 === $content_ref ) {
				$length = $decoder->read_len();
				$struct = array(
					'kind'   => 'gc',
					'id'     => array(
						'client' => $client,
						'clock'  => $clock,
					),
					'length' => $length,
				);
				$clock += $length;
			} elseif ( 10 === $content_ref ) {
				$length = $decoder->read_rest_var_uint();
				$struct = array(
					'kind'   => 'skip',
					'id'     => array(
						'client' => $client,
						'clock'  => $clock,
					),
					'length' => $length,
				);
				$clock += $length;
			} else {
				$origin       = ( $info & 0x80 ) ? gutenberg_yjs_read_id( $decoder, true ) : null;
				$right_origin = ( $info & 0x40 ) ? gutenberg_yjs_read_id( $decoder, false ) : null;
				$parent       = null;
				$parent_sub   = null;

				if ( null === $origin && null === $right_origin ) {
					$parent_info = $decoder->read_parent_info();
					if ( $parent_info & 1 ) {
						$parent = array(
							'type' => 'root',
							'key'  => $decoder->read_string(),
						);
					} else {
						$parent = array_merge( array( 'type' => 'id' ), gutenberg_yjs_read_id( $decoder, true ) );
					}

					if ( $info & 0x20 ) {
						$parent_sub = $decoder->read_key();
					}
				}

				$content = gutenberg_yjs_decode_item_content( $decoder, $content_ref );
				$length  = $content['length'];
				$struct  = array(
					'kind'         => 'item',
					'id'           => array(
						'client' => $client,
						'clock'  => $clock,
					),
					'info'         => $info,
					'content_ref'  => $content_ref,
					'origin'       => $origin,
					'right_origin' => $right_origin,
					'parent'       => $parent,
					'parent_sub'   => $parent_sub,
					'content'      => $content,
					'length'       => $length,
				);
				$clock += $length;
			}

			$client_block['structs'][] = $struct;
			$structs[]                 = $struct;
		}

		$client_blocks[] = $client_block;
	}

	$delete_set_count = $decoder->rest_has_content()
		? $decoder->read_rest_var_uint()
		: 0;

	return array(
		'client_blocks'    => $client_blocks,
		'structs'          => $structs,
		'delete_set_count' => $delete_set_count,
	);
}

/**
 * Reads a left or right Yjs ID from a V2 decoder.
 *
 * @return array{client:int,clock:int}
 */
function gutenberg_yjs_read_id( Gutenberg_Yjs_Update_V2_Array_Decoder $decoder, bool $left ): array {
	return array(
		'client' => $decoder->read_client(),
		'clock'  => $left ? $decoder->read_left_clock() : $decoder->read_right_clock(),
	);
}

/**
 * Decodes Yjs Item content.
 *
 * @return array<string, mixed>
 */
function gutenberg_yjs_decode_item_content( Gutenberg_Yjs_Update_V2_Array_Decoder $decoder, int $content_ref ): array {
	switch ( $content_ref ) {
		case AbstractContent::TYPE_STRING:
			$text = $decoder->read_string();
			return array(
				'type'   => 'string',
				'value'  => $text,
				'length' => gutenberg_yjs_utf16_clock_len( $text ),
			);

		case AbstractContent::TYPE_TYPE:
			$type_ref = $decoder->read_type_ref();
			$types    = array(
				ContentType::YARRAY => 'Y.Array',
				ContentType::YMAP   => 'Y.Map',
				ContentType::YTEXT  => 'Y.Text',
			);
			return array(
				'type'     => 'type',
				'type_ref' => $type_ref,
				'name'     => $types[ $type_ref ] ?? 'unknown',
				'length'   => 1,
			);

		case AbstractContent::TYPE_ANY:
			$length = $decoder->read_len();
			$values = array();
			for ( $i = 0; $i < $length; $i++ ) {
				$values[] = $decoder->read_any();
			}
			return array(
				'type'   => 'any',
				'values' => $values,
				'length' => $length,
			);

		default:
			throw new RuntimeException( 'Unsupported Yjs content ref: ' . $content_ref );
	}
}

/**
 * Encodes a fresh document containing one paragraph block.
 */
function gutenberg_yjs_encode_single_paragraph_document_update_v2( int $client_id, string $paragraph_text, string $block_client_id ): string {
	$encoder = new Gutenberg_Yjs_Update_V2_Encoder();

	$encoder->write_rest_var_uint( 1 ); // one client state.
	$encoder->write_rest_var_uint( 8 ); // eight structs.
	$encoder->write_client( $client_id );
	$encoder->write_rest_var_uint( 0 ); // start clock.

	gutenberg_yjs_write_item( $encoder, 7, null, null, array( 'type' => 'root', 'key' => 'document' ), 'blocks', function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ): void {
		$encoder->write_type_ref( 0 ); // Y.Array.
	} );

	gutenberg_yjs_write_item( $encoder, 7, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => 0 ), null, function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ): void {
		$encoder->write_type_ref( 1 ); // Y.Map.
	} );

	gutenberg_yjs_write_item( $encoder, 8, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => 1 ), 'name', function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ): void {
		$encoder->write_len( 1 );
		$encoder->write_any( 'core/paragraph' );
	} );

	gutenberg_yjs_write_item( $encoder, 7, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => 1 ), 'attributes', function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ): void {
		$encoder->write_type_ref( 1 ); // Y.Map.
	} );

	gutenberg_yjs_write_item( $encoder, 7, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => 3 ), 'content', function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ): void {
		$encoder->write_type_ref( 2 ); // Y.Text.
	} );

	gutenberg_yjs_write_item( $encoder, 4, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => 4 ), null, function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ) use ( $paragraph_text ): void {
		$encoder->write_string( $paragraph_text );
	} );

	gutenberg_yjs_write_item( $encoder, 7, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => 1 ), 'innerBlocks', function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ): void {
		$encoder->write_type_ref( 0 ); // Y.Array.
	} );

	gutenberg_yjs_write_item( $encoder, 8, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => 1 ), 'clientId', function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ) use ( $block_client_id ): void {
		$encoder->write_len( 1 );
		$encoder->write_any( $block_client_id );
	} );

	$encoder->write_rest_var_uint( 0 ); // delete set.

	return $encoder->to_string();
}

/**
 * Encodes a Yjs updateV2 that inserts one paragraph block after an existing
 * block item in an existing Gutenberg `document.blocks` Y.Array.
 *
 * @return string Binary updateV2 bytes.
 */
function gutenberg_yjs_encode_paragraph_insert_after_update_v2( int $client_id, string $paragraph_text, string $block_client_id, int $left_origin_client, int $left_origin_clock, int $start_clock = 0, ?array $right_origin = null, ?array $content_insert = null ): string {
	$encoder = new Gutenberg_Yjs_Update_V2_Encoder();
	$has_text = '' !== $paragraph_text;
	$has_content_insert = is_array( $content_insert ) && ! empty( $content_insert['text'] ) && ! empty( $content_insert['parent'] );

	$encoder->write_rest_var_uint( 1 ); // one client state.
	$encoder->write_rest_var_uint( ( $has_text ? 9 : 8 ) + ( $has_content_insert ? 1 : 0 ) );
	$encoder->write_client( $client_id );
	$encoder->write_rest_var_uint( $start_clock );

	gutenberg_yjs_write_item(
		$encoder,
		7,
		array(
			'client' => $left_origin_client,
			'clock'  => $left_origin_clock,
		),
		$right_origin,
		null,
		null,
		function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ): void {
			$encoder->write_type_ref( 1 ); // Y.Map block.
		}
	);

	gutenberg_yjs_write_paragraph_block_fields( $encoder, $client_id, $paragraph_text, $block_client_id, $start_clock );

	if ( $has_content_insert ) {
		$parent = $content_insert['parent'];
		gutenberg_yjs_write_item(
			$encoder,
			4,
			isset( $content_insert['origin'] ) && is_array( $content_insert['origin'] ) ? $content_insert['origin'] : null,
			isset( $content_insert['right_origin'] ) && is_array( $content_insert['right_origin'] ) ? $content_insert['right_origin'] : null,
			array(
				'type'   => 'id',
				'client' => (int) $parent['client'],
				'clock'  => (int) $parent['clock'],
			),
			null,
			function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ) use ( $content_insert ): void {
				$encoder->write_string( (string) $content_insert['text'] );
			}
		);
	}

	$encoder->write_rest_var_uint( 0 ); // delete set.

	return $encoder->to_string();
}

/**
 * Writes the nested fields for a newly inserted paragraph block map.
 */
function gutenberg_yjs_write_paragraph_block_fields( Gutenberg_Yjs_Update_V2_Encoder $encoder, int $client_id, string $paragraph_text, string $block_client_id, int $block_clock ): void {
	$name_clock         = $block_clock + 1;
	$is_valid_clock     = $name_clock + 1;
	$attributes_clock   = $is_valid_clock + 1;
	$content_type_clock = $attributes_clock + 1;

	unset( $name_clock, $is_valid_clock );

	gutenberg_yjs_write_item( $encoder, 8, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => $block_clock ), 'name', function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ): void {
		$encoder->write_len( 1 );
		$encoder->write_any( 'core/paragraph' );
	} );

	gutenberg_yjs_write_item( $encoder, 8, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => $block_clock ), 'isValid', function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ): void {
		$encoder->write_len( 1 );
		$encoder->write_any( true );
	} );

	gutenberg_yjs_write_item( $encoder, 7, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => $block_clock ), 'attributes', function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ): void {
		$encoder->write_type_ref( 1 ); // Y.Map.
	} );

	gutenberg_yjs_write_item( $encoder, 7, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => $attributes_clock ), 'content', function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ): void {
		$encoder->write_type_ref( 2 ); // Y.Text.
	} );

	if ( '' !== $paragraph_text ) {
		gutenberg_yjs_write_item( $encoder, 4, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => $content_type_clock ), null, function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ) use ( $paragraph_text ): void {
			$encoder->write_string( $paragraph_text );
		} );
	}

	gutenberg_yjs_write_item( $encoder, 8, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => $attributes_clock ), 'dropCap', function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ): void {
		$encoder->write_len( 1 );
		$encoder->write_any( false );
	} );

	gutenberg_yjs_write_item( $encoder, 7, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => $block_clock ), 'innerBlocks', function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ): void {
		$encoder->write_type_ref( 0 ); // Y.Array.
	} );

	gutenberg_yjs_write_item( $encoder, 8, null, null, array( 'type' => 'id', 'client' => $client_id, 'clock' => $block_clock ), 'clientId', function ( Gutenberg_Yjs_Update_V2_Encoder $encoder ) use ( $block_client_id ): void {
		$encoder->write_len( 1 );
		$encoder->write_any( $block_client_id );
	} );
}

/**
 * Gets the Yjs clock length for a JS string, measured in UTF-16 code units.
 */
function gutenberg_yjs_utf16_clock_len( string $value ): int {
	return ( new ContentString( $value ) )->getLength();
}

/**
 * Gets the number of Yjs client-clock ticks consumed by the paragraph block
 * portion of an inserted paragraph update.
 */
function gutenberg_yjs_paragraph_block_clock_len( string $paragraph_text ): int {
	return 8 + gutenberg_yjs_utf16_clock_len( $paragraph_text );
}

/**
 * Gets the number of Yjs client-clock ticks consumed by one inserted paragraph
 * block generated by gutenberg_yjs_encode_paragraph_insert_after_update_v2().
 */
function gutenberg_yjs_paragraph_insert_clock_len( string $paragraph_text, ?array $content_insert = null ): int {
	$length = gutenberg_yjs_paragraph_block_clock_len( $paragraph_text );
	if ( is_array( $content_insert ) && isset( $content_insert['text'] ) ) {
		$length += gutenberg_yjs_utf16_clock_len( (string) $content_insert['text'] );
	}

	return $length;
}

/**
 * Writes a Yjs Item struct.
 *
 * @param Gutenberg_Yjs_Update_V2_Encoder $encoder       Encoder.
 * @param int                             $content_ref   Yjs content ref.
 * @param array<string, int>|null         $origin        Left origin ID.
 * @param array<string, int>|null         $right_origin  Right origin ID.
 * @param array<string, mixed>|null       $parent        Parent descriptor.
 * @param string|null                     $parent_sub    Map key.
 * @param callable                        $write_content Content writer.
 */
function gutenberg_yjs_write_item( Gutenberg_Yjs_Update_V2_Encoder $encoder, int $content_ref, ?array $origin, ?array $right_origin, ?array $parent, ?string $parent_sub, callable $write_content ): void {
	$info = $content_ref & 0x1f;
	if ( null !== $origin ) {
		$info |= 0x80;
	}
	if ( null !== $right_origin ) {
		$info |= 0x40;
	}
	if ( null !== $parent_sub ) {
		$info |= 0x20;
	}

	$encoder->write_info( $info );

	if ( null !== $origin ) {
		$encoder->write_left_id( $origin['client'], $origin['clock'] );
	}

	if ( null !== $right_origin ) {
		$encoder->write_right_id( $right_origin['client'], $right_origin['clock'] );
	}

	if ( null === $origin && null === $right_origin ) {
		if ( ! is_array( $parent ) ) {
			throw new InvalidArgumentException( 'A parent descriptor is required when an item has no origin.' );
		}

		if ( 'root' === $parent['type'] ) {
			$encoder->write_parent_info( true );
			$encoder->write_string( $parent['key'] );
		} else {
			$encoder->write_parent_info( false );
			$encoder->write_left_id( $parent['client'], $parent['clock'] );
		}

		if ( null !== $parent_sub ) {
			$encoder->write_key( $parent_sub );
		}
	}

	$write_content( $encoder );
}
