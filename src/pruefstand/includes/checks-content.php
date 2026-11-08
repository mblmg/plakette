<?php
/**
 * SEO checks that look at every published post and page.
 */

defined( 'ABSPATH' ) || exit;

class Pruefstand_Check_Alt_Text extends Pruefstand_Check {
	public $id        = 'content_alt';
	public $group     = 'Inhalte';
	public $weight    = 5;
	public $title     = 'Bilder haben einen Alternativtext';
	public $problem   = 'Bilder ohne Alternativtext';
	public $explain   = 'Der Alternativtext beschreibt ein Bild in wenigen Worten. Blinde Menschen hören ihn über ihren Vorleser, und Google versteht nur durch ihn, was auf dem Bild zu sehen ist – wichtig für die Bildersuche.';
	public $fix_label = 'Alternativtexte speichern';
	public $fix_does  = 'Trägt die Texte direkt in deine Beiträge und in die Mediathek ein. Die Vorschläge stammen aus Bildtitel oder Dateiname – prüfe sie kurz, gute Beschreibungen sagen, was zu sehen ist.';
	public $risk      = 'review';
	public $kind      = 'items';

	public function find( Pruefstand_Context $ctx ) {
		$items = array();
		$seen  = array();
		foreach ( $ctx->posts() as $post ) {
			$p = new WP_HTML_Tag_Processor( $post->post_content );
			$n = 0;
			while ( $p->next_tag( 'img' ) ) {
				$alt = $p->get_attribute( 'alt' );
				if ( null === $alt || '' === trim( (string) $alt ) ) {
					$src    = (string) $p->get_attribute( 'src' );
					$att_id = preg_match( '/\bwp-image-(\d+)\b/', (string) $p->get_attribute( 'class' ), $m ) ? (int) $m[1] : 0;
					if ( ! $att_id ) {
						$att_id = attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $src ) );
					}
					$items[] = array(
						'id'    => 'p:' . $post->ID . ':' . $n,
						'label' => wp_basename( wp_parse_url( $src, PHP_URL_PATH ) ),
						'sub'   => sprintf( __( 'in „%s“', 'pruefstand' ), get_the_title( $post ) ),
						'thumb' => $src,
						'value' => self::suggest( $att_id, $src, $post ),
						'link'  => get_edit_post_link( $post->ID, 'raw' ),
					);
					if ( $att_id ) {
						$seen[ $att_id ] = true;
					}
				}
				++$n;
				if ( count( $items ) >= 300 ) {
					break 2;
				}
			}
			$thumb = (int) get_post_thumbnail_id( $post );
			if ( $thumb && ! isset( $seen[ $thumb ] ) && '' === trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) ) ) {
				$seen[ $thumb ] = true;
				$items[]        = array(
					'id'    => 'a:' . $thumb,
					'label' => wp_basename( (string) get_attached_file( $thumb ) ),
					'sub'   => sprintf( __( 'Beitragsbild von „%s“', 'pruefstand' ), get_the_title( $post ) ),
					'thumb' => wp_get_attachment_image_url( $thumb, 'thumbnail' ),
					'value' => self::suggest( $thumb, '', $post ),
					'link'  => get_edit_post_link( $thumb, 'raw' ),
				);
			}
		}
		return $items;
	}

	/** Best available description: existing alt, a real title, caption, a readable file name, then the post title. */
	public static function suggest( $att_id, $src, $post ) {
		if ( $att_id ) {
			$alt = trim( (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ) );
			if ( $alt ) {
				return $alt;
			}
			$caption = trim( wp_strip_all_tags( (string) wp_get_attachment_caption( $att_id ) ) );
			if ( $caption ) {
				return Pruefstand_Modules::shorten( $caption, 120 );
			}
			$title = trim( (string) get_the_title( $att_id ) );
			$human = self::humanize( $title );
			if ( $human ) {
				return $human;
			}
			$src = (string) get_attached_file( $att_id );
		}
		$human = self::humanize( pathinfo( (string) wp_parse_url( $src, PHP_URL_PATH ), PATHINFO_FILENAME ) );
		if ( $human ) {
			return $human;
		}
		return $post ? wp_strip_all_tags( get_the_title( $post ) ) : '';
	}

	/** "kuchen-mit-erdbeeren-1024x768" → "Kuchen mit erdbeeren"; camera names like IMG_1234 → ''. */
	public static function humanize( $name ) {
		$name = preg_replace( '/-(\d+x\d+|scaled|e\d{10,})$/i', '', (string) $name );
		$name = trim( preg_replace( '/[\s_\-.]+/', ' ', $name ) );
		if ( '' === $name || preg_match( '/^(img|dsc|dscn|dcim|pxl|mvimg|photo|foto|image|bild|screenshot|bildschirmfoto|whatsapp|unbenannt|untitled)\b/i', $name ) ) {
			return '';
		}
		$letters = preg_replace( '/[^\p{L}]/u', '', $name );
		if ( mb_strlen( $letters ) < 4 || preg_match( '/\d{4,}/', $name ) ) {
			return '';
		}
		return mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 );
	}

	public function run( Pruefstand_Context $ctx ) {
		$items = $this->find( $ctx );
		if ( ! $items ) {
			return $this->pass( __( 'Alle Bilder in Beiträgen und Seiten haben einen Alternativtext.', 'pruefstand' ) );
		}
		return $this->fail(
			sprintf( _n( '%d Bild ohne Alternativtext.', '%d Bilder ohne Alternativtext.', count( $items ), 'pruefstand' ), count( $items ) ),
			array(
				'items'      => $items,
				'item_field' => array( 'label' => __( 'Was ist auf dem Bild zu sehen?', 'pruefstand' ), 'max' => 125 ),
			)
		);
	}

	public function fix( array $req, array &$undo ) {
		$by_post = array();
		$done    = 0;
		foreach ( (array) ( isset( $req['items'] ) ? $req['items'] : array() ) as $item ) {
			$value = sanitize_text_field( isset( $item['value'] ) ? $item['value'] : '' );
			$id    = isset( $item['id'] ) ? (string) $item['id'] : '';
			if ( '' === $value ) {
				continue;
			}
			if ( preg_match( '/^a:(\d+)$/', $id, $m ) && wp_attachment_is_image( (int) $m[1] ) && current_user_can( 'edit_post', (int) $m[1] ) ) {
				Pruefstand_Log::set_postmeta( (int) $m[1], '_wp_attachment_image_alt', $value, $undo );
				++$done;
			} elseif ( preg_match( '/^p:(\d+):(\d+)$/', $id, $m ) ) {
				$by_post[ (int) $m[1] ][ (int) $m[2] ] = $value;
			}
		}
		foreach ( $by_post as $post_id => $alts ) {
			$post = get_post( $post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$p = new WP_HTML_Tag_Processor( $post->post_content );
			$n = 0;
			while ( $p->next_tag( 'img' ) ) {
				if ( isset( $alts[ $n ] ) ) {
					$p->set_attribute( 'alt', $alts[ $n ] );
					++$done;
					// Also store it in the media library, so the next use of this image has it too.
					if ( preg_match( '/\bwp-image-(\d+)\b/', (string) $p->get_attribute( 'class' ), $m ) && '' === trim( (string) get_post_meta( (int) $m[1], '_wp_attachment_image_alt', true ) ) ) {
						Pruefstand_Log::set_postmeta( (int) $m[1], '_wp_attachment_image_alt', $alts[ $n ], $undo );
					}
				}
				++$n;
			}
			$undo[] = array( 'post_field', $post_id, 'post_content', $post->post_content );
			wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $p->get_updated_html() ) ) );
		}
		return sprintf( _n( '%d Alternativtext gespeichert.', '%d Alternativtexte gespeichert.', $done, 'pruefstand' ), $done );
	}
}

class Pruefstand_Check_Post_Descriptions extends Pruefstand_Check {
	public $id        = 'content_descriptions';
	public $group     = 'Inhalte';
	public $weight    = 6;
	public $title     = 'Beiträge und Seiten haben eine Beschreibung für Google';
	public $problem   = 'Beiträge und Seiten ohne Beschreibung für Google';
	public $explain   = 'Wie bei der Startseite: Die Meta-Beschreibung ist der kurze Text unter dem Titel in den Google-Ergebnissen. Ohne sie zeigt Google irgendeinen Ausschnitt – oft mitten im Satz.';
	public $fix_label = 'Beschreibungen speichern';
	public $fix_does  = 'Speichert die Beschreibungen (Vorschlag: der Anfang des Textes) und gibt sie unsichtbar im Kopf jeder Seite aus. Neue Beiträge bekommen automatisch eine.';
	public $kind      = 'items';

	public function run( Pruefstand_Context $ctx ) {
		$samples = $ctx->samples();
		$missing = 0;
		foreach ( $samples as $facts ) {
			if ( '' === (string) $facts['description'] ) {
				++$missing;
			}
		}
		if ( ! $samples ) {
			if ( ! $ctx->posts() ) {
				return $this->skip( __( 'Noch keine veröffentlichten Beiträge oder Seiten.', 'pruefstand' ) );
			}
			if ( Pruefstand_Modules::on( 'meta_desc' ) && ! Pruefstand_Env::seo_plugin() ) {
				return $this->pass( __( 'Prüfstand gibt für alle Seiten eine Beschreibung aus.', 'pruefstand' ) );
			}
		} elseif ( ! $missing ) {
			return $this->pass( sprintf( __( '%d zufällig geprüfte Seiten haben eine Beschreibung.', 'pruefstand' ), count( $samples ) ) );
		}
		if ( Pruefstand_Env::seo_plugin() ) {
			return $this->fail(
				sprintf( __( '%1$d von %2$d geprüften Seiten haben keine Beschreibung.', 'pruefstand' ), $missing, count( $samples ) ),
				array( 'kind' => 'auto', 'blocked' => sprintf( __( 'Trage die Beschreibungen im Bearbeitungsfenster jedes Beitrags bei %s ein.', 'pruefstand' ), Pruefstand_Env::seo_plugin() ) )
			);
		}
		$items = array();
		foreach ( array_slice( $ctx->posts(), 0, 200 ) as $post ) {
			if ( (int) get_option( 'page_on_front' ) === $post->ID || get_post_meta( $post->ID, '_pruefstand_desc', true ) ) {
				continue;
			}
			$items[] = array(
				'id'    => 'post:' . $post->ID,
				'label' => get_the_title( $post ),
				'sub'   => 'page' === $post->post_type ? __( 'Seite', 'pruefstand' ) : __( 'Beitrag', 'pruefstand' ),
				'value' => Pruefstand_Modules::generate_description( $post->ID ),
				'link'  => get_permalink( $post ),
			);
		}
		return $this->fail(
			$samples ? sprintf( __( '%1$d von %2$d geprüften Seiten haben keine Beschreibung.', 'pruefstand' ), $missing, count( $samples ) ) : __( 'Keine Beschreibungen gefunden.', 'pruefstand' ),
			array(
				'items'      => $items,
				'item_field' => array( 'label' => __( 'Beschreibung (120–155 Zeichen)', 'pruefstand' ), 'max' => 160, 'multiline' => true ),
			)
		);
	}

	public function fix( array $req, array &$undo ) {
		if ( ! Pruefstand_Modules::on( 'meta_desc' ) ) {
			Pruefstand_Log::set_module( 'meta_desc', true, $undo );
		}
		$done = 0;
		foreach ( (array) ( isset( $req['items'] ) ? $req['items'] : array() ) as $item ) {
			if ( ! preg_match( '/^post:(\d+)$/', isset( $item['id'] ) ? (string) $item['id'] : '', $m ) || ! current_user_can( 'edit_post', (int) $m[1] ) ) {
				continue;
			}
			$value = trim( preg_replace( '/\s+/', ' ', sanitize_textarea_field( isset( $item['value'] ) ? $item['value'] : '' ) ) );
			if ( '' !== $value ) {
				Pruefstand_Log::set_postmeta( (int) $m[1], '_pruefstand_desc', $value, $undo );
				++$done;
			}
		}
		return sprintf( _n( '%d Beschreibung gespeichert.', '%d Beschreibungen gespeichert.', $done, 'pruefstand' ), $done );
	}
}

class Pruefstand_Check_Titles extends Pruefstand_Check {
	public $id        = 'content_titles';
	public $group     = 'Inhalte';
	public $weight    = 4;
	public $title     = 'Eindeutige Titel in passender Länge';
	public $problem   = 'Titel fehlen, sind doppelt oder zu lang';
	public $explain   = 'Jede Seite braucht einen eigenen Titel. Doppelte Titel verwirren Google (welche Seite ist die richtige?), und Titel über 60 Zeichen werden in den Suchergebnissen abgeschnitten.';
	public $fix_label = 'Titel speichern';
	public $fix_does  = 'Leere Titel werden direkt im Beitrag gesetzt. Bei doppelten oder zu langen Titeln bleibt die Überschrift auf der Seite gleich – nur der Titel für Google und den Browser-Tab ändert sich.';
	public $risk      = 'review';
	public $kind      = 'items';

	public function run( Pruefstand_Context $ctx ) {
		if ( Pruefstand_Env::seo_plugin() ) {
			return $this->skip( sprintf( __( 'Titel werden von %s verwaltet.', 'pruefstand' ), Pruefstand_Env::seo_plugin() ) );
		}
		$posts  = $ctx->posts();
		$counts = array();
		foreach ( $posts as $post ) {
			$key = mb_strtolower( trim( $post->post_title ) );
			if ( '' !== $key ) {
				$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
			}
		}
		$site  = get_bloginfo( 'name' );
		$items = array();
		foreach ( $posts as $post ) {
			$title  = trim( wp_strip_all_tags( $post->post_title ) );
			$custom = get_post_meta( $post->ID, '_pruefstand_title', true );
			if ( $custom ) {
				continue;
			}
			$base = array(
				'id'   => 'post:' . $post->ID,
				'link' => get_edit_post_link( $post->ID, 'raw' ),
			);
			if ( '' === $title ) {
				$items[] = $base + array(
					'label' => __( '(ohne Titel)', 'pruefstand' ),
					'sub'   => __( 'Titel fehlt', 'pruefstand' ),
					'value' => Pruefstand_Modules::shorten( wp_trim_words( wp_strip_all_tags( excerpt_remove_blocks( $post->post_content ) ), 8, '' ), 60 ),
				);
			} elseif ( $counts[ mb_strtolower( $title ) ] > 1 ) {
				$cats    = get_the_category( $post->ID );
				$suffix  = $cats && 'uncategorized' !== $cats[0]->slug ? $cats[0]->name : get_the_date( 'F Y', $post );
				$items[] = $base + array(
					'label' => $title,
					'sub'   => sprintf( __( 'Titel kommt %d-mal vor', 'pruefstand' ), $counts[ mb_strtolower( $title ) ] ),
					'value' => Pruefstand_Modules::shorten( $title . ' – ' . $suffix, 60 ),
				);
			} elseif ( mb_strlen( $title . ' – ' . $site ) > 65 ) {
				$items[] = $base + array(
					'label' => $title,
					'sub'   => sprintf( __( '%d Zeichen mit Website-Name', 'pruefstand' ), mb_strlen( $title . ' – ' . $site ) ),
					'value' => Pruefstand_Modules::shorten( $title, 60 ),
				);
			}
			if ( count( $items ) >= 200 ) {
				break;
			}
		}
		if ( ! $items ) {
			return $this->pass( __( 'Alle Titel sind eindeutig und passend lang.', 'pruefstand' ) );
		}
		return $this->warn(
			sprintf( _n( '%d Titel sollte angepasst werden.', '%d Titel sollten angepasst werden.', count( $items ), 'pruefstand' ), count( $items ) ),
			array(
				'items'      => $items,
				'item_field' => array( 'label' => __( 'Titel für Google (max. 60 Zeichen)', 'pruefstand' ), 'max' => 60 ),
			)
		);
	}

	public function fix( array $req, array &$undo ) {
		$done = 0;
		foreach ( (array) ( isset( $req['items'] ) ? $req['items'] : array() ) as $item ) {
			if ( ! preg_match( '/^post:(\d+)$/', isset( $item['id'] ) ? (string) $item['id'] : '', $m ) || ! current_user_can( 'edit_post', (int) $m[1] ) ) {
				continue;
			}
			$id    = (int) $m[1];
			$value = sanitize_text_field( isset( $item['value'] ) ? $item['value'] : '' );
			if ( '' === $value ) {
				continue;
			}
			$post = get_post( $id );
			if ( '' === trim( $post->post_title ) ) {
				$undo[] = array( 'post_field', $id, 'post_title', $post->post_title );
				wp_update_post( wp_slash( array( 'ID' => $id, 'post_title' => $value ) ) );
			} else {
				Pruefstand_Log::set_postmeta( $id, '_pruefstand_title', $value, $undo );
				if ( ! Pruefstand_Modules::on( 'seo_titles' ) ) {
					Pruefstand_Log::set_module( 'seo_titles', true, $undo );
				}
			}
			++$done;
		}
		return sprintf( _n( '%d Titel gespeichert.', '%d Titel gespeichert.', $done, 'pruefstand' ), $done );
	}
}

class Pruefstand_Check_Content_H1 extends Pruefstand_Check {
	public $id        = 'content_h1';
	public $group     = 'Inhalte';
	public $weight    = 3;
	public $title     = 'Nur eine Hauptüberschrift pro Seite';
	public $problem   = 'Seiten mit mehreren Hauptüberschriften (H1)';
	public $explain   = 'Die Hauptüberschrift (H1) sagt Google, worum es auf der Seite geht. Dein Theme setzt den Titel schon als H1. Weitere H1 im Text machen unklar, was das eigentliche Thema ist.';
	public $fix_label = 'In Zwischenüberschriften umwandeln';
	public $fix_does  = 'Wandelt die H1-Überschriften im Text in H2 um. Text und Reihenfolge bleiben gleich, die Überschriften werden höchstens etwas kleiner dargestellt.';
	public $kind      = 'items';

	public function run( Pruefstand_Context $ctx ) {
		$items = array();
		foreach ( $ctx->posts() as $post ) {
			$count = preg_match_all( '/<h1[\s>]/i', $post->post_content );
			if ( $count ) {
				$items[] = array(
					'id'    => 'post:' . $post->ID,
					'label' => get_the_title( $post ),
					'sub'   => sprintf( _n( '%d H1 im Text', '%d H1 im Text', $count, 'pruefstand' ), $count ),
					'link'  => get_permalink( $post ),
				);
			}
		}
		if ( ! $items ) {
			return $this->pass( __( 'Keine zusätzlichen H1-Überschriften im Text.', 'pruefstand' ) );
		}
		// Only a problem when the theme already prints the title as H1.
		$res = Pruefstand_Crawler::fetch( $items[0]['link'] );
		if ( $res['ok'] && $res['status'] < 400 ) {
			$facts = Pruefstand_Crawler::analyse( $res['body'], $items[0]['link'] );
			if ( count( $facts['h1'] ) < 2 ) {
				return $this->pass( __( 'Dein Theme nutzt die H1 aus dem Text als einzige Hauptüberschrift – das passt.', 'pruefstand' ) );
			}
		}
		return $this->fail( sprintf( _n( '%d Seite hat zusätzliche H1-Überschriften.', '%d Seiten haben zusätzliche H1-Überschriften.', count( $items ), 'pruefstand' ), count( $items ) ), array( 'items' => $items ) );
	}

	public static function demote( $html ) {
		$html = preg_replace_callback(
			'/<!--\s+wp:heading\s+(\{.*?\})\s+-->/s',
			function ( $m ) {
				$attrs = json_decode( $m[1], true );
				if ( ! is_array( $attrs ) || ! isset( $attrs['level'] ) || 1 !== (int) $attrs['level'] ) {
					return $m[0];
				}
				unset( $attrs['level'] );
				return $attrs ? '<!-- wp:heading ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' -->' : '<!-- wp:heading -->';
			},
			$html
		);
		$html = preg_replace( '/<h1(?=[\s>])/i', '<h2', $html );
		return preg_replace( '#</h1>#i', '</h2>', $html );
	}

	public function fix( array $req, array &$undo ) {
		$done = 0;
		foreach ( (array) ( isset( $req['items'] ) ? $req['items'] : array() ) as $item ) {
			if ( ! preg_match( '/^post:(\d+)$/', isset( $item['id'] ) ? (string) $item['id'] : '', $m ) || ! current_user_can( 'edit_post', (int) $m[1] ) ) {
				continue;
			}
			$post = get_post( (int) $m[1] );
			$new  = self::demote( $post->post_content );
			if ( $new !== $post->post_content ) {
				$undo[] = array( 'post_field', $post->ID, 'post_content', $post->post_content );
				wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $new ) ) );
				++$done;
			}
		}
		return sprintf( _n( '%d Seite korrigiert.', '%d Seiten korrigiert.', $done, 'pruefstand' ), $done );
	}
}

class Pruefstand_Check_Thin_Content extends Pruefstand_Check {
	public $id        = 'content_thin';
	public $group     = 'Inhalte';
	public $weight    = 2;
	public $title     = 'Beiträge haben genug Inhalt';
	public $problem   = 'Sehr kurze Beiträge';
	public $explain   = 'Beiträge mit weniger als 300 Wörtern beantworten selten eine Frage vollständig. Google stuft viele solcher Seiten als „dünn“ ein, was die ganze Website schwächen kann. Am besten erweiterst du sie. Alternativ kannst du sie für Google ausblenden.';
	public $fix_label = 'Für Google ausblenden (noindex)';
	public $fix_does  = 'Die ausgewählten Beiträge bleiben für Besucher sichtbar, Google nimmt sie aber nicht mehr in die Suche auf. Sobald du sie erweitert hast, kannst du das im Protokoll rückgängig machen.';
	public $risk      = 'review';
	public $kind      = 'items';

	public static function words( $content ) {
		$text = wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $content ) ) );
		$text = trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
	}

	public function run( Pruefstand_Context $ctx ) {
		$items = array();
		foreach ( $ctx->posts() as $post ) {
			if ( 'post' !== $post->post_type || get_post_meta( $post->ID, '_pruefstand_noindex', true ) ) {
				continue;
			}
			$words = self::words( $post->post_content );
			if ( $words < 300 ) {
				$items[] = array(
					'id'    => 'post:' . $post->ID,
					'label' => get_the_title( $post ),
					'sub'   => sprintf( _n( '%d Wort', '%d Wörter', $words, 'pruefstand' ), $words ),
					'link'  => get_edit_post_link( $post->ID, 'raw' ),
				);
			}
		}
		if ( ! $items ) {
			return $this->pass( __( 'Alle Beiträge haben mindestens 300 Wörter.', 'pruefstand' ) );
		}
		return $this->warn( sprintf( _n( '%d Beitrag hat weniger als 300 Wörter.', '%d Beiträge haben weniger als 300 Wörter.', count( $items ), 'pruefstand' ), count( $items ) ), array( 'items' => $items ) );
	}

	public function fix( array $req, array &$undo ) {
		$done = 0;
		foreach ( (array) ( isset( $req['items'] ) ? $req['items'] : array() ) as $item ) {
			if ( preg_match( '/^post:(\d+)$/', isset( $item['id'] ) ? (string) $item['id'] : '', $m ) && current_user_can( 'edit_post', (int) $m[1] ) ) {
				Pruefstand_Log::set_postmeta( (int) $m[1], '_pruefstand_noindex', '1', $undo );
				++$done;
			}
		}
		return sprintf( _n( '%d Beitrag für Google ausgeblendet.', '%d Beiträge für Google ausgeblendet.', $done, 'pruefstand' ), $done );
	}
}

class Pruefstand_Check_Broken_Links extends Pruefstand_Check {
	public $id        = 'content_broken_links';
	public $group     = 'Inhalte';
	public $weight    = 5;
	public $title     = 'Interne Links funktionieren';
	public $problem   = 'Links, die ins Leere führen';
	public $explain   = 'Diese Links zeigen auf Seiten deiner Website, die es nicht (mehr) gibt. Besucher landen auf „Seite nicht gefunden“, und Google wertet kaputte Links als Zeichen für eine schlecht gepflegte Website.';
	public $fix_label = 'Links korrigieren';
	public $fix_does  = 'Trägt die neue Adresse ein. Lässt du das Feld leer, wird der Link entfernt – der verlinkte Text bleibt stehen.';
	public $risk      = 'review';
	public $kind      = 'items';

	/** @return array<string,array> raw href => [posts…] for local links that WordPress cannot resolve. */
	private function candidates( Pruefstand_Context $ctx ) {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$uploads   = wp_upload_dir( null, false );
		$found     = array();
		foreach ( $ctx->posts() as $post ) {
			$p = new WP_HTML_Tag_Processor( $post->post_content );
			while ( $p->next_tag( 'a' ) ) {
				$href = trim( (string) $p->get_attribute( 'href' ) );
				if ( '' === $href || '#' === $href[0] || preg_match( '#^(mailto|tel|javascript|data):#i', $href ) ) {
					continue;
				}
				$abs  = Pruefstand_Crawler::absolutize( $href );
				$host = wp_parse_url( $abs, PHP_URL_HOST );
				if ( ! $host || $host !== $home_host || preg_match( '#/wp-(admin|login|json)#', $abs ) ) {
					continue;
				}
				$clean = strtok( $abs, '#' );
				if ( 0 === strpos( $clean, $uploads['baseurl'] ) ) {
					if ( file_exists( $uploads['basedir'] . urldecode( substr( strtok( $clean, '?' ), strlen( $uploads['baseurl'] ) ) ) ) ) {
						continue;
					}
				} elseif ( untrailingslashit( $clean ) === untrailingslashit( home_url() ) || url_to_postid( $clean ) ) {
					continue;
				}
				$found[ $href ][ $post->ID ] = $post;
			}
		}
		return $found;
	}

	public function run( Pruefstand_Context $ctx ) {
		$items  = array();
		$tested = 0;
		foreach ( $this->candidates( $ctx ) as $href => $posts ) {
			if ( $tested >= 40 ) {
				break;
			}
			++$tested;
			$res = Pruefstand_Crawler::fetch( Pruefstand_Crawler::absolutize( $href ) );
			if ( ! $res['ok'] || ! in_array( $res['status'], array( 404, 410 ), true ) ) {
				continue;
			}
			foreach ( $posts as $post ) {
				$items[] = array(
					'id'    => 'post:' . $post->ID . ':' . md5( $href ),
					'label' => $href,
					'sub'   => sprintf( __( 'in „%s“', 'pruefstand' ), get_the_title( $post ) ),
					'value' => self::guess( $href ),
					'link'  => get_edit_post_link( $post->ID, 'raw' ),
				);
			}
		}
		if ( ! $items ) {
			return $this->pass( $tested ? sprintf( __( '%d verdächtige Links getestet – alle erreichbar.', 'pruefstand' ), $tested ) : __( 'Alle internen Links führen zu existierenden Seiten.', 'pruefstand' ) );
		}
		return $this->fail(
			sprintf( _n( '%d Link führt ins Leere.', '%d Links führen ins Leere.', count( $items ), 'pruefstand' ), count( $items ) ),
			array(
				'items'      => $items,
				'item_field' => array( 'label' => __( 'Neue Adresse (leer lassen = Link entfernen)', 'pruefstand' ), 'max' => 500, 'type' => 'url' ),
			)
		);
	}

	/** Look for a published post whose slug matches the last part of the dead address. */
	private static function guess( $href ) {
		$path = trim( (string) wp_parse_url( Pruefstand_Crawler::absolutize( $href ), PHP_URL_PATH ), '/' );
		$slug = sanitize_title( basename( $path ) );
		if ( strlen( $slug ) < 3 ) {
			return '';
		}
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page') AND post_name LIKE %s ORDER BY post_date DESC LIMIT 1", $wpdb->esc_like( substr( $slug, 0, 30 ) ) . '%' ) );
		return $id ? get_permalink( (int) $id ) : '';
	}

	public function fix( array $req, array &$undo ) {
		$by_post = array();
		foreach ( (array) ( isset( $req['items'] ) ? $req['items'] : array() ) as $item ) {
			if ( preg_match( '/^post:(\d+):([a-f0-9]{32})$/', isset( $item['id'] ) ? (string) $item['id'] : '', $m ) ) {
				$by_post[ (int) $m[1] ][ $m[2] ] = esc_url_raw( trim( isset( $item['value'] ) ? (string) $item['value'] : '' ) );
			}
		}
		$done = 0;
		foreach ( $by_post as $post_id => $targets ) {
			$post = get_post( $post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$html = $post->post_content;
			// 1) Replace addresses.
			$p = new WP_HTML_Tag_Processor( $html );
			while ( $p->next_tag( 'a' ) ) {
				$href = trim( (string) $p->get_attribute( 'href' ) );
				$key  = md5( $href );
				if ( isset( $targets[ $key ] ) && '' !== $targets[ $key ] ) {
					$p->set_attribute( 'href', $targets[ $key ] );
					++$done;
				}
			}
			$html = $p->get_updated_html();
			// 2) Remove links where no new address was given (keep the text).
			$html = preg_replace_callback(
				'#<a\b([^>]*)>(.*?)</a>#is',
				function ( $m ) use ( $targets, &$done ) {
					if ( ! preg_match( '/\shref\s*=\s*(["\'])(.*?)\1/is', $m[1], $h ) ) {
						return $m[0];
					}
					$key = md5( trim( html_entity_decode( $h[2], ENT_QUOTES ) ) );
					if ( ! isset( $targets[ $key ] ) ) {
						$key = md5( trim( $h[2] ) );
					}
					if ( isset( $targets[ $key ] ) && '' === $targets[ $key ] ) {
						++$done;
						return $m[2];
					}
					return $m[0];
				},
				$html
			);
			if ( $html !== $post->post_content ) {
				$undo[] = array( 'post_field', $post_id, 'post_content', $post->post_content );
				wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $html ) ) );
			}
		}
		return sprintf( _n( '%d Link korrigiert.', '%d Links korrigiert.', $done, 'pruefstand' ), $done );
	}
}
