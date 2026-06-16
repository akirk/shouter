<?php
/**
 * Minimal PHP encoder for the subset of Yjs updateV2 used by Gutenberg RTC prototypes.
 *
 * @package Gutenberg_Yjs_Update_V2
 */

/**
 * Byte encoder for lib0-style primitive values.
 */
class Gutenberg_Yjs_Binary_Encoder {
	/**
	 * Encoded bytes.
	 *
	 * @var string
	 */
	private string $data = '';

	/**
	 * Writes raw bytes.
	 */
	public function write_bytes( string $bytes ): void {
		$this->data .= $bytes;
	}

	/**
	 * Writes one byte.
	 */
	public function write_uint8( int $value ): void {
		$this->data .= chr( $value & 0xff );
	}

	/**
	 * Writes a lib0 varuint.
	 */
	public function write_var_uint( int $value ): void {
		while ( $value > 0x7f ) {
			$this->write_uint8( 0x80 | ( $value & 0x7f ) );
			$value = intdiv( $value, 128 );
		}

		$this->write_uint8( $value & 0x7f );
	}

	/**
	 * Writes a lib0 varint.
	 */
	public function write_var_int( int $value ): void {
		$is_negative = $value < 0;
		if ( $is_negative ) {
			$value = -$value;
		}

		$this->write_uint8(
			( $value > 0x3f ? 0x80 : 0 )
			| ( $is_negative ? 0x40 : 0 )
			| ( $value & 0x3f )
		);
		$value = intdiv( $value, 64 );

		while ( $value > 0 ) {
			$this->write_uint8( ( $value > 0x7f ? 0x80 : 0 ) | ( $value & 0x7f ) );
			$value = intdiv( $value, 128 );
		}
	}

	/**
	 * Writes a length-prefixed UTF-8 string.
	 */
	public function write_var_string( string $value ): void {
		$this->write_var_uint( strlen( $value ) );
		$this->write_bytes( $value );
	}

	/**
	 * Writes a length-prefixed byte array.
	 */
	public function write_var_uint8_array( string $bytes ): void {
		$this->write_var_uint( strlen( $bytes ) );
		$this->write_bytes( $bytes );
	}

	/**
	 * Writes a lib0 "any" value. This intentionally covers the data types that
	 * Gutenberg paragraph updates emit into paragraph block updates.
	 *
	 * @param mixed $value Value.
	 */
	public function write_any( $value ): void {
		if ( is_string( $value ) ) {
			$this->write_uint8( 119 );
			$this->write_var_string( $value );
			return;
		}

		if ( is_int( $value ) ) {
			$this->write_uint8( 125 );
			$this->write_var_int( $value );
			return;
		}

		if ( is_float( $value ) ) {
			$this->write_uint8( 123 );
			$this->write_bytes( strrev( pack( 'd', $value ) ) );
			return;
		}

		if ( is_bool( $value ) ) {
			$this->write_uint8( $value ? 120 : 121 );
			return;
		}

		if ( null === $value ) {
			$this->write_uint8( 126 );
			return;
		}

		if ( is_array( $value ) ) {
			if ( array_is_list( $value ) ) {
				$this->write_uint8( 117 );
				$this->write_var_uint( count( $value ) );
				foreach ( $value as $item ) {
					$this->write_any( $item );
				}
				return;
			}

			$this->write_uint8( 118 );
			$this->write_var_uint( count( $value ) );
			foreach ( $value as $key => $item ) {
				$this->write_var_string( (string) $key );
				$this->write_any( $item );
			}
			return;
		}

		$this->write_uint8( 127 );
	}

	/**
	 * Gets encoded bytes.
	 */
	public function to_string(): string {
		return $this->data;
	}
}

/**
 * Byte decoder for lib0-style primitive values.
 */
class Gutenberg_Yjs_Binary_Decoder {
	private string $data;
	private int $offset = 0;

	public function __construct( string $data ) {
		$this->data = $data;
	}

	public function offset(): int {
		return $this->offset;
	}

	public function remaining(): int {
		return strlen( $this->data ) - $this->offset;
	}

	public function read_uint8(): int {
		if ( $this->offset >= strlen( $this->data ) ) {
			throw new RuntimeException( 'Unexpected end of data.' );
		}

		return ord( $this->data[ $this->offset++ ] );
	}

	public function read_bytes( int $length ): string {
		if ( $length > $this->remaining() ) {
			throw new RuntimeException( 'Byte read exceeds remaining data.' );
		}

		$bytes = substr( $this->data, $this->offset, $length );
		$this->offset += $length;
		return $bytes;
	}

	public function read_remaining_bytes(): string {
		return $this->read_bytes( $this->remaining() );
	}

	public function read_var_uint(): int {
		$num        = 0;
		$multiplier = 1;

		do {
			$byte = $this->read_uint8();
			$num += ( $byte & 0x7f ) * $multiplier;
			$multiplier *= 128;
		} while ( $byte >= 0x80 );

		return $num;
	}

	/**
	 * Reads a lib0 varint and preserves the sign bit for negative zero.
	 *
	 * @return array{value:int,negative:bool}
	 */
	public function read_var_int_info(): array {
		$byte        = $this->read_uint8();
		$is_negative = (bool) ( $byte & 0x40 );
		$num         = $byte & 0x3f;
		$multiplier  = 64;

		while ( $byte & 0x80 ) {
			$byte = $this->read_uint8();
			$num += ( $byte & 0x7f ) * $multiplier;
			$multiplier *= 128;
		}

		return array(
			'value'    => $is_negative ? -$num : $num,
			'negative' => $is_negative,
		);
	}

	public function read_var_int(): int {
		return $this->read_var_int_info()['value'];
	}

	public function read_var_string(): string {
		return $this->read_bytes( $this->read_var_uint() );
	}

	public function read_var_uint8_array(): string {
		return $this->read_bytes( $this->read_var_uint() );
	}

	/**
	 * Reads a lib0 "any" value for the value types Gutenberg paragraph tooling needs to inspect.
	 *
	 * @return mixed
	 */
	public function read_any() {
		$type = $this->read_uint8();

		switch ( $type ) {
			case 119:
				return $this->read_var_string();
			case 125:
				return $this->read_var_int();
			case 126:
				return null;
			case 127:
				return null;
			case 120:
				return true;
			case 121:
				return false;
			case 117:
				$length = $this->read_var_uint();
				$items  = array();
				for ( $i = 0; $i < $length; $i++ ) {
					$items[] = $this->read_any();
				}
				return $items;
			case 118:
				$length = $this->read_var_uint();
				$object = array();
				for ( $i = 0; $i < $length; $i++ ) {
					$object[ $this->read_var_string() ] = $this->read_any();
				}
				return $object;
			case 116:
				return $this->read_var_uint8_array();
			default:
				throw new RuntimeException( 'Unsupported lib0 any type: ' . $type );
		}
	}
}

/**
 * lib0 RLE encoder.
 */
class Gutenberg_Yjs_Rle_Encoder {
	private Gutenberg_Yjs_Binary_Encoder $encoder;
	private $state = null;
	private int $count = 0;

	public function __construct() {
		$this->encoder = new Gutenberg_Yjs_Binary_Encoder();
	}

	public function write_uint8( int $value ): void {
		if ( $this->count > 0 && $this->state === $value ) {
			$this->count++;
			return;
		}

		if ( $this->count > 0 ) {
			$this->encoder->write_var_uint( $this->count - 1 );
		}

		$this->count = 1;
		$this->state = $value;
		$this->encoder->write_uint8( $value );
	}

	public function to_string(): string {
		return $this->encoder->to_string();
	}
}

/**
 * lib0 RleDecoder for uint8 values.
 */
class Gutenberg_Yjs_Rle_Decoder {
	private Gutenberg_Yjs_Binary_Decoder $decoder;
	private ?int $state = null;
	private int $count = 0;

	public function __construct( string $bytes ) {
		$this->decoder = new Gutenberg_Yjs_Binary_Decoder( $bytes );
	}

	public function read_uint8(): int {
		if ( 0 === $this->count ) {
			$this->state = $this->decoder->read_uint8();
			$this->count = $this->decoder->remaining() > 0 ? $this->decoder->read_var_uint() + 1 : -1;
		}

		$this->count--;
		return (int) $this->state;
	}
}

/**
 * lib0 UintOptRleEncoder.
 */
class Gutenberg_Yjs_Uint_Opt_Rle_Encoder {
	private Gutenberg_Yjs_Binary_Encoder $encoder;
	private int $state = 0;
	private int $count = 0;

	public function __construct() {
		$this->encoder = new Gutenberg_Yjs_Binary_Encoder();
	}

	public function write( int $value ): void {
		if ( $this->count > 0 && $this->state === $value ) {
			$this->count++;
			return;
		}

		$this->flush();
		$this->count = 1;
		$this->state = $value;
	}

	private function flush(): void {
		if ( $this->count <= 0 ) {
			return;
		}

		$this->encoder->write_var_int( 1 === $this->count ? $this->state : -$this->state );
		if ( $this->count > 1 ) {
			$this->encoder->write_var_uint( $this->count - 2 );
		}
	}

	public function to_string(): string {
		$this->flush();
		return $this->encoder->to_string();
	}
}

/**
 * lib0 UintOptRleDecoder.
 */
class Gutenberg_Yjs_Uint_Opt_Rle_Decoder {
	private Gutenberg_Yjs_Binary_Decoder $decoder;
	private int $state = 0;
	private int $count = 0;

	public function __construct( $bytes_or_decoder ) {
		$this->decoder = $bytes_or_decoder instanceof Gutenberg_Yjs_Binary_Decoder
			? $bytes_or_decoder
			: new Gutenberg_Yjs_Binary_Decoder( (string) $bytes_or_decoder );
	}

	public function read(): int {
		if ( 0 === $this->count ) {
			$info        = $this->decoder->read_var_int_info();
			$this->state = $info['negative'] ? -$info['value'] : $info['value'];
			$this->count = $info['negative'] ? $this->decoder->read_var_uint() + 2 : 1;
		}

		$this->count--;
		return $this->state;
	}
}

/**
 * lib0 IntDiffOptRleEncoder.
 */
class Gutenberg_Yjs_Int_Diff_Opt_Rle_Encoder {
	private Gutenberg_Yjs_Binary_Encoder $encoder;
	private int $state = 0;
	private int $count = 0;
	private int $diff = 0;

	public function __construct() {
		$this->encoder = new Gutenberg_Yjs_Binary_Encoder();
	}

	public function write( int $value ): void {
		if ( $this->count > 0 && $this->diff === $value - $this->state ) {
			$this->state = $value;
			$this->count++;
			return;
		}

		$this->flush();
		$this->count = 1;
		$this->diff  = $value - $this->state;
		$this->state = $value;
	}

	private function flush(): void {
		if ( $this->count <= 0 ) {
			return;
		}

		$this->encoder->write_var_int( ( $this->diff * 2 ) + ( 1 === $this->count ? 0 : 1 ) );
		if ( $this->count > 1 ) {
			$this->encoder->write_var_uint( $this->count - 2 );
		}
	}

	public function to_string(): string {
		$this->flush();
		return $this->encoder->to_string();
	}
}

/**
 * lib0 IntDiffOptRleDecoder.
 */
class Gutenberg_Yjs_Int_Diff_Opt_Rle_Decoder {
	private Gutenberg_Yjs_Binary_Decoder $decoder;
	private int $state = 0;
	private int $count = 0;
	private int $diff = 0;

	public function __construct( string $bytes ) {
		$this->decoder = new Gutenberg_Yjs_Binary_Decoder( $bytes );
	}

	public function read(): int {
		if ( 0 === $this->count ) {
			$encoded    = $this->decoder->read_var_int();
			$has_count  = (bool) ( $encoded & 1 );
			$this->diff = (int) floor( $encoded / 2 );
			$this->count = $has_count ? $this->decoder->read_var_uint() + 2 : 1;
		}

		$this->state += $this->diff;
		$this->count--;
		return $this->state;
	}
}

/**
 * lib0 StringEncoder.
 */
class Gutenberg_Yjs_String_Encoder {
	private Gutenberg_Yjs_Uint_Opt_Rle_Encoder $lengths;
	private string $buffer = '';

	public function __construct() {
		$this->lengths = new Gutenberg_Yjs_Uint_Opt_Rle_Encoder();
	}

	public function write( string $value ): void {
		$this->buffer .= $value;
		$this->lengths->write( strlen( $value ) );
	}

	public function to_string(): string {
		$encoder = new Gutenberg_Yjs_Binary_Encoder();
		$encoder->write_var_string( $this->buffer );
		$encoder->write_bytes( $this->lengths->to_string() );
		return $encoder->to_string();
	}
}

/**
 * lib0 StringDecoder.
 */
class Gutenberg_Yjs_String_Decoder {
	private Gutenberg_Yjs_Uint_Opt_Rle_Decoder $lengths;
	private string $string;
	private int $offset = 0;

	public function __construct( string $bytes ) {
		$decoder       = new Gutenberg_Yjs_Binary_Decoder( $bytes );
		$this->string  = $decoder->read_var_string();
		$this->lengths = new Gutenberg_Yjs_Uint_Opt_Rle_Decoder( $decoder );
	}

	public function read(): string {
		$length = $this->lengths->read();
		$value  = substr( $this->string, $this->offset, $length );
		$this->offset += $length;
		return $value;
	}
}

/**
 * Minimal Yjs updateV2 encoder.
 */
class Gutenberg_Yjs_Update_V2_Encoder {
	private Gutenberg_Yjs_Binary_Encoder $rest_encoder;
	private Gutenberg_Yjs_Int_Diff_Opt_Rle_Encoder $key_clock_encoder;
	private Gutenberg_Yjs_Uint_Opt_Rle_Encoder $client_encoder;
	private Gutenberg_Yjs_Int_Diff_Opt_Rle_Encoder $left_clock_encoder;
	private Gutenberg_Yjs_Int_Diff_Opt_Rle_Encoder $right_clock_encoder;
	private Gutenberg_Yjs_Rle_Encoder $info_encoder;
	private Gutenberg_Yjs_String_Encoder $string_encoder;
	private Gutenberg_Yjs_Rle_Encoder $parent_info_encoder;
	private Gutenberg_Yjs_Uint_Opt_Rle_Encoder $type_ref_encoder;
	private Gutenberg_Yjs_Uint_Opt_Rle_Encoder $len_encoder;

	public function __construct() {
		$this->rest_encoder        = new Gutenberg_Yjs_Binary_Encoder();
		$this->key_clock_encoder   = new Gutenberg_Yjs_Int_Diff_Opt_Rle_Encoder();
		$this->client_encoder      = new Gutenberg_Yjs_Uint_Opt_Rle_Encoder();
		$this->left_clock_encoder  = new Gutenberg_Yjs_Int_Diff_Opt_Rle_Encoder();
		$this->right_clock_encoder = new Gutenberg_Yjs_Int_Diff_Opt_Rle_Encoder();
		$this->info_encoder        = new Gutenberg_Yjs_Rle_Encoder();
		$this->string_encoder      = new Gutenberg_Yjs_String_Encoder();
		$this->parent_info_encoder = new Gutenberg_Yjs_Rle_Encoder();
		$this->type_ref_encoder    = new Gutenberg_Yjs_Uint_Opt_Rle_Encoder();
		$this->len_encoder         = new Gutenberg_Yjs_Uint_Opt_Rle_Encoder();
	}

	public function write_rest_var_uint( int $value ): void {
		$this->rest_encoder->write_var_uint( $value );
	}

	public function write_info( int $value ): void {
		$this->info_encoder->write_uint8( $value );
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

	public function write_parent_info( bool $is_y_key ): void {
		$this->parent_info_encoder->write_uint8( $is_y_key ? 1 : 0 );
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
		$this->rest_encoder->write_any( $value );
	}

	public function to_string(): string {
		$encoder = new Gutenberg_Yjs_Binary_Encoder();
		$encoder->write_var_uint( 0 );
		$encoder->write_var_uint8_array( $this->key_clock_encoder->to_string() );
		$encoder->write_var_uint8_array( $this->client_encoder->to_string() );
		$encoder->write_var_uint8_array( $this->left_clock_encoder->to_string() );
		$encoder->write_var_uint8_array( $this->right_clock_encoder->to_string() );
		$encoder->write_var_uint8_array( $this->info_encoder->to_string() );
		$encoder->write_var_uint8_array( $this->string_encoder->to_string() );
		$encoder->write_var_uint8_array( $this->parent_info_encoder->to_string() );
		$encoder->write_var_uint8_array( $this->type_ref_encoder->to_string() );
		$encoder->write_var_uint8_array( $this->len_encoder->to_string() );
		$encoder->write_bytes( $this->rest_encoder->to_string() );
		return $encoder->to_string();
	}
}

/**
 * Minimal Yjs updateV2 decoder.
 */
class Gutenberg_Yjs_Update_V2_Decoder {
	private Gutenberg_Yjs_Binary_Decoder $rest_decoder;
	private Gutenberg_Yjs_Int_Diff_Opt_Rle_Decoder $key_clock_decoder;
	private Gutenberg_Yjs_Uint_Opt_Rle_Decoder $client_decoder;
	private Gutenberg_Yjs_Int_Diff_Opt_Rle_Decoder $left_clock_decoder;
	private Gutenberg_Yjs_Int_Diff_Opt_Rle_Decoder $right_clock_decoder;
	private Gutenberg_Yjs_Rle_Decoder $info_decoder;
	private Gutenberg_Yjs_String_Decoder $string_decoder;
	private Gutenberg_Yjs_Rle_Decoder $parent_info_decoder;
	private Gutenberg_Yjs_Uint_Opt_Rle_Decoder $type_ref_decoder;
	private Gutenberg_Yjs_Uint_Opt_Rle_Decoder $len_decoder;

	/**
	 * @var array<int, string>
	 */
	private array $keys = array();

	public function __construct( string $update ) {
		$decoder = new Gutenberg_Yjs_Binary_Decoder( $update );
		$decoder->read_var_uint(); // feature flag.

		$this->key_clock_decoder   = new Gutenberg_Yjs_Int_Diff_Opt_Rle_Decoder( $decoder->read_var_uint8_array() );
		$this->client_decoder      = new Gutenberg_Yjs_Uint_Opt_Rle_Decoder( $decoder->read_var_uint8_array() );
		$this->left_clock_decoder  = new Gutenberg_Yjs_Int_Diff_Opt_Rle_Decoder( $decoder->read_var_uint8_array() );
		$this->right_clock_decoder = new Gutenberg_Yjs_Int_Diff_Opt_Rle_Decoder( $decoder->read_var_uint8_array() );
		$this->info_decoder        = new Gutenberg_Yjs_Rle_Decoder( $decoder->read_var_uint8_array() );
		$this->string_decoder      = new Gutenberg_Yjs_String_Decoder( $decoder->read_var_uint8_array() );
		$this->parent_info_decoder = new Gutenberg_Yjs_Rle_Decoder( $decoder->read_var_uint8_array() );
		$this->type_ref_decoder    = new Gutenberg_Yjs_Uint_Opt_Rle_Decoder( $decoder->read_var_uint8_array() );
		$this->len_decoder         = new Gutenberg_Yjs_Uint_Opt_Rle_Decoder( $decoder->read_var_uint8_array() );
		$this->rest_decoder        = new Gutenberg_Yjs_Binary_Decoder( $decoder->read_remaining_bytes() );
	}

	public function read_left_id(): array {
		return array(
			'client' => $this->client_decoder->read(),
			'clock'  => $this->left_clock_decoder->read(),
		);
	}

	public function read_right_id(): array {
		return array(
			'client' => $this->client_decoder->read(),
			'clock'  => $this->right_clock_decoder->read(),
		);
	}

	public function read_client(): int {
		return $this->client_decoder->read();
	}

	public function read_info(): int {
		return $this->info_decoder->read_uint8();
	}

	public function read_string(): string {
		return $this->string_decoder->read();
	}

	public function read_parent_info(): bool {
		return 1 === $this->parent_info_decoder->read_uint8();
	}

	public function read_type_ref(): int {
		return $this->type_ref_decoder->read();
	}

	public function read_len(): int {
		return $this->len_decoder->read();
	}

	/**
	 * @return mixed
	 */
	public function read_any() {
		return $this->rest_decoder->read_any();
	}

	public function read_rest_var_uint(): int {
		return $this->rest_decoder->read_var_uint();
	}

	public function read_key(): string {
		$key_clock = $this->key_clock_decoder->read();
		if ( ! array_key_exists( $key_clock, $this->keys ) ) {
			$this->keys[ $key_clock ] = $this->read_string();
		}

		return $this->keys[ $key_clock ];
	}
}

/**
 * Decodes Yjs updateV2 structs into plain arrays.
 *
 * @return array<string, mixed>
 */
function gutenberg_yjs_decode_update_v2( string $update ): array {
	$decoder       = new Gutenberg_Yjs_Update_V2_Decoder( $update );
	$state_count   = $decoder->read_rest_var_uint();
	$client_blocks = array();
	$structs       = array();

	for ( $state_index = 0; $state_index < $state_count; $state_index++ ) {
		$struct_count = $decoder->read_rest_var_uint();
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
				$origin       = ( $info & 0x80 ) ? $decoder->read_left_id() : null;
				$right_origin = ( $info & 0x40 ) ? $decoder->read_right_id() : null;
				$parent       = null;
				$parent_sub   = null;

				if ( null === $origin && null === $right_origin ) {
					if ( $decoder->read_parent_info() ) {
						$parent = array(
							'type' => 'root',
							'key'  => $decoder->read_string(),
						);
					} else {
						$parent = array_merge( array( 'type' => 'id' ), $decoder->read_left_id() );
					}

					if ( $info & 0x20 ) {
						$parent_sub = $decoder->read_string();
					}
				}

				$content = gutenberg_yjs_decode_item_content( $decoder, $content_ref );
				$length  = $content['length'];
				$struct  = array(
					'kind'          => 'item',
					'id'            => array(
						'client' => $client,
						'clock'  => $clock,
					),
					'info'          => $info,
					'content_ref'   => $content_ref,
					'origin'        => $origin,
					'right_origin'  => $right_origin,
					'parent'        => $parent,
					'parent_sub'    => $parent_sub,
					'content'       => $content,
					'length'        => $length,
				);
				$clock += $length;
			}

			$client_block['structs'][] = $struct;
			$structs[]                 = $struct;
		}

		$client_blocks[] = $client_block;
	}

	$delete_set_count = $decoder->read_rest_var_uint();

	return array(
		'client_blocks'    => $client_blocks,
		'structs'          => $structs,
		'delete_set_count' => $delete_set_count,
	);
}

/**
 * Decodes Yjs Item content.
 *
 * @return array<string, mixed>
 */
function gutenberg_yjs_decode_item_content( Gutenberg_Yjs_Update_V2_Decoder $decoder, int $content_ref ): array {
	switch ( $content_ref ) {
		case 4:
			$text = $decoder->read_string();
			return array(
				'type'   => 'string',
				'value'  => $text,
				'length' => gutenberg_yjs_utf16_clock_len( $text ),
			);
		case 7:
			$type_ref = $decoder->read_type_ref();
			$types    = array(
				0 => 'Y.Array',
				1 => 'Y.Map',
				2 => 'Y.Text',
				3 => 'Y.XmlElement',
				4 => 'Y.XmlFragment',
				5 => 'Y.XmlHook',
				6 => 'Y.XmlText',
			);
			return array(
				'type'     => 'type',
				'type_ref' => $type_ref,
				'name'     => $types[ $type_ref ] ?? 'unknown',
				'length'   => 1,
			);
		case 8:
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
 * Encodes a fresh document containing one paragraph block. This is primarily a
 * byte-for-byte fixture target.
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
 * @param int    $client_id           Client ID for the new bot-authored structs.
 * @param string $paragraph_text      Paragraph rich-text content.
 * @param string $block_client_id     Gutenberg block clientId value.
 * @param int    $left_origin_client  Client ID of the block item to insert after.
 * @param int    $left_origin_clock   Clock of the block item to insert after.
 * @return string Binary updateV2 bytes.
 */
function gutenberg_yjs_encode_paragraph_insert_after_update_v2( int $client_id, string $paragraph_text, string $block_client_id, int $left_origin_client, int $left_origin_clock, int $start_clock = 0, ?array $right_origin = null, ?array $content_insert = null ): string {
	$encoder = new Gutenberg_Yjs_Update_V2_Encoder();
	$has_text = '' !== $paragraph_text;
	$has_content_insert = is_array( $content_insert ) && ! empty( $content_insert['text'] ) && ! empty( $content_insert['parent'] );

	$encoder->write_rest_var_uint( 1 ); // one client state.
	$encoder->write_rest_var_uint( ( $has_text ? 9 : 8 ) + ( $has_content_insert ? 1 : 0 ) ); // block + fields + optional document.content text.
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
		array(),
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
	$name_clock          = $block_clock + 1;
	$is_valid_clock      = $name_clock + 1;
	$attributes_clock    = $is_valid_clock + 1;
	$content_type_clock  = $attributes_clock + 1;

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
	if ( function_exists( 'mb_convert_encoding' ) ) {
		return intdiv( strlen( mb_convert_encoding( $value, 'UTF-16LE', 'UTF-8' ) ), 2 );
	}

	return strlen( $value );
}

/**
 * Gets the number of Yjs client-clock ticks consumed by one inserted paragraph
 * block generated by gutenberg_yjs_encode_paragraph_insert_after_update_v2().
 */
function gutenberg_yjs_paragraph_insert_clock_len( string $paragraph_text, ?array $content_insert = null ): int {
	$length = 8 + gutenberg_yjs_utf16_clock_len( $paragraph_text );
	if ( is_array( $content_insert ) && isset( $content_insert['text'] ) ) {
		$length += gutenberg_yjs_utf16_clock_len( (string) $content_insert['text'] );
	}

	return $length;
}

/**
 * Writes a Yjs Item struct.
 *
 * @param Gutenberg_Yjs_Update_V2_Encoder $encoder       Encoder.
 * @param int                           $content_ref   Yjs content ref.
 * @param array<string, int>|null        $origin        Left origin ID.
 * @param array<string, int>|null        $right_origin  Right origin ID.
 * @param array<string, mixed>           $parent        Parent descriptor.
 * @param string|null                    $parent_sub    Map key.
 * @param callable                       $write_content Content writer.
 */
function gutenberg_yjs_write_item( Gutenberg_Yjs_Update_V2_Encoder $encoder, int $content_ref, ?array $origin, ?array $right_origin, array $parent, ?string $parent_sub, callable $write_content ): void {
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
		if ( 'root' === $parent['type'] ) {
			$encoder->write_parent_info( true );
			$encoder->write_string( $parent['key'] );
		} else {
			$encoder->write_parent_info( false );
			$encoder->write_left_id( $parent['client'], $parent['clock'] );
		}

		if ( null !== $parent_sub ) {
			$encoder->write_string( $parent_sub );
		}
	}

	$write_content( $encoder );
}
