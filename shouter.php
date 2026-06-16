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

const SHOUTER_REST_NAMESPACE = 'shouter/v1';
const SHOUTER_OPTION_BOT_USER_ID = 'shouter_bot_user_id';
const SHOUTER_BOT_CLOCK_META_KEY = '_shouter_bot_clock';
const SHOUTER_ROOM_STATE_META_KEY = '_shouter_room_state';
const SHOUTER_AWARENESS_NUDGE_TTL = 20;
const SHOUTER_ROOM_STATE_SCHEMA_VERSION = 2;

require_once __DIR__ . '/includes/yjs-update-v2.php';

add_action( 'admin_init', 'shouter_register_settings' );
add_action( 'admin_menu', 'shouter_register_settings_page' );
add_action( 'rest_api_init', 'shouter_register_rest_routes' );
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
 * @param int    $post_id            Post ID.
 * @param string $room               Sync room.
 * @param string $shouted_text       Text to insert.
 * @param int    $left_origin_client Existing Yjs block-item client ID.
 * @param int    $left_origin_clock  Existing Yjs block-item clock.
 * @return array<string, mixed>|WP_Error
 */
function shouter_emit_bot_paragraph_after( int $post_id, string $room, string $shouted_text, int $left_origin_client, int $left_origin_clock, ?array $right_origin = null, string $source_text = '' ) {
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
	$content_insert = shouter_build_content_insert_for_shouted_paragraph( $post_id, $shouted_text, $source_text );
	$update        = shouter_yjs_encode_paragraph_insert_after_update_v2(
		$bot_client_id,
		$shouted_text,
		$block_id,
		$left_origin_client,
		$left_origin_clock,
		$start_clock,
		$right_origin,
		$content_insert
	);

	$previous_user_id = get_current_user_id();
	wp_set_current_user( $bot_user_id );

	$request = new WP_REST_Request( 'POST', '/wp-sync/v1/updates' );
	$request->set_body_params(
		array(
			'rooms' => array(
				array(
					'after'     => 0,
					'awareness' => shouter_build_bot_awareness( $bot_user, $post_id, $shouted_text, $bot_client_id, $start_clock + 4 ),
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
		$state   = shouter_get_room_state( $post_id );
		$decoded = shouter_yjs_decode_update_v2( $update );
		shouter_apply_decoded_update_to_room_state( $state, $decoded );
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

	$clock_len = shouter_yjs_paragraph_insert_clock_len( $shouted_text, $content_insert );
	shouter_set_bot_clock( $post_id, $bot_client_id, $start_clock + $clock_len );

	return array(
		'ok'               => true,
		'bot_client_id'    => $bot_client_id,
		'start_clock'      => $start_clock,
		'next_clock'       => $start_clock + $clock_len,
		'update_bytes'     => strlen( $update ),
		'block_client_id'  => $block_id,
		'left_origin'      => array(
			'client' => $left_origin_client,
			'clock'  => $left_origin_clock,
		),
		'right_origin'     => $right_origin,
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
					'absoluteOffset'   => shouter_yjs_utf16_clock_len( $shouted_text ),
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
		'/insert-after',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'shouter_handle_insert_after',
			'permission_callback' => 'shouter_check_permissions',
			'args'                => array(
				'post_id'            => array(
					'required' => true,
					'type'     => 'integer',
				),
				'room'               => array(
					'required' => true,
					'type'     => 'string',
				),
				'text'               => array(
					'required' => true,
					'type'     => 'string',
				),
				'left_origin_client' => array(
					'required' => true,
					'type'     => 'integer',
				),
				'left_origin_clock'  => array(
					'required' => true,
					'type'     => 'integer',
				),
				'right_origin_client' => array(
					'required' => false,
					'type'     => 'integer',
				),
				'right_origin_clock'  => array(
					'required' => false,
					'type'     => 'integer',
				),
				'shouted'            => array(
					'required' => false,
					'type'     => 'boolean',
				),
			),
		)
	);
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
			'rooms'        => shouter_summarize_rooms( $rooms ),
		)
	);

	shouter_maybe_emit_bot_awareness_nudges_for_rooms( $rooms );
	shouter_decode_rooms_for_logging( $rooms );

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

	$rooms = shouter_get_request_rooms( $request );
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
		$triggers = array();

		foreach ( $updates as $update ) {
			if ( ! is_array( $update ) || ( $update['type'] ?? '' ) !== 'update' || empty( $update['data'] ) || ! is_string( $update['data'] ) ) {
				continue;
			}

			$binary = base64_decode( $update['data'], true );
			if ( false === $binary ) {
				continue;
			}

			try {
				$decoded = shouter_yjs_decode_update_v2( $binary );
			} catch ( RuntimeException $exception ) {
				shouter_log(
					'bot-rtc-decode-error',
					array(
						'room'    => $room,
						'message' => $exception->getMessage(),
					)
				);
				continue;
			}

			$triggers = array_merge( $triggers, shouter_apply_decoded_update_to_room_state( $state, $decoded ) );
		}

		shouter_set_room_state( $post_id, $state );

		foreach ( $triggers as $trigger ) {
			$dedupe_key = $trigger['source_block_id'] . ':' . md5( $trigger['source_text'] );
			if ( isset( $state['shouted'][ $dedupe_key ] ) ) {
				continue;
			}

			$state['shouted'][ $dedupe_key ] = time();
			shouter_set_room_state( $post_id, $state );

			$result = shouter_emit_bot_paragraph_after(
				$post_id,
				$room,
				shouter_shout_text( $trigger['source_text'] ),
				(int) $trigger['left_origin']['client'],
				(int) $trigger['left_origin']['clock'],
				$trigger['right_origin'],
				(string) $trigger['source_text']
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

	return $response;
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
 * Development endpoint for emitting a PHP-generated bot RTC paragraph insert.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function shouter_handle_insert_after( WP_REST_Request $request ): WP_REST_Response {
	$text = (string) $request->get_param( 'text' );
	if ( ! $request->get_param( 'shouted' ) ) {
		$text = shouter_shout_text( $text );
	}

	$result = shouter_emit_bot_paragraph_after(
		(int) $request->get_param( 'post_id' ),
		(string) $request->get_param( 'room' ),
		$text,
		(int) $request->get_param( 'left_origin_client' ),
		(int) $request->get_param( 'left_origin_clock' ),
		null !== $request->get_param( 'right_origin_client' ) && null !== $request->get_param( 'right_origin_clock' )
			? array(
				'client' => (int) $request->get_param( 'right_origin_client' ),
				'clock'  => (int) $request->get_param( 'right_origin_clock' ),
			)
			: null
	);

	shouter_log(
		'bot-rtc-insert',
		is_wp_error( $result )
			? array(
				'ok'      => false,
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			)
			: $result
	);

	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response(
			array(
				'ok'      => false,
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			),
			400
		);
	}

	return new WP_REST_Response( $result, 200 );
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
		array(
			'schema_version'      => SHOUTER_ROOM_STATE_SCHEMA_VERSION,
			'blocks'              => array(),
			'attributes_to_block' => array(),
			'root_content'        => '',
			'root_content_items'  => array(),
			'root_content_text'   => null,
			'text_to_block'       => array(),
			'text_items_to_block' => array(),
			'shouted'             => array(),
		),
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
 * Applies decoded Yjs structs to Shouter's lightweight room state.
 *
 * @param array<string, mixed> $state   State, mutated in place.
 * @param array<string, mixed> $decoded Decoded update.
 * @return array<int, array<string, mixed>> Shout triggers.
 */
function shouter_apply_decoded_update_to_room_state( array &$state, array $decoded ): array {
	$triggers = array();
	$structs  = isset( $decoded['structs'] ) && is_array( $decoded['structs'] ) ? $decoded['structs'] : array();

	foreach ( $structs as $struct ) {
		if ( ! is_array( $struct ) || ( $struct['kind'] ?? '' ) !== 'item' ) {
			continue;
		}

		$id      = shouter_yjs_id_key( $struct['id'] ?? null );
		$content = isset( $struct['content'] ) && is_array( $struct['content'] ) ? $struct['content'] : array();

		if ( 'type' === ( $content['type'] ?? '' ) && 'Y.Map' === ( $content['name'] ?? '' ) ) {
			shouter_room_state_note_map_item( $state, $struct, $id );
		}

		$parent_sub = isset( $struct['parent_sub'] ) && is_string( $struct['parent_sub'] ) ? $struct['parent_sub'] : null;
		$parent_key = shouter_yjs_parent_key( $struct['parent'] ?? null );

		if ( $parent_sub ) {
			shouter_room_state_note_root_field( $state, $struct, $id, $parent_sub );
		}

		if ( $parent_sub && $parent_key ) {
			shouter_room_state_note_map_field( $state, $struct, $id, $parent_key, $parent_sub );
		}

		if ( 'string' === ( $content['type'] ?? '' ) ) {
			shouter_room_state_note_text_item( $state, $struct, $id );
			shouter_room_state_note_root_content_item( $state, $struct, $id );
		}
	}

	foreach ( $state['blocks'] as $block_id => $block ) {
		if (
			isset( $block['name'], $block['origin'] ) &&
			'core/paragraph' === $block['name'] &&
			'' === trim( (string) ( $block['content'] ?? '' ) ) &&
			empty( $block['trigger_checked'] )
		) {
			$state['blocks'][ $block_id ]['trigger_checked'] = true;
			$origin_key = shouter_yjs_id_key( $block['origin'] );
			$source     = $origin_key && isset( $state['blocks'][ $origin_key ] ) ? $state['blocks'][ $origin_key ] : null;
			$source_text = is_array( $source ) ? trim( (string) ( $source['content'] ?? '' ) ) : '';

			if ( $source_text && isset( $source['name'] ) && 'core/paragraph' === $source['name'] ) {
				$triggers[] = array(
					'source_block_id' => $origin_key,
					'source_text'     => $source_text,
					'left_origin'     => $block['origin'],
					'right_origin'    => shouter_yjs_id_from_key( $block_id ),
				);
			}
		}
	}

	return $triggers;
}

/**
 * Notes a root document field item.
 */
function shouter_room_state_note_root_field( array &$state, array $struct, string $id, string $parent_sub ): void {
	$content = isset( $struct['content'] ) && is_array( $struct['content'] ) ? $struct['content'] : array();
	$parent  = isset( $struct['parent'] ) && is_array( $struct['parent'] ) ? $struct['parent'] : null;

	if (
		'content' === $parent_sub &&
		'root' === ( $parent['type'] ?? '' ) &&
		'document' === ( $parent['key'] ?? '' ) &&
		'type' === ( $content['type'] ?? '' ) &&
		'Y.Text' === ( $content['name'] ?? '' )
	) {
		$state['root_content_text'] = shouter_yjs_id_from_key( $id );
	}
}

/**
 * Notes a Y.Map item that might be a block or nested block structure.
 */
function shouter_room_state_note_map_item( array &$state, array $struct, string $id ): void {
	if ( '' === $id ) {
		return;
	}

	if ( ! isset( $state['blocks'][ $id ] ) ) {
		$state['blocks'][ $id ] = array(
			'id'      => shouter_yjs_id_from_key( $id ),
			'content' => '',
		);
	}

	if ( isset( $struct['origin'] ) && is_array( $struct['origin'] ) ) {
		$state['blocks'][ $id ]['origin'] = $struct['origin'];
	}

	if ( isset( $struct['right_origin'] ) && is_array( $struct['right_origin'] ) ) {
		$state['blocks'][ $id ]['right_origin'] = $struct['right_origin'];
	}
}

/**
 * Notes a Y.Map field item.
 */
function shouter_room_state_note_map_field( array &$state, array $struct, string $id, string $parent_key, string $parent_sub ): void {
	$content = isset( $struct['content'] ) && is_array( $struct['content'] ) ? $struct['content'] : array();

	if ( 'name' === $parent_sub && 'any' === ( $content['type'] ?? '' ) && isset( $content['values'][0] ) ) {
		$state['blocks'][ $parent_key ]['name'] = (string) $content['values'][0];
		return;
	}

	if ( 'clientId' === $parent_sub && 'any' === ( $content['type'] ?? '' ) && isset( $content['values'][0] ) ) {
		$state['blocks'][ $parent_key ]['clientId'] = (string) $content['values'][0];
		return;
	}

	if ( 'attributes' === $parent_sub && 'type' === ( $content['type'] ?? '' ) && 'Y.Map' === ( $content['name'] ?? '' ) ) {
		$state['attributes_to_block'][ $id ] = $parent_key;
		return;
	}

	if ( 'content' === $parent_sub && 'type' === ( $content['type'] ?? '' ) && 'Y.Text' === ( $content['name'] ?? '' ) && isset( $state['attributes_to_block'][ $parent_key ] ) ) {
		$state['text_to_block'][ $id ] = $state['attributes_to_block'][ $parent_key ];
	}
}

/**
 * Notes a Y.Text string item.
 */
function shouter_room_state_note_text_item( array &$state, array $struct, string $id ): void {
	$content = isset( $struct['content'] ) && is_array( $struct['content'] ) ? $struct['content'] : array();
	$text    = isset( $content['value'] ) ? (string) $content['value'] : '';
	$block   = null;
	$parent  = shouter_yjs_parent_key( $struct['parent'] ?? null );

	if ( $parent && isset( $state['text_to_block'][ $parent ] ) ) {
		$block = $state['text_to_block'][ $parent ];
	} elseif ( isset( $struct['origin'] ) && is_array( $struct['origin'] ) ) {
		$origin_key = shouter_find_text_item_block_by_origin( $state, $struct['origin'] );
		$block      = $origin_key;
	}

	if ( ! $block ) {
		return;
	}

	$state['blocks'][ $block ]['content'] = (string) ( $state['blocks'][ $block ]['content'] ?? '' ) . $text;
	$state['text_items_to_block'][ $id ]  = array(
		'block'  => $block,
		'length' => (int) ( $struct['length'] ?? shouter_yjs_utf16_clock_len( $text ) ),
		'origin' => isset( $struct['origin'] ) && is_array( $struct['origin'] ) ? $struct['origin'] : null,
		'text'   => $text,
	);
	$state['blocks'][ $block ]['content'] = shouter_reconstruct_block_text_from_items( $state, $block );
}

/**
 * Reconstructs a block's text by following Yjs item origins.
 */
function shouter_reconstruct_block_text_from_items( array $state, string $block_id ): string {
	$children = array();
	$nodes    = array();

	foreach ( $state['text_items_to_block'] as $item_key => $item ) {
		if ( ! is_array( $item ) || (string) ( $item['block'] ?? '' ) !== $block_id || ! isset( $item['text'] ) ) {
			continue;
		}

		$item_id = shouter_yjs_id_from_key( (string) $item_key );
		if ( ! $item_id ) {
			continue;
		}

		$chars = preg_split( '//u', (string) $item['text'], -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) || empty( $chars ) ) {
			continue;
		}

		foreach ( $chars as $index => $char ) {
			$id = array(
				'client' => (int) $item_id['client'],
				'clock'  => (int) $item_id['clock'] + $index,
			);
			$id_key = shouter_yjs_id_key( $id );
			$origin = 0 === $index
				? ( isset( $item['origin'] ) && is_array( $item['origin'] ) ? $item['origin'] : null )
				: array(
					'client' => (int) $item_id['client'],
					'clock'  => (int) $item_id['clock'] + $index - 1,
				);
			$origin_key = $origin ? shouter_yjs_id_key( $origin ) : '';

			$nodes[ $id_key ]      = array(
				'id'   => $id,
				'text' => $char,
			);
			$children[ $origin_key ][] = $id_key;
		}
	}

	foreach ( $children as &$child_keys ) {
		usort(
			$child_keys,
			static function ( string $a, string $b ) use ( $nodes ): int {
				$a_id = $nodes[ $a ]['id'];
				$b_id = $nodes[ $b ]['id'];
				return ( (int) $a_id['client'] <=> (int) $b_id['client'] ) ?: ( (int) $a_id['clock'] <=> (int) $b_id['clock'] );
			}
		);
	}
	unset( $child_keys );

	$text = '';
	$walk = static function ( string $origin_key ) use ( &$walk, &$text, $children, $nodes ): void {
		foreach ( $children[ $origin_key ] ?? array() as $child_key ) {
			$text .= $nodes[ $child_key ]['text'];
			$walk( $child_key );
		}
	};

	$walk( '' );

	return $text;
}

/**
 * Notes a string item in the root document.content Y.Text.
 */
function shouter_room_state_note_root_content_item( array &$state, array $struct, string $id ): void {
	$root_content_text = isset( $state['root_content_text'] ) && is_array( $state['root_content_text'] ) ? $state['root_content_text'] : null;
	$parent            = shouter_yjs_parent_key( $struct['parent'] ?? null );
	$root_content_key  = $root_content_text ? shouter_yjs_id_key( $root_content_text ) : '';

	if ( ! $root_content_key || $parent !== $root_content_key ) {
		return;
	}

	$content = isset( $struct['content'] ) && is_array( $struct['content'] ) ? $struct['content'] : array();
	$text    = isset( $content['value'] ) ? (string) $content['value'] : '';
	$length  = (int) ( $struct['length'] ?? shouter_yjs_utf16_clock_len( $text ) );

	$state['root_content'] .= $text;
	$state['root_content_items'][ $id ] = array(
		'offset' => shouter_yjs_utf16_clock_len( (string) $state['root_content'] ) - $length,
		'length' => $length,
		'text'   => $text,
	);
}

/**
 * Builds a serialized-content insertion descriptor for a shouted paragraph.
 *
 * @return array<string, mixed>|null
 */
function shouter_build_content_insert_for_shouted_paragraph( int $post_id, string $shouted_text, string $source_text = '' ): ?array {
	$state             = shouter_get_room_state( $post_id );
	$root_content_text = isset( $state['root_content_text'] ) && is_array( $state['root_content_text'] ) ? $state['root_content_text'] : null;
	$root_content      = isset( $state['root_content'] ) ? (string) $state['root_content'] : '';

	if ( ! $root_content_text ) {
		return null;
	}

	if ( '' === $root_content && '' !== $source_text ) {
		return array(
			'parent'       => $root_content_text,
			'origin'       => null,
			'right_origin' => null,
			'text'         => shouter_serialize_paragraph_block( $source_text ) . "\n\n" . shouter_serialize_paragraph_block( $shouted_text ) . "\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->",
		);
	}

	if ( '' === $root_content ) {
		return null;
	}

	$serialized = "\n\n" . shouter_serialize_paragraph_block( $shouted_text );
	$offset     = strlen( $root_content );

	if ( preg_match( '/\n\n<!-- wp:paragraph(?:\\s+\\/| -->\n<p><\\/p>\n<!-- \\/wp:paragraph -->)\\s*$/', $root_content, $matches, PREG_OFFSET_CAPTURE ) ) {
		$offset = (int) $matches[0][1];
	}

	$position = shouter_root_content_position_to_yjs_ids( $state, $offset );
	if ( ! $position ) {
		return null;
	}

	return array(
		'parent'       => $root_content_text,
		'origin'       => $position['origin'],
		'right_origin' => $position['right_origin'],
		'text'         => $serialized,
	);
}

/**
 * Serializes plain paragraph text as Gutenberg paragraph block markup.
 */
function shouter_serialize_paragraph_block( string $text ): string {
	return "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";
}

/**
 * Converts a root content UTF-8 byte offset to approximate Yjs neighbor IDs.
 *
 * @return array{origin:?array<string,int>,right_origin:?array<string,int>}|null
 */
function shouter_root_content_position_to_yjs_ids( array $state, int $offset ): ?array {
	$items = isset( $state['root_content_items'] ) && is_array( $state['root_content_items'] ) ? $state['root_content_items'] : array();
	if ( empty( $items ) ) {
		return array(
			'origin'       => null,
			'right_origin' => null,
		);
	}

	uasort(
		$items,
		static function ( $a, $b ): int {
			return (int) ( $a['offset'] ?? 0 ) <=> (int) ( $b['offset'] ?? 0 );
		}
	);

	$origin       = null;
	$right_origin = null;
	$utf16_offset = shouter_yjs_utf16_clock_len( substr( (string) ( $state['root_content'] ?? '' ), 0, $offset ) );

	foreach ( $items as $item_key => $item ) {
		$item_id = shouter_yjs_id_from_key( (string) $item_key );
		if ( ! $item_id ) {
			continue;
		}

		$start = (int) ( $item['offset'] ?? 0 );
		$end   = $start + (int) ( $item['length'] ?? 0 );
		if ( $utf16_offset <= $start ) {
			$right_origin = $item_id;
			break;
		}

		if ( $utf16_offset <= $end ) {
			$relative = max( 0, $utf16_offset - $start );
			if ( $relative > 0 ) {
				$origin = array(
					'client' => (int) $item_id['client'],
					'clock'  => (int) $item_id['clock'] + $relative - 1,
				);
			}
			if ( $relative < (int) ( $item['length'] ?? 0 ) ) {
				$right_origin = array(
					'client' => (int) $item_id['client'],
					'clock'  => (int) $item_id['clock'] + $relative,
				);
			}
			break;
		}

		$origin = array(
			'client' => (int) $item_id['client'],
			'clock'  => (int) $item_id['clock'] + (int) ( $item['length'] ?? 0 ) - 1,
		);
	}

	return array(
		'origin'       => $origin,
		'right_origin' => $right_origin,
	);
}

/**
 * Finds the block owning a text item origin.
 */
function shouter_find_text_item_block_by_origin( array $state, array $origin ): ?string {
	$client = isset( $origin['client'] ) ? (int) $origin['client'] : 0;
	$clock  = isset( $origin['clock'] ) ? (int) $origin['clock'] : 0;

	foreach ( $state['text_items_to_block'] as $item_key => $item ) {
		$item_id = shouter_yjs_id_from_key( (string) $item_key );
		if ( ! $item_id || (int) $item_id['client'] !== $client ) {
			continue;
		}

		$start = (int) $item_id['clock'];
		$end   = $start + (int) ( $item['length'] ?? 0 );
		if ( $clock >= $start && $clock < $end ) {
			return isset( $item['block'] ) ? (string) $item['block'] : null;
		}
	}

	return null;
}

/**
 * Builds a stable key for a Yjs ID.
 */
function shouter_yjs_id_key( $id ): string {
	if ( ! is_array( $id ) || ! isset( $id['client'], $id['clock'] ) ) {
		return '';
	}

	return (int) $id['client'] . ':' . (int) $id['clock'];
}

/**
 * Builds a stable key for a Yjs parent ID descriptor.
 */
function shouter_yjs_parent_key( $parent ): string {
	if ( ! is_array( $parent ) || ( $parent['type'] ?? '' ) !== 'id' ) {
		return '';
	}

	return shouter_yjs_id_key( $parent );
}

/**
 * Parses a stable Yjs ID key.
 *
 * @return array{client:int,clock:int}|null
 */
function shouter_yjs_id_from_key( string $key ): ?array {
	if ( ! preg_match( '/^(\d+):(\d+)$/', $key, $matches ) ) {
		return null;
	}

	return array(
		'client' => (int) $matches[1],
		'clock'  => (int) $matches[2],
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
		try {
			$result['y_update_v2_structs'] = shouter_summarize_decoded_yjs_update( shouter_yjs_decode_update_v2( $binary ) );
		} catch ( RuntimeException $exception ) {
			$result['y_update_v2_decode_error'] = $exception->getMessage();
		}
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
 * Summarizes decoded Yjs structs for logs.
 *
 * @param array<string, mixed> $decoded Decoded update.
 * @return array<string, mixed>
 */
function shouter_summarize_decoded_yjs_update( array $decoded ): array {
	$items = array();

	foreach ( $decoded['structs'] ?? array() as $struct ) {
		if ( ! is_array( $struct ) || ( $struct['kind'] ?? '' ) !== 'item' ) {
			continue;
		}

		$content = isset( $struct['content'] ) && is_array( $struct['content'] ) ? $struct['content'] : array();
		$item    = array(
			'id'           => shouter_yjs_id_key( $struct['id'] ?? null ),
			'origin'       => shouter_yjs_id_key( $struct['origin'] ?? null ),
			'right_origin' => shouter_yjs_id_key( $struct['right_origin'] ?? null ),
			'parent'       => shouter_yjs_parent_key( $struct['parent'] ?? null ),
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
