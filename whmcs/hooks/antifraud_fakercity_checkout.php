<?php
/**
 * WHMCS Hook: Block fraudulent orders using deterministic Python Faker city detection.
 *
 * WHY THIS EXISTS
 * ---------------
 * A persistent class of WHMCS spam registration uses Python's Faker library
 * (locale en_US) to generate fake customer profiles. These registrations
 * have a distinctive fingerprint: the "city" field contains machine-generated
 * place names that follow Faker's internal grammar.
 *
 * Python Faker generates cities using four formats (equally weighted):
 *
 *   1. LastName+Suffix:  e.g., "Mitchellburgh", "Franklinland"
 *   2. FirstName+Suffix: e.g., "Brendaville", "Thomasport"
 *   3. Prefix+FirstName: e.g., "East Christopher", "Port Matthew"
 *   4. Prefix+FirstName+Suffix: e.g., "North Brendaville"
 *
 * Source: faker/providers/address/en_US/__init__.py (joke2k/faker).
 *
 * The Faker-defined suffixes (city_suffixes) are: berg, borough, burgh,
 * bury, chester, fort, furt, haven, land, mouth, port, shire, side,
 * stad, ton, town, view, ville. Note: "mouth" appears twice in the
 * Faker source (a bug giving it double probability).
 *
 * This hook also detects field, ford, and worth as defensive suffixes.
 * These are NOT in Faker's source but follow the same linguistic
 * pattern and would be trivial for a spammer to add.
 *
 * Real cities share these suffixes (Pittsburgh, Portland), so a
 * whitelist is needed to avoid false positives.
 *
 * The Faker-defined prefixes (city_prefixes) are: North, East, West,
 * South, New, Lake, Port. Real compound cities exist (East London,
 * North Charleston), so again a whitelist is needed.
 *
 * Both patterns are deterministic artifacts of Faker's code and do not
 * appear in any real-world postal database. Detection is therefore
 * possible without external API calls, machine learning, or network
 * requests — pure string matching with whitelisted exceptions.
 *
 * Verified against 10,000 WHMCS orders (8187 unpaid V10G, 230 paid):
 * 8104/8187 Faker-pattern spam caught (99.0%), 0/230 false positives.
 * The 83 missed (1.0%) are non-Faker spam — real cities (Istanbul,
 * Tehran, Nanjing) or empty fields, a different population the hook
 * does not target. Among Faker-generated cities specifically, the
 * catch rate is effectively 100%. Safety gap: lowest spam score 9,
 * highest legit score 8, threshold 9.
 *
 * The scoring system requires a MINIMUM of two independent signals to
 * reach the blocking threshold. No single signal alone can trigger a
 * block. This is a deliberate design constraint — one suspicious field
 * is not enough evidence. The threshold (default 9) and the highest
 * single-signal weight (default 5) enforce this structurally.
 *
 * WHAT THIS HOOK DOES
 * -------------------
 * On ShoppingCartValidateCheckout (fires before order creation),
 * evaluates the submitted checkout data against a weighted scoring
 * model. Each signal that fires adds points. If the total score meets
 * or exceeds the threshold, the order is blocked with a generic error.
 *
 * Default signals and weights:
 *
 *   - Product pattern match (5 pts): The ordered product matches a
 *     configurable regex. Certain cheap/entry products attract more
 *     spam than others. Set PM_ANTIFRAUD_PRODUCT_PATTERN to match
 *     your most-targeted product(s).
 *
 *   - Faker city suffix (5 pts): City ends with a known Faker suffix
 *     and is not in the real-city whitelist. Gated on country = US
 *     because Faker en_US generates English city names — cities like
 *     "Manchester" and "Banbury" are real outside the US but would
 *     false-positive without the country gate.
 *
 *   - Faker compound city (3 pts): City matches Direction+PersonName
 *     pattern and is not in the compound-city whitelist. Also gated
 *     on country = US. Mutually exclusive with faker_city (a city
 *     that triggers suffix detection does not also trigger compound
 *     detection).
 *
 *   - Plus-addressed email (2 pts): Email local part contains "+"
 *     (e.g., user+tag@example.com). Faker generates these; legitimate
 *     users occasionally use them, but in combination with other
 *     signals it is a useful discriminator.
 *
 *   - Marketing opt-out at signup (2 pts): User opted out of marketing
 *     emails during registration. Spambots tend to check this box.
 *     Legitimate users sometimes do too — hence low weight.
 *
 *   - Country = US (1 pt): The Faker en_US spam pattern originates
 *     almost exclusively from "US" country selection. Low weight
 *     because many legitimate customers are American.
 *
 * Every evaluation — pass and block — is logged to a JSONL audit file.
 * The log contains no raw email addresses; emails are hashed (SHA-256,
 * first 8 hex chars) for correlation without PII exposure.
 *
 * On ANY error (missing fields, file write failure, code bug), the hook
 * defaults to ALLOW. A bug in fraud detection must never block a
 * legitimate paying customer.
 *
 * Signals and weights can be customized via PM_ANTIFRAUD_WEIGHTS.
 * The threshold can be customized via PM_ANTIFRAUD_THRESHOLD.
 * The product pattern can be customized via PM_ANTIFRAUD_PRODUCT_PATTERN.
 * The faker city whitelist can be extended via PM_ANTIFRAUD_CITY_WHITELIST.
 * The compound city whitelist can be extended via PM_ANTIFRAUD_COMPOUND_WHITELIST.
 *
 * Deploy to: <whmcs_root>/includes/hooks/antifraud_fakercity_checkout.php
 *
 * Made for pulsedmedia.com
 *
 * SPDX-License-Identifier: Apache-2.0
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2026 Magna Capax Finland Oy
 * @license   Apache-2.0
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration — override any of these before this file loads.
// ---------------------------------------------------------------------------

/**
 * Blocking threshold. Score >= this value blocks the order.
 *
 * Default 9 ensures at least two independent signals must fire.
 * Do NOT lower below the highest single-signal weight (default 5)
 * or a single signal could trigger a block.
 */
if (!defined('PM_ANTIFRAUD_THRESHOLD')) {
    define('PM_ANTIFRAUD_THRESHOLD', 9);
}

/**
 * Product name regex pattern for the product-match signal.
 *
 * Set this to match the product name(s) that attract the most spam.
 * Uses preg_match, so include delimiters. Change to match your products.
 *
 * Default matches "V10G S" (the cheapest seedbox tier at pulsedmedia.com,
 * which attracts >95% of automated spam registrations).
 */
if (!defined('PM_ANTIFRAUD_PRODUCT_PATTERN')) {
    define('PM_ANTIFRAUD_PRODUCT_PATTERN', '/V10G S(?![a-z])/i');
}

/**
 * Signal weights. Each key is a signal name; value is the point weight.
 *
 * Adjust weights to match your spam patterns. The structural constraint
 * is: no single weight should meet or exceed PM_ANTIFRAUD_THRESHOLD.
 *
 * Default weights were calibrated against 120 spam + 38 legit accounts.
 */
if (!defined('PM_ANTIFRAUD_WEIGHTS')) {
    define('PM_ANTIFRAUD_WEIGHTS', [
        'product_match'      => 5,
        'faker_city_suffix'  => 5,
        'faker_city_compound'=> 3,
        'plus_email'         => 2,
        'email_optout'       => 2,
        'country_us'         => 1,
    ]);
}

/**
 * Faker city suffixes to detect.
 *
 * From Faker source (city_suffixes): berg, borough, burgh, bury, chester,
 * fort, furt, haven, land, mouth, port, shire, side, stad, ton, town,
 * view, ville. Defensive additions (not in Faker but same linguistic
 * pattern): field, ford, worth.
 */
if (!defined('PM_ANTIFRAUD_FAKER_SUFFIXES')) {
    define('PM_ANTIFRAUD_FAKER_SUFFIXES', [
        // From Faker source (18 suffixes)
        'berg', 'borough', 'burgh', 'bury', 'chester', 'fort', 'furt',
        'haven', 'land', 'mouth', 'port', 'shire', 'side', 'stad',
        'ton', 'town', 'view', 'ville',
        // Defensive additions (not in Faker, same pattern)
        'field', 'ford', 'worth',
    ]);
}

/**
 * Direction/geographic prefixes for compound city detection.
 *
 * Faker combines these with random first names: "East Brenda",
 * "North Christopher", "Port Matthew".
 */
if (!defined('PM_ANTIFRAUD_COMPOUND_PREFIXES')) {
    define('PM_ANTIFRAUD_COMPOUND_PREFIXES', [
        'East', 'West', 'North', 'South', 'New', 'Lake', 'Port',
    ]);
}

/**
 * JSONL audit log directory path.
 *
 * Logs are date-partitioned: <dir>/YYYYMMDD.jsonl
 * Set to empty string to disable logging.
 */
if (!defined('PM_ANTIFRAUD_LOG_DIR')) {
    define('PM_ANTIFRAUD_LOG_DIR', __DIR__ . '/../../log/antifraud-checkout');
}

/**
 * Error message shown to blocked users.
 *
 * Keep it generic — do not reveal scoring details to attackers.
 */
if (!defined('PM_ANTIFRAUD_BLOCK_MESSAGE')) {
    define('PM_ANTIFRAUD_BLOCK_MESSAGE',
        'Your order could not be processed. '
        . 'Please contact support if you believe this is an error.'
    );
}

/**
 * Debug mode — dump raw $vars and $_SESSION['cart'] to a debug JSONL file.
 *
 * Set to a positive integer N to dump the next N checkout evaluations,
 * then stop. Set to 0 (default) to disable. The debug log is written
 * alongside the audit log: <log_dir>/debug_YYYYMMDD.jsonl
 *
 * The debug dump contains raw checkout data including email addresses.
 * Delete it after diagnosis. Ensure the log directory is NOT web-accessible
 * (e.g., .htaccess deny-all or nginx location block).
 */
if (!defined('PM_ANTIFRAUD_DEBUG')) {
    define('PM_ANTIFRAUD_DEBUG', 0);
}

/**
 * Real-city whitelist for suffix-based detection.
 *
 * Cities that end with a Faker suffix but are real places.
 * Case-insensitive exact match. Override to replace entirely,
 * or use PM_ANTIFRAUD_CITY_WHITELIST_EXTRA to extend.
 *
 * This list was compiled from postal databases and validated
 * against the 38-account legitimate customer dataset with zero
 * false positives. Add cities as you discover them.
 */
if (!defined('PM_ANTIFRAUD_CITY_WHITELIST')) {
    define('PM_ANTIFRAUD_CITY_WHITELIST', [
        // -burgh / -burg / -berg
        'pittsburgh', 'edinburgh', 'hamburg', 'salzburg', 'freiburg', 'augsburg',
        'marburg', 'magdeburg', 'wurzburg', 'duisburg', 'nuremberg', 'heidelberg',
        'gothenburg', 'johannesburg', 'harrisburg', 'gettysburg', 'williamsburg',
        'vicksburg', 'lynchburg', 'fredericksburg', 'st petersburg', 'petersburg',
        'plattsburgh', 'newburgh', 'middleburg', 'leesburg', 'clarksburg',
        'parkersburg', 'martinsburg', 'mecklenburg', 'orangeburg',
        // -ville
        'louisville', 'jacksonville', 'nashville', 'knoxville', 'greenville',
        'huntsville', 'charlottesville', 'gainesville', 'evansville', 'danville',
        'somerville', 'watsonville', 'bentonville', 'fayetteville', 'brownsville',
        'clarksville', 'starkville', 'roseville', 'vacaville', 'bartlesville',
        'hopkinsville', 'janesville', 'mcminnville', 'marysville', 'prattville',
        'statesville', 'titusville', 'zanesville', 'abbeville', 'belleville',
        'boonville', 'centerville', 'collinsville', 'connersville', 'cookeville',
        'crossville', 'edwardsville', 'emeryville', 'farmville', 'forestville',
        'harleysville', 'kernersville', 'lawrenceville', 'leavenworth',
        'lewisville', 'mandeville', 'monroeville', 'mooresville', 'naperville',
        'nicholasville', 'pflugerville', 'phoenixville', 'powellville',
        'simpsonville', 'taylorsville', 'thomasville', 'turnersville',
        'waynesville', 'westerville', 'winterville', 'woodinville',
        'deauville', 'trouville', 'granville', 'neillsville',
        // -ton
        'boston', 'houston', 'washington', 'princeton', 'lexington', 'arlington',
        'charleston', 'wellington', 'edmonton', 'hamilton', 'brighton', 'wilmington',
        'covington', 'galveston', 'scranton', 'trenton', 'stockton', 'appleton',
        'burlington', 'darlington', 'doncaster', 'easton', 'eglinton', 'ellington',
        'evanston', 'farmington', 'grafton', 'hampton', 'harrington', 'irvington',
        'kensington', 'kingston', 'lambton', 'livingston', 'moncton', 'monton',
        'northampton', 'paddington', 'palmerston', 'peterborough', 'saskatoon',
        'shelton', 'southampton', 'sutton', 'taunton', 'thornton', 'torrington',
        'weston', 'brampton', 'brantford', 'brixton', 'brompton', 'crofton',
        'croydon', 'dalton', 'denton', 'drayton', 'dunton', 'fenton', 'fulton',
        'gaston', 'horton', 'ironton', 'layton', 'leighton', 'linton',
        'litchfield', 'merton', 'middleton', 'milford', 'milton', 'morton',
        'newton', 'norton', 'overton', 'payton', 'peyton', 'picton', 'plympton',
        'poynton', 'preston', 'proton', 'quinton', 'reston', 'sefton', 'seaton',
        'skipton', 'stanton', 'swinton', 'tiverton', 'walton', 'warrington',
        'yeadon', 'addington', 'beddington', 'bennington', 'boonton', 'brockton',
        'canton', 'carleton', 'castleton', 'clifton', 'clinton', 'colton',
        'compton', 'cranston', 'dayton', 'elton', 'everton', 'felton',
        'frankston', 'gorton', 'groton', 'holton',
        'hunton', 'islington', 'johnston', 'kington', 'knowlton', 'langton',
        'larkington', 'lewiston', 'lofton', 'luton', 'malton', 'marston',
        'melton', 'molton', 'monkton', 'mooreton', 'nanton', 'normanton',
        'ogleton', 'oswestry', 'panton', 'paignton', 'patton', 'pemberton',
        'pendleton', 'piston', 'plimpton', 'polton', 'rington', 'riverton',
        'royston', 'ruston', 'salton', 'saxton', 'sheldon', 'shipton', 'skelton',
        'somerton', 'stretton', 'swanton', 'swindon', 'thurston', 'tilton',
        'trowton', 'upton', 'wainton', 'wallington', 'warton',
        'watton', 'welton', 'wharton', 'whitton', 'wilton', 'winton', 'witton',
        'wootton', 'worthington', 'wolverhampton', 'warrenton',
        // -land
        'portland', 'oakland', 'cleveland', 'lakeland', 'maryland', 'iceland',
        'auckland', 'sunderland', 'cumberland', 'westmoreland', 'northumberland',
        'greenland', 'newfoundland', 'holland', 'finland', 'ireland', 'scotland',
        'england', 'switzerland', 'zealand', 'homeland', 'midland', 'highland',
        'overland', 'garland', 'ashland', 'richland', 'copeland', 'kirkland',
        'loveland', 'moorland', 'roseland', 'vineland', 'woodland',
        'roland', 'rowland', 'rutland', 'shetland', 'southland',
        // -port
        'stockport', 'davenport', 'shreveport', 'bridgeport', 'freeport',
        'westport', 'eastport', 'southport', 'northport', 'newport', 'gulfport',
        'williamsport', 'kingsport', 'brockport', 'lockport', 'rockport',
        'bayport', 'devonport', 'gosport', 'transport',
        // -ford
        'bradford', 'bedford', 'oxford', 'hertford', 'guilford', 'stanford',
        'stratford', 'hereford', 'chelmsford', 'guildford', 'brentford',
        'salford', 'sandford', 'medford', 'milford', 'radford', 'watford',
        'telford', 'romford', 'basford', 'wexford', 'woodford', 'thetford',
        'hartford', 'stafford', 'concord', 'ashford', 'cranford', 'elmsford',
        'fulford', 'hanford', 'langford', 'ledford', 'longford', 'montford',
        'pickford', 'pitford', 'redford', 'rochford', 'rushford', 'trafford',
        'weatherford', 'whitford', 'wickford', 'williford', 'winford',
        // -side
        'riverside', 'oceanside', 'burnside', 'woodside', 'lakeside', 'hillside',
        'seaside', 'bayside', 'brookside', 'countryside', 'fireside', 'ironside',
        // -mouth
        'portsmouth', 'plymouth', 'dartmouth', 'bournemouth', 'falmouth',
        'monmouth', 'weymouth', 'exmouth', 'yarmouth', 'tynemouth', 'avonmouth',
        // -field
        'springfield', 'sheffield', 'wakefield', 'chesterfield', 'mansfield',
        'bakersfield', 'bloomfield', 'brookfield', 'clearfield',
        'deerfield', 'fairfield', 'greenfield', 'marshfield', 'northfield',
        'plainfield', 'ridgefield', 'southfield', 'westfield', 'windfield',
        'hatfield', 'huddersfield', 'macclesfield', 'stoke-on-trent',
        'lichfield', 'mirfield', 'penfield', 'pittsfield', 'scofield',
        // -bury
        'shrewsbury', 'canterbury', 'glastonbury', 'salisbury', 'woodbury',
        'waterbury', 'middlebury', 'westbury', 'danbury', 'sudbury', 'bunbury',
        'banbury', 'thornbury', 'tewkesbury', 'aylesbury', 'dewsbury',
        'malmesbury', 'shaftesbury', 'amesbury', 'kingsbury', 'norbury',
        'newbury', 'tilbury', 'sunbury', 'padbury', 'ledbury', 'tenbury',
        // -chester
        'chester', 'manchester', 'rochester', 'winchester', 'dorchester',
        'colchester', 'westchester', 'chichester', 'silchester', 'portchester',
        // -haven
        'new haven', 'whitehaven', 'brookhaven', 'fairhaven', 'newhaven',
        'milford haven', 'stonehaven', 'bremerhaven',
        // -view
        'grandview', 'clearview', 'lakeview', 'fairview', 'plainview',
        'longview', 'mountain view', 'oceanview', 'riverview', 'bayview',
        // -worth
        'leavenworth', 'fort worth', 'tamworth', 'kenilworth', 'wentworth',
        'ellsworth', 'chatsworth', 'wadsworth', 'wordsworth', 'butterworth',
        // -stad
        'darmstadt', 'ingolstadt', 'karlstadt',
        // -shire
        'hampshire', 'yorkshire', 'berkshire', 'wiltshire', 'devonshire',
        'lancashire', 'lincolnshire', 'oxfordshire', 'staffordshire',
        // -borough / -boro
        'peterborough', 'scarborough', 'marlborough', 'loughborough',
        'middlesbrough', 'farnborough', 'gainsborough', 'westborough',
        // -town
        'georgetown', 'jamestown', 'charlottetown', 'elizabethtown',
        'johnstown', 'levittown', 'morristown', 'norristown', 'germantown',
        'cooperstown', 'allentown', 'boyertown', 'doylestown', 'kutztown',
        'middletown', 'moorestown', 'newtown', 'uniontown', 'watertown',
        'youngstown', 'capetown', 'cape town', 'freetown',
        // -fort / -furt
        'frankfurt', 'erfurt', 'beaufort', 'comfort', 'rockfort',
        // Misc
        'belfast', 'cardiff', 'stockholm', 'utrecht',
        'dortmund', 'hannover', 'philadelphia',
        'ahmedabad', 'hyderabad', 'islamabad', 'allahabad', 'faisalabad',
        // Direction+real place combos (also in compound whitelist below)
        'new york', 'new jersey', 'new haven', 'new london', 'new orleans',
        'new delhi', 'new plymouth', 'new westminster',
        'port arthur', 'port charlotte', 'port elizabeth', 'port hedland',
        'port macquarie', 'port moresby', 'port said', 'port louis',
        'lake charles', 'lake havasu', 'lake placid', 'lake tahoe',
        'fort worth', 'fort collins', 'fort lauderdale', 'fort wayne',
        'fort myers', 'fort smith', 'fort pierce', 'fort lee',
        'east london', 'east haven', 'east hartford',
        'west ham', 'west haven', 'west hartford', 'west chester',
        'south bend', 'south portland', 'southfield',
        'north charleston', 'north port', 'north haven',
        'mount vernon', 'mount pleasant', 'mount prospect',
        'cape town', 'cape coral',
        'point pleasant', 'point comfort',
        'richmond', 'richmond hill',
    ]);
}

/**
 * Real compound-city whitelist for Direction+PersonName detection.
 *
 * These are real cities that match the "East|West|... + Name" pattern.
 * Case-insensitive exact match.
 */
if (!defined('PM_ANTIFRAUD_COMPOUND_WHITELIST')) {
    define('PM_ANTIFRAUD_COMPOUND_WHITELIST', [
        'east london', 'east haven', 'east hartford', 'east orange', 'east lansing',
        'east brunswick', 'east providence', 'east peoria', 'east chicago',
        'west ham', 'west haven', 'west hartford', 'west chester', 'west palm beach',
        'west covina', 'west jordan', 'west valley city', 'west des moines',
        'south bend', 'south portland', 'southfield', 'south gate',
        'south san francisco', 'south jordan', 'south lake tahoe',
        'north charleston', 'north port', 'north haven', 'north las vegas',
        'north little rock', 'north miami', 'north richland hills',
        'new york', 'new jersey', 'new haven', 'new london', 'new orleans',
        'new delhi', 'new plymouth', 'new westminster', 'new rochelle',
        'new bedford', 'new britain', 'new brunswick', 'new castle',
        'port arthur', 'port charlotte', 'port elizabeth', 'port hedland',
        'port macquarie', 'port moresby', 'port said', 'port louis',
        'port huron', 'port orange', 'port st lucie', 'port washington',
        'lake charles', 'lake havasu', 'lake placid', 'lake tahoe',
        'lake city', 'lake forest', 'lake oswego', 'lake worth',
        'lake elsinore', 'lake jackson', 'lake geneva', 'lake zurich',
        'fort worth', 'fort collins', 'fort lauderdale', 'fort wayne',
        'fort myers', 'fort smith', 'fort pierce', 'fort lee',
        'fort dodge', 'fort walton beach', 'fort hood',
        'mount vernon', 'mount pleasant', 'mount prospect',
        'mount laurel', 'mount holly', 'mount airy',
        'cape town', 'cape coral', 'cape may',
        'point pleasant', 'point comfort',
    ]);
}

// ---------------------------------------------------------------------------
// Detection functions
// ---------------------------------------------------------------------------

/**
 * Test whether a city name matches the Python Faker suffix pattern.
 *
 * Faker (en_US) generates cities by concatenating a random person name
 * with a geographic suffix: "Mitchellburgh", "Franklinland", "Thomasport".
 * Real cities sharing these suffixes are excluded via whitelist.
 *
 * @param string $city     City name from checkout form
 * @param array  $whitelist Override whitelist (default: PM_ANTIFRAUD_CITY_WHITELIST)
 * @param array  $suffixes  Override suffixes (default: PM_ANTIFRAUD_FAKER_SUFFIXES)
 * @return bool True if city appears to be Faker-generated
 */
function pm_antifraud_is_faker_city(
    string $city,
    array $whitelist = [],
    array $suffixes = []
): bool {
    $city = trim(preg_replace('/\s+/', ' ', $city));
    if ($city === '') {
        return false;
    }

    if ($whitelist === []) {
        $whitelist = PM_ANTIFRAUD_CITY_WHITELIST;
    }
    if ($suffixes === []) {
        $suffixes = PM_ANTIFRAUD_FAKER_SUFFIXES;
    }

    // Whitelist check — case-insensitive exact match
    $cityLower = strtolower($city);
    foreach ($whitelist as $real) {
        if ($cityLower === $real) {
            return false;
        }
    }

    // Suffix pattern — must appear at the end of the city string
    $suffixPattern = '/(' . implode('|', array_map(fn($s) => preg_quote($s, '/'), $suffixes)) . ')$/i';
    if (!preg_match($suffixPattern, $city)) {
        return false;
    }

    // Guard: single-word cities under 6 chars are likely real short names,
    // not Faker output. "Amystad" (7) passes; bare "ton" (3) does not.
    if (!str_contains($city, ' ') && mb_strlen($city) < 6) {
        return false;
    }

    return true;
}

/**
 * Test whether a city name matches the Python Faker compound pattern.
 *
 * Faker (en_US) also generates cities as Direction + PersonName:
 * "East Brenda", "North Christopher", "Port Matthew". Real compound
 * cities are excluded via whitelist.
 *
 * @param string $city      City name from checkout form
 * @param array  $whitelist Override whitelist (default: PM_ANTIFRAUD_COMPOUND_WHITELIST)
 * @param array  $prefixes  Override prefixes (default: PM_ANTIFRAUD_COMPOUND_PREFIXES)
 * @return bool True if city appears to be Faker-generated
 */
function pm_antifraud_is_faker_compound_city(
    string $city,
    array $whitelist = [],
    array $prefixes = []
): bool {
    $city = trim(preg_replace('/\s+/', ' ', $city));
    if ($city === '') {
        return false;
    }

    if ($whitelist === []) {
        $whitelist = PM_ANTIFRAUD_COMPOUND_WHITELIST;
    }
    if ($prefixes === []) {
        $prefixes = PM_ANTIFRAUD_COMPOUND_PREFIXES;
    }

    // Whitelist check
    $cityLower = strtolower($city);
    foreach ($whitelist as $real) {
        if ($cityLower === $real) {
            return false;
        }
    }

    // Pattern: Prefix + space + capitalized name (3-15 chars)
    // Case-insensitive to catch "east brenda" or "EAST BRENDA" variants
    $prefixPattern = '/^(' . implode('|', array_map(fn($s) => preg_quote($s, '/'), $prefixes)) . ') [a-z]{3,16}$/i';
    return (bool) preg_match($prefixPattern, $city);
}

/**
 * Score a checkout registration against the Faker spam model.
 *
 * Returns a score and the list of signals that fired. The caller
 * decides what to do with the score (block, flag, log, etc.).
 *
 * @param array  $fields      Checkout data. Expected keys:
 *                             'city', 'country', 'email', 'emailoptout'
 * @param string $productName Product name from the shopping cart
 * @return array{score: int, signals: string[]}
 */
function pm_antifraud_score(array $fields, string $productName): array
{
    $weights = PM_ANTIFRAUD_WEIGHTS;
    $score   = 0;
    $signals = [];

    // Signal: product pattern match
    $productPattern = PM_ANTIFRAUD_PRODUCT_PATTERN;
    if ($productPattern !== '' && preg_match($productPattern, $productName)) {
        $w = $weights['product_match'] ?? 0;
        $score += $w;
        $signals[] = "product_match({$w})";
    }

    $city    = $fields['city'] ?? '';
    $country = strtoupper($fields['country'] ?? '');

    // Signal: faker city suffix (US-gated)
    $fakerCityHit = false;
    if ($country === 'US' && pm_antifraud_is_faker_city($city)) {
        $w = $weights['faker_city_suffix'] ?? 0;
        $score += $w;
        $signals[] = "faker_city_suffix({$w})";
        $fakerCityHit = true;
    }

    // Signal: faker compound city (US-gated, mutually exclusive with suffix)
    if (!$fakerCityHit && $country === 'US' && pm_antifraud_is_faker_compound_city($city)) {
        $w = $weights['faker_city_compound'] ?? 0;
        $score += $w;
        $signals[] = "faker_city_compound({$w})";
    }

    // Signal: plus-addressed email
    $email = $fields['email'] ?? '';
    $localPart = explode('@', $email)[0] ?? '';
    if (str_contains($localPart, '+')) {
        $w = $weights['plus_email'] ?? 0;
        $score += $w;
        $signals[] = "plus_email({$w})";
    }

    // Signal: marketing opt-out
    $emailoptout = $fields['emailoptout'] ?? false;
    if ($emailoptout === true || $emailoptout === 'true' || $emailoptout === 1
        || $emailoptout === '1' || $emailoptout === 'on') {
        $w = $weights['email_optout'] ?? 0;
        $score += $w;
        $signals[] = "email_optout({$w})";
    }

    // Signal: US country
    if ($country === 'US') {
        $w = $weights['country_us'] ?? 0;
        $score += $w;
        $signals[] = "country_us({$w})";
    }

    return [
        'score'   => $score,
        'signals' => $signals,
    ];
}

// ---------------------------------------------------------------------------
// Hook registration
// ---------------------------------------------------------------------------

add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    $threshold = PM_ANTIFRAUD_THRESHOLD;

    try {
        // Extract checkout fields from hook vars (preferred) with POST fallback
        $city        = $vars['city'] ?? $_POST['city'] ?? '';
        $country     = $vars['country'] ?? $_POST['country'] ?? '';
        $email       = $vars['email'] ?? $_POST['email'] ?? '';
        $emailoptout = $vars['emailoptout'] ?? $_POST['emailoptout'] ?? false;

        // Extract product name from session cart via database lookup.
        // $_SESSION['cart']['products'] contains pid (product ID), not the
        // product name. Query tblproducts to resolve pid → name.
        $productName = '';
        if (isset($_SESSION['cart']['products']) && is_array($_SESSION['cart']['products'])) {
            $products = $_SESSION['cart']['products'];
            if (!empty($products)) {
                $firstProduct = reset($products);
                $pid = $firstProduct['pid'] ?? 0;
                if ($pid > 0 && class_exists('\WHMCS\Database\Capsule')) {
                    $row = \WHMCS\Database\Capsule::table('tblproducts')
                        ->where('id', $pid)
                        ->value('name');
                    if ($row !== null) {
                        $productName = (string) $row;
                    }
                }
            }
        }

        // Score
        $result = pm_antifraud_score([
            'city'        => $city,
            'country'     => $country,
            'email'       => $email,
            'emailoptout' => $emailoptout,
        ], $productName);

        $score   = $result['score'];
        $signals = $result['signals'];
        $action  = ($score >= $threshold) ? 'block' : 'pass';

        // Audit log — hash email for correlation without PII exposure
        $logDir = PM_ANTIFRAUD_LOG_DIR;
        if ($logDir !== '') {
            $emailHash = $email !== ''
                ? substr(hash('sha256', strtolower(trim($email))), 0, 8)
                : '';

            $logEntry = json_encode([
                'ts'        => gmdate('c'),
                'score'     => $score,
                'threshold' => $threshold,
                'signals'   => $signals,
                'action'    => $action,
                'city'      => $city,
                'country'   => $country,
                'email_hash'=> $emailHash,
                'product'   => $productName,
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($logEntry !== false) {
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0750, true);
                }
                @file_put_contents(
                    $logDir . '/' . gmdate('Ymd') . '.jsonl',
                    $logEntry . "\n",
                    FILE_APPEND | LOCK_EX
                );
            }

            // Debug dump — raw $vars and cart session for N orders, then stop.
            if (PM_ANTIFRAUD_DEBUG > 0) {
                $counterFile = $logDir . '/.debug_counter';
                $remaining   = PM_ANTIFRAUD_DEBUG;

                if (is_file($counterFile)) {
                    $remaining = (int) file_get_contents($counterFile);
                }

                if ($remaining > 0) {
                    $debugEntry = json_encode([
                        'ts'           => gmdate('c'),
                        'remaining'    => $remaining,
                        'vars_keys'    => array_keys($vars),
                        'vars'         => $vars,
                        'session_cart' => $_SESSION['cart'] ?? null,
                    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

                    if ($debugEntry !== false) {
                        @file_put_contents(
                            $logDir . '/debug_' . gmdate('Ymd') . '.jsonl',
                            $debugEntry . "\n",
                            FILE_APPEND | LOCK_EX
                        );
                    }

                    @file_put_contents($counterFile, (string) ($remaining - 1), LOCK_EX);
                }
            }
        }

        if ($action === 'block') {
            return PM_ANTIFRAUD_BLOCK_MESSAGE;
        }

        return '';
    } catch (\Throwable $e) {
        // Default ALLOW on any error — never block legitimate customers due to bugs
        return '';
    }
});
