<?php
/**
 * Plugin Name: Shouter
 * Description: Repeats completed Gutenberg paragraphs in uppercase through Gutenberg RTC, with PHP-side RTC logging.
 * Version: 0.1.0
 * Author: Alex Kirk
 * Text Domain: shouter
 *
 * @package Shouter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SHOUTER_OPTION_BOT_USER_ID = 'shouter_bot_user_id';
const SHOUTER_BOT_CLOCK_META_KEY = '_shouter_bot_clock';
const SHOUTER_ROOM_STATE_META_KEY = '_shouter_room_state';
const SHOUTER_AWARENESS_NUDGE_TTL = 20;
const SHOUTER_ROOM_STATE_SCHEMA_VERSION = 3;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/gutenberg-yjs-update-v2.php';
require_once __DIR__ . '/includes/gutenberg-rtc-paragraphs.php';
require_once __DIR__ . '/includes/gutenberg-rtc-debug-log.php';

add_action( 'admin_init', 'shouter_register_settings' );
add_action( 'admin_menu', 'shouter_register_settings_page' );
add_filter( 'rest_pre_dispatch', 'shouter_log_wp_sync_requests', 10, 3 );
add_filter( 'rest_post_dispatch', 'shouter_respond_to_wp_sync_requests', 10, 3 );

/**
 * Registers Shouter settings.
 */
function shouter_register_settings(): void {
	register_setting(
		'shouter',
		SHOUTER_OPTION_BOT_USER_ID,
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		)
	);

	add_settings_section(
		'shouter_bot_section',
		__( 'Bot identity', 'shouter' ),
		'__return_null',
		'shouter'
	);

	add_settings_field(
		SHOUTER_OPTION_BOT_USER_ID,
		__( 'Bot user', 'shouter' ),
		'shouter_render_bot_user_field',
		'shouter',
		'shouter_bot_section'
	);
}

/**
 * Registers the Shouter settings page.
 */
function shouter_register_settings_page(): void {
	add_options_page(
		__( 'Shouter', 'shouter' ),
		__( 'Shouter', 'shouter' ),
		'manage_options',
		'shouter',
		'shouter_render_settings_page'
	);
}

/**
 * Renders the bot user setting.
 */
function shouter_render_bot_user_field(): void {
	wp_dropdown_users(
		array(
			'name'              => SHOUTER_OPTION_BOT_USER_ID,
			'id'                => SHOUTER_OPTION_BOT_USER_ID,
			'selected'          => shouter_get_bot_user_id(),
			'show_option_none'  => __( 'Select a user', 'shouter' ),
			'option_none_value' => 0,
			'role__in'          => array( 'administrator', 'editor', 'author', 'contributor' ),
		)
	);
}

/**
 * Renders the settings page.
 */
function shouter_render_settings_page(): void {
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p><?php esc_html_e( 'Choose the WordPress user Shouter should use when emitting PHP-generated Gutenberg RTC updates and awareness.', 'shouter' ); ?></p>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'shouter' );
			do_settings_sections( 'shouter' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Gets the configured bot user ID.
 */
function shouter_get_bot_user_id(): int {
	return absint( get_option( SHOUTER_OPTION_BOT_USER_ID, 0 ) );
}

/**
 * Gets the stable RTC client ID used by Shouter for a bot user.
 */
function shouter_get_bot_client_id( int $bot_user_id ): int {
	return abs( crc32( 'shouter-bot-' . $bot_user_id ) );
}

/**
 * Emits the configured bot user's awareness state into the sync room.
 *
 * This is PHP-only RTC awareness. It does not enqueue browser JavaScript.
 *
 * @param int    $post_id      Post ID.
 * @param string $room         Sync room.
 * @param string $shouted_text Inserted shouted text.
 * @return true|WP_Error
 */
function shouter_emit_bot_awareness( int $post_id, string $room, string $shouted_text ) {
	if ( ! $post_id || '' === $room ) {
		return new WP_Error( 'shouter_missing_room', __( 'Missing Shouter room.', 'shouter' ) );
	}

	$bot_user_id = shouter_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return new WP_Error( 'shouter_missing_bot_user', __( 'No Shouter bot user is configured.', 'shouter' ) );
	}

	$bot_user = get_user_by( 'id', $bot_user_id );
	if ( ! $bot_user || ! user_can( $bot_user, 'edit_post', $post_id ) ) {
		return new WP_Error( 'shouter_bot_cannot_edit', __( 'The configured Shouter bot user cannot edit this post.', 'shouter' ) );
	}

	$previous_user_id = get_current_user_id();
	wp_set_current_user( $bot_user_id );

	$request = new WP_REST_Request( 'POST', '/wp-sync/v1/updates' );
	$request->set_body_params(
		array(
			'rooms' => array(
				array(
					'after'     => 0,
					'awareness' => array(
						'collaboratorInfo' => array(
							'avatar_urls'  => rest_get_avatar_urls( $bot_user->user_email ),
							'browserType'  => 'Shouter',
							'enteredAt'    => (int) floor( microtime( true ) * 1000 ),
							'id'           => $bot_user->ID,
							'name'         => $bot_user->display_name,
							'slug'         => $bot_user->user_nicename,
						),
						'editorState'      => array(
							'selection' => array(
								'type' => 'none',
							),
						),
						'shouterState'     => array(
							'postId'      => $post_id,
							'shoutedText' => $shouted_text,
						),
					),
					'client_id' => shouter_get_bot_client_id( $bot_user_id ),
					'room'      => $room,
					'updates'   => array(),
				),
			),
		)
	);

	$response = rest_do_request( $request );
	wp_set_current_user( $previous_user_id );

	if ( $response->is_error() ) {
		return $response->as_error();
	}

	return true;
}

/**
 * Emits bot awareness for solo post-room polls so Gutenberg resumes its update queue.
 */
function shouter_maybe_emit_bot_awareness_nudge( int $post_id, string $room, int $client_id, array $updates ): void {
	unset( $updates );

	if ( ! $post_id || '' === $room ) {
		return;
	}

	$bot_user_id = shouter_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return;
	}

	$bot_client_id = shouter_get_bot_client_id( $bot_user_id );
	if ( $client_id === $bot_client_id ) {
		return;
	}

	$transient_key = 'shouter_awareness_nudge_' . md5( $room );
	if ( get_transient( $transient_key ) ) {
		return;
	}

	set_transient( $transient_key, time(), SHOUTER_AWARENESS_NUDGE_TTL );

	$result = shouter_emit_bot_awareness( $post_id, $room, '' );
	shouter_log(
		'bot-rtc-awareness-nudge',
		is_wp_error( $result )
			? array(
				'ok'        => false,
				'room'      => $room,
				'post_id'   => $post_id,
				'client_id' => $client_id,
				'code'      => $result->get_error_code(),
				'message'   => $result->get_error_message(),
			)
			: array(
				'ok'            => true,
				'room'          => $room,
				'post_id'       => $post_id,
				'client_id'     => $client_id,
				'bot_client_id' => $bot_client_id,
			)
	);
}

/**
 * Emits a bot-authored paragraph insertion into a Gutenberg sync room.
 *
 * @param int                              $post_id      Post ID.
 * @param string                           $room         Sync room.
 * @param string                           $shouted_text Text to insert.
 * @param Gutenberg_RTC_Completed_Paragraph $paragraph  Completed paragraph event.
 * @return array<string, mixed>|WP_Error
 */
function shouter_emit_bot_paragraph_after( int $post_id, string $room, string $shouted_text, Gutenberg_RTC_Completed_Paragraph $paragraph ) {
	if ( ! $post_id || '' === $room ) {
		return new WP_Error( 'shouter_missing_room', __( 'Missing Shouter room.', 'shouter' ) );
	}

	if ( '' === $shouted_text ) {
		return new WP_Error( 'shouter_missing_text', __( 'Missing shouted text.', 'shouter' ) );
	}

	$bot_user_id = shouter_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return new WP_Error( 'shouter_missing_bot_user', __( 'No Shouter bot user is configured.', 'shouter' ) );
	}

	$bot_user = get_user_by( 'id', $bot_user_id );
	if ( ! $bot_user || ! user_can( $bot_user, 'edit_post', $post_id ) ) {
		return new WP_Error( 'shouter_bot_cannot_edit', __( 'The configured Shouter bot user cannot edit this post.', 'shouter' ) );
	}

	$bot_client_id = shouter_get_bot_client_id( $bot_user_id );
	$start_clock   = shouter_get_bot_clock( $post_id, $bot_client_id );
	$block_id      = wp_generate_uuid4();
	$state         = shouter_get_room_state( $post_id );
	$insert        = gutenberg_rtc_build_paragraph_insert(
		$state,
		$paragraph,
		$shouted_text,
		$bot_client_id,
		$start_clock,
		$block_id
	);
	$update        = $insert['update'];

	$previous_user_id = get_current_user_id();
	wp_set_current_user( $bot_user_id );

	$request = new WP_REST_Request( 'POST', '/wp-sync/v1/updates' );
	$request->set_body_params(
		array(
			'rooms' => array(
				array(
					'after'     => 0,
					'awareness' => shouter_build_bot_awareness( $bot_user, $post_id, $shouted_text, $bot_client_id, (int) $insert['cursor_clock'] ),
					'client_id' => $bot_client_id,
					'room'      => $room,
					'updates'   => array(
						array(
							'type' => 'update',
							'data' => base64_encode( $update ),
						),
					),
				),
			),
		)
	);

	$response = rest_do_request( $request );
	wp_set_current_user( $previous_user_id );

	if ( $response->is_error() ) {
		return $response->as_error();
	}

	try {
		$decoded = gutenberg_yjs_decode_update_v2( $update );
		gutenberg_rtc_apply_decoded_update_to_paragraph_state( $state, $decoded );
		shouter_set_room_state( $post_id, $state );
	} catch ( RuntimeException $exception ) {
		shouter_log(
			'bot-rtc-state-apply-error',
			array(
				'room'    => $room,
				'message' => $exception->getMessage(),
			)
		);
	}

	shouter_set_bot_clock( $post_id, $bot_client_id, (int) $insert['next_clock'] );

	return array(
		'ok'               => true,
		'bot_client_id'    => $bot_client_id,
		'start_clock'      => $start_clock,
		'next_clock'       => (int) $insert['next_clock'],
		'update_bytes'     => strlen( $update ),
		'block_client_id'  => $block_id,
		'left_origin'      => $insert['left_origin'],
		'right_origin'     => $insert['right_origin'],
		'response_status'  => $response->get_status(),
		'response_payload' => $response->get_data(),
	);
}

/**
 * Builds bot awareness for an inserted paragraph cursor.
 */
function shouter_build_bot_awareness( WP_User $bot_user, int $post_id, string $shouted_text, int $bot_client_id, int $ytext_type_clock ): array {
	return array(
		'collaboratorInfo' => array(
			'avatar_urls' => rest_get_avatar_urls( $bot_user->user_email ),
			'browserType' => 'Shouter',
			'enteredAt'   => (int) floor( microtime( true ) * 1000 ),
			'id'          => $bot_user->ID,
			'name'        => $bot_user->display_name,
			'slug'        => $bot_user->user_nicename,
		),
		'editorState'      => array(
			'selection' => array(
				'type'           => 'cursor',
				'cursorPosition' => array(
					'relativePosition' => array(
						'type'  => array(
							'client' => $bot_client_id,
							'clock'  => $ytext_type_clock,
						),
						'tname' => null,
						'item'  => null,
						'assoc' => 0,
					),
					'absoluteOffset'   => gutenberg_yjs_utf16_clock_len( $shouted_text ),
					'attributeKey'     => 'content',
				),
			),
		),
		'shouterState'     => array(
			'postId'      => $post_id,
			'shoutedText' => $shouted_text,
		),
	);
}

/**
 * Gets the next bot client clock for a post.
 */
function shouter_get_bot_clock( int $post_id, int $bot_client_id ): int {
	$clocks = get_post_meta( $post_id, SHOUTER_BOT_CLOCK_META_KEY, true );
	if ( ! is_array( $clocks ) ) {
		return 0;
	}

	return isset( $clocks[ (string) $bot_client_id ] ) ? max( 0, (int) $clocks[ (string) $bot_client_id ] ) : 0;
}

/**
 * Stores the next bot client clock for a post.
 */
function shouter_set_bot_clock( int $post_id, int $bot_client_id, int $clock ): void {
	$clocks = get_post_meta( $post_id, SHOUTER_BOT_CLOCK_META_KEY, true );
	if ( ! is_array( $clocks ) ) {
		$clocks = array();
	}

	$clocks[ (string) $bot_client_id ] = max( 0, $clock );
	update_post_meta( $post_id, SHOUTER_BOT_CLOCK_META_KEY, $clocks );
}

/**
 * Converts text to Shouter's shouted form.
 */
function shouter_shout_text( string $text ): string {
	return strtoupper( preg_replace( '/[!"#$%&\'()*+,\.\/:;<=>?@\[\\\\\]\^_`{|}~-]/', '!', $text ) ?? $text );
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

	$rooms = gutenberg_rtc_get_request_rooms( $request );
	if ( ! is_array( $rooms ) ) {
		shouter_log(
			'wp-sync-request',
			array(
				'route'        => $request->get_route(),
				'method'       => $request->get_method(),
				'content_type' => $request->get_header( 'content-type' ),
				'body_length'  => strlen( (string) $request->get_body() ),
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
			'body_length'  => strlen( (string) $request->get_body() ),
			'room_count'   => count( $rooms ),
			'rooms'        => gutenberg_rtc_summarize_rooms( $rooms ),
		)
	);

	shouter_maybe_emit_bot_awareness_nudges_for_rooms( $rooms );
	gutenberg_rtc_decode_rooms_for_logging( $rooms, 'shouter_log' );

	return $result;
}

/**
 * Emits immediate bot awareness for post rooms in a sync payload.
 *
 * @param array<int, mixed> $rooms Rooms payload.
 */
function shouter_maybe_emit_bot_awareness_nudges_for_rooms( array $rooms ): void {
	$bot_user_id = shouter_get_bot_user_id();
	$bot_client  = $bot_user_id ? shouter_get_bot_client_id( $bot_user_id ) : 0;

	foreach ( $rooms as $room_request ) {
		if ( ! is_array( $room_request ) ) {
			continue;
		}

		$client_id = isset( $room_request['client_id'] ) ? (int) $room_request['client_id'] : 0;
		if ( $bot_client && $client_id === $bot_client ) {
			continue;
		}

		$room    = isset( $room_request['room'] ) && is_string( $room_request['room'] ) ? $room_request['room'] : '';
		$post_id = shouter_get_post_id_from_room( $room );
		$updates = isset( $room_request['updates'] ) && is_array( $room_request['updates'] ) ? $room_request['updates'] : array();

		if ( $post_id ) {
			shouter_maybe_emit_bot_awareness_nudge( $post_id, $room, $client_id, $updates );
		}
	}
}

/**
 * Responds to accepted Gutenberg sync updates with PHP-generated bot RTC updates.
 *
 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Response.
 * @param WP_REST_Server                                   $server   Server instance.
 * @param WP_REST_Request                                  $request  Request.
 * @return mixed Unchanged response.
 */
function shouter_respond_to_wp_sync_requests( $response, WP_REST_Server $server, WP_REST_Request $request ) {
	unset( $server );

	if ( '/wp-sync/v1/updates' !== $request->get_route() || 'POST' !== $request->get_method() ) {
		return $response;
	}

	$rooms = gutenberg_rtc_get_request_rooms( $request );
	if ( ! is_array( $rooms ) ) {
		return $response;
	}

	$bot_user_id = shouter_get_bot_user_id();
	$bot_client  = $bot_user_id ? shouter_get_bot_client_id( $bot_user_id ) : 0;

	foreach ( $rooms as $room_request ) {
		if ( ! is_array( $room_request ) ) {
			continue;
		}

		$client_id = isset( $room_request['client_id'] ) ? (int) $room_request['client_id'] : 0;
		if ( $bot_client && $client_id === $bot_client ) {
			continue;
		}

		$room    = isset( $room_request['room'] ) && is_string( $room_request['room'] ) ? $room_request['room'] : '';
		$post_id = shouter_get_post_id_from_room( $room );
		$updates = isset( $room_request['updates'] ) && is_array( $room_request['updates'] ) ? $room_request['updates'] : array();

		if ( ! $post_id || empty( $updates ) ) {
			continue;
		}

		$state    = shouter_get_room_state( $post_id );
		$paragraphs = gutenberg_rtc_apply_paragraph_updates(
			$state,
			$updates,
			static function ( RuntimeException $exception ) use ( $room ): void {
				shouter_log(
					'bot-rtc-decode-error',
					array(
						'room'    => $room,
						'message' => $exception->getMessage(),
					)
				);
			}
		);

		shouter_set_room_state( $post_id, $state );

		shouter_insert_after_completed_paragraphs( $post_id, $room, $state, $paragraphs );
	}

	return $response;
}

/**
 * Applies Shouter's behavior to completed paragraph events.
 *
 * @param array<string, mixed>                      $state      Current paragraph document state.
 * @param array<int, Gutenberg_RTC_Completed_Paragraph> $paragraphs Completed paragraph events.
 */
function shouter_insert_after_completed_paragraphs( int $post_id, string $room, array &$state, array $paragraphs ): void {
	foreach ( $paragraphs as $paragraph ) {
		if ( ! $paragraph instanceof Gutenberg_RTC_Completed_Paragraph ) {
			continue;
		}

		$dedupe_key = $paragraph->dedupe_key();
		if ( isset( $state['shouted'][ $dedupe_key ] ) ) {
			continue;
		}

		$state['shouted'][ $dedupe_key ] = time();
		shouter_set_room_state( $post_id, $state );

		$result = shouter_emit_bot_paragraph_after(
			$post_id,
			$room,
			shouter_shout_text( $paragraph->text() ),
			$paragraph
		);

		shouter_log(
			'bot-rtc-auto-insert',
			is_wp_error( $result )
				? array(
					'ok'      => false,
					'room'    => $room,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
				: array_merge( array( 'room' => $room ), $result )
		);
	}
}

/**
 * Extracts a post ID from a Gutenberg post sync room.
 */
function shouter_get_post_id_from_room( string $room ): int {
	if ( preg_match( '/^postType\/post:(\d+)$/', $room, $matches ) ) {
		return (int) $matches[1];
	}

	return 0;
}

/**
 * Gets Shouter's lightweight CRDT room state.
 *
 * @return array<string, mixed>
 */
function shouter_get_room_state( int $post_id ): array {
	$state = get_post_meta( $post_id, SHOUTER_ROOM_STATE_META_KEY, true );
	if ( ! is_array( $state ) ) {
		$state = array();
	}

	if ( (int) ( $state['schema_version'] ?? 0 ) !== SHOUTER_ROOM_STATE_SCHEMA_VERSION ) {
		$state = array();
	}

	return array_merge(
		gutenberg_rtc_empty_paragraph_document_state( SHOUTER_ROOM_STATE_SCHEMA_VERSION ),
		$state
	);
}

/**
 * Stores Shouter's lightweight CRDT room state.
 */
function shouter_set_room_state( int $post_id, array $state ): void {
	update_post_meta( $post_id, SHOUTER_ROOM_STATE_META_KEY, $state );
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
