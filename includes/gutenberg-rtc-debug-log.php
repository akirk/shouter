<?php
/**
 * Debug logging helpers for Gutenberg RTC payloads.
 *
 * @package Gutenberg_RTC_Debug_Log
 */

/**
 * Gets rooms from parsed request params, JSON params, or raw JSON body.
 *
 * @param WP_REST_Request $request Request object.
 * @return array<int, mixed>|null
 */
function gutenberg_rtc_get_request_rooms( WP_REST_Request $request ): ?array {
	$rooms = $request->get_param( 'rooms' );
	if ( is_array( $rooms ) ) {
		return $rooms;
	}

	$json_params = $request->get_json_params();
	if ( is_array( $json_params ) && isset( $json_params['rooms'] ) && is_array( $json_params['rooms'] ) ) {
		return $json_params['rooms'];
	}

	$body = $request->get_body();
	if ( '' === $body ) {
		return null;
	}

	$decoded = json_decode( $body, true );
	if ( is_array( $decoded ) && isset( $decoded['rooms'] ) && is_array( $decoded['rooms'] ) ) {
		return $decoded['rooms'];
	}

	return null;
}

/**
 * Builds a compact room summary for request-level logging.
 *
 * @param array<int, mixed> $rooms Sync rooms.
 * @return array<int, array<string, mixed>>
 */
function gutenberg_rtc_summarize_rooms( array $rooms ): array {
	$summary = array();

	foreach ( $rooms as $room_index => $room_request ) {
		if ( ! is_array( $room_request ) ) {
			$summary[] = array(
				'room_index' => $room_index,
				'error'      => 'room_must_be_object',
			);
			continue;
		}

		$updates   = isset( $room_request['updates'] ) && is_array( $room_request['updates'] ) ? $room_request['updates'] : array();
		$awareness = isset( $room_request['awareness'] ) && is_array( $room_request['awareness'] ) ? $room_request['awareness'] : array();

		$summary[] = array(
			'room_index'     => $room_index,
			'room'           => isset( $room_request['room'] ) && is_string( $room_request['room'] ) ? $room_request['room'] : '',
			'client_id'      => isset( $room_request['client_id'] ) ? (int) $room_request['client_id'] : 0,
			'after'          => isset( $room_request['after'] ) ? (int) $room_request['after'] : null,
			'update_count'   => count( $updates ),
			'awareness_keys' => array_keys( $awareness ),
		);
	}

	return $summary;
}

/**
 * Decodes rooms and writes update/awareness entries to the PHP error log.
 *
 * @param array<int, mixed> $rooms Sync rooms.
 * @param callable          $log Callback receiving event name and payload.
 * @return array<int, mixed>
 */
function gutenberg_rtc_decode_rooms_for_logging( array $rooms, callable $log ): array {
	$results = array();

	foreach ( $rooms as $room_index => $room_request ) {
		if ( ! is_array( $room_request ) ) {
			$results[] = array(
				'room_index' => $room_index,
				'error'      => 'room_must_be_object',
			);
			continue;
		}

		$room      = isset( $room_request['room'] ) && is_string( $room_request['room'] ) ? $room_request['room'] : '';
		$client_id = isset( $room_request['client_id'] ) ? (int) $room_request['client_id'] : 0;
		$updates   = isset( $room_request['updates'] ) && is_array( $room_request['updates'] ) ? $room_request['updates'] : array();

		$room_result = array(
			'room'         => $room,
			'client_id'    => $client_id,
			'update_count' => count( $updates ),
			'updates'      => array(),
		);

		foreach ( $updates as $update_index => $update ) {
			$decoded = gutenberg_rtc_decode_update_entry( $update, $room, $client_id, $update_index );
			$room_result['updates'][] = $decoded;
			$log( 'update', $decoded );
		}

		if ( array_key_exists( 'awareness', $room_request ) ) {
			$log(
				'awareness',
				array(
					'room'      => $room,
					'client_id' => $client_id,
					'awareness' => $room_request['awareness'],
				)
			);
		}

		$results[] = $room_result;
	}

	return $results;
}

/**
 * Decodes one typed update entry.
 *
 * @param mixed  $update       Update entry.
 * @param string $room         Room name.
 * @param int    $client_id    Client ID.
 * @param int    $update_index Update index.
 * @return array<string, mixed>
 */
function gutenberg_rtc_decode_update_entry( $update, string $room, int $client_id, int $update_index ): array {
	$result = array(
		'room'         => $room,
		'client_id'    => $client_id,
		'update_index' => $update_index,
	);

	if ( ! is_array( $update ) ) {
		$result['error'] = 'update_must_be_object';
		return $result;
	}

	$type = isset( $update['type'] ) && is_string( $update['type'] ) ? $update['type'] : '';
	$data = isset( $update['data'] ) && is_string( $update['data'] ) ? $update['data'] : '';

	$result['type']          = $type;
	$result['base64_length'] = strlen( $data );

	if ( '' === $data ) {
		$result['error'] = 'missing_data';
		return $result;
	}

	$binary = base64_decode( $data, true );
	if ( false === $binary ) {
		$result['error'] = 'invalid_base64';
		return $result;
	}

	$reader = new Gutenberg_RTC_Debug_Update_Reader( $binary );

	$result['byte_length'] = strlen( $binary );
	$result['hex_prefix']  = bin2hex( substr( $binary, 0, 24 ) );

	if ( 'sync_step1' === $type || 'sync_step2' === $type ) {
		$result['sync_message'] = gutenberg_rtc_decode_sync_message_summary( $reader );
		$result['remaining']    = $reader->remaining();
		return $result;
	}

	if ( 'update' === $type || 'compaction' === $type ) {
		$result['y_update_v2'] = gutenberg_rtc_decode_update_v2_summary( $reader );
		try {
			$result['y_update_v2_structs'] = gutenberg_rtc_summarize_decoded_yjs_update( gutenberg_yjs_decode_update_v2( $binary ) );
		} catch ( RuntimeException $exception ) {
			$result['y_update_v2_decode_error'] = $exception->getMessage();
		}
		$result['remaining'] = $reader->remaining();
		return $result;
	}

	$result['warning']   = 'unknown_update_type';
	$result['remaining'] = $reader->remaining();
	return $result;
}

/**
 * Decodes the outer y-protocols/sync message wrapper.
 *
 * @param Gutenberg_RTC_Debug_Update_Reader $reader Reader.
 * @return array<string, mixed>
 */
function gutenberg_rtc_decode_sync_message_summary( Gutenberg_RTC_Debug_Update_Reader $reader ): array {
	$summary = array();

	try {
		$message_type = $reader->read_var_uint();
		$type_names   = array(
			0 => 'sync_step1',
			1 => 'sync_step2',
			2 => 'update',
		);

		$summary['message_type'] = $message_type;
		$summary['message_name'] = $type_names[ $message_type ] ?? 'unknown';

		if ( 0 === $message_type || 1 === $message_type || 2 === $message_type ) {
			$summary['payload'] = $reader->read_var_uint8_array_summary();
		}
	} catch ( RuntimeException $exception ) {
		$summary['decode_error'] = $exception->getMessage();
		$summary['offset']       = $reader->offset();
		$summary['remaining']    = $reader->remaining();
	}

	return $summary;
}

/**
 * Best-effort structural decoder for Yjs updateV2 payloads.
 *
 * @param Gutenberg_RTC_Debug_Update_Reader $reader Reader.
 * @return array<string, mixed>
 */
function gutenberg_rtc_decode_update_v2_summary( Gutenberg_RTC_Debug_Update_Reader $reader ): array {
	$summary = array();

	try {
		$summary['feature_flag'] = $reader->read_var_uint();

		$summary['key_clock_decoder']   = $reader->read_var_uint8_array_summary();
		$summary['client_decoder']      = $reader->read_var_uint8_array_summary();
		$summary['left_clock_decoder']  = $reader->read_var_uint8_array_summary();
		$summary['right_clock_decoder'] = $reader->read_var_uint8_array_summary();
		$summary['info_decoder']        = $reader->read_var_uint8_array_summary();
		$summary['string_decoder']      = $reader->read_var_uint8_array_summary();
		$summary['parent_info_decoder'] = $reader->read_var_uint8_array_summary();
		$summary['type_ref_decoder']    = $reader->read_var_uint8_array_summary();
		$summary['len_decoder']         = $reader->read_var_uint8_array_summary();

		$summary['rest_decoder'] = array(
			'offset' => $reader->offset(),
			'bytes'  => $reader->remaining(),
		);
	} catch ( RuntimeException $exception ) {
		$summary['decode_error'] = $exception->getMessage();
		$summary['offset']       = $reader->offset();
		$summary['remaining']    = $reader->remaining();
	}

	return $summary;
}

/**
 * Summarizes decoded Yjs structs for logs.
 *
 * @param array<string, mixed> $decoded Decoded update.
 * @return array<string, mixed>
 */
function gutenberg_rtc_summarize_decoded_yjs_update( array $decoded ): array {
	$items = array();

	foreach ( $decoded['structs'] ?? array() as $struct ) {
		if ( ! is_array( $struct ) || ( $struct['kind'] ?? '' ) !== 'item' ) {
			continue;
		}

		$content = isset( $struct['content'] ) && is_array( $struct['content'] ) ? $struct['content'] : array();
		$item    = array(
			'id'           => gutenberg_rtc_yjs_id_key( $struct['id'] ?? null ),
			'origin'       => gutenberg_rtc_yjs_id_key( $struct['origin'] ?? null ),
			'right_origin' => gutenberg_rtc_yjs_id_key( $struct['right_origin'] ?? null ),
			'parent'       => gutenberg_rtc_yjs_parent_key( $struct['parent'] ?? null ),
			'parent_sub'   => $struct['parent_sub'] ?? null,
			'content_type' => $content['type'] ?? null,
			'length'       => $struct['length'] ?? null,
		);

		if ( 'type' === ( $content['type'] ?? '' ) ) {
			$item['type_name'] = $content['name'] ?? null;
		} elseif ( 'string' === ( $content['type'] ?? '' ) ) {
			$item['text'] = $content['value'] ?? '';
		} elseif ( 'any' === ( $content['type'] ?? '' ) ) {
			$item['values'] = $content['values'] ?? array();
		}

		$items[] = array_filter(
			$item,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);
	}

	return array(
		'client_blocks' => array_map(
			static function ( $block ) {
				return array(
					'client'       => $block['client'] ?? null,
					'start_clock'  => $block['start_clock'] ?? null,
					'struct_count' => $block['struct_count'] ?? null,
				);
			},
			$decoded['client_blocks'] ?? array()
		),
		'items'         => $items,
	);
}

/**
 * Tiny lib0-style binary reader for probe logging.
 */
class Gutenberg_RTC_Debug_Update_Reader {
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

	public function read_var_uint(): int {
		$num   = 0;
		$shift = 0;

		do {
			$byte = $this->read_uint8();
			$num |= ( $byte & 0x7f ) << $shift;
			$shift += 7;

			if ( $shift > PHP_INT_SIZE * 8 ) {
				throw new RuntimeException( 'Varuint exceeds PHP integer size.' );
			}
		} while ( $byte >= 0x80 );

		return $num;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function read_var_uint8_array_summary(): array {
		$length = $this->read_var_uint();
		$offset = $this->offset;

		if ( $length > $this->remaining() ) {
			throw new RuntimeException( 'Length-prefixed array exceeds remaining data.' );
		}

		$bytes = substr( $this->data, $this->offset, $length );
		$this->offset += $length;

		return array(
			'offset'     => $offset,
			'byte_count' => $length,
			'hex_prefix' => bin2hex( substr( $bytes, 0, 24 ) ),
		);
	}
}
