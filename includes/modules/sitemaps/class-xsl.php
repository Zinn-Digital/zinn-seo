<?php
/**
 * The human view of the sitemap in a browser (zs-049).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Sitemaps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An XSLT 1.0 stylesheet served by the site itself (no remote font, script or image), in the
 * site's language and direction, light and dark, with its stylesheet `sitemap.css` (its own
 * file, served beside it). It only changes how a browser SHOWS the file;
 * search engines read the XML and ignore it. Never sent in no-trace mode.
 */
final class Xsl {

	/**
	 * The stylesheet.
	 *
	 * @return string
	 */
	public static function render(): string {
		$t = static fn( string $s ): string => Writer::text( $s );

		$title      = $t( __( 'XML sitemap', 'zinn-seo' ) );
		$intro      = $t( __( 'This file lists the pages search engines may index. It is written for them; this view only makes it readable.', 'zinn-seo' ) );
		$index_note = $t( __( 'Each row is one sitemap file.', 'zinn-seo' ) );
		$col_file   = $t( __( 'Sitemap', 'zinn-seo' ) );
		$col_page   = $t( __( 'Address', 'zinn-seo' ) );
		$col_images = $t( __( 'Images', 'zinn-seo' ) );
		$col_langs  = $t( __( 'Languages', 'zinn-seo' ) );
		$col_mod    = $t( __( 'Last changed', 'zinn-seo' ) );
		$count      = $t( __( 'Addresses in this file:', 'zinn-seo' ) );
		$files      = $t( __( 'Sitemap files:', 'zinn-seo' ) );
		$back       = $t( __( 'All sitemaps', 'zinn-seo' ) );
		$dir        = is_rtl() ? 'rtl' : 'ltr';
		$lang       = $t( str_replace( '_', '-', (string) get_locale() ) );
		$index      = $t( (string) wp_parse_url( Sitemaps::url(), PHP_URL_PATH ) );
		$css        = $t( (string) wp_parse_url( Sitemaps::root( '/sitemap.css' ), PHP_URL_PATH ) );

		$xsl = '<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml" exclude-result-prefixes="s image xhtml">
<xsl:output method="html" encoding="UTF-8" indent="no" doctype-system="about:legacy-compat"/>
<xsl:template match="/">
<html lang="{$lang}" dir="{$dir}">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<meta name="robots" content="noindex, follow"/>
<title>{$title}</title>
<xsl:element name="link"><xsl:attribute name="rel">stylesheet</xsl:attribute><xsl:attribute name="href">{$css}</xsl:attribute></xsl:element>
</head>
<body>
<main>
<h1>{$title}</h1>
<p>{$intro}</p>
<xsl:choose>
<xsl:when test="s:sitemapindex">
<p>{$index_note} {$files} <strong><xsl:value-of select="count(s:sitemapindex/s:sitemap)"/></strong></p>
<table><thead><tr><th>{$col_file}</th><th>{$col_mod}</th></tr></thead><tbody>
<xsl:for-each select="s:sitemapindex/s:sitemap">
<tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td><td class="n"><xsl:value-of select="concat(substring(s:lastmod,1,10),\' \',substring(s:lastmod,12,5))"/></td></tr>
</xsl:for-each>
</tbody></table>
</xsl:when>
<xsl:otherwise>
<p><a href="{$index}">{$back}</a> · {$count} <strong><xsl:value-of select="count(s:urlset/s:url)"/></strong></p>
<table><thead><tr><th>{$col_page}</th><th>{$col_images}</th><th>{$col_langs}</th><th>{$col_mod}</th></tr></thead><tbody>
<xsl:for-each select="s:urlset/s:url">
<tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td><td class="n"><xsl:if test="count(image:image) &gt; 0"><xsl:value-of select="count(image:image)"/></xsl:if></td><td class="n"><xsl:if test="count(xhtml:link) &gt; 0"><xsl:value-of select="count(xhtml:link)"/></xsl:if></td><td class="n"><xsl:value-of select="concat(substring(s:lastmod,1,10),\' \',substring(s:lastmod,12,5))"/></td></tr>
</xsl:for-each>
</tbody></table>
</xsl:otherwise>
</xsl:choose>
</main>
</body>
</html>
</xsl:template>
</xsl:stylesheet>
';

		return strtr(
			$xsl,
			array(
				'{$back}'       => $back,
				'{$col_file}'   => $col_file,
				'{$col_images}' => $col_images,
				'{$col_langs}'  => $col_langs,
				'{$col_mod}'    => $col_mod,
				'{$col_page}'   => $col_page,
				'{$count}'      => $count,
				'{$css}'        => $css,
				'{$dir}'        => $dir,
				'{$files}'      => $files,
				'{$index}'      => $index,
				'{$index_note}' => $index_note,
				'{$intro}'      => $intro,
				'{$lang}'       => $lang,
				'{$title}'      => $title,
			)
		);
	}

	/**
	 * The plain-CSS view (`/sitemap-view.css`) a browser applies to the XML itself when it no
	 * longer runs XSLT (zs-762): each address on its own line, readable, light and dark, in the
	 * site's language and direction. Links cannot be made clickable with CSS alone; the
	 * addresses are shown in full.
	 *
	 * @return string
	 */
	public static function view_css(): string {
		$q     = static fn( string $s ): string => '"' . str_replace( array( '\\', '"', "\n", "\r", '<' ), array( '\\\\', '\\"', ' ', ' ', '\\3c ' ), $s ) . '"';
		$title = $q( __( 'XML sitemap', 'zinn-seo' ) );
		$intro = $q( __( 'This file lists the pages search engines may index. It is written for them; this view only makes it readable.', 'zinn-seo' ) );
		$dir   = is_rtl() ? 'rtl' : 'ltr';

		return '@namespace image url(http://www.google.com/schemas/sitemap-image/1.1);
@namespace xhtml url(http://www.w3.org/1999/xhtml);
:root{color-scheme:light dark;display:block;direction:' . $dir . ';background:#fff;color:#1d2327;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans","Noto Sans Arabic",sans-serif;max-inline-size:1100px;margin-inline:auto;padding-block:24px;padding-inline:16px;box-sizing:border-box}
:root::before{content:' . $title . ';display:block;font-size:1.5rem;font-weight:700;margin-block-end:4px}
url:first-of-type::before,sitemap:first-of-type::before{content:' . $intro . ';display:block;color:#50575e;margin-block-end:16px}
url,sitemap{display:block;padding-block:8px;border-block-end:1px solid #dcdcde}
loc{display:block;color:#2251c9;overflow-wrap:anywhere}
lastmod{display:block;font-size:.85rem;color:#50575e}
changefreq,priority,image|image,xhtml|link{display:none}
@media (prefers-color-scheme:dark){:root{background:#16181b;color:#e6e7e8}loc{color:#8fb0ff}lastmod,url:first-of-type::before,sitemap:first-of-type::before{color:#a7aaad}url,sitemap{border-color:#2c3338}}
';
	}

	/**
	 * The human view's stylesheet (`/sitemap.css`).
	 *
	 * @return string
	 */
	public static function css(): string {
		return <<<'CSS'
:root{color-scheme:light dark;--bg:#fff;--fg:#1d2327;--muted:#50575e;--line:#dcdcde;--row:#f6f7f7;--link:#2251c9}
@media (prefers-color-scheme:dark){:root{--bg:#16181b;--fg:#e6e7e8;--muted:#a7aaad;--line:#2c3338;--row:#1d2125;--link:#8fb0ff}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans","Noto Sans Arabic",sans-serif}
main{max-inline-size:1100px;margin-inline:auto;padding-block:24px;padding-inline:16px}
h1{font-size:1.5rem;margin-block:0 4px}
p{margin-block:4px 12px;color:var(--muted)}
a{color:var(--link);overflow-wrap:anywhere}
table{inline-size:100%;border-collapse:collapse;margin-block-start:12px}
th,td{text-align:start;padding-block:8px;padding-inline:10px;border-block-end:1px solid var(--line);vertical-align:top}
th{font-size:.8rem;text-transform:uppercase;letter-spacing:.03em;color:var(--muted)}
tbody tr:nth-child(odd){background:var(--row)}
td.n{white-space:nowrap;inline-size:1%}
@media (max-width:640px){thead{display:none}tr{display:block;padding-block:8px}td{display:block;border:0;padding-block:2px}td.n{inline-size:auto}td.n:empty{display:none}}
CSS;
	}
}
