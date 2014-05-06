<?php

class GO_Quotes
{
	//Set to TRUE when we are parsing the shortcodes on save.
	public $is_save_post = FALSE;
	//Store $post_ID from save_post action.
	public $post_id      = NULL;
	public $quote_id     = 0;
	public $slug         = 'go-quotes';

	/**
	 * Initialize the plugin and register hooks.
	 */
	public function __construct()
	{
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'init', array( $this, 'init' ) );
		add_filter( 'save_post', array( $this, 'save_post' ), 10, 2 );
	}// end __construct

	/**
	 * Functions and actions to run on init
	 */
	public function init()
	{
		add_shortcode( 'pullquote', array( $this, 'pullquote_shortcode' ) );
		add_shortcode( 'quote', array( $this, 'quote_shortcode' ) );
		add_shortcode( 'blockquote', array( $this, 'blockquote_shortcode' ) );
	}

	/**
	 *	Singleton for config data
	 */
	private function config(  $key = NULL )
	{
		if ( ! isset( $this->config ) || ! $this->config )
		{
			$this->config = apply_filters(
				'go_config',
				array(
					'quote_types' => array(
							'blockquote',
							'pullquote',
							'quote',
						),
					'taxonomy'    => 'person',
				),
				$this->slug
			);
		}// end if

		if ( $key )
		{
			return isset( $this->config[ $key ] ) ? $this->config[ $key ] : NULL;
		}// end if

		return $this->config;
	}// end config


	/**
	 * lazy load the script config
	 */
	private function script_config( $key = NULL )
	{
		if ( ! isset( $this->script_config ) )
		{
			$this->script_config = apply_filters( 'go_config', array( 'version' => 1 ), 'go-script-version' );
		}// end if

		if ( $key )
		{
			return isset( $this->script_config[ $key ] ) ? $this->script_config[ $key ] : NULL;
		}// end if

		return $this->script_config;
	}// end script_config

	/* TinyMCE shizzle */

	/**
	 * Check for the rich text editor before adding the filters for our custom buttons
	 * NOTE: this won't work until we have button images
	 */
	public function admin_init()
	{
		add_filter( 'mce_external_plugins', array( $this, 'mce_external_plugins' ) );
		add_filter( 'mce_buttons', array( $this, 'mce_buttons' ) );
	}// end admin_init

	/**
	 * Load the tinymce plugin script
	 */
	public function mce_external_plugins( $plugins )
	{
		$plugins['go-quotes'] = plugins_url( 'js/go-quotes-mce.js', __FILE__ );
		return $plugins;
	}// end mce_external_plugins

	/**
	 * Add our custom buttons to the tinymce button array
	 */
	public function mce_buttons( $buttons )
	{
		//remove the default blockquote button - we're going to replace it
		unset( $buttons['b-quote'] );
		array_push( $buttons, 'separator' );

		foreach ( $this->config( 'quote_types' ) as $quote_type )
		{
			array_push( $buttons, $quote_type );
		}// end foreach

		return $buttons;
	}// end mce_buttons

	/**
	 * Load js to add quicktags buttons
	 */
	public function admin_enqueue_scripts( $hook )
	{
		//Bail if we're not on an edit page
		if ( $hook != 'edit.php' )
		{
			return;
		}// end if

		wp_enqueue_script( 'edit_form_top', plugins_url( 'js/go-quotes-qt.js', __FILE__ ), array( 'quicktags' ), $this->script_config( 'version' ) );

		wp_localize_script(
			'go-quotes-qt',
			'go_quote_types',
			array(
				'types' => $this->config( 'quote_types' ),
			)
		);
	}// end admin_enqueue_scripts

	/**
	 * Render the block-level quotes.
	 * @param string $type - the quote type
	 * @param array $atts
	 *              'attribution' adds an attribution block at the bottom of the blockquote
	 *              'person ' adds a person term
	 * @param string $content - the actual quote content
	 * @return string
	 */
	public function render_quote( $type, $atts, $content )
	{
		//check if we're saving and add person terms
		if ( isset( $atts[ 'person' ] ) && $this->is_save_post )
		{
			wp_set_post_terms( $this->post_id, $atts[ 'person' ], $this->config( 'taxonomy' ), TRUE );
			return;
		}//end if

		//bail if no content
		if ( is_null( $content ) || is_admin() )
		{
			return;
		}// end if

		$attributes = shortcode_atts(
			array(
				'attribution' => FALSE,
				'person'      => FALSE,
				),
			$atts
		);

		$person = $attributes['person'] ? str_replace( ' ', '-', $attributes['person'] ) : FALSE;
		$attribution = $attributes['attribution'] ? $attributes['attribution'] : FALSE;

		/* Not going to bother escaping it here, since we're going to escape it 6 more times in this function...thanks VIP */
		$cite_link = $person ? get_term_link( $attributes['person'], 'person' ) : '';

		ob_start();
		if ( 'pullquote' == $type || 'blockquote' == $type )
		{
			//set some defaults
			$wrapped_content               = '<p class="content">' . esc_html( $content ) . '</p>';
			$attribution_start     = '<footer><cite>';
			$attribution_end       = '</cite></footer>';
			$wrapper_start         = '';
			$wrapper_end           = '';

			switch ( $type )
			{
				case 'pullquote':
					$quote_block_start     = '<aside class="pullquote" id="quote-' . absint( ++$this->quote_id ) . '">';
					$quote_block_end       = '</aside>';
					$wrapper_start         = '<div class="boxed">';
					$wrapper_end           = '</div>';
					break;

				case 'blockquote':
					$quote_block_start     = '<blockquote id="quote-' . absint( ++$this->quote_id ) . '">';
					$quote_block_end       = '</blockquote>';
					break;

				default:
					$quote_block_start     = '<q id="quote-' . absint( ++$this->quote_id );
					$wrapped_content       = esc_html( $content );
					$quote_block_end       = '</q>';
					break;
			}//end switch

			echo $quote_block_start;

			echo $wrapper_start;

			echo $wrapped_content;

			if ( $attribution )
			{
				echo $attribution_start;

				//if we have a person term, wrap it in a cite link
				if ( $person )
				{
					if ( ! is_wp_error( $cite_link ) )
					{
						?>
						<a href='<?php echo esc_url( $cite_link ); ?>'>
						<?php
					}// end if
				}// end if
				echo esc_html( $attribution );
				if ( $person )
				{
					?>
					</a>
					<?php
				}// end if
				echo $attribution_end;
			}// end if

			echo $wrapper_end;

			echo '<div class="social">';
			echo '<a href="' . esc_url( go_local_bsocial()->build_twitter_url( $post, get_permalink( $post->ID ), esc_html( $content ), 'quote', FALSE, '#quote-' . $this->quote_id ) ). '" title="Share on Twitter" class="goicon icon-twitter-circled"></a>';
			echo '<a href="' . esc_url( go_local_bsocial()->build_facebook_url( $post, get_permalink( $post->ID ), FALSE, '#quote-' . $this->quote_id, esc_html( $content ) ) ). '" title="Share on Facebook" class="goicon icon-facebook-circled"></a>';
			echo '<a href="' . esc_url( $_SERVER['REQUEST_URI'] . '#quote-' . $this->quote_id ) . '" class="goicon icon-linkedin-circled"></a>';
			echo '</div>';
			echo $quote_block_end;
		}//end if
		else
		{
			// $type = 'quote'
			$quote_string = '<q';

			if ( $attributes['person'] )
			{
				//if we have a person term, wrap it in a cite link
				if ( ! is_wp_error( $cite_link ) )
				{
					$quote_string .= ' cite="' . esc_url( $cite_link ) . '"';
				}
			}// end if

			$quote_string .= ' id="quote-' . ++$this->quote_id . '">' . esc_html( $content ) . '</q>';

			echo $quote_string;
		}//end else
		return ob_get_clean();
	}// end render_quote

	/**
	 * Pullquote shortcode handler.
	 * @param array $atts
	 *              'attribution' adds an attribution block below the quote
	 *              'person ' adds a person term
	 * @param string $content - the actual quote content
	 * @return string
	 */
	public function pullquote_shortcode( $atts, $content )
	{
		return $this->render_quote( 'pullquote', $atts, $content );
	}// end pullquote_shortcode

	/**
	 * Blockquote shortcode handler.
	 * @param array $atts
	 *              'attribution' adds an attribution block at the bottom of the blockquote
	 *              'person ' adds a person term
	 * @param string $content - the actual quote content
	 * @return string
	 */
	public function blockquote_shortcode( $atts, $content = NULL )
	{
		return $this->render_quote( 'blockquote', $atts, $content );
	}// end blockquote_shortcode

	/**
	 * Inline quote shortcode handler.
	 * @param array $atts
	 *              'person ' adds a person term, and a cite attribute to the q tag
	 * @param string $content - the actual quote content
	 * @return string
	 */
	public function quote_shortcode( $atts, $content = NULL )
	{
		return $this->render_quote( 'quote', $atts, $content );
	}// end quote_shortcode

	/**
	 * Hooks to the save_post action and looks though the content for
	 * person attributes ( specifically person="NAME")
	 * then adds the name to the post as a person term
	 */
	public function save_post( $post_id, $post )
	{
		// check that this isn't an autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		{
			return;
		}//end if

		$this->is_save_post = TRUE;
		$this->post_id = $post_id;

		$content = $post->post_content;
		do_shortcode( $content );

		$this->is_save_post = FALSE;
		$this->post_id = NULL;
	}// end save_post
}// end class


/**
 * GO_Alerts Singleton
 */
function go_quotes()
{
	global $go_quotes;

	if ( ! $go_quotes )
	{
		$go_quotes = new GO_Quotes;
	}//end if

	return $go_quotes;
}//end go_quotes