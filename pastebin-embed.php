<?php if (!defined('PmWiki')) exit();
/** \pastebin-embed.php
  * \Copyright 2017-2021 Said Achmiz
  * \Licensed under the MIT License
  * \brief Embed Pastebin pastes in a wikipage.
  */
$RecipeInfo['PastebinEmbed']['Version'] = '2018-10-19';

## (:pastebin-embed:)
Markup('pastebin-embed', '<fulltext', '/\\(:pastebin-embed\\s+(.+?)\\s*:\\)/', 'PastebinEmbed');

SDV($PastebinEmbedHighlightStyle, "background-color: yellow;");

function PastebinEmbed ($m) {
	static $id = 1;
	
	## Parse arguments to the markup.
	$parsed = ParseArgs($m[1]);

	## These are the "bare" arguments (ones which don't require a key, just value(s)).	
	$args = $parsed[''];
	$paste_id = $args[0];
	$noJS = in_array('no-js', $args);
	$noFooter = in_array('nofooter', $args);
	$noLineNumbers = in_array('nolinenums', $args);
	$raw = in_array('raw', $args);
	$noPre = in_array('no-pre', $args);
	
	## Convert the comma-delimited line ranges to an array containing each line to be
	## included as values.
	## Note that the line numbers will be zero-indexed (for use with raw text, etc.).
	$line_ranges = $parsed['lines'] ? explode(',', $parsed['lines']) : array();
	$line_numbers = array();
	$to_end_from = -1;
	foreach ($line_ranges as $key => $line_range) {
		if (preg_match("/([0-9]+)[-–]([0-9]+)/", $line_range, $m)) {
			$line_numbers = array_merge($line_numbers, range(--$m[1],--$m[2]));
		} else if (preg_match("/([0-9]+)[-–]$/", $line_range, $m)) {
			$line_numbers[] = $to_end_from = --$m[1];
		} else {
			$line_numbers[] = --$line_range;
		}
	}
	
	## Same thing, but for highlighted line ranges.
	$hl_line_ranges = $parsed['hl'] ? explode(',', $parsed['hl']) : array();
	$hl_line_numbers = array();
	$hl_to_end_from = -1;
	foreach ($hl_line_ranges as $key => $hl_line_range) {
		if (preg_match("/([0-9]+)[-–]([0-9]+)/", $hl_line_range, $m)) {
			$hl_line_numbers = array_merge($hl_line_numbers, range(--$m[1],--$m[2]));
		} else if (preg_match("/([0-9]+)[-–]$/", $hl_line_range, $m)) {
			$hl_line_numbers[] = $hl_to_end_from = --$m[1];
		} else {
			$hl_line_numbers[] = --$hl_line_range;
		}
	}
	
	$embed_js_url = "https://pastebin.com/embed_js/$paste_id";
	$embed_iframe_url = "https://pastebin.com/embed_iframe/$paste_id";
	$embed_raw_url = "https://pastebin.com/raw/$paste_id";
	
	$out = "<span class='pastebin-embed-error'>Unknown error.</span>";
	
	## There are three 'modes': raw (retrieve just the text, client-side, and insert it
	## into the page), no-js (retrieve the HTML, server-side, and insert it into the 
	## page), and default (i.e., JS-based - insert the scriptlet, and let it retrieve and 
	## insert the HTML into the page, client-side & async).
	## The mode is set by arguments to the (:pastebin-embed:) markup:
	## - if neither the 'raw' nor the 'no-js' option is given, default (JS) mode is used
	## - if the 'raw' option is given, raw mode is used
	## - if the 'no-js' option is given, no-js mode is used
	if ($raw) {
		$raw_text = file_get_contents($embed_raw_url);
		if (!$raw_text) return Keep("<span class='pastebin-embed-error'>Could not retrieve paste!</span>");
		
		$raw_lines = explode("\n", $raw_text);
		## Convert HTML entities.
		if (!$noPre) {
			foreach ($raw_lines as $line)
				$line = PVSE($line);
		}
		## Highlighting only works if no-pre is NOT enabled.
		if (!empty($hl_line_numbers) && !$noPre) {
			if ($hl_to_end_from >= 0)
				$hl_line_numbers = array_merge($hl_line_numbers, range($hl_to_end_from, count($raw_lines) - 1));
			foreach ($hl_line_numbers as $l) {
				$raw_lines[$l] = "<span class='pastebin-embed-highlighted-line'>" . rtrim($raw_lines[$l]) . "</span>";
			}
		}
		## Filter by line number ranges, if specified.
		if (!empty($line_numbers)) {			
			if ($to_end_from >= 0)
				$line_numbers = array_merge($line_numbers, range($to_end_from, count($raw_lines) - 1));
			$raw_lines = array_intersect_key($raw_lines, array_flip($line_numbers));
		}
		$raw_text = implode("\n", $raw_lines);
		
		## The 'no-pre' option means we shouldn't wrap the text in a <pre> tag.
		$out = $noPre ? $raw_text : Keep("<pre class='escaped embedPastebinRaw' id='pastebinEmbed_$id'>\n" . $raw_text . "\n</pre>\n");
	} else if ($noJS) {
		include_once('simple_html_dom.php');
		
		$content_html = file_get_html($embed_iframe_url);
		if (!$content_html) return Keep("<span class='pastebin-embed-error'>Could not retrieve paste!</span>");
		$content = $content_html->find(".embedPastebin", 0);
		$content->id = "pastebinEmbed_$id";
		
		$styles_html = file_get_html($embed_js_url);
		if (!$styles_html) return Keep("<span class='pastebin-embed-error'>Could not retrieve styles!</span>");
		$styles = $styles_html->find("style", 0);
		
		## Filter specified line ranges (if any have been specified via the lines= 
		## parameter).
		if (!empty($line_numbers)) {
			$lines = $content_html->find(".embedPastebin > ol > li");
			if ($to_end_from >= 0)
				$line_numbers = array_merge($line_numbers, range($to_end_from, count($lines) - 1));
			foreach ($lines as $i => $line) {
				if (!in_array($i, $line_numbers))
					$line->outertext = '';
				else
					$line->value = ++$i;
			}
		}
		
		## Highlight specified line ranges (if any have been specified via the hl= 
		## parameter).
		if (!empty($hl_line_numbers)) {
			$lines = $content_html->find(".embedPastebin > ol > li");
			if ($hl_to_end_from >= 0)
				$hl_line_numbers = array_merge($hl_line_numbers, range($hl_to_end_from, count($lines) - 1));
			foreach ($lines as $i => $line) {
				if (in_array($i, $hl_line_numbers)) {
					$line->children(0)->class .= " pastebin-embed-highlighted-line";
				}
			}
		}
		
		$out = Keep($styles.$content);
	} else {
		$out = Keep("<script id='pastebinEmbedScript_$id' src='$embed_js_url'></script>");

		if (!empty($hl_line_numbers) || !empty($line_numbers)) {
			$line_numbers_js = "[ ".implode(", ",$line_numbers)." ]";
			$hl_line_numbers_js = "[ ".implode(", ",$hl_line_numbers)." ]";
			$out .= Keep("
<script>
	var num_lines = document.querySelector('#pastebinEmbedScript_$id').parentElement.nextSibling.querySelectorAll('.embedPastebin > ol > li').length;
	
	var line_numbers = $line_numbers_js;	
	var to_end_from = $to_end_from;
	if (to_end_from >= 0)
		line_numbers = [...line_numbers, ...[...Array(num_lines - to_end_from)].map((_, i) => to_end_from + i)];

	var hl_line_numbers = $hl_line_numbers_js;
	var hl_to_end_from = $hl_to_end_from;
	if (hl_to_end_from >= 0)
		hl_line_numbers = [...hl_line_numbers, ...[...Array(num_lines - hl_to_end_from)].map((_, i) => hl_to_end_from + i)];

	document.querySelector('#pastebinEmbedScript_$id').parentElement.nextSibling.querySelectorAll('.embedPastebin > ol > li').forEach(function (line, i) {
		// Highlight specified line ranges (if any have been specified via the hl= parameter).
		if (hl_line_numbers.indexOf(i) != -1)
			line.firstChild.className += ' pastebin-embed-highlighted-line';

		// Filter specified line ranges (if any have been specified via the lines= parameter).
		if (line_numbers.length > 0) {
			if (line_numbers.indexOf(i) == -1)
				line.parentElement.removeChild(line);
			else
				line.value = ++i;
		}
	});
</script>
			");
		}
		
		PastebinEmbedAppendFooter();
	}
	
	global $HTMLStylesFmt;
	if (!$raw && $noFooter) {
		$HTMLStylesFmt['pastebin-embed'][] = "#pastebinEmbed_$id .embedFooter { display: none; }\n";
	}
	if (!$raw && $noLineNumbers) {
		$HTMLStylesFmt['pastebin-embed'][] = "#pastebinEmbed_$id > ol { padding-left: 5px; }\n";
	}
	
	PastebinEmbedInjectStyles();
	
	$id++;
	return $out;
}

function PastebinEmbedAppendFooter() {
	static $ran_once = false;
	if (!$ran_once) {
		global $HTMLFooterFmt;
		$HTMLFooterFmt[] = 
"<script>
	document.querySelectorAll('div.embedPastebin').forEach(function (embed) {
		if (embed.previousSibling && embed.previousSibling.tagName == 'P') {
			embed.id = 'pastebinEmbed_' + embed.previousSibling.firstChild.id.substring(20);
		}
	});
</script>\n";
	}
	$ran_once = true;
}

function PastebinEmbedInjectStyles() {
	static $ran_once = false;
	if (!$ran_once) {
		global $HTMLStylesFmt, $PastebinEmbedHighlightStyle;
		$styles = "
.embedPastebinRaw .pastebin-embed-highlighted-line { $PastebinEmbedHighlightStyle display: inline-block; width: calc(100% + 4px); padding-left: 4px; margin-left: -4px; }
.embedPastebin li .pastebin-embed-highlighted-line { $PastebinEmbedHighlightStyle }
#wikitext .embedPastebin ol { margin: 0; padding: 0 0 0 60px; }
";
		$HTMLStylesFmt['pastebin-embed'][] = $styles;
	}
	$ran_once = true;
}