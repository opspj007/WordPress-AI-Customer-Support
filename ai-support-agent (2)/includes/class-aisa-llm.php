<?php
defined( 'ABSPATH' ) || exit;

/** Provider adapters with a tool-calling loop. Supports Anthropic and OpenAI-compatible APIs. */
class AISA_LLM {

	const MAX_STEPS = 5;

	/**
	 * @param array    $history [ ['role'=>'user|assistant','content'=>string], ... ] starting with a user turn.
	 * @param callable $exec    function ( string $tool_name, array $input ): string
	 * @param array    $usage   Filled with 'in' and 'out' token counts.
	 * @return string|WP_Error
	 */
	public static function complete( array $history, $system, array $tools, callable $exec, array &$usage ) {
		$usage = array( 'in' => 0, 'out' => 0 );
		if ( 'openai' === AISA_Settings::get( 'provider' ) ) {
			return self::openai( $history, $system, $tools, $exec, $usage );
		}
		return self::anthropic( $history, $system, $tools, $exec, $usage );
	}

	private static function anthropic( $history, $system, $tools, $exec, &$usage ) {
		$defs = array();
		foreach ( $tools as $name => $t ) {
			$defs[] = array(
				'name'         => $name,
				'description'  => $t['description'],
				'input_schema' => $t['input_schema'],
			);
		}
		$msgs = $history;

		for ( $step = 0; $step < self::MAX_STEPS; $step++ ) {
			$body = array(
				'model'      => AISA_Settings::get( 'model' ),
				'max_tokens' => 1024,
				'system'     => $system,
				'messages'   => $msgs,
			);
			if ( $defs ) {
				$body['tools'] = $defs;
			}
			$res = self::post(
				'https://api.anthropic.com/v1/messages',
				array(
					'x-api-key'         => AISA_Settings::api_key(),
					'anthropic-version' => '2023-06-01',
				),
				$body
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$usage['in']  += (int) ( $res['usage']['input_tokens'] ?? 0 );
			$usage['out'] += (int) ( $res['usage']['output_tokens'] ?? 0 );
			$content       = isset( $res['content'] ) && is_array( $res['content'] ) ? $res['content'] : array();

			if ( 'tool_use' === ( $res['stop_reason'] ?? '' ) ) {
				foreach ( $content as &$block ) {
					if ( 'tool_use' === ( $block['type'] ?? '' ) && empty( $block['input'] ) ) {
						$block['input'] = new stdClass(); // Must serialize as {} not [].
					}
				}
				unset( $block );
				$msgs[]  = array( 'role' => 'assistant', 'content' => $content );
				$results = array();
				foreach ( $content as $block ) {
					if ( 'tool_use' === ( $block['type'] ?? '' ) ) {
						$input     = is_array( $block['input'] ) ? $block['input'] : array();
						$results[] = array(
							'type'        => 'tool_result',
							'tool_use_id' => $block['id'],
							'content'     => (string) call_user_func( $exec, $block['name'], $input ),
						);
					}
				}
				$msgs[] = array( 'role' => 'user', 'content' => $results );
				continue;
			}

			$text = '';
			foreach ( $content as $block ) {
				if ( 'text' === ( $block['type'] ?? '' ) ) {
					$text .= $block['text'];
				}
			}
			return trim( $text );
		}
		return new WP_Error( 'aisa_loop', 'Too many tool steps.' );
	}

	private static function openai( $history, $system, $tools, $exec, &$usage ) {
		$defs = array();
		foreach ( $tools as $name => $t ) {
			$defs[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $name,
					'description' => $t['description'],
					'parameters'  => $t['input_schema'],
				),
			);
		}
		$msgs = array_merge( array( array( 'role' => 'system', 'content' => $system ) ), $history );
		$url  = trailingslashit( AISA_Settings::get( 'openai_base' ) ) . 'chat/completions';

		for ( $step = 0; $step < self::MAX_STEPS; $step++ ) {
			$body = array(
				'model'    => AISA_Settings::get( 'model' ),
				'messages' => $msgs,
			);
			if ( $defs ) {
				$body['tools'] = $defs;
			}
			$res = self::post( $url, array( 'Authorization' => 'Bearer ' . AISA_Settings::api_key() ), $body );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$usage['in']  += (int) ( $res['usage']['prompt_tokens'] ?? 0 );
			$usage['out'] += (int) ( $res['usage']['completion_tokens'] ?? 0 );
			$msg           = $res['choices'][0]['message'] ?? array();

			if ( ! empty( $msg['tool_calls'] ) ) {
				$msgs[] = $msg;
				foreach ( $msg['tool_calls'] as $call ) {
					$args   = json_decode( $call['function']['arguments'] ?? '{}', true );
					$msgs[] = array(
						'role'         => 'tool',
						'tool_call_id' => $call['id'],
						'content'      => (string) call_user_func( $exec, $call['function']['name'], is_array( $args ) ? $args : array() ),
					);
				}
				continue;
			}
			return trim( (string) ( $msg['content'] ?? '' ) );
		}
		return new WP_Error( 'aisa_loop', 'Too many tool steps.' );
	}

	private static function post( $url, $headers, $body ) {
		$res = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => array_merge( array( 'content-type' => 'application/json' ), $headers ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 || ! is_array( $json ) ) {
			$msg = is_array( $json ) && isset( $json['error']['message'] ) ? $json['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'aisa_api', $msg );
		}
		return $json;
	}
}
