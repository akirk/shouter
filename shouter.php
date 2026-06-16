<?php
/**
 * Plugin Name: Shouter
 * Description: Repeats completed Gutenberg paragraphs in uppercase through the synced entity path, with RTC logging.
 * Version: 0.1.0
 * Author: Alex Kirk
 * Text Domain: shouter
 *
 * @package Shouter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SHOUTER_REST_NAMESPACE = 'shouter/v1';

add_action( 'rest_api_init', 'shouter_register_rest_routes' );
add_filter( 'rest_pre_dispatch', 'shouter_log_wp_sync_requests', 10, 3 );
add_action( 'enqueue_block_editor_assets', 'shouter_enqueue_editor_probe' );

/**
 * Registers REST routes.
 */
function shouter_register_rest_routes(): void {
	register_rest_route(
		SHOUTER_REST_NAMESPACE,
		'/updates',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'shouter_handle_updates',
			'permission_callback' => 'shouter_check_permissions',
			'args'                => array(
				'rooms' => array(
					'required' => true,
					'type'     => 'array',
				),
			),
		)
	);

	register_rest_route(
		SHOUTER_REST_NAMESPACE,
		'/typed',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'shouter_handle_typed_event',
			'permission_callback' => 'shouter_check_permissions',
			'args'                => array(
				'after_length'  => array(
					'required' => true,
					'type'     => 'integer',
				),
				'before_length' => array(
					'required' => true,
					'type'     => 'integer',
				),
				'inserted'      => array(
					'required' => false,
					'type'     => 'string',
				),
				'offset'        => array(
					'required' => true,
					'type'     => 'integer',
				),
				'post_id'       => array(
					'required' => false,
					'type'     => 'integer',
				),
				'removed'       => array(
					'required' => false,
					'type'     => 'string',
				),
				'room'          => array(
					'required' => false,
					'type'     => 'string',
				),
			),
		)
	);
}

/**
 * Adds a small editor-side script that echoes completed paragraphs.
 */
function shouter_enqueue_editor_probe(): void {
	wp_register_script(
		'shouter-editor',
		'',
		array( 'wp-api-fetch', 'wp-blocks', 'wp-data', 'wp-dom-ready' ),
		'0.1.0',
		true
	);

	wp_enqueue_script( 'shouter-editor' );
	wp_add_inline_script( 'shouter-editor', shouter_get_editor_probe_script() );
}

/**
 * Returns the inline editor-side probe script.
 */
function shouter_get_editor_probe_script(): string {
	return <<<'JS'
( function ( wp ) {
	if ( ! wp?.apiFetch || ! wp?.blocks || ! wp?.data || ! wp?.domReady ) {
		return;
	}

	function getEditorText() {
		const blockEditor = wp.data.select( 'core/block-editor' );
		if ( ! blockEditor?.getBlocks ) {
			return '';
		}

		const serialized = wp.blocks.serialize( blockEditor.getBlocks() );
		const document = new DOMParser().parseFromString( serialized, 'text/html' );
		return document.body.textContent || '';
	}

	function getPostId() {
		const editor = wp.data.select( 'core/editor' );
		return editor?.getCurrentPostId?.() || 0;
	}

	function getRoom() {
		const postId = getPostId();
		return postId ? `postType/post:${ postId }` : '';
	}

	function diffText( before, after ) {
		if ( before === after ) {
			return null;
		}

		const beforeChars = Array.from( before );
		const afterChars = Array.from( after );
		let start = 0;

		while (
			start < beforeChars.length &&
			start < afterChars.length &&
			beforeChars[ start ] === afterChars[ start ]
		) {
			start++;
		}

		let beforeEnd = beforeChars.length;
		let afterEnd = afterChars.length;
		while (
			beforeEnd > start &&
			afterEnd > start &&
			beforeChars[ beforeEnd - 1 ] === afterChars[ afterEnd - 1 ]
		) {
			beforeEnd--;
			afterEnd--;
		}

		return {
			after_length: afterChars.length,
			before_length: beforeChars.length,
			inserted: afterChars.slice( start, afterEnd ).join( '' ),
			offset: start,
			post_id: getPostId(),
			removed: beforeChars.slice( start, beforeEnd ).join( '' ),
			room: getRoom(),
		};
	}

	function isEmptyParagraph( block ) {
		if ( ! block || block.name !== 'core/paragraph' ) {
			return false;
		}

		const content = block.attributes?.content || '';
		const document = new DOMParser().parseFromString( content, 'text/html' );
		return ! ( document.body.textContent || '' ).trim();
	}

	function getParagraphPlainText( block ) {
		if ( ! block || block.name !== 'core/paragraph' ) {
			return '';
		}

		const content = block.attributes?.content || '';
		const document = new DOMParser().parseFromString( content, 'text/html' );
		return document.body.textContent || '';
	}

	function shoutText( text ) {
		return text.toUpperCase().replace( /[!"#$%&'()*+,./:;<=>?@[\\\]^_`{|}~-]/g, '!' );
	}

	function maybeInsertFollowUpParagraph( diff ) {
		if ( diff.inserted !== '\n\n\n\n' || diff.removed ) {
			return;
		}

		const blockEditor = wp.data.select( 'core/block-editor' );
		const core = wp.data.dispatch( 'core' );
		const editor = wp.data.select( 'core/editor' );
		const selectedClientId = blockEditor?.getSelectedBlockClientId?.();

		if ( ! selectedClientId || ! core?.editEntityRecord ) {
			return;
		}

		const selectedBlock = blockEditor.getBlock?.( selectedClientId );
		if ( ! isEmptyParagraph( selectedBlock ) ) {
			return;
		}

		const rootClientId = blockEditor.getBlockRootClientId?.( selectedClientId );
		const insertIndex = blockEditor.getBlockIndex?.( selectedClientId );
		if ( rootClientId || typeof insertIndex !== 'number' || insertIndex < 0 ) {
			return;
		}

		const currentBlocks = blockEditor.getBlocks?.();
		const postId = getPostId();
		const postType = editor?.getCurrentPostType?.() || 'post';

		if ( ! currentBlocks || ! postId ) {
			return;
		}

		const completedParagraphText = getParagraphPlainText( currentBlocks[ insertIndex - 1 ] ).trim();
		if ( ! completedParagraphText ) {
			return;
		}

		const nextBlocks = [
			...currentBlocks.slice( 0, insertIndex ),
			wp.blocks.createBlock( 'core/paragraph', { content: shoutText( completedParagraphText ) } ),
			...currentBlocks.slice( insertIndex ),
		];

		core.editEntityRecord(
			'postType',
			postType,
			postId,
			{
				blocks: nextBlocks,
				content: wp.blocks.serialize( nextBlocks ),
			},
			{ isCached: false }
		);
	}

	wp.domReady( function () {
		let previousText = getEditorText();
		let isQueued = false;

		wp.data.subscribe( function () {
			if ( isQueued ) {
				return;
			}

			isQueued = true;
			window.queueMicrotask( function () {
				isQueued = false;
				const nextText = getEditorText();
				const diff = diffText( previousText, nextText );
				previousText = nextText;

				if ( ! diff || ( ! diff.inserted && ! diff.removed ) ) {
					return;
				}

				maybeInsertFollowUpParagraph( diff );

				wp.apiFetch( {
					method: 'POST',
					path: '/shouter/v1/typed',
					data: diff,
				} ).catch( function () {} );
			} );
		} );
	} );
} )( window.wp );
JS;
}

/**
 * Passively logs real Gutenberg sync requests without intercepting them.
 *
 * @param mixed           $result  Response to replace requested version with.
 * @param WP_REST_Server  $server  Server instance.
 * @param WP_REST_Request $request Request used to generate the response.
 * @return mixed Unchanged response.
 */
function shouter_log_wp_sync_requests( $result, WP_REST_Server $server, WP_REST_Request $request ) {
	unset( $server );

	if ( '/wp-sync/v1/updates' !== $request->get_route() || 'POST' !== $request->get_method() ) {
		return $result;
	}

	$rooms = shouter_get_request_rooms( $request );
	if ( ! is_array( $rooms ) ) {
		shouter_log(
			'wp-sync-request',
			array(
				'route'        => $request->get_route(),
				'method'       => $request->get_method(),
				'content_type' => $request->get_header( 'content-type' ),
				'body_length'  => strlen( $request->get_body() ),
				'error'        => 'missing_rooms',
			)
		);
		return $result;
	}

	shouter_log(
		'wp-sync-request',
		array(
			'route'        => $request->get_route(),
			'method'       => $request->get_method(),
			'content_type' => $request->get_header( 'content-type' ),
			'body_length'  => strlen( $request->get_body() ),
			'room_count'   => count( $rooms ),
			'rooms'        => shouter_summarize_rooms( $rooms ),
		)
	);

	shouter_decode_rooms_for_logging( $rooms );

	return $result;
}

/**
 * Checks whether the current user may use the probe.
 *
 * @return true|WP_Error
 */
function shouter_check_permissions() {
	if ( current_user_can( 'edit_posts' ) ) {
		return true;
	}

	return new WP_Error(
		'shouter_forbidden',
		__( 'You do not have permission to inspect sync updates.', 'shouter' ),
		array( 'status' => rest_authorization_required_code() )
	);
}

/**
 * Handles a Gutenberg-style rooms payload and logs decoded updates.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function shouter_handle_updates( WP_REST_Request $request ): WP_REST_Response {
	$rooms = shouter_get_request_rooms( $request );
	if ( ! is_array( $rooms ) ) {
		return new WP_REST_Response(
			array(
				'ok'    => false,
				'error' => 'missing_rooms',
			),
			400
		);
	}

	$results = shouter_decode_rooms_for_logging( $rooms );

	return new WP_REST_Response(
		array(
			'ok'    => true,
			'rooms' => $results,
		),
		200
	);
}

/**
 * Handles editor-side text diff events and logs the authenticated user.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function shouter_handle_typed_event( WP_REST_Request $request ): WP_REST_Response {
	$user = wp_get_current_user();

	shouter_log(
		'typed-character',
		array(
			'user'          => array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'user_login'   => $user->user_login,
			),
			'room'          => $request->get_param( 'room' ),
			'post_id'       => (int) $request->get_param( 'post_id' ),
			'offset'        => (int) $request->get_param( 'offset' ),
			'inserted'      => (string) $request->get_param( 'inserted' ),
			'removed'       => (string) $request->get_param( 'removed' ),
			'before_length' => (int) $request->get_param( 'before_length' ),
			'after_length'  => (int) $request->get_param( 'after_length' ),
		)
	);

	return new WP_REST_Response(
		array(
			'ok' => true,
		),
		200
	);
}

/**
 * Gets rooms from parsed request params, JSON params, or raw JSON body.
 *
 * @param WP_REST_Request $request Request object.
 * @return array<int, mixed>|null
 */
function shouter_get_request_rooms( WP_REST_Request $request ): ?array {
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
function shouter_summarize_rooms( array $rooms ): array {
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
 * @return array<int, mixed>
 */
function shouter_decode_rooms_for_logging( array $rooms ): array {
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
			$decoded = shouter_decode_update_entry( $update, $room, $client_id, $update_index );
			$room_result['updates'][] = $decoded;
			shouter_log( 'update', $decoded );
		}

		if ( array_key_exists( 'awareness', $room_request ) ) {
			shouter_log(
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
function shouter_decode_update_entry( $update, string $room, int $client_id, int $update_index ): array {
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

	$reader = new Shouter_Update_Reader( $binary );

	$result['byte_length'] = strlen( $binary );
	$result['hex_prefix']  = bin2hex( substr( $binary, 0, 24 ) );

	if ( 'sync_step1' === $type ) {
		$result['sync_message'] = shouter_decode_sync_message_summary( $reader );
		$result['remaining']  = $reader->remaining();
		return $result;
	}

	if ( 'sync_step2' === $type ) {
		$result['sync_message'] = shouter_decode_sync_message_summary( $reader );
		$result['remaining']  = $reader->remaining();
		return $result;
	}

	if ( 'update' === $type || 'compaction' === $type ) {
		$result['y_update_v2'] = shouter_decode_update_v2_summary( $reader );
		$result['remaining']   = $reader->remaining();
		return $result;
	}

	$result['warning']   = 'unknown_update_type';
	$result['remaining'] = $reader->remaining();
	return $result;
}

/**
 * Decodes the outer y-protocols/sync message wrapper.
 *
 * @param Shouter_Update_Reader $reader Reader.
 * @return array<string, mixed>
 */
function shouter_decode_sync_message_summary( Shouter_Update_Reader $reader ): array {
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
 * This intentionally avoids claiming semantic decoding. It reads the top-level
 * lib0 varuint/framed sections enough to prove PHP can parse the transport bytes.
 *
 * @param Shouter_Update_Reader $reader Reader.
 * @return array<string, mixed>
 */
function shouter_decode_update_v2_summary( Shouter_Update_Reader $reader ): array {
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
 * Logs a probe event.
 *
 * @param string $event Event name.
 * @param mixed  $data  Event payload.
 */
function shouter_log( string $event, $data ): void {
	$message = '[Shouter] ' . gmdate( 'c' ) . ' ' . $event . ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Tiny lib0-style binary reader for probe logging.
 */
class Shouter_Update_Reader {
	/**
	 * Binary data.
	 *
	 * @var string
	 */
	private string $data;

	/**
	 * Current byte offset.
	 *
	 * @var int
	 */
	private int $offset = 0;

	/**
	 * Constructor.
	 *
	 * @param string $data Binary data.
	 */
	public function __construct( string $data ) {
		$this->data = $data;
	}

	/**
	 * Gets current offset.
	 */
	public function offset(): int {
		return $this->offset;
	}

	/**
	 * Gets remaining byte count.
	 */
	public function remaining(): int {
		return strlen( $this->data ) - $this->offset;
	}

	/**
	 * Reads one byte.
	 */
	public function read_uint8(): int {
		if ( $this->offset >= strlen( $this->data ) ) {
			throw new RuntimeException( 'Unexpected end of data.' );
		}

		return ord( $this->data[ $this->offset++ ] );
	}

	/**
	 * Reads a lib0 varuint.
	 */
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
	 * Reads a length-prefixed byte array summary.
	 *
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
