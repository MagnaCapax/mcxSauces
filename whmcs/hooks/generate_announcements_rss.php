<?php
/**
 * WHMCS Hook: Regenerate static announcements RSS on add/edit.
 *
 * WHY THIS EXISTS
 * ---------------
 * WHMCS ships announcementsrss.php — an IonCube-encrypted file that has
 * been effectively unmaintained for over 15 years. Community bug reports
 * go back to 2008: malformed URLs (Dec 2008), empty encoding declaration
 * string (Nov 2009), failed W3C RSS validation and SimpleXMLElement parse
 * errors (Apr 2010), timezone config ignored (Apr 2010), unauthenticated
 * access leaking draft announcements (Jul 2014). None were fixed. After
 * 2015 the community stopped reporting — not because the problems were
 * solved, but because nobody expected fixes anymore.
 *
 * The core architectural problem: the file runs an unbounded SELECT on
 * tblannouncements with no LIMIT, no pagination, and no configuration
 * option. Every request triggers a full table scan and dumps every
 * announcement ever published into a single XML response. For any
 * installation that has been running for years, this response grows
 * without bound. There is no authentication, no rate limiting, and no
 * server-side caching — every GET hits the database. The IonCube
 * encryption means you cannot patch it, limit it, or even audit what
 * it does. The URL is publicly documented in WHMCS's own documentation.
 *
 * For installations that consume this feed (e.g. displaying recent
 * announcements on a customer dashboard via SimpleXMLElement), the
 * stock file is a reliability liability: the unbounded response is slow
 * to parse, and any encoding inconsistency in historical content
 * produces invalid XML that crashes standards-compliant parsers.
 *
 * Long-running installations often have legacy latin1 tables that
 * accumulate Windows-1252 bytes (smart quotes, em-dashes, euro signs)
 * over years of content entry. WHMCS upgrades have never included
 * charset conversion and no official migration path exists. The stock
 * RSS generator emits these bytes inside an XML document declared as
 * UTF-8 — producing invalid XML that crashes any standards-compliant
 * parser. The sanitizer in this hook maps those bytes to proper HTML
 * entities before XML generation, making the output valid regardless
 * of the source table's charset.
 *
 * Fixing this properly in WHMCS core would be straightforward: add a
 * LIMIT clause, declare the encoding, sanitize non-UTF-8 bytes. The
 * community has been asking since 2008. This hook exists because
 * waiting another 15 years is not a viable strategy.
 *
 * A common workaround is to cron-cache the stock file to a static copy
 * (e.g. wget every 2 hours), which eliminates per-request DB load but
 * not the unbounded size, encoding problems, or update latency. This
 * event-driven hook replaces that approach entirely.
 *
 * WHAT THIS HOOK DOES
 * -------------------
 * On AnnouncementAdd and AnnouncementEdit events, queries the 15 most
 * recent published announcements via Capsule ORM, generates valid UTF-8
 * RSS 2.0 XML with proper encoding handling (including Windows-1252
 * byte mapping for legacy content), validates the output with
 * simplexml_load_string, and atomically writes a static file that
 * overwrites the stock announcementsrss.php — same path, same URL,
 * but valid XML, bounded size, and zero database load on read.
 *
 * NOTE: No AnnouncementDelete hook exists in WHMCS (as of 8.13.x).
 * Deleted announcements remain in the static file until the next
 * add/edit event triggers regeneration. For most installations this
 * is acceptable; if not, a lightweight cron calling
 * pmGenerateAnnouncementsRss() daily covers the gap.
 *
 * Deploy to: <whmcs_root>/includes/hooks/generate_announcements_rss.php
 *
 * Manual trigger (from WHMCS root):
 *   php -r "require 'init.php'; pmGenerateAnnouncementsRss();"
 *   (init.php auto-loads all hook files — do NOT require the hook separately)
 *
 * SPDX-License-Identifier: Apache-2.0
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2026 Magna Capax Finland Oy
 * @license   Apache-2.0
 */

use WHMCS\Database\Capsule;

/** Absolute path to the generated RSS file. Derived from WHMCS root. */
define('PM_RSS_OUTPUT', dirname(__DIR__, 2) . '/announcementsrss.php');

/**
 * Source encoding: what charset does PHP receive from the database?
 *
 * This is your MySQL CONNECTION charset — not the table charset.
 * The connection charset controls what bytes MySQL sends to PHP.
 * Even if your tables are utf8mb4, a latin1 connection means PHP
 * gets latin1 bytes. Check yours with:
 *   SHOW VARIABLES LIKE 'character_set_connection';
 *
 * Or in PHP:
 *   require 'init.php';
 *   echo \WHMCS\Database\Capsule::connection()
 *        ->select("SHOW VARIABLES LIKE 'character_set_connection'")[0]->Value;
 *
 * If you set $mysql_charset in configuration.php, your connection
 * charset matches that value. If you never set it, WHMCS defaults
 * to latin1 — change this constant to 'Windows-1252'.
 *
 * Common values:
 *   'UTF-8'         — utf8 or utf8mb4 connection ($mysql_charset = 'utf8' or 'utf8mb4')
 *   'Windows-1252'  — latin1 connection (superset of ISO-8859-1, handles smart quotes)
 *   'ISO-8859-1'    — latin1 connection (strict ISO, no smart quotes in 0x80-0x9F range)
 *   'ISO-8859-15'   — latin9 (like ISO-8859-1 but adds euro sign at 0xA4)
 *
 * When in doubt, use 'Windows-1252' — it is the safest choice for latin1
 * connections because it handles the 0x80-0x9F byte range that ISO-8859-1
 * leaves undefined. Most "latin1" content on the web is actually Windows-1252.
 */
define('PM_RSS_SOURCE_ENCODING', 'UTF-8');

function pmGenerateAnnouncementsRss()
{
    $outputPath = PM_RSS_OUTPUT;

    try {
        $rows = Capsule::table('tblannouncements')
            ->where('published', 1)
            ->where('parentid', 0)
            ->orderBy('date', 'DESC')
            ->limit(15)
            ->get(['id', 'date', 'title', 'announcement']);
    } catch (\Throwable $e) {
        error_log('generate_announcements_rss: DB failed: ' . $e->getMessage());
        return;
    }

    // Build channel metadata from WHMCS system settings
    $sysUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL') ?: '', '/');
    $companyName = \WHMCS\Config\Setting::getValue('CompanyName') ?: 'WHMCS';

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= "<rss version=\"2.0\">\n<channel>\n";
    $xml .= '<title>' . htmlspecialchars($companyName, ENT_XML1, 'UTF-8') . ' Announcements</title>' . "\n";
    $xml .= '<link>' . htmlspecialchars($sysUrl . '/announcements', ENT_XML1, 'UTF-8') . '</link>' . "\n";
    $xml .= '<description>Latest announcements</description>' . "\n";

    foreach ($rows as $row) {
        $title = pmRssSanitize($row->title);
        $body  = pmRssSanitize($row->announcement);
        $date  = date('r', strtotime($row->date));
        $slug  = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($row->title)), '-');
        $link  = $sysUrl . '/announcements/' . (int)$row->id . '/' . $slug;

        $xml .= "<item>\n";
        $xml .= '<title><![CDATA[' . pmCdata($title) . ']]></title>' . "\n";
        $xml .= '<description><![CDATA[' . pmCdata($body) . ']]></description>' . "\n";
        $xml .= "<pubDate>{$date}</pubDate>\n";
        $xml .= "<link>{$link}</link>\n";
        $xml .= "</item>\n";
    }

    $xml .= "</channel>\n</rss>\n";

    // Validate XML parses cleanly before overwriting
    libxml_use_internal_errors(true);
    if (simplexml_load_string($xml) === false) {
        error_log('generate_announcements_rss: invalid XML, aborting');
        libxml_clear_errors();
        return;
    }

    // Atomic write: temp file then rename
    $tmp = $outputPath . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $xml) === false) {
        error_log('generate_announcements_rss: write failed');
        @unlink($tmp);
        return;
    }
    if (!rename($tmp, $outputPath)) {
        error_log('generate_announcements_rss: rename failed');
        @unlink($tmp);
    }
}

/**
 * Sanitize string for UTF-8 XML.  Maps Windows-1252 bytes (0x80-0x9F)
 * to HTML entities, then converts from the database connection charset
 * (PM_RSS_SOURCE_ENCODING) to UTF-8.
 *
 * The source encoding must match your MySQL connection charset, not the
 * table charset — MySQL converts on output. With a latin1 connection
 * (WHMCS default), accented characters arrive as single latin1 bytes
 * that must be converted to multi-byte UTF-8.
 */
function pmRssSanitize($str)
{
    if ($str === null || $str === '') {
        return '';
    }

    // Windows-1252 bytes 0x80-0x9F -> Unicode HTML entities
    static $w = [
        "\x80"=>'&#8364;',"\x82"=>'&#8218;',"\x83"=>'&#402;',
        "\x84"=>'&#8222;',"\x85"=>'&#8230;',"\x86"=>'&#8224;',
        "\x87"=>'&#8225;',"\x88"=>'&#710;', "\x89"=>'&#8240;',
        "\x8A"=>'&#352;', "\x8B"=>'&#8249;',"\x8C"=>'&#338;',
        "\x8E"=>'&#381;', "\x91"=>'&#8216;',"\x92"=>'&#8217;',
        "\x93"=>'&#8220;',"\x94"=>'&#8221;',"\x95"=>'&#8226;',
        "\x96"=>'&#8211;',"\x97"=>'&#8212;',"\x98"=>'&#732;',
        "\x99"=>'&#8482;',"\x9A"=>'&#353;', "\x9B"=>'&#8250;',
        "\x9C"=>'&#339;', "\x9E"=>'&#382;', "\x9F"=>'&#376;',
    ];

    // Map W1252 specials first, then convert from connection charset to UTF-8.
    $mapped = strtr($str, $w);
    $utf8 = mb_convert_encoding($mapped, 'UTF-8', PM_RSS_SOURCE_ENCODING);
    // Strip any remaining invalid sequences
    return mb_convert_encoding($utf8, 'UTF-8', 'UTF-8');
}

/** Escape ]]> inside CDATA content. */
function pmCdata($str)
{
    return str_replace(']]>', ']]]]><![CDATA[>', $str);
}

add_hook('AnnouncementAdd', 1, 'pmGenerateAnnouncementsRss');
add_hook('AnnouncementEdit', 1, 'pmGenerateAnnouncementsRss');
