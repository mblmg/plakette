<?php
/**
 * Test fixture (mu-plugin): a typical Google Analytics 4 snippet pasted into wp_head (no consent).
 */
add_action(
	'wp_head',
	function () {
		echo "<script async src=\"https://www.googletagmanager.com/gtag/js?id=G-TEST123\"></script>\n";
		echo "<script>\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n  gtag('js', new Date());\n  gtag('config', 'G-TEST123');\n</script>\n";
	},
	5
);
