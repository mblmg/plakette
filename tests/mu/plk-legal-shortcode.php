<?php
/* Test fixture: an imprint that only exists as a shortcode (like eRecht24) – the stored content has no address. */
add_shortcode( 'plk_erecht24_imprint', function () {
	return '<h2>Angaben gemäß § 5 DDG</h2><p>Konditorei Sonnenschein GmbH<br>Münsterplatz 7<br>79098 Freiburg</p><p>E-Mail: <a href="mailto:info@konditorei-sonnenschein.example">Schreiben Sie uns</a></p>';
} );
