<?php
/**
 * Base class for all checks plus the shared audit context.
 *
 * A check knows how to look for one problem (run) and how to fix it (fix).
 * Texts are written for beginners: `explain` says what it is and why it matters,
 * `fix_does` says exactly what the button changes.
 */

defined( 'ABSPATH' ) || exit;

abstract class Plakette_Check {

	/** @var string Unique id. */
	public $id = '';
	/** @var string legal|security|speed|seo – see Plakette_Auditor::areas() */
	public $area = 'seo';
	/** @var string Group shown in the report. */
	public $group = '';
	/** @var int 1–10: how much this matters. */
	public $weight = 3;
	/** @var string Title when everything is fine (the goal). */
	public $title = '';
	/** @var string Title when the problem was found. */
	public $problem = '';
	/** @var string Short explanation for beginners. */
	public $explain = '';
	/** @var string Button text. */
	public $fix_label = '';
	/** @var string What the fix changes, in one or two sentences. */
	public $fix_does = '';
	/** @var string safe = can run in "fix all safe ones", review = look first. */
	public $risk = 'safe';
	/** @var string auto|input|items|letter */
	public $kind = 'auto';
	/** @var bool Can the protocol restore the previous state? */
	public $undoable = true;
	/** @var string Warning shown for fixes that cannot be undone (default: about deleted data). */
	public $undo_note = '';
	/** @var int Items per request for item fixes. */
	public $batch = 50;

	/**
	 * @return array status (pass|fail|warn|skip), detail, plus optional:
	 *   items[] (id,label,sub,value,link), item_field (label,max,placeholder), input (label,value,max,multiline),
	 *   letter (string), blocked (reason the fix is not possible here), kind (override).
	 */
	abstract public function run( Plakette_Context $ctx );

	/**
	 * @param array $req   ['items' => [ ['id'=>…, 'value'=>…], … ], 'value' => string]
	 * @param array $undo  Undo operations, filled by the fix.
	 * @return string|WP_Error Success message.
	 */
	public function fix( array $req, array &$undo ) {
		return new WP_Error( 'plakette_nofix', __( 'Für diesen Punkt gibt es keine automatische Behebung.', 'plakette' ) );
	}

	public function meta() {
		return array(
			'id'        => $this->id,
			'area'      => $this->area,
			'group'     => $this->group,
			'weight'    => $this->weight,
			'title'     => $this->title,
			'problem'   => $this->problem,
			'explain'   => $this->explain,
			'fix_label' => $this->fix_label,
			'fix_does'  => $this->fix_does,
			'risk'      => $this->risk,
			'kind'      => $this->kind,
			'batch'     => $this->batch,
			'undoable'  => $this->undoable,
			'undo_note' => $this->undo_note,
		);
	}

	/* Helpers for subclasses. */

	protected function pass( $detail = '', array $extra = array() ) {
		return array_merge( array( 'status' => 'pass', 'detail' => $detail ), $extra );
	}

	protected function fail( $detail, array $extra = array() ) {
		return array_merge( array( 'status' => 'fail', 'detail' => $detail ), $extra );
	}

	protected function warn( $detail, array $extra = array() ) {
		return array_merge( array( 'status' => 'warn', 'detail' => $detail ), $extra );
	}

	protected function skip( $detail ) {
		return array( 'status' => 'skip', 'detail' => $detail );
	}

	protected function no_html( Plakette_Context $ctx ) {
		$home = $ctx->home();
		return $this->skip(
			sprintf(
				/* translators: %s: error message */
				__( 'Die Startseite konnte nicht abgerufen werden (%s). Diese Prüfung braucht den echten Seiteninhalt.', 'plakette' ),
				isset( $home['error'] ) ? $home['error'] : 'HTTP ' . ( isset( $home['status'] ) ? $home['status'] : '?' )
			)
		);
	}

	/** Message for the hosting provider, ready to copy. */
	protected function letter( $request ) {
		$f = Plakette_Env::host_facts();
		return sprintf(
			/* translators: 1: request, 2: site url, 3: PHP version, 4: WordPress version, 5: web server */
			__( "Hallo,\n\nfür meine Website %2\$s bitte ich um folgende Änderung:\n\n%1\$s\n\nTechnische Angaben:\n- PHP-Version: %3\$s\n- WordPress: %4\$s\n- Webserver: %5\$s\n\nVielen Dank und freundliche Grüße", 'plakette' ),
			$request,
			$f['site'],
			$f['php'],
			$f['wp'],
			$f['server']
		);
	}

	protected static function size( $bytes ) {
		return size_format( (int) $bytes, $bytes >= MB_IN_BYTES ? 1 : 0 );
	}
}

/**
 * Data shared by all checks during one audit. Everything is fetched lazily and once.
 */
class Plakette_Context {

	private $home     = null;
	private $samples  = null;
	private $sample_html = array();
	private $posts    = null;
	private $measured = null;

	public function home() {
		if ( null === $this->home ) {
			$this->home = Plakette_Crawler::page( home_url( '/' ) );
		}
		return $this->home;
	}

	public function home_ok() {
		$home = $this->home();
		return ! empty( $home['ok'] ) && $home['status'] < 400 && ! empty( $home['facts'] );
	}

	/** @return array facts of the home page */
	public function facts() {
		$home = $this->home();
		return isset( $home['facts'] ) ? $home['facts'] : array();
	}

	/** Up to four recent posts/pages, crawled like the home page. */
	public function samples() {
		if ( null === $this->samples ) {
			$this->samples = array();
			$ids           = get_posts(
				array(
					'post_type'      => array( 'post', 'page' ),
					'post_status'    => 'publish',
					'posts_per_page' => 4,
					'orderby'        => 'modified',
					'fields'         => 'ids',
					'has_password'   => false,
					'post__not_in'   => array_filter( array( (int) get_option( 'page_on_front' ) ) ),
				)
			);
			foreach ( $ids as $id ) {
				$res = Plakette_Crawler::fetch( get_permalink( $id ) );
				if ( $res['ok'] && $res['status'] < 400 ) {
					$this->samples[ $id ]     = Plakette_Crawler::analyse( $res['body'], $res['url'] );
					$this->sample_html[ $id ] = $res['body'];
				}
			}
		}
		return $this->samples;
	}

	/** Raw HTML of the home page as a visitor gets it ('' when it could not be fetched). */
	public function html() {
		$home = $this->home();
		return isset( $home['html'] ) ? (string) $home['html'] : '';
	}

	/** Raw HTML of the home page plus the sample pages: post id (0 = home) => html. */
	public function all_html() {
		$this->samples();
		return array( 0 => $this->html() ) + $this->sample_html;
	}

	/** Cookies the home page sets for a first-time visitor (names). */
	public function cookies() {
		$home = $this->home();
		return isset( $home['cookies'] ) ? (array) $home['cookies'] : array();
	}

	/** Published posts and pages (id, type, title, content) – max 500, newest first. */
	public function posts() {
		if ( null === $this->posts ) {
			$this->posts = get_posts(
				array(
					'post_type'              => array( 'post', 'page' ),
					'post_status'            => 'publish',
					'posts_per_page'         => 500,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);
		}
		return $this->posts;
	}

	/** Result of the last measurement in the browser (or empty array). */
	public function measured() {
		if ( null === $this->measured ) {
			$this->measured = (array) get_option( 'plakette_measure', array() );
		}
		return $this->measured;
	}
}
